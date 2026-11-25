<?php
declare(strict_types=1);

namespace wise\agent\closure;

use wise\agent\closure\store\ClosureTraceStoreInterface;
use wise\agent\closure\store\DbClosureTraceStore;
use wise\support\context\ContextualLogger;

/**
 * 闭环派发器＼�
 *
 * 职责边界（**四件事，一件都不多**）：
 *
 * 1. **归一化**（委托 {@see ClosureEventFactory}）：既有事件 → {@see ClosureEvent}；
 * 2. **路由**（委托 {@see ClosureRouter}）：该事件该投给哪些连接器；
 * 3. **隔离派发**：逐个连接器投递，**每个都独立 try/catch + 计时**；
 * 4. **留痕**：事件与每次投递落库，状态聚合（ok / partial / failed）。
 *
 * ## 三条不可动摇的约束
 *
 * 1. **`dispatch()` 绝不抛异常**：`ToolExecute` 的 `Event::trigger` 在 `AgentManager` 里
 *    **未包 try/catch**（`AgentManager.php:927/968`），B2 若抛异常会**打断 AI 主链路**。
 *    因此整体再包一层兜底，最坏情况返回 `failed` 结果。
 * 2. **不重试**：重试下沉到 `wise-notification` / `wise-webhook` 自己的 job＼�。
 * 3. **超时只告警不改写事实**：超过 `closure.timeout_ms` 记 `slow=true`，
 *    **`status` 仍按下游真实结果记** —— 否则会成为"发成功却被标记失败"的重复投递来源
 *    （见 {@see ConnectorResult} 类注释）。
 *
 * ## 可测试性
 *
 * `registry()` / `router()` / `store()` / `factory()` / `now()` 都是 `protected` 接缝，
 * 因此本类**不加 `final`**：单测注入内存仓储 + 假连接器即可完全离线。
 */
class ClosureDispatcher
{
    use ClosureOptions;

    /** 默认单连接器软超时（毫秒） */
    public const DEFAULT_TIMEOUT_MS = 3000;

    private ?ClosureConnectorRegistry $registry = null;

    private ?ClosureRouter $router = null;

    private ?ClosureTraceStoreInterface $store = null;

    private ?ClosureEventFactory $factory = null;

    /**
     * @param array<string, mixed> $options `closure.*` 覆盖项
     */
    public function __construct(
        array $options = [],
        ?ClosureConnectorRegistry $registry = null,
        ?ClosureRouter $router = null,
        ?ClosureTraceStoreInterface $store = null,
        ?ClosureEventFactory $factory = null
    ) {
        $this->setClosureOptions($options);
        $this->registry = $registry;
        $this->router   = $router;
        $this->store    = $store;
        $this->factory  = $factory;
    }

    /**
     * 从既有事件派发（事件监听器入口，B2-4 用它）
     *
     * 未知事件 / `ToolExecute(BEFORE)` ⇒ 返回 `null`（安静跳过，不打断主链路）。
     *
     * @param array<string, mixed> $context
     */
    public function dispatchEvent(object $sourceEvent, array $context = []): ?ClosureDispatchResult
    {
        $event = $this->factory()->fromEvent($sourceEvent, $context);

        return $event === null ? null : $this->dispatch($event);
    }

    /**
     * 派发一个已归一化的闭环事件
     */
    public function dispatch(ClosureEvent $event): ClosureDispatchResult
    {
        $started = microtime(true);

        try {
            return $this->doDispatch($event, $started);
        } catch (\Throwable $e) {
            $this->log('error', sprintf('[Closure] 派发异常（已兜住，不影响主流程）：%s', $e->getMessage()));

            return new ClosureDispatchResult(
                status: ClosureDispatchResult::STATUS_FAILED,
                traceId: $event->traceId,
                scene: $event->scene,
                durationMs: $this->elapsedMs($started),
                error: 'dispatcher_exception: ' . $e->getMessage(),
            );
        }
    }

    /**
     * 补投一条失败的投递（`closure retry` 用，B2-4）
     *
     * **刻意不重新路由**：路由是按「场景 + 读写/风险」判定的，重跑一遍可能因为配置变更而算出不同结果，
     * 那会变成"补投 A 却发给了 B"。补投就是**原样重投同一个连接器**，
     * 并用**原事件的载荷**（`ClosureEvent::fromArray($row)`）—— 同一份数据、同一个目标。
     *
     * 补投会**新增一行**投递记录（见 `ai_closure_deliveries.attempts` 的注释）；
     * 因此「补了几次」= 同 (trace_id, connector) 的失败行数。
     */
    public function retryDelivery(string $connectorName, ClosureEvent $event, int $eventId = 0): ConnectorResult
    {
        $notes  = [];
        $result = $this->deliver($connectorName, $event, $notes);

        try {
            $this->store()->appendDelivery($eventId, $event->traceId, $result);
        } catch (\Throwable $e) {
            $this->log('warning', '[Closure] 补投记录落库失败：' . $e->getMessage());
        }

        $this->log(
            $result->isOk() ? 'info' : 'warning',
            sprintf('[Closure] 补投 %s（trace=%s）：%s', $connectorName, $event->traceId, $result->status)
        );

        return $result;
    }


    protected function registry(): ClosureConnectorRegistry
    {
        return $this->registry ??= new ClosureConnectorRegistry();
    }

    protected function router(): ClosureRouter
    {
        return $this->router ??= new ClosureRouter($this->closureOptions());
    }

    protected function store(): ClosureTraceStoreInterface
    {
        return $this->store ??= new DbClosureTraceStore($this->closureOptions());
    }

    protected function factory(): ClosureEventFactory
    {
        return $this->factory ??= new ClosureEventFactory($this->closureOptions());
    }

    protected function timeoutMs(): int
    {
        return max(1, (int) $this->closureOpt('timeout_ms', self::DEFAULT_TIMEOUT_MS));
    }


    private function doDispatch(ClosureEvent $event, float $started): ClosureDispatchResult
    {
        $explain = $this->router()->explain($event);
        $kept    = $explain['kept'];
        $notes   = $explain['notes'];

        if ($kept === []) {
            $this->log('debug', sprintf(
                '[Closure] 未触发（%s，trace=%s）：%s',
                $event->scene,
                $event->traceId,
                $explain['reason'] !== '' ? $explain['reason'] : json_encode($explain['dropped'], JSON_UNESCAPED_UNICODE)
            ));

            return new ClosureDispatchResult(
                status: ClosureDispatchResult::STATUS_SKIPPED,
                traceId: $event->traceId,
                scene: $event->scene,
                dropped: $explain['dropped'],
                notes: array_merge($notes, [[
                    'code'    => 'closure.not_routed',
                    'message' => $explain['reason'] !== ''
                        ? '场景未配置，闭环未触发'
                        : '全部连接器被读写/风险过滤，闭环未触发',
                ]]),
                durationMs: $this->elapsedMs($started),
            );
        }

        $eventId = 0;

        try {
            $eventId = $this->store()->createEvent($event, ClosureDispatchResult::STATUS_OK);
        } catch (\Throwable $e) {
            $notes[] = ['code' => 'closure.event_store_failed', 'message' => $e->getMessage()];
            $this->log('warning', '[Closure] 事件落库失败：' . $e->getMessage());
        }

        $deliveries = [];

        foreach ($kept as $name) {
            $result = $this->deliver($name, $event, $notes);

            $deliveries[] = $result;

            try {
                $this->store()->appendDelivery($eventId, $event->traceId, $result);
            } catch (\Throwable $e) {
                $notes[] = ['code' => 'closure.delivery_store_failed', 'message' => $e->getMessage()];
                $this->log('warning', '[Closure] 投递落库失败：' . $e->getMessage());
            }
        }

        $status = $this->aggregate($deliveries);

        if ($eventId > 0) {
            try {
                $this->store()->updateEventStatus($eventId, $status, [
                    'duration_ms' => $this->elapsedMs($started),
                    'error'       => $status === ClosureDispatchResult::STATUS_FAILED
                        ? implode(' | ', array_map(
                            static fn(ConnectorResult $r): string => $r->connector . ': ' . $r->error,
                            array_filter($deliveries, static fn(ConnectorResult $r): bool => $r->isFailed())
                        ))
                        : '',
                ]);
            } catch (\Throwable $e) {
                $notes[] = ['code' => 'closure.event_update_failed', 'message' => $e->getMessage()];
            }
        }

        $result = new ClosureDispatchResult(
            status: $status,
            traceId: $event->traceId,
            scene: $event->scene,
            eventId: $eventId,
            deliveries: $deliveries,
            dropped: $explain['dropped'],
            notes: $notes,
            durationMs: $this->elapsedMs($started),
        );

        $this->log(
            $status === ClosureDispatchResult::STATUS_OK ? 'info' : 'warning',
            '[Closure] ' . $result->summary()
        );

        return $result;
    }

    /**
     * 投递到单个连接器（**每个连接器独立隔离 + 计时**）
     *
     * @param array<int, array{code:string, message:string}> $notes
     */
    private function deliver(string $name, ClosureEvent $event, array &$notes): ConnectorResult
    {
        $connector = $this->registry()->get($name);

        if ($connector === null) {
            return ConnectorResult::skipped($name, 'connector_not_registered');
        }

        if (!$connector->supports($event)) {
            return ConnectorResult::skipped($name, 'unsupported_event');
        }

        $started = microtime(true);

        try {
            $result = $connector->dispatch($event);
        } catch (\Throwable $e) {
            return ConnectorResult::failed(
                $name,
                'connector_exception: ' . $e->getMessage(),
                $this->elapsedMs($started)
            );
        }

        $elapsed = $this->elapsedMs($started);
        $slow    = $elapsed > $this->timeoutMs();

        if ($slow) {
            $notes[] = [
                'code'    => 'closure.connector_slow',
                'message' => sprintf(
                    '连接器 `%s` 耗时 %dms，超过阈值 %dms（post-hoc 判定：PHP 同步执行无法抢占；'
                    . 'status 仍按真实结果记，避免"发成功却标记失败"引发重复投递）',
                    $name,
                    $elapsed,
                    $this->timeoutMs()
                ),
            ];
        }

        return $result->withTiming($elapsed, $slow);
    }

    /**
     * 状态聚合（**只统计"真的投过"的连接器**）
     *
     * @param ConnectorResult[] $deliveries
     */
    private function aggregate(array $deliveries): string
    {
        $attempted = array_filter($deliveries, static fn(ConnectorResult $r): bool => !$r->isSkipped());

        if ($attempted === []) {
            return ClosureDispatchResult::STATUS_SKIPPED;
        }

        $failed = count(array_filter($attempted, static fn(ConnectorResult $r): bool => $r->isFailed()));

        if ($failed === 0) {
            return ClosureDispatchResult::STATUS_OK;
        }

        return $failed === count($attempted)
            ? ClosureDispatchResult::STATUS_FAILED
            : ClosureDispatchResult::STATUS_PARTIAL;
    }

    private function elapsedMs(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }

    private function log(string $level, string $message): void
    {
        try {
            ContextualLogger::channel('ai')->{$level}($message);
        } catch (\Throwable) {
        }
    }
}
