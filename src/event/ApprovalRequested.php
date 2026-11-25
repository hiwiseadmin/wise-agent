<?php
declare(strict_types=1);

namespace wise\agent\event;

/**
 * 审批单已创建事件
 *
 * 当 AI 能力调用因审批策略被阻断并生成审批单时触发。
 * 业务方 / 插件可监听它以接入自己的通知（邮件、IM、工单系统等）。
 *
 * @property int    $approvalId     审批单 ID
 * @property string $ident          审批单号
 * @property string $capabilityKind tool | skill
 * @property string $capabilityName 能力名称
 * @property string $permission     权限标识
 * @property string $access         访问类型
 * @property string $risk           风险等级
 * @property string $mode           审批模式 confirm | workflow
 * @property string $rule           命中的审批规则
 * @property string $reason         需要审批的说明
 * @property int    $requesterId    申请人用户 ID
 * @property string $requesterType  申请人用户类型
 */
class ApprovalRequested extends Event
{
    public function __construct(
        public readonly int $approvalId,
        public readonly string $ident,
        public readonly string $capabilityKind,
        public readonly string $capabilityName,
        public readonly string $permission,
        public readonly string $access,
        public readonly string $risk,
        public readonly string $mode,
        public readonly string $rule,
        public readonly string $reason,
        public readonly int $requesterId = 0,
        public readonly string $requesterType = 'admin'
    ) {
        parent::__construct();
    }

    public function getEventName(): string
    {
        return 'ApprovalRequested';
    }
}
