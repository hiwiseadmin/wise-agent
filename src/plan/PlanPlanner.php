<?php
declare(strict_types=1);

namespace wise\agent\plan;

use wise\agent\client\AiClientFactory;
use wise\agent\client\Feature;
use wise\agent\contract\AiClientInterface;

/**
 * 计划规划器＼�
 *
 * 「目标 → 计划」这一步的**唯一实现**。三件事：
 *
 * 1. **一次性的 JSON 产出**（不是 Function Calling 的 `tool_calls`）：
 *    计划是**数据**不是动作；用 tool_calls 表达会让模型在规划期就可能直接调工具，
 * 2. **能力目录按目标裁剪**（第 ① 层，`PlanCatalog::toPrompt()`）：
 *    不再像现状那样把全量工具 Schema 每步都喂给 LLM（`AgentManager.php:206`）；
 * 3. **不合法即回环修复**：把 `PlanValidationReport::repairHint()` 作为下一轮输入，
 *    次数受 `plan.max_plan_repairs` 约束；用尽即**拒绝**（`ok=false` 且 `plan=null`），
 *    一步都不执行。
 *
 * ## 三个「不接受」的边界
 *
 * - **不接受目录外的能力**：`PlanValidator` 第 4 条会拒（本类只负责把目录给全）；
 * - **不接受调用方的上限被放宽**：调用方传入的 `limits` **覆盖**计划自报的 `limits`
 *   （`PlanValidator` 还会再取 `min`，两道保险）；
 * - **不接受「规划失败还返回半成品」**：`ok=false ⇒ plan=null`（见 `PlanPlanResult`）。
 *
 * `client()` / `catalog()` / `validator()` 是 `protected` 接缝，因此本类**不加 `final`**，
 * 单测可注入脚本化客户端实现完全离线（不需要 API key / 联网 / DB）。
 */
class PlanPlanner
{
    use PlanOptions;

    /** 规划模式：由 LLM 规划（默认） */
    public const MODE_LLM = 'llm';

    /** 规划模式：只接受调用方显式传入的步骤（不调用 LLM） */
    public const MODE_MANUAL = 'manual';

    private ?AiClientInterface $client = null;

    private ?PlanCatalog $catalog = null;

    /**
     * @param array<string, mixed> $options `plan.*` 覆盖项
     */
    public function __construct(
        array $options = [],
        ?AiClientInterface $client = null,
        private ?PlanValidator $validator = null,
        private ?PlanCatalogBuilder $catalogBuilder = null
    ) {
        $this->setPlanOptions($options);
        $this->client = $client;
    }

    /**
     * 规划一份计划
     *
     * @param string                    $goal   目标（**只作为目标**，不参与字段名生成）
     * @param array<string, mixed>      $inputs 计划级输入；`manual` 模式下从 `inputs['steps']` 取步骤
     * @param array<string, mixed>      $limits 调用方上限（覆盖计划自报值）
     */
    public function plan(string $goal, array $inputs = [], array $limits = []): PlanPlanResult
    {
        $trace = [];

        if ($this->plannerMode() === self::MODE_MANUAL) {
            return $this->manualPlan($goal, $inputs, $limits, $trace);
        }

        $catalog = $this->catalog();

        if ($catalog->isEmpty()) {
            return new PlanPlanResult(
                ok: false,
                attempts: 0,
                catalogSize: 0,
                trace: [['step' => 'plan.no_catalog', 'message' => '能力目录为空（无已注册工具/技能/节点，或全部被 allowed_kinds/deny_capabilities 过滤）']],
                error: 'plan_no_catalog',
            );
        }

        $catalogPrompt = $catalog->toPrompt($this->catalogChars());
        $messages      = [
            ['role' => 'system', 'content' => $this->systemPrompt($catalog, $catalogPrompt)],
            ['role' => 'user', 'content' => $this->goalPrompt($goal, $inputs)],
        ];

        $maxTries = 1 + max(0, (int) $this->planOpt('max_plan_repairs', 2));
        $usage    = [];
        $attempts = 0;
        $report   = null;
        $notes    = [];

        while ($attempts < $maxTries) {
            $attempts++;

            try {
                $response = $this->client()->chat($messages, [], $this->chatOptions());
            } catch (\Throwable $e) {
                $trace[] = ['step' => 'plan.error', 'message' => sprintf('第 %d 次规划调用失败：%s', $attempts, $e->getMessage())];

                return new PlanPlanResult(
                    ok: false,
                    attempts: $attempts,
                    usage: $usage,
                    catalogSize: $catalog->size(),
                    trace: $trace,
                    error: 'planner_call_failed: ' . $e->getMessage(),
                );
            }

            $usage   = $this->mergeUsage($usage, $response['usage'] ?? []);
            $content = (string) ($response['content'] ?? '');

            $trace[] = [
                'step'    => 'plan.llm',
                'message' => sprintf('第 %d 次规划：返回 %d 字符', $attempts, mb_strlen($content, 'UTF-8')),
            ];

            $json = $this->parseJson($content);

            if ($json === null) {
                $trace[] = ['step' => 'plan.unparsable', 'message' => '返回内容不是合法 JSON，回环要求重出'];

                $messages[] = ['role' => 'assistant', 'content' => $content];
                $messages[] = ['role' => 'user', 'content' => $this->jsonRepairHint()];

                continue;
            }

            $plan   = $this->planFromJson($json, $goal, $limits, $notes);
            $report = $this->validator()->validate($plan);

            if ($report->ok) {
                $trace[] = [
                    'step'    => 'plan.passed',
                    'message' => sprintf('第 %d 次规划通过校验：%d 步', $attempts, count($report->plan->steps)),
                ];

                return new PlanPlanResult(
                    ok: true,
                    plan: $report->plan,
                    report: $report,
                    attempts: $attempts,
                    usage: $usage,
                    catalogSize: $catalog->size(),
                    trace: $trace,
                    notes: array_merge($notes, $report->notes),
                );
            }

            $trace[] = [
                'step'    => 'plan.invalid',
                'message' => sprintf('第 %d 次规划未通过校验（%d 项错误），回环修复', $attempts, count($report->errors)),
            ];

            $messages[] = ['role' => 'assistant', 'content' => $content];
            $messages[] = ['role' => 'user', 'content' => $report->repairHint()];
        }

        $trace[] = ['step' => 'plan.rejected', 'message' => sprintf('修复次数用尽（%d 次），拒绝该计划，未执行任何步骤', $attempts)];

        return new PlanPlanResult(
            ok: false,
            report: $report,
            attempts: $attempts,
            usage: $usage,
            catalogSize: $catalog->size(),
            trace: $trace,
            notes: $notes,
            error: 'plan_invalid_after_repairs',
        );
    }


    /**
     * 规划模式（默认 `llm`）
     */
    public function plannerMode(): string
    {
        $mode = strtolower(trim((string) $this->planOpt('planner', self::MODE_LLM)));

        return $mode === self::MODE_MANUAL ? self::MODE_MANUAL : self::MODE_LLM;
    }


    /**
     * LLM 客户端（`protected` 接缝：单测注入脚本化客户端）
     */
    protected function client(): AiClientInterface
    {
        return $this->client ??= AiClientFactory::create($this->providerName());
    }

    /**
     * 能力目录（进程内缓存；`protected` 接缝便于离线测试）
     */
    protected function catalog(): PlanCatalog
    {
        return $this->catalog ??= $this->catalogBuilder()->build();
    }

    protected function catalogBuilder(): PlanCatalogBuilder
    {
        return $this->catalogBuilder ??= new PlanCatalogBuilder($this->planOptions());
    }

    protected function validator(): PlanValidator
    {
        return $this->validator ??= new PlanValidator($this->planOptions());
    }

    /**
     * 规划 Prompt 的 system 部分（输出契约 + 硬规则 + 能力目录）
     */
    protected function systemPrompt(PlanCatalog $catalog, string $catalogPrompt): string
    {
        $kinds     = implode('|', PlanStep::KINDS);
        $asserts   = implode('|', PlanAssertions::TYPES);
        $failModes = implode('|', PlanStep::ON_VERIFY_FAIL);

        $prompt = <<<PROMPT
你是 WiseAdmin 的任务规划器。你**只产出计划（JSON）**，不执行任何动作、不调用任何工具。

# 输出格式（必须是**纯 JSON**，不要解释文字；可用 ```json 围栏）
{
  "goal": "目标原话",
  "steps": [
    {
      "id": "s1",
      "kind": "{$kinds}",
      "target": "能力名（必须来自下方目录，逐字一致）",
      "label": "这一步做什么（人类可读）",
      "input": { "参数名": "取值或 \$steps.s1.data.xxx" },
      "condition": "可选条件，如 \$steps.s1.data.total != null",
      "verify": [ {"type": "{$asserts}", "path": "data.total", "value": 1} ],
      "on_verify_fail": "{$failModes}",
      "retries": 0,
      "timeout": 30
    }
  ]
}

# 硬规则（违反任一条计划都会被拒绝）
1. `kind` 只能是 {$kinds}；`target` **必须逐字来自下方能力目录**，不得编造或改写。
2. `input` 的**键**必须是该能力声明的参数名（见目录的「参数:」）；**值**可以是任意文本或 `\$steps.<更早步骤id>.路径` 引用。
3. **引用只能指向更早的步骤**（禁止自引用与前向引用）。
4. **写步骤（目录里标注【写】）必须至少给出一条 `verify` 断言**，让写操作能被自证。
5. `verify` 断言类型只能是 {$asserts}；**不要写正则**（正则会被拒绝）；`count_gte`/`gte`/`lte` 的 `value` 必须是数字。
6. `on_verify_fail` 只能是 {$failModes}；`retries` ≥ 0。
7. 步骤数尽量少（每一步都要有明确目的）；不要为了「看起来完整」而堆步骤。
8. 目标里没有给出的取值**不要编造**：宁可少写一个参数，也不要用占位符或猜测值。

# 可用能力
{$catalogPrompt}
PROMPT;

        return str_replace(["\r\n", "\r"], "\n", $prompt);
    }

    /**
     * 目标提示（用户消息）
     *
     * @param array<string, mixed> $inputs
     */
    protected function goalPrompt(string $goal, array $inputs): string
    {
        $lines = ['目标：' . $goal];

        if ($inputs !== []) {
            $lines[] = '已知输入（可在 input 里用 `$input.<键>` 引用）：' . json_encode($inputs, JSON_UNESCAPED_UNICODE);
        }

        $lines[] = '请输出计划 JSON。';

        return implode("\n", $lines);
    }

    protected function jsonRepairHint(): string
    {
        return '你上一次的回复不是合法 JSON。请**只**输出计划 JSON（可从 ```json 围栏开始），不要任何解释文字。';
    }


    /**
     * `manual` 模式：不调 LLM，只校验调用方给出的步骤
     *
     * @param array<string, mixed> $inputs
     * @param array<string, mixed> $limits
     * @param array<int, array{step:string, message:string}> $trace
     */
    private function manualPlan(string $goal, array $inputs, array $limits, array $trace): PlanPlanResult
    {
        $steps = $inputs['steps'] ?? null;

        if (!is_array($steps) || $steps === []) {
            return new PlanPlanResult(
                ok: false,
                attempts: 0,
                trace: [['step' => 'plan.manual_missing', 'message' => 'planner=manual 时必须在 inputs.steps 里显式给出步骤']],
                error: 'manual_steps_required',
            );
        }

        $plan   = PlanDefinition::fromArray([
            'goal'    => $goal,
            'steps'   => $steps,
            'inputs'  => $inputs,
            'limits'  => $limits,
            'planner' => PlanDefinition::PLANNER_MANUAL,
        ]);
        $report = $this->validator()->validate($plan);

        return new PlanPlanResult(
            ok: $report->ok,
            plan: $report->ok ? $report->plan : null,
            report: $report,
            attempts: 0,
            catalogSize: 0,
            trace: $trace,
            notes: $report->notes,
            error: $report->ok ? null : 'manual_plan_invalid',
        );
    }

    /**
     * LLM JSON → `PlanDefinition`
     *
     * - **目标与上限以调用方为准**（`['goal'=>…, 'limits'=>…] + $json` 的左侧优先），
     *   避免「计划自报一个更宽的上限」；
     * - 计划自报的 `limits` 中未被调用方覆盖的键仍然保留（`PlanValidator` 只会收紧）；
     * - `default_retries` 只对**未声明重试**（=0）的步骤生效。
     *
     * @param array<string, mixed>  $json
     * @param array<string, mixed>  $limits
     * @param array<int, array{code:string, message:string}> $notes
     */
    private function planFromJson(array $json, string $goal, array $limits, array &$notes): PlanDefinition
    {
        $declared = is_array($json['limits'] ?? null) ? $json['limits'] : [];

        if ($declared !== [] && array_diff_key($declared, $limits) !== []) {
            $notes[] = [
                'code'    => 'plan.declared_limits',
                'message' => '计划自报了 limits（' . implode('/', array_keys($declared)) . '），'
                    . '生效值仍取「配置上限与调用方上限」的较小者，计划只能收紧不能放宽。',
            ];
        }

        $plan = PlanDefinition::fromArray([
            'goal'    => $goal,
            'steps'   => $json['steps'] ?? [],
            'inputs'  => $json['inputs'] ?? [],
            'limits'  => array_merge($declared, $limits),
            'planner' => PlanDefinition::PLANNER_LLM,
        ]);

        $defaultRetries = max(0, (int) $this->planOpt('default_retries', 0));

        if ($defaultRetries > 0) {
            $steps = [];

            foreach ($plan->steps as $step) {
                $steps[] = $step->retries === 0 ? $step->withRetries($defaultRetries) : $step;
            }

            $plan = $plan->withSteps($steps);
        }

        return $plan;
    }

    /**
     * 宽松解析模型返回的 JSON：去围栏 → 直接解析 → 取首尾大括号再试
     *
     * @return array<string, mixed>|null
     */
    protected function parseJson(string $content): ?array
    {
        $text = trim($content);

        if ($text === '') {
            return null;
        }

        if (preg_match('/```(?:json)?\s*(.+?)```/s', $text, $matches) === 1) {
            $text = trim($matches[1]);
        }

        $decoded = json_decode($text, true);

        if (is_array($decoded)) {
            return $decoded;
        }

        $start = strpos($text, '{');
        $end   = strrpos($text, '}');

        if ($start !== false && $end !== false && $end > $start) {
            $decoded = json_decode(substr($text, $start, $end - $start + 1), true);

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * 规划调用的 chat 选项
     *
     * 温度刻意低于对话（规划要稳）；客户端支持 JSON 模式时一并声明，
     * 但**不假设**所有 Provider 都支持（用 `supports()` 探测）。
     *
     * @return array<string, mixed>
     */
    protected function chatOptions(): array
    {
        $options = [
            'temperature' => (float) $this->planOpt('planner_temperature', 0.2),
            'max_tokens'  => max(1, (int) $this->planOpt('planner_max_tokens', 4096)),
        ];

        try {
            if ($this->client()->supports(Feature::JSON_MODE)) {
                $options['response_format'] = ['type' => 'json_object'];
            }
        } catch (\Throwable) {
        }

        return $options;
    }

    /**
     * @param array<string, mixed> $usage
     * @param array<string, mixed> $delta
     * @return array<string, mixed>
     */
    private function mergeUsage(array $usage, array $delta): array
    {
        $merged = $usage;

        foreach (['prompt_tokens', 'completion_tokens', 'total_tokens'] as $key) {
            $merged[$key] = (int) ($merged[$key] ?? 0) + (int) ($delta[$key] ?? 0);
        }

        return $merged;
    }

    private function catalogChars(): int
    {
        return max(200, (int) $this->planOpt('max_catalog_chars', PlanCatalog::DEFAULT_MAX_CHARS));
    }

    private function providerName(): ?string
    {
        $provider = trim((string) $this->planOpt('provider', ''));

        return $provider !== '' ? $provider : null;
    }

}
