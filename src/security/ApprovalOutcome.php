<?php
declare(strict_types=1);

namespace wise\agent\security;

/**
 * 审批闸门判定结果
 *
 * 由 `ApprovalManager::gate()` 产出，描述「本次调用是否可以继续」以及「不可以时要怎么处理」。
 */
final class ApprovalOutcome
{
    /** 无需审批，可直接执行 */
    public const NOT_REQUIRED = 'not_required';

    /** 命中可用审批单并已消费，本次放行 */
    public const APPROVED = 'approved';

    /** 已提交审批单，等待人工审批（本次阻断） */
    public const PENDING = 'pending';

    /** 存在被驳回的审批单（本次阻断） */
    public const REJECTED = 'rejected';

    /**
     * @param string $status     判定状态（见类常量）
     * @param int    $approvalId 审批单 ID（pending/rejected 时返回）
     * @param string $ident      审批单号
     * @param string $mode       审批模式：confirm | workflow
     * @param string $rule       命中的审批规则
     * @param string $reason     人类可读说明
     */
    public function __construct(
        public readonly string $status,
        public readonly int $approvalId = 0,
        public readonly string $ident = '',
        public readonly string $mode = '',
        public readonly string $rule = '',
        public readonly string $reason = ''
    ) {
    }

    public static function notRequired(): self
    {
        return new self(self::NOT_REQUIRED);
    }

    public static function approved(int $approvalId, string $ident, string $mode, string $rule): self
    {
        return new self(
            self::APPROVED,
            $approvalId,
            $ident,
            $mode,
            $rule,
            "已消费审批单 {$ident}，本次调用放行"
        );
    }

    public static function pending(int $approvalId, string $ident, string $mode, string $rule, string $reason): self
    {
        return new self(self::PENDING, $approvalId, $ident, $mode, $rule, $reason);
    }

    public static function rejected(int $approvalId, string $ident, string $mode, string $rule, string $reason): self
    {
        return new self(self::REJECTED, $approvalId, $ident, $mode, $rule, $reason);
    }

    /**
     * 是否允许继续执行
     */
    public function allowed(): bool
    {
        return in_array($this->status, [self::NOT_REQUIRED, self::APPROVED], true);
    }

    /**
     * 是否需要人工介入（pending / rejected 都会被阻断）
     */
    public function blocked(): bool
    {
        return !$this->allowed();
    }

    /**
     * 供前端 / AI / 审计消费的结构化信息
     */
    public function toArray(): array
    {
        return [
            'status'      => $this->status,
            'approval_id' => $this->approvalId,
            'ident'       => $this->ident,
            'mode'        => $this->mode,
            'rule'        => $this->rule,
            'reason'      => $this->reason,
        ];
    }
}
