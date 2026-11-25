<?php
declare(strict_types=1);

namespace wise\agent\plan;

/**
 * 计划步骤运行状态＼�
 *
 * 纯数据对象，字段与 `ai_plan_steps` 一一对应。其中两项是 B1 的关键设计载体：
 *
 * - **`arguments`（解析后的参数快照）**：续跑时用它**重建 `$steps` 上下文**；
 *   且当审批单按参数指纹匹配（`approval.request.scope = arguments`）时，
 *   续跑必须重发**完全相同的参数**，否则「批了也消费不到」＼�。
 * - **`approvalId`**：该步生成的审批单 id（用于「谁批的、批的哪一步」的追溯）。
 */
final class PlanStepRun implements \JsonSerializable
{
    public const STATUS_PENDING   = 'pending';
    public const STATUS_RUNNING   = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED    = 'failed';
    public const STATUS_SKIPPED   = 'skipped';

    /** 被审批闸门阻断（等待人工审批；不是失败） */
    public const STATUS_BLOCKED = 'blocked_pending_approval';

    /**
     * 全部状态
     *
     * @var string[]
     */
    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_RUNNING,
        self::STATUS_COMPLETED,
        self::STATUS_FAILED,
        self::STATUS_SKIPPED,
        self::STATUS_BLOCKED,
    ];

    /**
     * @param int     $runId        所属计划运行 id
     * @param int     $stepNo       步骤序号（1 基）
     * @param string  $stepId       计划里的步骤 id
     * @param string  $kind         见 `PlanStep::KIND_*`
     * @param string  $target       被引用能力
     * @param string  $label        人类可读
     * @param bool    $write        是否写步骤（能力 access 推导）
     * @param array   $arguments    **解析后**的参数快照（续跑与参数指纹匹配的依据）
     * @param mixed   $output       步骤输出
     * @param array   $verifyResult 断言结论（`{passed, failures[]}`）
     * @param string  $status       见 STATUS_*
     * @param int     $attempts     已尝试次数
     * @param float   $duration     耗时（秒）
     * @param ?string $error        失败原因
     * @param ?int    $approvalId   该步生成的审批单 id
     * @param string  $createTime
     * @param string  $updateTime
     */
    public function __construct(
        public readonly int $runId = 0,
        public readonly int $stepNo = 0,
        public readonly string $stepId = '',
        public readonly string $kind = '',
        public readonly string $target = '',
        public readonly string $label = '',
        public readonly bool $write = false,
        public readonly array $arguments = [],
        public readonly mixed $output = null,
        public readonly array $verifyResult = [],
        public readonly string $status = self::STATUS_PENDING,
        public readonly int $attempts = 0,
        public readonly float $duration = 0.0,
        public readonly ?string $error = null,
        public readonly ?int $approvalId = null,
        public readonly string $createTime = '',
        public readonly string $updateTime = '',
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            runId: (int) ($row['run_id'] ?? $row['runId'] ?? 0),
            stepNo: (int) ($row['step_no'] ?? $row['stepNo'] ?? 0),
            stepId: (string) ($row['step_id'] ?? $row['stepId'] ?? ''),
            kind: (string) ($row['kind'] ?? ''),
            target: (string) ($row['target'] ?? ''),
            label: (string) ($row['label'] ?? ''),
            write: (bool) ($row['write'] ?? false),
            arguments: self::decodeArray($row['arguments'] ?? []),
            output: self::decodeValue($row['output'] ?? null),
            verifyResult: self::decodeArray($row['verify_result'] ?? $row['verifyResult'] ?? []),
            status: (string) ($row['status'] ?? self::STATUS_PENDING),
            attempts: (int) ($row['attempts'] ?? 0),
            duration: (float) ($row['duration'] ?? 0),
            error: isset($row['error']) && $row['error'] !== null ? (string) $row['error'] : null,
            approvalId: isset($row['approval_id']) && $row['approval_id'] !== null ? (int) $row['approval_id'] : null,
            createTime: (string) ($row['create_time'] ?? $row['createTime'] ?? ''),
            updateTime: (string) ($row['update_time'] ?? $row['updateTime'] ?? ''),
        );
    }

    /**
     * 是否等待人工审批（计划应据此进入 `paused`，**不是失败**）
     */
    public function isBlocked(): bool
    {
        return $this->status === self::STATUS_BLOCKED;
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_FAILED, self::STATUS_SKIPPED], true);
    }

    public function verifyPassed(): bool
    {
        return ($this->verifyResult['passed'] ?? null) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'run_id'        => $this->runId,
            'step_no'       => $this->stepNo,
            'step_id'       => $this->stepId,
            'kind'          => $this->kind,
            'target'        => $this->target,
            'label'         => $this->label,
            'write'         => $this->write,
            'arguments'     => $this->arguments,
            'output'        => $this->output,
            'verify_result' => $this->verifyResult,
            'status'        => $this->status,
            'attempts'      => $this->attempts,
            'duration'      => $this->duration,
            'error'         => $this->error,
            'approval_id'   => $this->approvalId,
            'create_time'   => $this->createTime,
            'update_time'   => $this->updateTime,
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
        $decoded = self::decodeValue($value);

        return is_array($decoded) ? $decoded : [];
    }

    private static function decodeValue(mixed $value): mixed
    {
        if (!is_string($value) || $value === '') {
            return $value;
        }

        $decoded = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }
}
