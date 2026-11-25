<?php
declare(strict_types=1);

namespace wise\agent\workflow;

use wise\support\context\ContextualLogger;

/**
 * AI 能力审批工作流定义（wise-workflow 集成，可选）
 *
 * 状态机：pending → approved / rejected / cancelled
 *
 * 用途：为每张审批单建立 `ai_approval:{id}` 实例，借助 wise-workflow 记录
 * 状态流转历史（`Workflow::getHistory()`），便于审计与后续扩展多级/会签审批。
 *
 * ⚠️ 本类**不继承** `wise\workflow\Workflow`，也不在属性/签名中引用其类型——
 * 这样在未安装 wise-workflow 的环境下类文件仍可正常加载（审批单表自身即状态机，
 * 功能不受影响）。
 */
class AiApprovalWorkflow
{
    /** 工作流名称 */
    public const NAME = 'ai_approval';

    public const PLACE_PENDING   = 'pending';
    public const PLACE_APPROVED  = 'approved';
    public const PLACE_REJECTED  = 'rejected';
    public const PLACE_CANCELLED = 'cancelled';

    /** 转换名：通过（会签任务也绑定在此转换上） */
    public const TRANSITION_APPROVE = 'approve';

    /** 转换名：驳回 */
    public const TRANSITION_REJECT = 'reject';

    /** 转换名：撤回 */
    public const TRANSITION_CANCEL = 'cancel';

    /**
     * wise-workflow 是否可用
     */
    public static function available(): bool
    {
        return class_exists(\wise\workflow\Workflow::class)
            && class_exists(\wise\workflow\facade\Workflow::class);
    }

    /**
     * 构造工作流定义
     *
     * @return object|null wise\workflow\Workflow 实例；wise-workflow 缺失时返回 null
     */
    public static function create(): ?object
    {
        if (!self::available()) {
            return null;
        }

        $workflow = new \wise\workflow\Workflow(self::NAME, [], [], self::PLACE_PENDING);
        $workflow->setMetadata([
            'title'   => 'AI 能力审批',
            'version' => 1,
        ]);

        $workflow->addPlace(new \wise\workflow\model\Place(self::PLACE_PENDING));
        $workflow->addPlace(new \wise\workflow\model\Place(self::PLACE_APPROVED));
        $workflow->addPlace(new \wise\workflow\model\Place(self::PLACE_REJECTED));
        $workflow->addPlace(new \wise\workflow\model\Place(self::PLACE_CANCELLED));

        $workflow->addTransition(new \wise\workflow\model\Transition(self::TRANSITION_APPROVE, self::PLACE_PENDING, self::PLACE_APPROVED));
        $workflow->addTransition(new \wise\workflow\model\Transition(self::TRANSITION_REJECT, self::PLACE_PENDING, self::PLACE_REJECTED));
        $workflow->addTransition(new \wise\workflow\model\Transition(self::TRANSITION_CANCEL, self::PLACE_PENDING, self::PLACE_CANCELLED));

        return $workflow;
    }

    /**
     * 工作流实例标识（约定 "{workflow_name}:{id}"）
     */
    public static function subject(int $approvalId): string
    {
        return self::NAME . ':' . $approvalId;
    }

    /**
     * 注册到 WorkflowRegistry（供 wise-workflow 后台列表展示）
     */
    public static function register(): void
    {
        if (!self::available() || !class_exists(\wise\workflow\WorkflowRegistry::class)) {
            return;
        }

        try {
            $workflow = self::create();

            if ($workflow !== null) {
                \wise\workflow\WorkflowRegistry::add($workflow);
            }
        } catch (\Throwable $e) {
            ContextualLogger::channel('ai')->warning('[Approval] workflow register failed: ' . $e->getMessage());
        }
    }
}
