<?php
declare(strict_types=1);

namespace wise\agent\plan;

/**
 * 计划定义（B1 IR）
 *
 * 「一句目标 → 一串步骤」的结构化表达。**线性**（无 DAG / 无循环），
 * 与 `PipelineEngine` 的能力边界一致，因此可以直接物化为 Pipeline（{@see self::toPipelineSteps()}）。
 *
 * ## `limits` 只能**收紧**不能放宽
 *
 * 计划自身可以携带 `limits`（如「这次最多 5 步」），但生效值取
 * `min(配置上限, 计划声明)`（{@see PlanValidator}）—— 否则一份被注入的计划
 * 只要自带 `max_steps = 999` 就能绕过上限。
 */
final class PlanDefinition implements \JsonSerializable
{
    /** 规划者：LLM 产出 */
    public const PLANNER_LLM = 'llm';

    /** 规划者：调用方显式传入步骤（不经过 LLM） */
    public const PLANNER_MANUAL = 'manual';

    /** 规划者：由 Pipeline 定义逆向还原 */
    public const PLANNER_MATERIALIZED = 'materialized';

    /**
     * @param string $goal     原始目标（回显/审计；**不作为字段名或参数名的来源**）
     * @param array  $steps    {@see PlanStep}[]
     * @param array  $inputs   计划级输入（`$input.x` 的来源）
     * @param array  $limits   计划级上限（只能收紧）
     * @param array  $notes    诚实标注（如「本计划含 2 个写步骤」）
     * @param string $planner  见 PLANNER_*
     */
    public function __construct(
        public readonly string $goal = '',
        public readonly array $steps = [],
        public readonly array $inputs = [],
        public readonly array $limits = [],
        public readonly array $notes = [],
        public readonly string $planner = self::PLANNER_LLM,
    ) {
    }

    /**
     * 从计划 JSON 还原
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $steps = [];

        foreach (array_values(is_array($data['steps'] ?? null) ? $data['steps'] : []) as $index => $step) {
            if (is_array($step)) {
                $steps[] = PlanStep::fromArray($step, $index + 1);
            }
        }

        return new self(
            goal: trim((string) ($data['goal'] ?? '')),
            steps: $steps,
            inputs: is_array($data['inputs'] ?? null) ? $data['inputs'] : [],
            limits: is_array($data['limits'] ?? null) ? $data['limits'] : [],
            notes: is_array($data['notes'] ?? null) ? array_values($data['notes']) : [],
            planner: trim((string) ($data['planner'] ?? self::PLANNER_LLM)),
        );
    }

    /**
     * @param PlanStep[] $steps
     */
    public function withSteps(array $steps): self
    {
        return new self($this->goal, array_values($steps), $this->inputs, $this->limits, $this->notes, $this->planner);
    }

    /**
     * @param array<int, array<string, mixed>> $notes
     */
    public function withNotes(array $notes): self
    {
        return new self($this->goal, $this->steps, $this->inputs, $this->limits, array_values($notes), $this->planner);
    }

    /**
     * 回填计划级输入
     *
     * **由执行器在落库前调用**：`resume()` 从 `ai_plan_runs.plan` 里读回 `inputs`，
     * 若不回填，续跑时 `$input.x` 会全部解析为 `null`（B1-4 实测到的坑）。
     *
     * @param array<string, mixed> $inputs
     */
    public function withInputs(array $inputs): self
    {
        return new self($this->goal, $this->steps, $inputs, $this->limits, $this->notes, $this->planner);
    }

    public function stepById(string $id): ?PlanStep
    {
        foreach ($this->steps as $step) {
            if ($step instanceof PlanStep && $step->id === $id) {
                return $step;
            }
        }

        return null;
    }

    /**
     * 步骤 id → 序号（1 基，用于「引用只能指向更早步骤」的判定）
     *
     * @return array<string, int>
     */
    public function stepIndexes(): array
    {
        $map = [];
        foreach ($this->steps as $index => $step) {
            if ($step instanceof PlanStep) {
                $map[$step->id] = $index + 1;
            }
        }

        return $map;
    }

    /**
     * @return PlanStep[]
     */
    public function writeSteps(): array
    {
        return array_values(array_filter(
            $this->steps,
            static fn(mixed $step): bool => $step instanceof PlanStep && $step->isWrite()
        ));
    }

    public function isEmpty(): bool
    {
        return $this->steps === [];
    }

    /**
     * 物化为 Pipeline 步骤＼�
     *
     * 每个计划步骤 → 一个 `system.plan_step` skill 步骤，映射表原样搬运，
     * 因此**不需要改动 `PipelineEngine`**（它的步骤只认 skill 名）。
     *
     * @return array<int, array<string, mixed>>
     */
    public function toPipelineSteps(): array
    {
        $out = [];

        foreach ($this->steps as $step) {
            if (!$step instanceof PlanStep) {
                continue;
            }

            $out[] = [
                'skill'         => PlanShell::NODE,
                'input_mapping' => $step->toShellInput(),
                'output_key'    => $step->id,
                'condition'     => $step->condition,
                'retries'       => $step->retries,
                'timeout'       => $step->timeout,
                'on_error'      => 'fail',
            ];
        }

        return $out;
    }

    /**
     * 从 Pipeline 步骤定义逆向还原（物化产物 → 计划；供对拍与回放）
     *
     * @param array<int, array<string, mixed>> $pipelineSteps
     */
    public static function fromPipelineSteps(string $goal, array $pipelineSteps): self
    {
        $steps = [];

        foreach (array_values($pipelineSteps) as $index => $definition) {
            $skill = (string) ($definition['skill'] ?? $definition['skillName'] ?? '');

            if ($skill !== PlanShell::NODE) {
                continue;
            }

            $mapping = (array) ($definition['input_mapping'] ?? []);
            $steps[] = PlanStep::fromShellInput($mapping, $index + 1)->withWrite(false);
        }

        return new self($goal, $steps, [], [], [], self::PLANNER_MATERIALIZED);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'goal'    => $this->goal,
            'steps'   => array_map(
                static fn(mixed $step): array => $step instanceof PlanStep ? $step->toArray() : (array) $step,
                $this->steps
            ),
            'inputs'  => $this->inputs,
            'limits'  => $this->limits,
            'notes'   => $this->notes,
            'planner' => $this->planner,
        ];
    }

    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        return $this->toArray();
    }
}
