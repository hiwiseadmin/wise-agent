<?php
declare(strict_types=1);

namespace wise\agent\plan;

/**
 * 计划步骤（B1 IR）
 *
 * 一个步骤 = 「引用一个**已存在**的能力」+ 参数映射 + 可选条件 + 断言。
 *
 * ## 四种步骤
 *
 * | kind | 执行面 | 说明 |
 * |---|---|---|
 * | `skill` | `SkillManager::execute()` | 复用业务封装 |
 * | `tool` | `ToolRegistry::execute()` | A1 的 `menu.action.*`、A2/A3 的只读工具都在这里 |
 * | `action` | 同 `tool` | 声明式动作（`ActionTool` 本就注册在 `ToolRegistry`） |
 * | `node` | `NodeRegistry` | 插件工作流节点（`ns:type`） |
 *
 * ## 两条硬规则
 *
 * 1. **`write` 不由 LLM 声明**：它由被引用能力自身的 `access` 推导
 *    （`AccessResolver::resolveTool()/resolveSkill()` → `write|admin` 即写），
 *    `PlanValidator` 会用推导值覆盖声明值＼�。
 *    这样「计划里写了 `write:false`」无法把自己伪装成只读。
 * 2. **引用语法与 `PipelineEngine` 同构**（`$input.x` / `$steps.{id}.y`），
 *    因此物化为 Pipeline 时**映射表可以直接搬**（{@see self::toShellInput()}）。
 */
final class PlanStep implements \JsonSerializable
{
    public const KIND_SKILL  = 'skill';
    public const KIND_TOOL   = 'tool';
    public const KIND_ACTION = 'action';
    public const KIND_NODE   = 'node';

    /** 断言失败 → 按 `retries` 重跑该步 */
    public const RETRY_STEP = 'retry_step';

    /** 断言失败 → 带轨迹重新规划**剩余步骤**（唯一在执行阶段调 LLM 的分支） */
    public const REPLAN = 'replan';

    /** 断言失败 → 终止计划 */
    public const ABORT = 'abort';

    /**
     * @var string[]
     */
    public const ON_VERIFY_FAIL = [self::RETRY_STEP, self::REPLAN, self::ABORT];

    /**
     * @var string[]
     */
    public const KINDS = [self::KIND_SKILL, self::KIND_TOOL, self::KIND_ACTION, self::KIND_NODE];

    /**
     * @param string   $id           步骤 id（引用与续跑定位；缺省由序号生成 `s1`）
     * @param string   $kind         见 KIND_*
     * @param string   $target       被引用的能力名（必须真实存在）
     * @param string   $label        人类可读（轨迹与审批理由）
     * @param array    $input        参数映射（值位置可写任意自然语言，**键位置受 Schema 约束**）
     * @param ?string  $condition    条件表达式（与 `PipelineEngine` 同一语法子集）
     * @param array    $verify       断言列表（{@see PlanAssertions}）
     * @param string   $onVerifyFail 见 RETRY_STEP / REPLAN / ABORT
     * @param int      $retries      重试次数（幂等性由能力自身保证）
     * @param ?int     $timeout      单步超时秒数（**由 PlanExecutor 实现**）
     * @param bool     $write        是否写步骤（**由能力 access 推导，不接受声明**）
     */
    public function __construct(
        public readonly string $id,
        public readonly string $kind,
        public readonly string $target,
        public readonly string $label = '',
        public readonly array $input = [],
        public readonly ?string $condition = null,
        public readonly array $verify = [],
        public readonly string $onVerifyFail = self::ABORT,
        public readonly int $retries = 0,
        public readonly ?int $timeout = null,
        public readonly bool $write = false,
    ) {
    }

    /**
     * 从计划 JSON 还原（容错：缺 id 时按序号生成；kind 归一为小写）
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data, int $index = 1): self
    {
        $id = trim((string) ($data['id'] ?? ''));

        return new self(
            id: $id !== '' ? $id : 's' . $index,
            kind: strtolower(trim((string) ($data['kind'] ?? ''))),
            target: trim((string) ($data['target'] ?? $data['capability'] ?? '')),
            label: trim((string) ($data['label'] ?? '')),
            input: is_array($data['input'] ?? null) ? $data['input'] : [],
            condition: isset($data['condition']) && trim((string) $data['condition']) !== ''
                ? (string) $data['condition']
                : null,
            verify: is_array($data['verify'] ?? null) ? array_values($data['verify']) : [],
            onVerifyFail: strtolower(trim((string) ($data['on_verify_fail'] ?? $data['onVerifyFail'] ?? self::ABORT))),
            retries: (int) ($data['retries'] ?? 0),
            timeout: isset($data['timeout']) && $data['timeout'] !== null ? (int) $data['timeout'] : null,
            write: (bool) ($data['write'] ?? false),
        );
    }

    /**
     * 回填 `write`（由 `PlanValidator` 依能力 access 推导后调用）
     */
    public function withWrite(bool $write): self
    {
        return new self(
            $this->id,
            $this->kind,
            $this->target,
            $this->label,
            $this->input,
            $this->condition,
            $this->verify,
            $this->onVerifyFail,
            $this->retries,
            $this->timeout,
            $write
        );
    }

    /**
     * 回填重试次数（供 `plan.default_retries` 等策略使用）
     */
    public function withRetries(int $retries): self
    {
        return new self(
            $this->id,
            $this->kind,
            $this->target,
            $this->label,
            $this->input,
            $this->condition,
            $this->verify,
            $this->onVerifyFail,
            $retries,
            $this->timeout,
            $this->write
        );
    }

    public function isWrite(): bool
    {
        return $this->write;
    }

    public function hasVerify(): bool
    {
        return $this->verify !== [];
    }

    /**
     * 该步骤引用的步骤 id（`input` / `condition` / `verify` 里的 `$steps.x`）
     *
     * ⚠️ **刻意不过滤自引用**：`s2` 引用 `s2` 是**真错误**（会读到自己的未定义输出），
     * 必须交给 `PlanValidator` 显式拒绝（`invalid_reference`），
     * 在 IR 层悄悄过滤掉只会把这个错误藏起来。
     *
     * @return string[]
     */
    public function references(): array
    {
        $ids = array_merge(
            $this->collectStepIds($this->input),
            $this->collectStepIds($this->condition),
            $this->collectStepIds($this->verify)
        );

        return array_values(array_unique($ids));
    }

    /**
     * 物化为 `system.plan_step` 的输入＼�
     *
     * 关键：**引用原样保留**（`$steps.x` 不解析）—— 交给 `PipelineEngine` 的 `input_mapping`
     * 在运行期解析，语义与计划执行时一致。
     *
     * @return array<string, mixed>
     */
    public function toShellInput(): array
    {
        return array_filter([
            'id'     => $this->id,
            'kind'   => $this->kind,
            'target' => $this->target,
            'label'  => $this->label,
            'input'  => $this->input,
        ], static fn(mixed $v): bool => $v !== '' && $v !== []);
    }

    /**
     * 从 `system.plan_step` 的输入还原（物化产物 → 计划步骤）
     *
     * @param array<string, mixed> $input
     */
    public static function fromShellInput(array $input, int $index = 1): self
    {
        return self::fromArray($input, $index);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'             => $this->id,
            'kind'           => $this->kind,
            'target'         => $this->target,
            'label'          => $this->label,
            'input'          => $this->input,
            'condition'      => $this->condition,
            'verify'         => $this->verify,
            'on_verify_fail' => $this->onVerifyFail,
            'retries'        => $this->retries,
            'timeout'        => $this->timeout,
            'write'          => $this->write,
        ];
    }

    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        return $this->toArray();
    }

    /**
     * 递归收集 `$steps.{id}` 形态的引用（同时支持内联 `{$steps.x}`）
     *
     * @return string[]
     */
    private function collectStepIds(mixed $value): array
    {
        if (is_string($value)) {
            if (preg_match_all('/\$steps\.([A-Za-z0-9_\-]+)/', $value, $matches) > 0) {
                return $matches[1];
            }

            return [];
        }

        if (!is_array($value)) {
            return [];
        }

        $ids = [];
        foreach ($value as $item) {
            foreach ($this->collectStepIds($item) as $id) {
                $ids[] = $id;
            }
        }

        return $ids;
    }
}
