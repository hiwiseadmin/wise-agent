<?php
declare(strict_types=1);

namespace wise\agent\task;

use wise\agent\closure\ClosureDispatcher;
use wise\agent\closure\ClosureEventFactory;
use wise\support\context\ContextualLogger;

/**
 * 死信通知＼�
 *
 * 三个异步 job（AiAgentJob / PipelineJob / PlanJob）在**重试超限**时，除了落各自的
 * 状态列外，再通过 B2 闭环派发一条 `task_failed` 事件（默认路由 notification + audit）。
 *
 * 硬约束：
 * - `wise-agent.closure.enabled=false` 时**静默跳过**（通知不生效，但落库仍在 job 内完成，
 *   不假装发过）；
 * - **绝不抛异常**：死信通知失败绝不能影响 job 的 `delete()` 收尾（与 ClosureDispatcher 的
 *   「dispatch 不抛异常」契约一致，这里再兜一层）。
 */
final class DeadLetterNotifier
{
    /**
     * 派发 `task_failed` 死信通知（静默失败）
     *
     * @param array<string, mixed> $meta 死信上下文，见 {@see ClosureEventFactory::fromTaskFailed()}
     */
    public static function notify(array $meta): void
    {
        try {
            $options = (array) config('wise-agent.closure', []);

            if (!(bool) ($options['enabled'] ?? false)) {
                return;
            }

            $factory = new ClosureEventFactory($options);
            $event   = $factory->fromTaskFailed($meta);

            (new ClosureDispatcher($options))->dispatch($event);
        } catch (\Throwable $e) {
            try {
                ContextualLogger::warning('[DeadLetter] task_failed 通知失败：' . $e->getMessage());
            } catch (\Throwable) {
            }
        }
    }
}
