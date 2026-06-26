<?php
declare(strict_types=1);

namespace wise\agent\job;

use think\facade\Log;
use think\queue\Job;
use wise\agent\Agent\AgentManager;

/**
 * AI Agent async queue job
 *
 * Handles asynchronous agent execution via think-queue.
 * Receives job data (session_id, agent_type, provider, task, options)
 * and executes AgentManager::run().
 */
class AiAgentJob
{
    /**
     * Fire the job (called by think-queue worker)
     *
     * @param Job   $job  Queue job instance
     * @param array $data Job payload data
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

            // Reconstruct agent from persisted data
            $agent = $manager->create($agentType, $options);

            if ($provider) {
                $agent->setProviderName($provider);
            }

            if (!empty($toolNames)) {
                $agent->withTools($toolNames);
            }

            // Override session ID to match original
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

            // If job has been attempted too many times, delete it
            if ($job->attempts() > 3) {
                $job->delete();
                return;
            }

            // Otherwise release for retry (10s delay)
            $job->release(10);
        }
    }
}
