<?php
declare(strict_types=1);

namespace wise\agent\checker;

use wise\agent\task\TaskQueryService;
use wise\support\config\CheckLevel;
use wise\support\config\CheckResult;
use wise\support\config\ConfigCheckInterface;

/**
 * 健康检查项：最近 24h 死信＼�
 *
 * 只读 {@see TaskQueryService::deadLetterCount()}：`status=failed` 且 `error` 以
 * `Max retries exceeded` 开头（三个 job 超限分支的统一落库标记）的条数 > 0 时告警。
 * 结果经 `ConfigCheckCollector` 聚合、由 `PackageConfigChecker` 进既有健康检查/安全体检报告。
 */
class AiDeadLetterChecker implements ConfigCheckInterface
{
    public function __construct(
        private ?TaskQueryService $query = null,
    ) {
    }

    public function id(): string
    {
        return 'wise-agent:dead-letter';
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
            $count = $this->query()->deadLetterCount(86400);

            if ($count > 0) {
                return CheckResult::fail(
                    "最近 24 小时有 {$count} 条死信（重试超限）",
                    '请在任务中心排查失败原因，修复后重新提交'
                );
            }

            return CheckResult::ok('最近 24 小时无死信');
        } catch (\Throwable $e) {
            return CheckResult::fail(
                '检查死信失败: ' . $e->getMessage(),
                '请确认 ai_agent_logs 表可访问'
            );
        }
    }

    private function query(): TaskQueryService
    {
        return $this->query ??= new TaskQueryService();
    }
}
