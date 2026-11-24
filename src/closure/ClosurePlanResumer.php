<?php
declare(strict_types=1);

namespace wise\agent\closure;

use wise\agent\closure\store\ClosurePlanLinkInterface;
use wise\agent\closure\store\DbClosurePlanLink;
use wise\agent\plan\PlanExecutionResult;
use wise\agent\plan\PlanExecutor;
use wise\agent\plan\PlanRun;
use wise\support\context\ContextualLogger;

/**
 * 「批准后自动续跑计划」＼�**默认关闭**）
 *
 * ## 它解决什么
 *
 * `ApprovalManager` 的放行语义是「批准 → 申请人**重新发起**同一调用 → 原子消费审批单」
 * （`ApprovalManager.php:19-24`）。对 B1 的计划而言，这意味着用户批准后还要再喊一声 `plan_resume`。
 * 本类把这个"再喊一声"自动化 —— 但它**不是绕过审批**：
 * 续跑仍走 `PlanExecutor::resume()`（与人工 `plan_resume` **同一条路径**），
 * 写操作重发时仍会被 `gate()` 匹配并**原子消费一次**审批单。
 *
 * ## 五道判定（缺一不执行，且每条都给可读原因）
 *
 * | # | 条件 | 不满足时 |
 * |---|---|---|
 * | 1 | `closure.auto_resume_plans=true` | 不处理（默认关） |
 * | 2 | 事件是 `approval_decided` 且结论为 `approved` | 不处理 |
 * | 3 | `approval_id` 能定位到「仍在等这一步」的计划运行 | 视为**非计划类审批**（不打扰用户） |
 * | 4 | 目标运行状态是 `paused` | 给原因（不续跑） |
 * | 5 | 运行的发起人 = 审批单申请人 | 给原因（换人续跑会"批了却消费不到"） |
 *
 * ## ⚠️ 如实登记：**没有**做「参数指纹比对」（原设计有，实测后改为替代方案）
 *
 * `ApprovalManager::hashArguments()` 是 **protected**（`ApprovalManager.php:638`），
 * 闭包域拿不到它；**自己复算等于复制算法**（编码标志/键序稍有出入就会误判），必然漂移。
 *
 * 替代方案（同样的安全性，且失败模式更安全）：
 *
 * 1. 只续跑**被阻塞那一步所属的运行**（不是"任意关联的运行"）；
 * 2. 续跑参数直接来自 `ai_plan_steps.arguments`（**B1-3 保证与阻塞时字节一致**）；
 * 3. 若要求的步骤没有持久化参数 ⇒ **拒绝自动续跑**（给原因）；
 * 4. **最终裁决交给 `gate()`**：指纹若真的不符，写操作会被再次拦下（最坏结果是"没续成"，
 *    绝不是"绕过审批"）。
 */
class ClosurePlanResumer
{
    use ClosureOptions;

    private ?ClosurePlanLinkInterface $link = null;

    private ?PlanExecutor $executor = null;

    /**
     * @param array<string, mixed> $options `closure.*`（含 `plan.*` 覆盖项，供执行器使用）
     */
    public function __construct(
        array $options = [],
        ?ClosurePlanLinkInterface $link = null,
        ?PlanExecutor $executor = null
    ) {
        $this->setClosureOptions($options);
        $this->link     = $link;
        $this->executor = $executor;
    }

    /**
     * 尝试自动续跑（**绝不抛异常**：它挂在审批决策路径上）
     *
     * @return array{handled:bool, resumed:bool, reason:string, run_id:int}
     */
    public function maybeResume(ClosureEvent $event): array
    {
        try {
            return $this->doMaybeResume($event);
        } catch (\Throwable $e) {
            $this->log('warning', '[Closure] 自动续跑异常（已兜住，不影响审批）：' . $e->getMessage());

            return [
                'handled'  => true,
                'resumed'  => false,
                'reason'   => '自动续跑异常（已兜住）：' . $e->getMessage(),
                'run_id'   => 0,
            ];
        }
    }

    /**
     * @return array{handled:bool, resumed:bool, reason:string, run_id:int}
     */
    private function doMaybeResume(ClosureEvent $event): array
    {
        if (!$this->closureBool('auto_resume_plans', false)) {
            return $this->result(false, false, 'closure.auto_resume_plans=false（默认关：批准后不自动执行，请人工 plan_resume）');
        }

        if ($event->scene !== ClosureEvent::SCENE_APPROVAL_DECIDED
            || $event->result !== ClosureEvent::RESULT_APPROVED
        ) {
            return $this->result(false, false, '只有「审批通过」事件才触发自动续跑');
        }

        if ($event->approvalId <= 0) {
            return $this->result(true, false, '事件没有 approval_id，无法定位计划步骤');
        }

        $link = $this->link()->findPausedRunByApproval($event->approvalId);

        if ($link === null) {
            return $this->result(false, false, '该审批单未关联任何计划步骤（B2 只自动续跑由计划产生的审批）');
        }

        $runId = (int) $link['run_id'];

        if ((string) $link['status'] !== PlanRun::STATUS_PAUSED) {
            return $this->result(
                true,
                false,
                sprintf('计划运行 #%d 的状态是 %s（不是 paused），不自动续跑', $runId, (string) $link['status']),
                $runId
            );
        }

        if ((int) ($link['user_id'] ?? 0) !== $event->actorId) {
            return $this->result(
                true,
                false,
                sprintf(
                    '计划 #%d 由用户 #%d 发起，而审批单申请人是 #%d：不同人 ⇒ 不自动续跑（审批单按申请人匹配，换人续跑消费不到）',
                    $runId,
                    (int) ($link['user_id'] ?? 0),
                    $event->actorId
                ),
                $runId
            );
        }

        if (($link['arguments'] ?? []) === []) {
            return $this->result(
                true,
                false,
                sprintf('计划 #%d 的阻塞步骤（%s）没有持久化参数 ⇒ 不敢自动重发（重发参数漂移会导致审批单消费不到）', $runId, (string) ($link['step_id'] ?? '')),
                $runId
            );
        }

        $outcome = $this->executor()->resume($runId, [
            'user_id'   => $event->actorId,
            'user_type' => $event->actorType,
            'source'    => 'closure_auto_resume',
        ]);

        $reason = $outcome instanceof PlanExecutionResult
            ? sprintf('自动续跑 %s：%s', $outcome->status, $outcome->summary())
            : '自动续跑已发起';

        $this->log($outcome instanceof PlanExecutionResult && $outcome->isCompleted() ? 'info' : 'warning', '[Closure] ' . $reason);

        return $this->result(true, $outcome instanceof PlanExecutionResult && $outcome->isCompleted(), $reason, $runId);
    }


    protected function link(): ClosurePlanLinkInterface
    {
        return $this->link ??= new DbClosurePlanLink($this->closureOptions());
    }

    protected function executor(): PlanExecutor
    {
        return $this->executor ??= new PlanExecutor($this->closureOptions());
    }

    /**
     * @return array{handled:bool, resumed:bool, reason:string, run_id:int}
     */
    private function result(bool $handled, bool $resumed, string $reason, int $runId = 0): array
    {
        return ['handled' => $handled, 'resumed' => $resumed, 'reason' => $reason, 'run_id' => $runId];
    }

    private function log(string $level, string $message): void
    {
        try {
            ContextualLogger::channel('ai')->{$level}($message);
        } catch (\Throwable) {
        }
    }
}
