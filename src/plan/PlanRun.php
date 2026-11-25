<?php
declare(strict_types=1);

namespace wise\agent\plan;

/**
 * 计划运行状态＼�
 *
 * 纯数据对象：**不做任何 DB 访问**（落库与查询在 B1-3 的仓储里）。
 * 字段与 `ai_plan_runs` 表一一对应，`fromArray()` 同时接受「DB 行」与「计划定义」两种来源。
 *
 * ## 状态机＼�
 *
 * ```
 * running ──写步骤待审批──▶ paused(awaiting_approval, current_step=N)
 *    ▲                                │ 批准 + plan_resume
 *    └──────── 从第 N 步续跑 ◀────────┘
 *    ├── 断言失败 + replan ──▶ replanning ──▶ running
 *    ├── 超上限/不可恢复 ─────▶ aborted
 *    └── 全部完成 ───────────▶ completed
 * ```
 */
final class PlanRun implements \JsonSerializable
{
    public const STATUS_RUNNING      = 'running';
    public const STATUS_PAUSED       = 'paused';
    public const STATUS_REPLANNING   = 'replanning';
    public const STATUS_COMPLETED    = 'completed';
    public const STATUS_ABORTED      = 'aborted';

    /**
     * 全部状态
     *
     * @var string[]
     */
    public const STATUSES = [
        self::STATUS_RUNNING,
        self::STATUS_PAUSED,
        self::STATUS_REPLANNING,
        self::STATUS_COMPLETED,
        self::STATUS_ABORTED,
    ];

    /**
     * @param int         $runId         运行 id（落库后回填）
     * @param string      $trackingId    与 `ai_agent_logs.tracking_id` 关联（可观测复用）
     * @param string      $sessionId     会话 id
     * @param string      $userType      用户类型（续跑必须同人，见附录五 §8.1）
     * @param int         $userId        用户 id（`<=0` = 系统上下文）
     * @param string      $goal          原始目标
     * @param array       $plan          计划快照（`PlanDefinition::toArray()`）
     * @param string      $status        见 STATUS_*
     * @param int         $currentStep   当前步骤序号（1 基；**续跑定位靠它**）
     * @param int         $totalSteps    步骤总数
     * @param int         $totalTokens   累计 token
     * @param float       $totalDuration 累计耗时（秒）
     * @param ?string     $error         终止原因
     * @param string      $source        来源：`chat` / `api` / `cli` / `materialized`
     * @param string      $planner       规划者（见 `PlanDefinition::PLANNER_*`）
     * @param array       $metadata      附加信息
     * @param string      $createTime
     * @param string      $updateTime
     */
    public function __construct(
        public readonly int $runId = 0,
        public readonly string $trackingId = '',
        public readonly string $sessionId = '',
        public readonly string $userType = 'admin',
        public readonly int $userId = 0,
        public readonly string $goal = '',
        public readonly array $plan = [],
        public readonly string $status = self::STATUS_RUNNING,
        public readonly int $currentStep = 0,
        public readonly int $totalSteps = 0,
        public readonly int $totalTokens = 0,
        public readonly float $totalDuration = 0.0,
        public readonly ?string $error = null,
        public readonly string $source = 'chat',
        public readonly string $planner = PlanDefinition::PLANNER_LLM,
        public readonly array $metadata = [],
        public readonly string $createTime = '',
        public readonly string $updateTime = '',
    ) {
    }

    /**
     * 从 DB 行 / 数组还原（JSON 列自动解码）
     *
     * @param array<string, mixed> $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            runId: (int) ($row['run_id'] ?? $row['runId'] ?? 0),
            trackingId: (string) ($row['tracking_id'] ?? $row['trackingId'] ?? ''),
            sessionId: (string) ($row['session_id'] ?? $row['sessionId'] ?? ''),
            userType: (string) ($row['user_type'] ?? $row['userType'] ?? 'admin'),
            userId: (int) ($row['user_id'] ?? $row['userId'] ?? 0),
            goal: (string) ($row['goal'] ?? ''),
            plan: self::decodeArray($row['plan'] ?? []),
            status: (string) ($row['status'] ?? self::STATUS_RUNNING),
            currentStep: (int) ($row['current_step'] ?? $row['currentStep'] ?? 0),
            totalSteps: (int) ($row['total_steps'] ?? $row['totalSteps'] ?? 0),
            totalTokens: (int) ($row['total_tokens'] ?? $row['totalTokens'] ?? 0),
            totalDuration: (float) ($row['total_duration'] ?? $row['totalDuration'] ?? 0),
            error: isset($row['error']) && $row['error'] !== null ? (string) $row['error'] : null,
            source: (string) ($row['source'] ?? 'chat'),
            planner: (string) ($row['planner'] ?? PlanDefinition::PLANNER_LLM),
            metadata: self::decodeArray($row['metadata'] ?? []),
            createTime: (string) ($row['create_time'] ?? $row['createTime'] ?? ''),
            updateTime: (string) ($row['update_time'] ?? $row['updateTime'] ?? ''),
        );
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_ABORTED], true);
    }

    /**
     * 是否处于「等待外部动作（人工审批）」的暂停态
     */
    public function isPaused(): bool
    {
        return $this->status === self::STATUS_PAUSED;
    }

    /**
     * 进度（0~1；步骤数为 0 时记 0）
     */
    public function progress(): float
    {
        if ($this->totalSteps <= 0) {
            return 0.0;
        }

        return min(1.0, max(0.0, $this->currentStep / $this->totalSteps));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'run_id'         => $this->runId,
            'tracking_id'    => $this->trackingId,
            'session_id'     => $this->sessionId,
            'user_type'      => $this->userType,
            'user_id'        => $this->userId,
            'goal'           => $this->goal,
            'plan'           => $this->plan,
            'status'         => $this->status,
            'current_step'   => $this->currentStep,
            'total_steps'    => $this->totalSteps,
            'total_tokens'   => $this->totalTokens,
            'total_duration' => $this->totalDuration,
            'error'          => $this->error,
            'source'         => $this->source,
            'planner'        => $this->planner,
            'metadata'       => $this->metadata,
            'create_time'    => $this->createTime,
            'update_time'    => $this->updateTime,
        ];
    }

    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        return $this->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodeArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }
}
