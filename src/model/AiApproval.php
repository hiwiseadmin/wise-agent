<?php
declare(strict_types=1);

namespace wise\agent\model;

use think\Model;
use think\model\concern\SoftDelete;

/**
 * AI 能力审批单模型
 *
 * 表：ws_ai_approvals（见 database/migrations/20251002000012_create_ai_approvals_table.php）
 *
 * @property int         $id
 * @property string      $ident             审批单号
 * @property string      $capability_kind   tool | skill
 * @property string      $capability_name
 * @property string      $permission
 * @property string      $access
 * @property string      $risk
 * @property string      $mode              confirm | workflow
 * @property string      $rule
 * @property string      $reason
 * @property string|null $arguments         JSON
 * @property string      $arguments_hash
 * @property int         $requester_id
 * @property string      $requester_type
 * @property string      $session_id
 * @property string      $status            pending | approved | rejected | consumed | cancelled | expired
 * @property int         $uses_total
 * @property int         $uses_left
 * @property int         $decider_id
 * @property string      $decider_type
 * @property string      $decision_note
 * @property string|null $decided_at
 * @property string|null $expire_time
 * @property string      $workflow_subject
 */
class AiApproval extends Model
{
    use SoftDelete;

    /** 状态：待审批 */
    public const STATUS_PENDING = 'pending';

    /** 状态：已批准（未用尽，可消费） */
    public const STATUS_APPROVED = 'approved';

    /** 状态：已驳回 */
    public const STATUS_REJECTED = 'rejected';

    /** 状态：已用尽 */
    public const STATUS_CONSUMED = 'consumed';

    /** 状态：已撤回 */
    public const STATUS_CANCELLED = 'cancelled';

    /** 状态：已失效 */
    public const STATUS_EXPIRED = 'expired';

    /** 终态（不可再变更） */
    public const FINAL_STATUSES = [
        self::STATUS_REJECTED,
        self::STATUS_CONSUMED,
        self::STATUS_CANCELLED,
        self::STATUS_EXPIRED,
    ];

    protected $name = 'ai_approvals';

    protected $autoWriteTimestamp = true;

    protected $createTime = 'create_time';

    protected $updateTime = 'update_time';

    protected $deleteTime = 'delete_time';

    protected $type = [
        'uses_total'   => 'integer',
        'uses_left'    => 'integer',
        'requester_id' => 'integer',
        'decider_id'   => 'integer',
    ];

    /**
     * 状态 → 中文标签
     */
    public static function statusLabels(): array
    {
        return [
            self::STATUS_PENDING   => '待审批',
            self::STATUS_APPROVED  => '已批准',
            self::STATUS_REJECTED  => '已驳回',
            self::STATUS_CONSUMED  => '已用尽',
            self::STATUS_CANCELLED => '已撤回',
            self::STATUS_EXPIRED   => '已失效',
        ];
    }

    public static function statusLabel(string $status): string
    {
        return self::statusLabels()[$status] ?? $status;
    }

    /**
     * 是否为终态
     */
    public function isFinal(): bool
    {
        return in_array((string) $this->getAttr('status'), self::FINAL_STATUSES, true);
    }
}
