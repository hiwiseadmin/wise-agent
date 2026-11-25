<?php
declare(strict_types=1);

namespace wise\agent\checker;

use wise\agent\task\TaskQueryService;
use wise\support\config\CheckLevel;
use wise\support\config\CheckResult;
use wise\support\config\ConfigCheckInterface;

/**
 * 健康检查项：心跳过期的运行中任务＼�
 *
 * 只读 {@see TaskQueryService::staleRunning()}：`status=running` 且心跳过期（默认
 * `wise-agent.tasks.stale_seconds`，600s）的任务数 > 0 时告警。结果经
 * `ConfigCheckCollector` 聚合、由 `PackageConfigChecker` 进既有健康检查/安全体检报告。
 */
class AiStaleTaskChecker implements ConfigCheckInterface
{
    public function __construct(
        private ?TaskQueryService $query = null,
    ) {
    }

    public function id(): string
    {
        return 'wise-agent:stale-task';
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
            $threshold = (int) $this->staleSeconds();
            $stale     = $this->query()->staleRunning($threshold);
            $count     = count($stale);

            if ($count > 0) {
                return CheckResult::fail(
                    "发现 {$count} 个心跳过期的运行中任务（> {$threshold}s）",
                    '运行 php think wise:agent:scan-timeouts 标记超时，并排查执行体是否卡死'
                );
            }

            return CheckResult::ok('无心跳过期的运行中任务');
        } catch (\Throwable $e) {
            return CheckResult::fail(
                '检查超时任务失败: ' . $e->getMessage(),
                '请确认 ai_agent_logs 表可访问'
            );
        }
    }

    private function query(): TaskQueryService
    {
        return $this->query ??= new TaskQueryService();
    }

    private function staleSeconds(): int
    {
        try {
            $value = (int) config('wise-agent.tasks.stale_seconds', 600);
            return $value > 0 ? $value : 600;
        } catch (\Throwable) {
            return 600;
        }
    }
}
