<?php
declare(strict_types=1);

namespace wise\agent\closure\store;

use think\facade\Db;
use wise\agent\closure\ClosureEvent;
use wise\agent\closure\ClosureOptions;
use wise\agent\closure\ConnectorResult;

/**
 * 闭环轨迹的数据库实现（B2-1）
 *
 * 表结构见 `database/migrations/20261016000003_create_ai_closure_events_table.php`
 * 与 `..._20261016000004_create_ai_closure_deliveries_table.php`；列名与
 * {@see ClosureEvent::toArray()} / {@see ConnectorResult::toArray()} + `event_id`/`trace_id`
 * **一一对应**。
 *
 * 设计取舍（与 B1-3 的 `DbPlanRunStore` 保持一致）：
 *
 * - JSON 字段（`payload`）编码失败**不抛异常**，落 `null` ——
 *   「记不下日志」不该打断业务；
 * - `appendDelivery()` 是**只增不改**：一次投递一行（补投会新增一行并累加 `attempts`），
 *   因为「补投了几次、每次结果如何」本身就是运维要看的证据。
 */
class DbClosureTraceStore implements ClosureTraceStoreInterface
{
    use ClosureOptions;

    private const TABLE_EVENTS     = 'ai_closure_events';
    private const TABLE_DELIVERIES = 'ai_closure_deliveries';

    /**
     * @param array<string, mixed> $options `closure.*`（支持 `closure.tables.*` 覆盖表名）
     */
    public function __construct(array $options = [])
    {
        $this->setClosureOptions($options);
    }

    public function createEvent(ClosureEvent $event, string $status): int
    {
        $now  = date('Y-m-d H:i:s');
        $data = [
            'trace_id'        => $event->traceId,
            'scene'           => $event->scene,
            'capability_kind' => $event->capabilityKind,
            'capability_name' => $event->capabilityName,
            'access'          => $event->access,
            'risk'            => $event->risk,
            'write'           => $event->write,
            'result'          => $event->result,
            'reason'          => mb_substr($event->reason, 0, 500, 'UTF-8'),
            'payload'         => $this->encode($event->payload),
            'payload_hash'    => $event->payloadHash(),
            'approval_id'     => $event->approvalId,
            'plan_run_id'     => $event->planRunId,
            'session_id'      => $event->sessionId,
            'tracking_id'     => $event->trackingId,
            'actor_id'        => $event->actorId,
            'actor_type'      => $event->actorType,
            'status'          => $status,
            'occurred_at'     => $event->occurredAt(),
            'create_time'     => $now,
            'update_time'     => $now,
        ];

        return (int) Db::name($this->tableEvents())->insertGetId($data);
    }

    public function updateEventStatus(int $eventId, string $status, array $fields = []): void
    {
        if ($eventId <= 0) {
            return;
        }

        $fields['status']      = $status;
        $fields['update_time'] = date('Y-m-d H:i:s');

        Db::name($this->tableEvents())->where('event_id', $eventId)->update($fields);
    }

    public function appendDelivery(int $eventId, string $traceId, ConnectorResult $result): int
    {
        $now = date('Y-m-d H:i:s');

        return (int) Db::name($this->tableDeliveries())->insertGetId([
            'event_id'    => max(0, $eventId),
            'trace_id'    => $traceId,
            'connector'   => $result->connector,
            'target'      => mb_substr($result->target, 0, 500, 'UTF-8'),
            'status'      => $result->status,
            'response'    => $this->encode($result->response),
            'error'       => $result->error,
            'duration_ms' => $result->durationMs,
            'slow'        => $result->slow,
            'attempts'    => 1,
            'create_time' => $now,
            'update_time' => $now,
        ]);
    }

    public function updateDelivery(int $deliveryId, array $fields): void
    {
        if ($deliveryId <= 0 || $fields === []) {
            return;
        }

        $fields['update_time'] = date('Y-m-d H:i:s');

        Db::name($this->tableDeliveries())->where('delivery_id', $deliveryId)->update($fields);
    }

    public function findEvent(int $eventId): ?array
    {
        if ($eventId <= 0) {
            return null;
        }

        $row = Db::name($this->tableEvents())->where('event_id', $eventId)->find();

        return is_array($row) ? $row : null;
    }

    public function findEventsByTrace(string $traceId, int $limit = 50): array
    {
        if (trim($traceId) === '') {
            return [];
        }

        return Db::name($this->tableEvents())
            ->where('trace_id', $traceId)
            ->order('event_id', 'asc')
            ->limit(max(1, $limit))
            ->select()
            ->toArray();
    }

    public function findDeliveries(string $traceId, ?string $status = null, int $limit = 200): array
    {
        if (trim($traceId) === '') {
            return [];
        }

        $query = Db::name($this->tableDeliveries())->where('trace_id', $traceId);

        if ($status !== null && $status !== '') {
            $query->where('status', $status);
        }

        return $query->order('delivery_id', 'asc')->limit(max(1, $limit))->select()->toArray();
    }

    public function findDelivery(int $deliveryId): ?array
    {
        if ($deliveryId <= 0) {
            return null;
        }

        $row = Db::name($this->tableDeliveries())->where('delivery_id', $deliveryId)->find();

        return is_array($row) ? $row : null;
    }

    public function listEvents(array $filter = [], int $limit = 20): array
    {
        $query = Db::name($this->tableEvents());

        if (($filter['scene'] ?? '') !== '' && $filter['scene'] !== null) {
            $query->where('scene', (string) $filter['scene']);
        }

        if (($filter['status'] ?? '') !== '' && $filter['status'] !== null) {
            $query->where('status', (string) $filter['status']);
        }

        if (($filter['trace_id'] ?? '') !== '' && $filter['trace_id'] !== null) {
            $query->where('trace_id', (string) $filter['trace_id']);
        }

        return $query->order('event_id', 'desc')->limit(max(1, $limit))->select()->toArray();
    }

    public function findFailedDeliveries(int $limit = 50): array
    {
        return Db::name($this->tableDeliveries())
            ->where('status', ConnectorResult::STATUS_FAILED)
            ->order('delivery_id', 'desc')
            ->limit(max(1, $limit))
            ->select()
            ->toArray();
    }

    public function pruneBefore(string $before): array
    {
        if (trim($before) === '') {
            return ['events' => 0, 'deliveries' => 0];
        }

        $deliveries = Db::name($this->tableDeliveries())->where('create_time', '<', $before)->delete();
        $events     = Db::name($this->tableEvents())->where('create_time', '<', $before)->delete();

        return ['events' => (int) $events, 'deliveries' => (int) $deliveries];
    }

    /**
     * 表名配置键是 `closure.table.*`（**单数**，与 B1 的 `plan.table.*` 同一套命名）
     *
     * ⚠️ B2-4 实测到的不一致：B2-1 这里曾写 `tables.*`（复数），而配置节与
     * `DbClosurePlanLink` 用的是 `table.*` —— 两边一旦对不上，会**静默退回代码默认表名**
     * （配置改了却不生效）。已统一为单数，并由 `ClosureConfigTest` 做守门断言。
     */
    protected function tableEvents(): string
    {
        return (string) $this->closureOpt('table.events', self::TABLE_EVENTS);
    }

    protected function tableDeliveries(): string
    {
        return (string) $this->closureOpt('table.deliveries', self::TABLE_DELIVERIES);
    }

    /**
     * JSON 编码：失败返回 `null`（**不抛异常**，见类注释）
     */
    protected function encode(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            return $value;
        }

        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE);

        return $encoded === false ? null : $encoded;
    }
}
