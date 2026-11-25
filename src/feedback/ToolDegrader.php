<?php
declare(strict_types=1);

namespace wise\agent\feedback;

use wise\agent\tool\ToolRegistry;
use wise\support\context\ContextualLogger;

/**
 * 工具自动降级＼�
 *
 * 某工具**连续失败** ≥ `feedback.health.fail_threshold` 时，自动从 `ToolRegistry`
 * 降权/下线（**不硬删除**，`restore()` 可恢复，命名空间一并保留）。
 *
 * ## 口径铁律�全护栏）
 * - 降级只降权/下线，**不硬删除**；`restore()` 可逆；
 * - `degrade`/`restore` 动作写审计（`auditFn` 可注入，默认记日志）；
 * - 连续失败判定是**确定性纯逻辑**（按时间序累加，成功清零），不引入随机。
 *
 * @package wise\agent
 */
final class ToolDegrader
{
    public function __construct(
        /** 可注入的审计回调（单测替身）：fn(string $name, string $action): void */
        private readonly ?\Closure $auditFn = null,
        /** 可注入的 ToolRegistry（单测用 newInstance 隔离实例） */
        private readonly ?ToolRegistry $registry = null,
    ) {
    }

    /**
     * 连续失败纯逻辑：按时间升序累加，成功清零
     *
     * @param array<int, array{tool: string, ok: bool}> $rows 按时间升序的调用记录
     * @return array<string, int> 每个工具「末尾连续失败数」
     */
    public static function computeConsecutiveFailures(array $rows): array
    {
        $consecutive = [];

        foreach ($rows as $row) {
            $tool = trim((string) ($row['tool'] ?? ''));
            if ($tool === '') {
                continue;
            }
            if ((bool) ($row['ok'] ?? false)) {
                $consecutive[$tool] = 0;
            } else {
                $consecutive[$tool] = ($consecutive[$tool] ?? 0) + 1;
            }
        }

        return $consecutive;
    }

    /**
     * 降级单个工具（下线 + 审计）
     */
    public function degrade(string $name): bool
    {
        if (!$this->registry()->degrade($name)) {
            return false;
        }
        $this->audit($name, 'degrade');

        return true;
    }

    /**
     * 恢复单个工具（重新上线 + 审计）
     */
    public function restore(string $name): bool
    {
        if (!$this->registry()->restore($name)) {
            return false;
        }
        $this->audit($name, 'restore');

        return true;
    }

    /**
     * 批量降级：连续失败 ≥ 阈值的工具
     *
     * @param array<string, int> $consecutiveFailures {@see self::computeConsecutiveFailures()} 的结果
     * @return string[] 本次被降级的工具名
     */
    public function degradeUnhealthy(array $consecutiveFailures): array
    {
        $threshold = $this->failThreshold();
        $degraded  = [];

        foreach ($consecutiveFailures as $tool => $count) {
            if ((int) $count >= $threshold && $this->degrade((string) $tool)) {
                $degraded[] = (string) $tool;
            }
        }

        return $degraded;
    }

    private function registry(): ToolRegistry
    {
        return $this->registry ?? ToolRegistry::instance();
    }

    private function audit(string $name, string $action): void
    {
        if ($this->auditFn !== null) {
            ($this->auditFn)($name, $action);
            return;
        }

        ContextualLogger::warning("[ToolDegrader] Tool '{$name}' {$action}ed");
    }

    private function failThreshold(): int
    {
        try {
            return max(1, (int) config('wise-agent.feedback.health.fail_threshold', 3));
        } catch (\Throwable) {
            return 3;
        }
    }
}
