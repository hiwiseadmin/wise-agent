<?php
declare(strict_types=1);

namespace wise\agent\workflow;

use wise\support\context\ContextualLogger;

/**
 * AI 审批 —— wise-workflow 桥接层
 *
 * 把「审批单」与 wise-workflow 引擎的全部交互收敛到本类，好处：
 *   1. `ApprovalManager` 不直接依赖 wise-workflow 的类型，未安装时零影响；
 *   2. 所有调用都包在 try/catch 内，工作流出现异常时审批单表自身仍是可靠状态机（降级）；
 *   3. 会签（多审批人）等进阶能力只在此处开启，业务主流程保持简单。
 *
 * 能力：
 *   - `start()`    为审批单建立 `ai_approval:{id}` 实例；workflow 模式下按配置创建会签任务
 *   - `decide()`   推进流转（含会签投票：票数满足策略后才流转）
 *   - `history()`  流转历史（供审批详情页展示）
 *
 * 工作流形态：
 *   pending ──approve──▶ approved
 *      ├──reject──▶ rejected
 *      └──cancel──▶ cancelled
 */
class ApprovalWorkflowBridge
{
    /** 会签任务绑定的转换名（与工作流定义同源） */
    public const TRANSITION_APPROVE = AiApprovalWorkflow::TRANSITION_APPROVE;

    /**
     * wise-workflow 是否可用
     *
     * 需同时满足：包已安装 + 配置 `security.approval.workflow.enabled` 为真。
     */
    public function available(): bool
    {
        if (!(bool) config('wise-agent.security.approval.workflow.enabled', true)) {
            return false;
        }

        return AiApprovalWorkflow::available();
    }

    /**
     * 建立工作流实例（可选创建会签任务）
     *
     * @param int    $approvalId 审批单 ID
     * @param string $permission 能力权限标识（写入流转上下文，便于审计）
     * @param string $mode       审批模式 confirm | workflow
     * @return string 工作流实例标识（失败返回空串）
     */
    public function start(int $approvalId, string $permission, string $mode): string
    {
        if (!$this->available()) {
            return '';
        }

        $subject = AiApprovalWorkflow::subject($approvalId);

        try {
            $workflow = AiApprovalWorkflow::create();

            if ($workflow === null) {
                return '';
            }

            \wise\workflow\facade\Workflow::init($workflow, $subject, [
                'capability' => $permission,
                'approval'   => $approvalId,
            ]);

            $approvers = $this->approvers();

            if ($mode === 'workflow' && $approvers !== []) {
                $this->tasks()->createTasks(
                    $workflow,
                    $subject,
                    self::TRANSITION_APPROVE,
                    $approvers,
                    $this->policy(),
                    null,
                    ['approval' => $approvalId, 'capability' => $permission]
                );
            }

            return $subject;
        } catch (\Throwable $e) {
            ContextualLogger::channel('ai')->warning('[Approval] workflow start failed: ' . $e->getMessage());

            return '';
        }
    }

    /**
     * 推进流转
     *
     * @param string $subject  工作流实例标识
     * @param string $decision approved | rejected | cancelled
     * @param int    $operator 操作人用户 ID
     * @param string $note     备注
     * @return array{handled:bool, completed:bool, pending:int, message:string}
     *         handled   = 是否交由工作流处理（false 表示无实例，由调用方自行判定）
     *         completed = 是否已真正完成流转（会签未满时为 false）
     *         pending   = 剩余待办票数
     */
    public function decide(string $subject, string $decision, int $operator, string $note = ''): array
    {
        if ($subject === '' || !$this->available()) {
            return ['handled' => false, 'completed' => false, 'pending' => 0, 'message' => ''];
        }

        try {
            $transition = match ($decision) {
                'approved'  => AiApprovalWorkflow::TRANSITION_APPROVE,
                'rejected'  => AiApprovalWorkflow::TRANSITION_REJECT,
                default     => AiApprovalWorkflow::TRANSITION_CANCEL,
            };

            if ($transition !== self::TRANSITION_APPROVE) {
                $this->tasks()->cancelTasks($subject, self::TRANSITION_APPROVE);
                $this->apply($subject, $transition);

                return ['handled' => true, 'completed' => true, 'pending' => 0, 'message' => ''];
            }

            $pendingTasks = $this->pendingTasks($subject);

            if ($pendingTasks !== []) {
                return $this->vote($subject, $pendingTasks, $operator, $note);
            }

            $this->apply($subject, $transition);

            return ['handled' => true, 'completed' => true, 'pending' => 0, 'message' => ''];
        } catch (\Throwable $e) {
            ContextualLogger::channel('ai')->warning('[Approval] workflow decide failed: ' . $e->getMessage());

            return ['handled' => false, 'completed' => false, 'pending' => 0, 'message' => ''];
        }
    }

    /**
     * 会签投票
     *
     * @param array<int, array> $pendingTasks
     * @return array{handled:bool, completed:bool, pending:int, message:string}
     */
    protected function vote(string $subject, array $pendingTasks, int $operator, string $note): array
    {
        $mine = null;

        foreach ($pendingTasks as $task) {
            if ((int) ($task['assignee'] ?? 0) === $operator) {
                $mine = $task;
                break;
            }
        }

        if ($mine === null) {
            return [
                'handled'   => true,
                'completed' => false,
                'pending'   => count($pendingTasks),
                'message'   => '当前用户不在该审批的审批人名单内',
            ];
        }

        $shouldTransition = $this->tasks()->completeTask(
            $subject,
            self::TRANSITION_APPROVE,
            (string) ($mine['task_key'] ?? ''),
            $operator,
            'approved',
            ['comment' => $note]
        );

        if ($shouldTransition) {
            $this->apply($subject, AiApprovalWorkflow::TRANSITION_APPROVE);
        }

        $remaining = count($this->pendingTasks($subject));

        return [
            'handled'   => true,
            'completed' => $shouldTransition,
            'pending'   => $remaining,
            'message'   => $shouldTransition ? '' : "已记录你的审批意见，还有 {$remaining} 位审批人待处理",
        ];
    }

    /**
     * 查询流转历史
     *
     * @return array<int, array>
     */
    public function history(string $subject, int $limit = 20): array
    {
        if ($subject === '' || !$this->available()) {
            return [];
        }

        try {
            return \wise\workflow\facade\Workflow::getHistory($subject, $limit);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * 剩余待办票数（用于审批中心展示）
     */
    public function pendingVotes(string $subject): int
    {
        return $subject === '' ? 0 : count($this->pendingTasks($subject));
    }


    /**
     * 待办任务列表（工作流不可用时返回空数组）
     *
     * @return array<int, array>
     */
    protected function pendingTasks(string $subject): array
    {
        try {
            return $this->tasks()->getPendingTasksForTransition($subject, self::TRANSITION_APPROVE);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * 执行状态转换（can 兜底，保证幂等）
     *
     * completeTask 在事件服务已注入时可能已自行触发流转，此处用 can() 判定，
     * 已流转则跳过，不会重复推进标记。
     */
    protected function apply(string $subject, string $transition): void
    {
        $workflow = AiApprovalWorkflow::create();

        if ($workflow === null) {
            return;
        }

        $facade = \wise\workflow\facade\Workflow::class;

        if ($facade::can($workflow, $subject, $transition)) {
            $facade::apply($workflow, $subject, $transition, ['by' => 'ai-approval']);
        }
    }

    /**
     * TaskManager 实例
     *
     * wise-workflow 缺失时由 app() 抛异常，调用方（start/decide/pendingTasks）
     * 均已包在 try/catch 内降级，不影响审批单主流程。
     */
    protected function tasks(): object
    {
        return app(\wise\workflow\task\TaskManager::class);
    }

    /**
     * 配置的审批人用户 ID 列表
     *
     * @return int[]
     */
    protected function approvers(): array
    {
        return array_values(array_filter(array_map(
            'intval',
            (array) config('wise-agent.security.approval.workflow.approvers', [])
        )));
    }

    /**
     * 会签策略：all（会签）/ any（或签）
     */
    protected function policy(): string
    {
        $policy = (string) config('wise-agent.security.approval.workflow.policy', 'any');

        return in_array($policy, ['all', 'any'], true) ? $policy : 'any';
    }
}
