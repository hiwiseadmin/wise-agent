<?php
declare(strict_types=1);

namespace wise\agent\feedback;

use wise\support\config\CheckLevel;
use wise\support\config\CheckResult;
use wise\support\config\ConfigCheckInterface;

/**
 * 工具健康预警＼�
 *
 * 只读 {@see ToolHealthTracker::stats()}：失败率 ≥ `feedback.health.failure_rate_threshold`
 * 的工具 ⇒ warn，接入 `ConfigCheckCollector`（复用 C1-3 的健康检查接入路径）。
 */
class ToolHealthChecker implements ConfigCheckInterface
{
    public function __construct(
        /** 可注入的统计回调（单测替身）：fn(): array */
        private readonly ?\Closure $statsFn = null,
    ) {
    }

    public function id(): string
    {
        return 'wise-agent:tool-health';
    }

    public function package(): string
    {
        return 'wise-agent';
    }

    public function level(): CheckLevel
    {
        return CheckLevel::WARNING;
    }

    public function check(): CheckResult
    {
        try {
            $stats     = $this->stats();
            $threshold = $this->threshold();

            $unhealthy = [];
            foreach ($stats as $tool) {
                $rate = (float) ($tool['failure_rate'] ?? 0);
                if ($rate >= $threshold) {
                    $unhealthy[] = sprintf(
                        '%s(失败率 %d%%: %d/%d)',
                        (string) ($tool['tool'] ?? '?'),
                        (int) round($rate * 100),
                        (int) ($tool['failure'] ?? 0),
                        (int) ($tool['calls'] ?? 0)
                    );
                }
            }

            if ($unhealthy !== []) {
                return CheckResult::fail(
                    '发现失败率超阈值的工具：' . implode('、', $unhealthy),
                    '请在工具健康面板排查失败原因，必要时降权/下线'
                );
            }

            return CheckResult::ok('无失败率超阈值的工具');
        } catch (\Throwable $e) {
            return CheckResult::fail(
                '检查工具健康失败: ' . $e->getMessage(),
                '请确认 ai_plan_steps / ai_closure_events 表可访问'
            );
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function stats(): array
    {
        if ($this->statsFn !== null) {
            return (array) ($this->statsFn)();
        }

        return (new ToolHealthTracker())->stats();
    }

    private function threshold(): float
    {
        try {
            $value = (float) config('wise-agent.feedback.health.failure_rate_threshold', 0.5);
            return $value > 0 && $value <= 1 ? $value : 0.5;
        } catch (\Throwable) {
            return 0.5;
        }
    }
}
