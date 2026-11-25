<?php
declare(strict_types=1);

namespace wise\agent\closure;

/**
 * 闭环事件＼�
 *
 * **四类触发源归一后的唯一形状**：`ApprovalRequested` / `ApprovalDecided` /
 * `ToolExecute(AFTER)` / `AgentComplete` 都由 {@see ClosureEventFactory} 变成这个对象，
 * 于是连接器、路由、回放命令只需要认识**一种**数据结构。
 *
 * ## 两条不可动摇的约定
 *
 * 1. **拿不到就留空，不编造**：`ApprovalDecided` 事件本身不带 `access/risk`
 *    （只有 `permission`）—— 那就留空字符串，而不是猜一个 `low` 出来；
 *    路由侧对「未知风险」有明确处置（见 {@see ClosureRouter}）。
 * 2. **`payload` 是「已脱敏的摘要」**：连接器出站（通知/webhook/审计）前**不得**再假设它安全，
 *    必须各自再过一遍脱敏（B2-2/B2-3）。归一化阶段只做**长度截断**，不做脱敏
 *    （脱敏规则属于各连接器的配置域）。
 */
final class ClosureEvent implements \JsonSerializable
{
    public const SCENE_APPROVAL_REQUESTED = 'approval_requested';
    public const SCENE_APPROVAL_DECIDED   = 'approval_decided';
    public const SCENE_TOOL_EXECUTED      = 'tool_executed';
    public const SCENE_AGENT_COMPLETED    = 'agent_completed';
    public const SCENE_TASK_FAILED        = 'task_failed';

    /** @var string[] 全部场景（配置校验与测试用） */
    public const SCENES = [
        self::SCENE_APPROVAL_REQUESTED,
        self::SCENE_APPROVAL_DECIDED,
        self::SCENE_TOOL_EXECUTED,
        self::SCENE_AGENT_COMPLETED,
        self::SCENE_TASK_FAILED,
    ];

    /** 等待人工决策 */
    public const RESULT_PENDING = 'pending';

    /** 审批通过（**决策结论**，不等于"写操作执行成功"） */
    public const RESULT_APPROVED = 'approved';

    /** 成功（执行结论） */
    public const RESULT_SUCCESS = 'success';

    /** 失败（含工具返回非 0 契约码、执行异常、权限拒绝） */
    public const RESULT_FAILURE = 'failure';

    /** 被驳回（决策结论） */
    public const RESULT_REJECTED = 'rejected';

    /** 被撤回（决策结论） */
    public const RESULT_CANCELLED = 'cancelled';

    /** 无法判定（如工具输出不是同核契约）—— 刻意保留，**不映射成 success/failure** */
    public const RESULT_UNKNOWN = 'unknown';

    /** 仅预演（沙箱/灰度未放量：能力被降级为影响面预演，未真正执行）—— 与 success 严格区分 */
    public const RESULT_PREVIEWED = 'previewed';

    /**
     * 全部结果取值
     *
     * ⚠️ 刻意把「决策结论」（`approved` / `rejected` / `cancelled`）与「执行结论」
     * （`success` / `failure`）**分开**：否则「审批通过了」会被读成「写操作成功了」，
     * 回放时会得出错误结论。
     *
     * @var string[]
     */
    public const RESULTS = [
        self::RESULT_PENDING,
        self::RESULT_APPROVED,
        self::RESULT_SUCCESS,
        self::RESULT_FAILURE,
        self::RESULT_REJECTED,
        self::RESULT_CANCELLED,
        self::RESULT_UNKNOWN,
        self::RESULT_PREVIEWED,
    ];

    /**
     * 决策结论集合（用于判定"这个结果是不是一个决策"）
     *
     * @var string[]
     */
    public const DECISION_RESULTS = [
        self::RESULT_APPROVED,
        self::RESULT_REJECTED,
        self::RESULT_CANCELLED,
    ];

    /**
     * @param string $traceId        贯通键（回放与关联的主键，策略见 {@see ClosureEventFactory}）
     * @param string $scene          见 `SCENE_*`
     * @param string $capabilityKind 能力种类（tool / skill / plan / agent / ''）
     * @param string $capabilityName 能力名
     * @param string $access         访问类型（read/write/execute/admin）—— 取不到则空串
     * @param string $risk           风险等级（low/medium/high）—— 取不到则空串
     * @param bool   $write          是否写操作（驱动路由与审计口径，见 {@see ClosureRouter}）
     * @param string $result         见 `RESULT_*`
     * @param string $reason         人类可读原因（通知正文/审计内容用）
     * @param array  $payload        上下文摘要（**长度已截断**；出站前由连接器再脱敏）
     * @param int    $approvalId     关联审批单
     * @param int    $planRunId      关联计划运行（B1 的 `ai_plan_runs.run_id`）
     * @param string $sessionId      关联会话
     * @param string $trackingId     关联 `ai_agent_logs.tracking_id`
     * @param int    $actorId        发起人（**不是**决策人；决策人放 payload）
     * @param string $actorType      发起人类型
     * @param float  $timestamp      事件发生时间（`microtime(true)`）
     */
    public function __construct(
        public readonly string $traceId,
        public readonly string $scene,
        public readonly string $capabilityKind = '',
        public readonly string $capabilityName = '',
        public readonly string $access = '',
        public readonly string $risk = '',
        public readonly bool $write = false,
        public readonly string $result = self::RESULT_UNKNOWN,
        public readonly string $reason = '',
        public readonly array $payload = [],
        public readonly int $approvalId = 0,
        public readonly int $planRunId = 0,
        public readonly string $sessionId = '',
        public readonly string $trackingId = '',
        public readonly int $actorId = 0,
        public readonly string $actorType = 'admin',
        public readonly float $timestamp = 0.0,
    ) {
    }

    /**
     * `kind:name`（能力标识，日志与投递目标用）
     */
    public function capability(): string
    {
        if ($this->capabilityKind === '' && $this->capabilityName === '') {
            return '';
        }

        return $this->capabilityKind . ':' . $this->capabilityName;
    }

    public function isWrite(): bool
    {
        return $this->write;
    }

    /**
     * 是否成功结果（`success` 才算；`unknown` 刻意不算）
     */
    public function isSuccess(): bool
    {
        return $this->result === self::RESULT_SUCCESS;
    }

    public function occurredAt(): string
    {
        $ts = $this->timestamp > 0 ? $this->timestamp : microtime(true);

        return date('Y-m-d H:i:s', (int) $ts);
    }

    /**
     * 追加/覆盖载荷字段（返回新实例，保持不可变）
     *
     * B2-4 的用途：`ApprovalDecided` 的**自动续跑结论**要在通知/审计/webhook **之前**写进载荷，
     * 这样「批准了但没自动续跑（附原因）」能随同一条通知到达申请人（验收 8），
     * 而不是只落在日志里。
     *
     * ⚠️ 调用方负责传入**安全**的值（本方法不做脱敏，因为它无法区分"我们自己的原因文案"与"外部数据"）；
     * 各连接器出站前仍会再脱一遍（双保险）。
     *
     * @param array<string, mixed> $extra
     */
    public function withPayload(array $extra): self
    {
        return new self(
            traceId: $this->traceId,
            scene: $this->scene,
            capabilityKind: $this->capabilityKind,
            capabilityName: $this->capabilityName,
            access: $this->access,
            risk: $this->risk,
            write: $this->write,
            result: $this->result,
            reason: $this->reason,
            payload: array_merge($this->payload, $extra),
            approvalId: $this->approvalId,
            planRunId: $this->planRunId,
            sessionId: $this->sessionId,
            trackingId: $this->trackingId,
            actorId: $this->actorId,
            actorType: $this->actorType,
            timestamp: $this->timestamp,
        );
    }

    /**
     * 载荷指纹（去重与「同一次操作反复触发」的排查用）
     *
     * 键序无关（`ksort` 后编码），因此同一份 payload 无论字段顺序如何，指纹一致。
     */
    public function payloadHash(): string
    {
        $payload = $this->payload;
        ksort($payload);

        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE);

        return hash('sha256', $encoded === false ? '' : $encoded);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'trace_id'        => $this->traceId,
            'scene'           => $this->scene,
            'capability_kind' => $this->capabilityKind,
            'capability_name' => $this->capabilityName,
            'access'          => $this->access,
            'risk'            => $this->risk,
            'write'           => $this->write,
            'result'          => $this->result,
            'reason'          => $this->reason,
            'payload'         => $this->payload,
            'approval_id'     => $this->approvalId,
            'plan_run_id'     => $this->planRunId,
            'session_id'      => $this->sessionId,
            'tracking_id'     => $this->trackingId,
            'actor_id'        => $this->actorId,
            'actor_type'      => $this->actorType,
            'occurred_at'     => $this->occurredAt(),
        ];
    }

    /**
     * 从落库行/数组还原（回放命令与单测用）
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $timestamp = $data['timestamp'] ?? null;

        if ($timestamp === null || $timestamp === '') {
            $occurredAt = (string) ($data['occurred_at'] ?? $data['create_time'] ?? '');
            $timestamp  = $occurredAt !== '' ? (float) strtotime($occurredAt) : 0.0;
        }

        return new self(
            traceId: (string) ($data['trace_id'] ?? ''),
            scene: (string) ($data['scene'] ?? ''),
            capabilityKind: (string) ($data['capability_kind'] ?? ''),
            capabilityName: (string) ($data['capability_name'] ?? ''),
            access: (string) ($data['access'] ?? ''),
            risk: (string) ($data['risk'] ?? ''),
            write: (bool) ($data['write'] ?? false),
            result: (string) ($data['result'] ?? self::RESULT_UNKNOWN),
            reason: (string) ($data['reason'] ?? ''),
            payload: is_array($data['payload'] ?? null) ? $data['payload'] : [],
            approvalId: (int) ($data['approval_id'] ?? 0),
            planRunId: (int) ($data['plan_run_id'] ?? 0),
            sessionId: (string) ($data['session_id'] ?? ''),
            trackingId: (string) ($data['tracking_id'] ?? ''),
            actorId: (int) ($data['actor_id'] ?? 0),
            actorType: (string) ($data['actor_type'] ?? 'admin'),
            timestamp: (float) $timestamp,
        );
    }

    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        return $this->toArray();
    }
}
