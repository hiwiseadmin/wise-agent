<?php
declare(strict_types=1);

namespace wise\agent\job;

use think\queue\Job;
use wise\support\context\ContextualLogger;
use wise\agent\execution\ExecutionTracker;
use wise\agent\plan\PlanExecutor;
use wise\agent\plan\store\DbPlanRunStore;
use wise\agent\task\DeadLetterNotifier;

/**
 * 计划异步执行 Job＼�
 *
 * 与 `AiAgentJob`（`src/job/AiAgentJob.php`）/ `PipelineJob`（`src/job/PipelineJob.php`）
 * **同一套重试与死信语义**：
 *
 * - 业务失败先 `release(10)` 重试；
 * - `attempts() > 3` ⇒ **不再静默 `delete()`**，而是把运行标记为失败
 *   （`ExecutionTracker::finish` + `ai_plan_runs.status=aborted`）后 `delete()`；
 * - 派发方式：`Queue::push(PlanJob::class, ['run_id' => .., 'tracking_id' => ..])`。
 *
 * 计划与「对话轮次」解耦：一份含审批等待的计划可能挂很久，因此默认异步
 * （`plan.default_async`）。
 */
class PlanJob
{
    /** 最大重试次数（与 AiAgentJob 保持一致，便于运维统一口径） */
    public const MAX_ATTEMPTS = 3;

    public function fire(Job $job, array $data): void
    {
        $runId      = (int) ($data['run_id'] ?? 0);
        $trackingId = (string) ($data['tracking_id'] ?? '');

        if ($runId <= 0) {
            ContextualLogger::error('[Plan Async] Missing run_id, job discarded');

            if ($trackingId !== '') {
                ExecutionTracker::finish($trackingId, null, 'Missing run_id', []);
            }

            $job->delete();

            return;
        }

        try {
            if ($trackingId !== '') {
                ExecutionTracker::update($trackingId, ExecutionTracker::STATUS_RUNNING, []);
            }

            app(PlanExecutor::class)->resume($runId);

            $job->delete();
        } catch (\Throwable $e) {
            ContextualLogger::error('[Plan Async] ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);

            if ($job->attempts() > self::MAX_ATTEMPTS) {
                $this->markDeadLetter($runId, $trackingId, $e);

                $this->notifyDeadLetter($runId, $trackingId, $e, $job->attempts());

                $job->delete();

                return;
            }

            $job->release(10);
        }
    }

    /**
     * 重试超限：把运行与追踪都标记为失败
     */
    protected function markDeadLetter(int $runId, string $trackingId, \Throwable $e): void
    {
        $reason = sprintf('Max retries exceeded (%d): %s', self::MAX_ATTEMPTS, $e->getMessage());

        try {
            if ($trackingId !== '') {
                ExecutionTracker::finish($trackingId, null, $reason, []);
            }
        } catch (\Throwable) {
        }

        try {
            (new DbPlanRunStore())->updateRun($runId, [
                'status' => \wise\agent\plan\PlanRun::STATUS_ABORTED,
                'error'  => $reason,
            ]);
        } catch (\Throwable $inner) {
            ContextualLogger::error('[Plan Async] Failed to mark dead letter: ' . $inner->getMessage());
        }
    }

    /**
     * C1-2：死信通知（发起人身份从计划运行表取；查不到时 actorId=0 ⇒ 通知 skipped，落库仍在）
     */
    protected function notifyDeadLetter(int $runId, string $trackingId, \Throwable $e, int $attempts): void
    {
        $userId    = 0;
        $userType  = 'admin';
        $sessionId = '';

        try {
            $run = (new DbPlanRunStore())->findRun($runId);
            if ($run !== null) {
                $userId    = $run->userId;
                $userType  = $run->userType;
                $sessionId = $run->sessionId;
            }
        } catch (\Throwable) {
        }

        DeadLetterNotifier::notify([
            'tracking_id' => $trackingId,
            'session_id'  => $sessionId,
            'user_id'     => $userId,
            'user_type'   => $userType,
            'job_type'    => 'plan',
            'attempts'    => $attempts,
            'last_error'  => $e->getMessage(),
            'reason'      => sprintf('Max retries exceeded (%d): %s', self::MAX_ATTEMPTS, $e->getMessage()),
        ]);
    }
}
