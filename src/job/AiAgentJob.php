<?php
declare(strict_types=1);

namespace wise\agent\job;

use wise\support\context\ContextualLogger;
use think\queue\Job;
use wise\agent\agent\AgentManager;
use wise\agent\execution\ExecutionTracker;
use wise\agent\task\DeadLetterNotifier;

/**
 * AI Agent 异步队列任务
 *
 * 通过 think-queue 处理异步 Agent 执行。
 * 接收任务数据（session_id、agent_type、provider、task、options、tracking_id）
 * 并执行 AgentManager::run()。
 *
 * 改进点（2026-07-27）:
 *   - 超过最大重试次数时写入 ai_agent_logs 失败记录（死信记录），不再静默删除
 *   - 集成 ExecutionTracker 统一状态管理
 */
class AiAgentJob
{
    /**
     * 执行任务（由 think-queue worker 调用）
     *
     * @param Job   $job  队列任务实例
     * @param array $data 任务负载数据
     */
    public function fire(Job $job, array $data): void
    {
        $sessionId  = $data['session_id'] ?? '';
        $trackingId = $data['tracking_id'] ?? '';
        $task       = $data['task'] ?? '';

        try {
            $agentType  = $data['agent_type'] ?? 'simple';
            $provider   = $data['provider'] ?? null;
            $options    = $data['options'] ?? [];
            $toolNames  = $data['tool_names'] ?? [];

            if (empty($sessionId) || empty($task)) {
                ContextualLogger::error('[WiseAgent Async] Invalid job data: missing session_id or task');

                if ($trackingId) {
                    ExecutionTracker::finish($trackingId, null, 'Invalid job data: missing session_id or task', []);
                }

                $job->delete();
                return;
            }

            if ($trackingId) {
                ExecutionTracker::update($trackingId, ExecutionTracker::STATUS_RUNNING, []);
            }

            $manager = app()->make(AgentManager::class);

            $agent = $manager->create($agentType, $options);

            if ($provider) {
                $agent->setProviderName($provider);
            }

            if (!empty($toolNames)) {
                $agent->withTools($toolNames);
            }

            $reflector = new \ReflectionClass($agent);
            $sessionProp = $reflector->getProperty('sessionId');
            $sessionProp->setAccessible(true);
            $sessionProp->setValue($agent, $sessionId);

            $result = $manager->run($agent, $task, $options);

            ContextualLogger::info("[WiseAgent Async] Job completed: {$sessionId}", [
                'result_length' => strlen($result),
            ]);

            $job->delete();
        } catch (\Throwable $e) {
            ContextualLogger::error("[WiseAgent Async] Job failed: {$e->getMessage()}", [
                'session_id' => $sessionId ?: 'unknown',
                'trace'      => $e->getTraceAsString(),
            ]);

            if ($job->attempts() > 3) {
                if ($trackingId) {
                    ExecutionTracker::finish(
                        $trackingId,
                        null,
                        "Max retries exceeded (3): {$e->getMessage()}",
                        []
                    );
                } elseif ($sessionId) {
                    ExecutionTracker::start('agent', [
                        'session_id' => $sessionId,
                        'task'       => $task,
                        'user_id'    => (int) ($data['user_id'] ?? 0),
                        'user_type'  => (string) ($data['user_type'] ?? 'admin'),
                    ], ExecutionTracker::STATUS_FAILED);
                }

                DeadLetterNotifier::notify([
                    'tracking_id' => $trackingId,
                    'session_id'  => $sessionId,
                    'user_id'     => (int) ($data['user_id'] ?? 0),
                    'user_type'   => (string) ($data['user_type'] ?? 'admin'),
                    'job_type'    => 'agent',
                    'attempts'    => $job->attempts(),
                    'last_error'  => $e->getMessage(),
                    'reason'      => "Max retries exceeded (3): {$e->getMessage()}",
                ]);

                ContextualLogger::warning("[WiseAgent Async] Max retries exceeded for session {$sessionId}, recorded as failed");
                $job->delete();
                return;
            }

            $job->release(10);
        }
    }
}
