<?php
declare(strict_types=1);

namespace wise\agent\execution;

use think\facade\Config;
use think\facade\Db;
use wise\support\context\ContextualLogger;

/**
 * 统一执行追踪器
 *
 * 管理 Agent/Pipeline 执行的全生命周期状态，覆盖同步、异步、流式三条执行路径。
 *
 * 状态流转:
 *   queued(0) → running(1) → completed(2) / failed(3) / timeout(4) / cancelled(5)
 *
 * 使用场景:
 *   同步:  start() → update(running) → finish(completed|failed)
 *   异步:  start(queued) → [队列中] → job::start(running) → finish(completed|failed)
 *   流式:  start() → update(running) → [yield events] → finish(completed|failed)
 */
class ExecutionTracker
{
    public const STATUS_QUEUED    = 0;
    public const STATUS_RUNNING   = 1;
    public const STATUS_COMPLETED = 2;
    public const STATUS_FAILED    = 3;
    public const STATUS_TIMEOUT   = 4;
    public const STATUS_CANCELLED = 5;

    /**
     * 启动一次执行追踪
     *
     * 在数据库中创建初始记录，返回唯一的 tracking_id。
     *
     * @param string $jobType   任务类型: 'agent' | 'pipeline' | 'skill'
     * @param array  $meta      初始元数据 (session_id, task, agent_type, provider, model, user_type, user_id)
     * @param int    $status    初始状态（默认 queued，同步调用传 running）
     * @return string tracking_id
     */
    public static function start(string $jobType, array $meta, int $status = self::STATUS_QUEUED): string
    {
        $trackingId = self::generateTrackingId();

        try {
            $table = 'ai_agent_logs';
            $now = date('Y-m-d H:i:s');

            $data = [
                'tracking_id'  => $trackingId,
                'session_id'   => $meta['session_id'] ?? '',
                'user_type'    => $meta['user_type'] ?? 'admin',
                'user_id'      => $meta['user_id'] ?? 0,
                'agent_type'   => $meta['agent_type'] ?? 'simple',
                'task'         => $meta['task'] ?? '',
                'result'       => null,
                'steps'        => 0,
                'total_tokens' => 0,
                'duration'     => 0,
                'provider'     => $meta['provider'] ?? '',
                'model'        => $meta['model'] ?? '',
                'status'       => $status,
                'error'        => null,
                'job_type'     => $jobType,
                'heartbeat'    => $status === self::STATUS_RUNNING ? $now : null,
                'metadata'     => isset($meta['metadata']) ? json_encode($meta['metadata'], JSON_UNESCAPED_UNICODE) : null,
                'create_time'  => $now,
            ];

            Db::name($table)->insert($data);

            ContextualLogger::debug("[ExecutionTracker] Started tracking [{$trackingId}] type={$jobType} status={$status}");
        } catch (\Throwable $e) {
            ContextualLogger::warning("[ExecutionTracker] Failed to start tracking: {$e->getMessage()}");
        }

        return $trackingId;
    }

    /**
     * 更新执行状态
     *
     * @param string $trackingId 追踪 ID
     * @param int    $status     新状态
     * @param array  $data       额外数据 (steps, total_tokens, duration, result, error, metadata, heartbeat)
     */
    public static function update(string $trackingId, int $status, array $data = []): void
    {
        try {
            $table = 'ai_agent_logs';
            $update = ['status' => $status];

            if ($status === self::STATUS_RUNNING) {
                $update['heartbeat'] = date('Y-m-d H:i:s');
            }

            if (isset($data['steps'])) {
                $update['steps'] = $data['steps'];
            }
            if (isset($data['total_tokens'])) {
                $update['total_tokens'] = $data['total_tokens'];
            }
            if (isset($data['duration'])) {
                $update['duration'] = $data['duration'];
            }
            if (isset($data['result'])) {
                $update['result'] = mb_substr((string) $data['result'], 0, 10000);
            }
            if (isset($data['error'])) {
                $update['error'] = mb_substr((string) $data['error'], 0, 2000);
            }
            if (isset($data['metadata'])) {
                $update['metadata'] = is_array($data['metadata'])
                    ? json_encode($data['metadata'], JSON_UNESCAPED_UNICODE)
                    : (string) $data['metadata'];
            }

            Db::name($table)->where('tracking_id', $trackingId)->update($update);
        } catch (\Throwable $e) {
            ContextualLogger::warning("[ExecutionTracker] Failed to update tracking [{$trackingId}]: {$e->getMessage()}");
        }
    }

    /**
     * 发送心跳（仅在 running 状态下更新 heartbeat 时间戳）
     *
     * @param string $trackingId 追踪 ID
     * @param array  $extra      额外进度数据 (steps, total_tokens, duration)
     */
    public static function heartbeat(string $trackingId, array $extra = []): void
    {
        try {
            $table = 'ai_agent_logs';
            $update = ['heartbeat' => date('Y-m-d H:i:s')];

            if (isset($extra['steps'])) {
                $update['steps'] = $extra['steps'];
            }
            if (isset($extra['total_tokens'])) {
                $update['total_tokens'] = $extra['total_tokens'];
            }
            if (isset($extra['duration'])) {
                $update['duration'] = $extra['duration'];
            }

            Db::name($table)->where('tracking_id', $trackingId)->update($update);
        } catch (\Throwable $e) {
        }
    }

    /**
     * 完成一次执行追踪
     *
     * @param string      $trackingId 追踪 ID
     * @param string|null $result     最终结果
     * @param string|null $error      错误信息
     * @param array       $data       额外数据 (steps, total_tokens, duration, metadata)
     */
    public static function finish(string $trackingId, ?string $result, ?string $error, array $data = []): void
    {
        $status = $error !== null ? self::STATUS_FAILED : self::STATUS_COMPLETED;

        $updateData = [
            'result' => $result,
            'error'  => $error,
        ];

        if (isset($data['steps'])) {
            $updateData['steps'] = $data['steps'];
        }
        if (isset($data['total_tokens'])) {
            $updateData['total_tokens'] = $data['total_tokens'];
        }
        if (isset($data['duration'])) {
            $updateData['duration'] = $data['duration'];
        }
        if (isset($data['metadata'])) {
            $updateData['metadata'] = is_array($data['metadata'])
                ? json_encode($data['metadata'], JSON_UNESCAPED_UNICODE)
                : (string) $data['metadata'];
        }

        self::update($trackingId, $status, $updateData);

        ContextualLogger::debug("[ExecutionTracker] Finished tracking [{$trackingId}] status={$status}");
    }

    /**
     * 取消一次执行（用户主动取消）
     *
     * @param string $trackingId 追踪 ID
     */
    public static function cancel(string $trackingId): void
    {
        self::update($trackingId, self::STATUS_CANCELLED, []);
    }

    /**
     * 标记超时（由守护进程或健康检查调用）
     *
     * @param string $trackingId 追踪 ID
     */
    public static function timeout(string $trackingId): void
    {
        self::update($trackingId, self::STATUS_TIMEOUT, [
            'error' => 'Execution timed out or process terminated unexpectedly',
        ]);
    }

    /**
     * 查询执行状态
     *
     * @param string $trackingId 追踪 ID
     * @return array|null 状态数组 {tracking_id, status, status_text, steps, total_tokens, duration, result, error, heartbeat, create_time}
     */
    public static function getStatus(string $trackingId): ?array
    {
        try {
            $table = 'ai_agent_logs';

            $row = Db::name($table)
                ->where('tracking_id', $trackingId)
                ->find();

            if (empty($row)) {
                return null;
            }

            return [
                'tracking_id'  => $row['tracking_id'] ?? $trackingId,
                'status'       => (int) ($row['status'] ?? 0),
                'status_text'  => self::statusText((int) ($row['status'] ?? 0)),
                'steps'        => (int) ($row['steps'] ?? 0),
                'total_tokens' => (int) ($row['total_tokens'] ?? 0),
                'duration'     => (float) ($row['duration'] ?? 0),
                'result'       => $row['result'] ?? null,
                'error'        => $row['error'] ?? null,
                'session_id'   => $row['session_id'] ?? null,
                'heartbeat'    => $row['heartbeat'] ?? null,
                'create_time'  => $row['create_time'] ?? null,
            ];
        } catch (\Throwable $e) {
            ContextualLogger::warning("[ExecutionTracker] Failed to get status [{$trackingId}]: {$e->getMessage()}");
            return null;
        }
    }

    /**
     * 生成执行追踪 ID（128 位随机，**不可猜测**）
     *
     * ⚠️ 这里刻意**不再用** `uniqid('track_', true)`：那个格式是「前缀 + 微秒时间戳 + . + 微秒」，
     * **低熵且可枚举** —— 而 `tracking_id` 是 `GET /admin/ai/task/status` 的**唯一凭据**，
     * 一旦可枚举，任何登录用户都能顺着时间戳猜出他人的执行记录，并读到 `result`（任务产出）。
     *
     * 换成 `random_bytes(16)`（128 位）后，猜测不可行，{@see self::isOwnedBy()} 的归属校验才真正立得住。
     *
     * 长度 38 字符（`track_` + 32 hex），≤ 列宽 64（迁移 `20251002000005` L23 的 `tracking_id`）。
     */
    public static function generateTrackingId(): string
    {
        return 'track_' . bin2hex(random_bytes(16));
    }

    /**
     * 该执行记录是否属于指定用户（**纯判定**，无 IO ⇒ 可离线单测）
     *
     * 两侧都做类型归一：MySQL 经 PDO 读出的 int 列通常是**字符串**（`'7'`），
     * 不做归一会出现「明明是自己却判成不是」的假阴性。
     *
     * @param array<string, mixed> $row `ai_agent_logs` 行
     */
    public static function rowOwnedBy(array $row, int $userId, string $userType): bool
    {
        return (int) ($row['user_id'] ?? 0) === $userId
            && (string) ($row['user_type'] ?? '') === $userType;
    }

    /**
     * 按 `tracking_id` 判定归属（**查询失败一律 fail-closed**：返回 `false`）
     */
    public static function isOwnedBy(string $trackingId, int $userId, string $userType): bool
    {
        if ($trackingId === '') {
            return false;
        }

        try {
            $row = Db::name('ai_agent_logs')
                ->field('user_id,user_type')
                ->where('tracking_id', $trackingId)
                ->find();
        } catch (\Throwable $e) {
            try {
                ContextualLogger::warning("[ExecutionTracker] Ownership check failed [{$trackingId}]: {$e->getMessage()}");
            } catch (\Throwable) {
            }

            return false;
        }

        return is_array($row) && self::rowOwnedBy($row, $userId, $userType);
    }

    /**
     * 取状态，**仅当该执行属于指定用户**（否则 `null`）
     *
     * 供 `GET /admin/ai/task/status` 使用。这里刻意把「归属」与「取数」合成一个入口：
     * `tracking_id` 是外部可传的凭据，调用方**必须先通过归属校验再拿到内容** ——
     * 接口把顺序固定下来，就不存在"忘了校验"的可能。
     *
     * 备注：本方法内含两次点查（归属 + 状态）。这是刻意取舍：
     * `ai_agent_logs` 有 `uk_tracking` 唯一索引，两次点查的代价远低于
     * 「复制一份十几行的字段映射」带来的漂移风险（映射只保留在 {@see self::getStatus()} 一处）。
     *
     * @return array<string, mixed>|null
     */
    public static function getStatusForOwner(string $trackingId, int $userId, string $userType): ?array
    {
        if (!self::isOwnedBy($trackingId, $userId, $userType)) {
            return null;
        }

        return self::getStatus($trackingId);
    }

    /**
     * 获取状态文本描述
     */
    public static function statusText(int $status): string
    {
        $map = [
            self::STATUS_QUEUED    => 'queued',
            self::STATUS_RUNNING   => 'running',
            self::STATUS_COMPLETED => 'completed',
            self::STATUS_FAILED    => 'failed',
            self::STATUS_TIMEOUT   => 'timeout',
            self::STATUS_CANCELLED => 'cancelled',
        ];

        return $map[$status] ?? 'unknown';
    }

    /**
     * 扫描超时任务（供 cron/守护进程调用）
     *
     * 查找状态为 running 但 heartbeat 超过 $timeoutSeconds 未更新的任务，
     * 自动标记为 timeout。
     *
     * @param int $timeoutSeconds 超时阈值（秒），默认 600（10 分钟）
     * @return int 标记为超时的任务数量
     */
    public static function scanTimeouts(int $timeoutSeconds = 600): int
    {
        try {
            $table = 'ai_agent_logs';
            $cutoff = date('Y-m-d H:i:s', time() - $timeoutSeconds);

            $stuck = Db::name($table)
                ->where('status', self::STATUS_RUNNING)
                ->where('heartbeat', '<', $cutoff)
                ->column('tracking_id');

            $count = 0;
            foreach ($stuck as $trackingId) {
                self::timeout($trackingId);
                $count++;
            }

            if ($count > 0) {
                ContextualLogger::warning("[ExecutionTracker] Auto-timed out {$count} stuck executions");
            }

            return $count;
        } catch (\Throwable $e) {
            ContextualLogger::warning("[ExecutionTracker] Failed to scan timeouts: {$e->getMessage()}");
            return 0;
        }
    }
}
