<?php
declare(strict_types=1);

namespace wise\agent\plan;

/**
 * 规划结果＼�
 *
 * `ok=false` 时 **`plan` 为 `null`** —— 这是刻意的：
 * 未通过校验的计划**一步都不许执行**＼�，
 * 因此不能把「一份不合法的计划」当返回值交出去，只能交出错误与轨迹。
 */
final class PlanPlanResult implements \JsonSerializable
{
    /**
     * @param bool                     $ok           是否产出**已通过校验**的计划
     * @param ?PlanDefinition          $plan         合法计划（ok=false 时为 null）
     * @param ?PlanValidationReport    $report       最后一次校验报告（便于排错与回环）
     * @param int                      $attempts     LLM 调用次数（1 = 一次成型；manual 模式为 0）
     * @param array                    $usage        累计 token 用量
     * @param int                      $catalogSize  目录条目数
     * @param array                    $trace        规划轨迹（可回放「AI 是怎么规划的」）
     * @param array                    $notes        诚实标注
     * @param ?string                  $error        失败原因（ok=false 时非空）
     */
    public function __construct(
        public readonly bool $ok,
        public readonly ?PlanDefinition $plan = null,
        public readonly ?PlanValidationReport $report = null,
        public readonly int $attempts = 0,
        public readonly array $usage = [],
        public readonly int $catalogSize = 0,
        public readonly array $trace = [],
        public readonly array $notes = [],
        public readonly ?string $error = null,
    ) {
    }

    public function totalTokens(): int
    {
        return (int) ($this->usage['total_tokens'] ?? 0);
    }

    public function summary(): string
    {
        if ($this->ok && $this->plan !== null) {
            return sprintf(
                '规划成功：%d 步（写步骤 %d 个），LLM 调用 %d 次',
                count($this->plan->steps),
                count($this->plan->writeSteps()),
                $this->attempts
            );
        }

        return sprintf('规划失败：%s', $this->error ?? '未知原因');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'ok'           => $this->ok,
            'plan'         => $this->plan?->toArray(),
            'report'       => $this->report?->toArray(),
            'attempts'     => $this->attempts,
            'usage'        => $this->usage,
            'total_tokens' => $this->totalTokens(),
            'catalog_size' => $this->catalogSize,
            'trace'        => $this->trace,
            'notes'        => $this->notes,
            'error'        => $this->error,
        ];
    }

    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        return $this->toArray();
    }
}
