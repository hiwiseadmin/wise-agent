<?php
declare(strict_types=1);

namespace wise\agent\closure\store;

use wise\agent\closure\ClosureEvent;
use wise\agent\closure\ConnectorResult;

/**
 * 闭环轨迹落库接缝＼�
 *
 * 抽成接口的两个实打实的理由（与 B1-3 的 `PlanRunStoreInterface` 同）：
 *
 * 1. **离线可测**：派发要写两张表，但单测环境没有数据库；注入内存实现即可完整验证
 *    「事件 → 多条投递 → 状态聚合」的行为；
 * 2. **未来可换**：C1 统一任务中心收口读面时，只换实现，不动派发器。
 *
 * 表的列名与 {@see ClosureEvent::toArray()} / {@see ConnectorResult::toArray()} **一一对应**
 * （见迁移 `..._create_ai_closure_events_table.php` / `..._create_ai_closure_deliveries_table.php`）。
 */
interface ClosureTraceStoreInterface
{
    /**
     * 写入事件，返回 `event_id`
     */
    public function createEvent(ClosureEvent $event, string $status): int;

    /**
     * 更新事件状态（只更新传入的键）
     *
     * @param array<string, mixed> $fields
     */
    public function updateEventStatus(int $eventId, string $status, array $fields = []): void;

    /**
     * 追加一次投递记录，返回 `delivery_id`
     */
    public function appendDelivery(int $eventId, string $traceId, ConnectorResult $result): int;

    /**
     * 更新投递记录（补投与死信标记用）
     *
     * @param array<string, mixed> $fields
     */
    public function updateDelivery(int $deliveryId, array $fields): void;

    /**
     * @return array<string, mixed>|null
     */
    public function findEvent(int $eventId): ?array;

    /**
     * 某个 trace 的全部事件（按时间升序）
     *
     * @return array<int, array<string, mixed>>
     */
    public function findEventsByTrace(string $traceId, int $limit = 50): array;

    /**
     * 某个 trace 的全部投递（可按状态过滤）
     *
     * @return array<int, array<string, mixed>>
     */
    public function findDeliveries(string $traceId, ?string $status = null, int $limit = 200): array;

    /**
     * @return array<string, mixed>|null
     */
    public function findDelivery(int $deliveryId): ?array;

    /**
     * 列出事件（闭环 `list` 命令用）
     *
     * @param array<string, mixed> $filter `scene` / `status` / `trace_id` / `limit`
     * @return array<int, array<string, mixed>>
     */
    public function listEvents(array $filter = [], int $limit = 20): array;

    /**
     * 列出**失败的投递**（`closure retry --failed` 用，B2-4）
     *
     * 只返回 `status=failed`：`skipped` 代表"本就不该投"（未注册/不支持/未配置目标/免打扰），
     * 补投它们只会再跳过一次。
     *
     * @return array<int, array<string, mixed>>
     */
    public function findFailedDeliveries(int $limit = 50): array;

    /**
     * 清理历史（`closure prune` 用，B2-4）
     *
     * @param string $before 早于此时间（`Y-m-d H:i:s`）的数据将被删除
     * @return array{events:int, deliveries:int} 实际删除行数
     */
    public function pruneBefore(string $before): array;
}
