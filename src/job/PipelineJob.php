<?php
declare(strict_types=1);

namespace wise\agent\job;

use wise\support\context\ContextualLogger;
use think\queue\Job;
use wise\agent\execution\ExecutionTracker;
use wise\agent\task\DeadLetterNotifier;

/**
 * 流水线异步队列任务
 *
 * 通过 think-queue 异步执行 Pipeline。
 *
 * 改进点（2026-07-27）:
 *   - 超过最大重试次数时写入失败记录（死信记录），不再静默删除
 *   - 集成 ExecutionTracker 统一状态管理
 */
class PipelineJob
{
    public function fire(Job $job, array $data): void
    {
        $pipelineName = $data['pipeline'] ?? '';
        $jobId        = $data['job_id'] ?? '';
        $trackingId   = $data['tracking_id'] ?? '';

        try {
            $input        = $data['input'] ?? [];
            $existingRunId = (int) ($data['run_id'] ?? 0);

            if (empty($pipelineName)) {
                ContextualLogger::error('[Pipeline Async] Invalid job data: missing pipeline name');

                if ($trackingId) {
                    ExecutionTracker::finish($trackingId, null, 'Invalid job data: missing pipeline name', []);
                }

                $job->delete();
                return;
            }

            if ($trackingId) {
                ExecutionTracker::update($trackingId, ExecutionTracker::STATUS_RUNNING, []);
            }

            $engine = app()->make(\wise\agent\pipeline\PipelineEngine::class);

            $result = $engine->run($pipelineName, $input, null, $existingRunId);

            if ($result->isCompleted()) {
                ContextualLogger::info("[Pipeline Async] Job completed: {$pipelineName} [{$jobId}]", [
                    'steps'    => count($result->getStepResults()),
                    'duration' => $result->getDuration(),
                ]);

                if ($trackingId) {
                    ExecutionTracker::finish(
                        $trackingId,
                        json_encode($result->getOutput(), JSON_UNESCAPED_UNICODE),
                        null,
                        [
                            'steps'    => count($result->getStepResults()),
                            'duration' => $result->getDuration(),
                        ]
                    );
                }
            } else {
                ContextualLogger::warning("[Pipeline Async] Job failed: {$pipelineName} [{$jobId}]", [
                    'error' => $result->getError(),
                ]);

                if ($trackingId) {
                    ExecutionTracker::finish(
                        $trackingId,
                        null,
                        $result->getError() ?? 'Pipeline execution failed',
                        [
                            'steps'    => count($result->getStepResults()),
                            'duration' => $result->getDuration(),
                        ]
                    );
                }
            }

            $job->delete();
        } catch (\Throwable $e) {
            ContextualLogger::error("[Pipeline Async] Job exception: {$e->getMessage()}", [
                'pipeline' => $pipelineName ?: 'unknown',
                'trace'    => $e->getTraceAsString(),
            ]);

            if ($job->attempts() > 3) {
                if ($trackingId) {
                    ExecutionTracker::finish(
                        $trackingId,
                        null,
                        "Max retries exceeded (3): {$e->getMessage()}",
                        []
                    );
                } elseif ($pipelineName) {
                    try {
                        $table = 'ai_pipeline_runs';
                        \think\facade\Db::name($table)->insert([
                            'pipeline_name' => $pipelineName,
                            'input'         => json_encode($data['input'] ?? [], JSON_UNESCAPED_UNICODE),
                            'status'        => 'failed',
                            'error'         => "Max retries exceeded (3): {$e->getMessage()}",
                            'source'        => 'queue',
                            'create_time'   => date('Y-m-d H:i:s'),
                            'update_time'   => date('Y-m-d H:i:s'),
                        ]);
                    } catch (\Throwable $dbException) {
                        ContextualLogger::warning("[Pipeline Async] Failed to record dead letter: {$dbException->getMessage()}");
                    }
                }

                DeadLetterNotifier::notify([
                    'tracking_id' => $trackingId,
                    'session_id'  => (string) ($data['session_id'] ?? ''),
                    'user_id'     => (int) ($data['user_id'] ?? 0),
                    'user_type'   => (string) ($data['user_type'] ?? 'admin'),
                    'job_type'    => 'pipeline',
                    'attempts'    => $job->attempts(),
                    'last_error'  => $e->getMessage(),
                    'reason'      => "Max retries exceeded (3): {$e->getMessage()}",
                ]);

                ContextualLogger::warning("[Pipeline Async] Max retries exceeded for pipeline {$pipelineName}, recorded as failed");
                $job->delete();
                return;
            }

            $job->release(10);
        }
    }
}
