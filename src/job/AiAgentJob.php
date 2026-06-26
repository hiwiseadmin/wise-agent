<?php
declare(strict_types=1);

namespace wise\agent\job;

use think\facade\Log;
use think\queue\Job;
use wise\agent\agent\AgentManager;

/**
 * AI Agent 异步队列任务
 *
 * 通过 think-queue 处理异步 Agent 执行。
 * 接收任务数据（session_id、agent_type、provider、task、options）
 * 并执行 AgentManager::run()。
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
        try {
            $sessionId  = $data['session_id'] ?? '';
            $agentType  = $data['agent_type'] ?? 'simple';
            $provider   = $data['provider'] ?? null;
            $task       = $data['task'] ?? '';
            $options    = $data['options'] ?? [];
            $toolNames  = $data['tool_names'] ?? [];

            if (empty($sessionId) || empty($task)) {
                Log::error('[WiseAgent Async] Invalid job data: missing session_id or task');
                $job->delete();
                return;
            }

            /** @var AgentManager $manager */
            $manager = app()->make(AgentManager::class);

            // 从持久化数据重建 Agent
            $agent = $manager->create($agentType, $options);

            if ($provider) {
                $agent->setProviderName($provider);
            }

            if (!empty($toolNames)) {
                $agent->withTools($toolNames);
            }

            // 覆盖会话 ID 以匹配原始值
            $reflector = new \ReflectionClass($agent);
            $sessionProp = $reflector->getProperty('sessionId');
            $sessionProp->setAccessible(true);
            $sessionProp->setValue($agent, $sessionId);

            $result = $manager->run($agent, $task, $options);

            Log::info("[WiseAgent Async] Job completed: {$sessionId}", [
                'result_length' => strlen($result),
            ]);

            $job->delete();
        } catch (\Throwable $e) {
            Log::error("[WiseAgent Async] Job failed: {$e->getMessage()}", [
                'session_id' => $data['session_id'] ?? 'unknown',
                'trace'      => $e->getTraceAsString(),
            ]);

            // 如果任务尝试次数过多，则删除
            if ($job->attempts() > 3) {
                $job->delete();
                return;
            }

            // 否则释放任务以便重试（延迟 10 秒）
            $job->release(10);
        }
    }
}
