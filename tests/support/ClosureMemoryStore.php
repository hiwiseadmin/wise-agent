<?php

declare(strict_types=1);

/**
 * 闭环轨迹的内存仓储（B2-4 测试支撑，**全局命名空间**，由各测试文件 require_once）
 *
 * 与 `tests/support/WiseAgentConfigLoader.php` 同一做法：不放进 `*Test.php`，
 * 因为 PHPUnit 的用例文件会被逐个加载，而这类共享替身应只加载一次。
 *
 * 行为与 {@see \wise\agent\closure\store\DbClosureTraceStore} 对齐（含新增的
 * `findFailedDeliveries` / `pruneBefore`），差别只在"内存 vs 数据库"。
 */
class ClosureMemoryStore implements \wise\agent\closure\store\ClosureTraceStoreInterface
{
    /** @var array<int, array<string, mixed>> */
    public array $events = [];

    /** @var array<int, array<string, mixed>> */
    public array $deliveries = [];

    private int $eventSeq = 0;

    private int $deliverySeq = 0;

    public function createEvent(\wise\agent\closure\ClosureEvent $event, string $status): int
    {
        $id = ++$this->eventSeq;

        $this->events[$id] = $event->toArray() + [
            'event_id'    => $id,
            'status'      => $status,
            'payload_hash' => $event->payloadHash(),
            'duration_ms' => 0,
            'error'       => '',
            'create_time' => date('Y-m-d H:i:s'),
        ];

        return $id;
    }

    public function updateEventStatus(int $eventId, string $status, array $fields = []): void
    {
        if (isset($this->events[$eventId])) {
            $this->events[$eventId] = array_merge($this->events[$eventId], $fields, ['status' => $status]);
        }
    }

    public function appendDelivery(int $eventId, string $traceId, \wise\agent\closure\ConnectorResult $result): int
    {
        $id = ++$this->deliverySeq;

        $this->deliveries[$id] = $result->toArray() + [
            'delivery_id' => $id,
            'event_id'    => $eventId,
            'trace_id'    => $traceId,
            'attempts'    => 1,
            'create_time' => date('Y-m-d H:i:s'),
        ];

        return $id;
    }

    public function updateDelivery(int $deliveryId, array $fields): void
    {
        if (isset($this->deliveries[$deliveryId])) {
            $this->deliveries[$deliveryId] = array_merge($this->deliveries[$deliveryId], $fields);
        }
    }

    public function findEvent(int $eventId): ?array
    {
        return $this->events[$eventId] ?? null;
    }

    public function findEventsByTrace(string $traceId, int $limit = 50): array
    {
        return array_slice(array_values(array_filter(
            $this->events,
            static fn(array $e): bool => ($e['trace_id'] ?? '') === $traceId
        )), 0, $limit);
    }

    public function findDeliveries(string $traceId, ?string $status = null, int $limit = 200): array
    {
        return array_slice(array_values(array_filter(
            $this->deliveries,
            static fn(array $d): bool => ($d['trace_id'] ?? '') === $traceId
                && ($status === null || $status === '' || ($d['status'] ?? '') === $status)
        )), 0, $limit);
    }

    public function findDelivery(int $deliveryId): ?array
    {
        return $this->deliveries[$deliveryId] ?? null;
    }

    public function listEvents(array $filter = [], int $limit = 20): array
    {
        $rows = array_values(array_filter($this->events, static function (array $e) use ($filter): bool {
            foreach (['scene', 'status'] as $key) {
                $want = (string) ($filter[$key] ?? '');

                if ($want !== '' && ($e[$key] ?? '') !== $want) {
                    return false;
                }
            }

            $trace = (string) ($filter['trace_id'] ?? '');

            return $trace === '' || ($e['trace_id'] ?? '') === $trace;
        }));

        return array_slice($rows, 0, $limit);
    }

    public function findFailedDeliveries(int $limit = 50): array
    {
        return array_slice(array_values(array_filter(
            $this->deliveries,
            static fn(array $d): bool => ($d['status'] ?? '') === \wise\agent\closure\ConnectorResult::STATUS_FAILED
        )), 0, $limit);
    }

    public function pruneBefore(string $before): array
    {
        $events     = 0;
        $deliveries = 0;

        foreach ($this->events as $id => $row) {
            if (($row['create_time'] ?? '') !== '' && $row['create_time'] < $before) {
                unset($this->events[$id]);
                $events++;
            }
        }

        foreach ($this->deliveries as $id => $row) {
            if (($row['create_time'] ?? '') !== '' && $row['create_time'] < $before) {
                unset($this->deliveries[$id]);
                $deliveries++;
            }
        }

        return ['events' => $events, 'deliveries' => $deliveries];
    }
}
