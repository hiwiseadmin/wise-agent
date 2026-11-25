<?php
declare(strict_types=1);

namespace wise\agent\plan;

/**
 * 计划执行结果＼�
 *
 * 与 `PlanRun::status` 同源的四态：`completed` / `aborted` / `paused` / `running`（异常中断时可能停在 running）。
 * **`paused` 不是失败**：它表示「某一步在等人工审批」，恢复入口是 `plan_resume`（B1-4）。
 */
final class PlanExecutionResult implements \JsonSerializable
{
    /**
     * @param string $status     见 `PlanRun::STATUS_*`
     * @param int    $runId      运行 id（落库后回填；未落库时为 0）
     * @param string $trackingId 与 `ai_agent_logs.tracking_id` 关联
     * @param array  $steps      {@see PlanStepRun}[]
     * @param array  $outputs    `step_id => 输出`（续跑与改道要用）
     * @param bool   $paused     是否因等待审批而暂停
     * @param int    $replans    已发生的改道次数
     * @param int    $tokens     改道消耗的 token（计划执行本身不调 LLM）
     * @param array  $trace      逐步轨迹（可回放「AI 是怎么跑完的」）
     */
    public function __construct(
        public readonly string $status,
        public readonly int $runId = 0,
        public readonly string $trackingId = '',
        public readonly array $steps = [],
        public readonly array $outputs = [],
        public readonly int $completed = 0,
        public readonly int $failed = 0,
        public readonly bool $paused = false,
        public readonly int $replans = 0,
        public readonly int $tokens = 0,
        public readonly float $duration = 0.0,
        public readonly array $trace = [],
        public readonly array $notes = [],
        public readonly ?string $error = null,
    ) {
    }

    public function isCompleted(): bool
    {
        return $this->status === PlanRun::STATUS_COMPLETED;
    }

    public function isAborted(): bool
    {
        return $this->status === PlanRun::STATUS_ABORTED;
    }

    /**
     * 等待审批的那一步（`paused` 时给调用方定位用）
     */
    public function blockedStep(): ?PlanStepRun
    {
        foreach ($this->steps as $step) {
            if ($step instanceof PlanStepRun && $step->isBlocked()) {
                return $step;
            }
        }

        return null;
    }

    public function summary(): string
    {
        $base = sprintf(
            '计划运行 #%d：%s（完成 %d / 失败 %d，改道 %d 次）',
            $this->runId,
            $this->status,
            $this->completed,
            $this->failed,
            $this->replans
        );

        if ($this->paused) {
            $blocked = $this->blockedStep();

            $base .= $blocked !== null
                ? sprintf('；第 %d 步等待审批（审批单 #%s）', $blocked->stepNo, (string) $blocked->approvalId)
                : '；等待人工审批';
        }

        if ($this->error !== null) {
            $base .= '；原因：' . $this->error;
        }

        return $base;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status'      => $this->status,
            'run_id'      => $this->runId,
            'tracking_id' => $this->trackingId,
            'completed'   => $this->completed,
            'failed'      => $this->failed,
            'paused'      => $this->paused,
            'replans'     => $this->replans,
            'tokens'      => $this->tokens,
            'duration'    => $this->duration,
            'steps'       => array_map(
                static fn(mixed $s): array => $s instanceof PlanStepRun ? $s->toArray() : (array) $s,
                $this->steps
            ),
            'outputs'     => $this->outputs,
            'trace'       => $this->trace,
            'notes'       => $this->notes,
            'error'       => $this->error,
        ];
    }

    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        return $this->toArray();
    }
}
