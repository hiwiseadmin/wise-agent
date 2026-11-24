<?php
declare(strict_types=1);

namespace wise\agent\event;

/**
 * 审批单已决策事件
 *
 * 审批单被批准 / 驳回 / 撤回时触发（AI 能力审批闭环的出口）。
 *
 * @property int    $approvalId     审批单 ID
 * @property string $ident          审批单号
 * @property string $decision       approved | rejected | cancelled
 * @property string $capabilityName 能力名称
 * @property string $permission     权限标识
 * @property int    $requesterId    申请人用户 ID
 * @property int    $deciderId      决策人用户 ID
 * @property string $note           决策备注
 */
class ApprovalDecided extends Event
{
    public function __construct(
        public readonly int $approvalId,
        public readonly string $ident,
        public readonly string $decision,
        public readonly string $capabilityName,
        public readonly string $permission,
        public readonly int $requesterId = 0,
        public readonly int $deciderId = 0,
        public readonly string $note = ''
    ) {
        parent::__construct();
    }

    public function getEventName(): string
    {
        return 'ApprovalDecided';
    }
}
