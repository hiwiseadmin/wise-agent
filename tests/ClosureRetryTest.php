<?php
declare(strict_types=1);

namespace wise\agent\test;

use PHPUnit\Framework\TestCase;
use wise\agent\closure\ClosureConnectorInterface;
use wise\agent\closure\ClosureConnectorRegistry;
use wise\agent\closure\ClosureDispatcher;
use wise\agent\closure\ClosureEvent;
use wise\agent\closure\ClosureRouter;
use wise\agent\closure\ConnectorResult;
use wise\agent\security\CapabilityAccess;

require_once __DIR__ . '/support/ClosureMemoryStore.php';

/**
 * B2-4 回归：补投与清理＼�
 *
 * 关键性质：补投是**原样重投同一个连接器**（不重新路由），且**只补投 failed**。
 */
class ClosureRetryTest extends TestCase
{
    private \ClosureMemoryStore $store;

    protected function setUp(): void
    {
        $this->store = new \ClosureMemoryStore();
    }

    private function event(): ClosureEvent
    {
        return new ClosureEvent(
            traceId: 'approval:AP1',
            scene: ClosureEvent::SCENE_APPROVAL_DECIDED,
            capabilityKind: 'tool',
            capabilityName: 'menu.action.host.stock.save',
            access: CapabilityAccess::WRITE,
            risk: CapabilityAccess::RISK_HIGH,
            write: true,
            result: ClosureEvent::RESULT_APPROVED,
            payload: ['decider_id' => 9],
            approvalId: 42,
            actorId: 7,
        );
    }

    /**
     * @param array<string, ClosureConnectorInterface> $connectors
     */
    private function dispatcher(array $connectors): ClosureDispatcher
    {
        $registry = new ClosureConnectorRegistry();

        foreach ($connectors as $connector) {
            $registry->register($connector);
        }

        return new ClosureDispatcher(['connectors' => array_keys($connectors)], $registry, new ClosureRouter([]), $this->store);
    }

    private function seedFailedDelivery(): array
    {
        $eventId = $this->store->createEvent($this->event(), 'failed');

        $deliveryId = $this->store->appendDelivery(
            $eventId,
            'approval:AP1',
            ConnectorResult::failed('notification', '网关超时')
        );

        return ['event_id' => $eventId, 'delivery_id' => $deliveryId];
    }

    public function testRetryReInvokesSameConnectorAndAppendsDeliveryRow(): void
    {
        $seed     = $this->seedFailedDelivery();
        $eventRow = $this->store->findEvent($seed['event_id']);
        $this->assertIsArray($eventRow);

        $event = ClosureEvent::fromArray($eventRow);

        $this->assertSame('approval:AP1', $event->traceId);
        $this->assertSame(['decider_id' => 9], $event->payload, '重建后载荷一致 ⇒ 补投发的是同一份数据');

        $connector = new RetryRecordingConnector('notification');
        $result    = $this->dispatcher(['notification' => $connector])
            ->retryDelivery('notification', $event, (int) $seed['event_id']);

        $this->assertTrue($result->isOk(), $result->error);
        $this->assertSame(['approval:AP1'], $connector->seen);
        $this->assertCount(2, $this->store->deliveries, '补投**新增一行**（原失败行保留 —— 那就是补投历史）');
        $this->assertSame(
            [ConnectorResult::STATUS_FAILED, ConnectorResult::STATUS_OK],
            array_column($this->store->deliveries, 'status')
        );
    }

    public function testRetryDoesNotReRouteByScene(): void
    {
        $seed  = $this->seedFailedDelivery();
        $event = ClosureEvent::fromArray($this->store->findEvent((int) $seed['event_id']));

        $connector = new RetryRecordingConnector('notification');

        $this->dispatcher(['notification' => $connector])->retryDelivery('notification', $event, (int) $seed['event_id']);

        $this->assertCount(1, $connector->seen, '补投按"原连接器"重投，不重新走场景路由（否则会变成"补 A 却发给 B"）');
    }

    public function testRetryWithUnregisteredConnectorIsSkippedNotCrashed(): void
    {
        $seed  = $this->seedFailedDelivery();
        $event = ClosureEvent::fromArray($this->store->findEvent((int) $seed['event_id']));

        $result = $this->dispatcher([])->retryDelivery('notification', $event, (int) $seed['event_id']);

        $this->assertTrue($result->isSkipped());
        $this->assertSame('connector_not_registered', $result->error);
        $this->assertCount(2, $this->store->deliveries, '补投尝试本身也要留痕');
    }

    public function testRetryFailureIsRecordedNotThrown(): void
    {
        $seed  = $this->seedFailedDelivery();
        $event = ClosureEvent::fromArray($this->store->findEvent((int) $seed['event_id']));

        $connector = new RetryRecordingConnector('notification');
        $connector->throw = new \RuntimeException('still down');

        $result = $this->dispatcher(['notification' => $connector])->retryDelivery('notification', $event, (int) $seed['event_id']);

        $this->assertTrue($result->isFailed());
        $this->assertStringContainsString('still down', $result->error);
        $this->assertCount(2, $this->store->deliveries);
    }

    public function testFindFailedDeliveriesOnlyReturnsFailed(): void
    {
        $eventId = $this->store->createEvent($this->event(), 'partial');

        $this->store->appendDelivery($eventId, 'approval:AP1', ConnectorResult::ok('audit'));
        $this->store->appendDelivery($eventId, 'approval:AP1', ConnectorResult::skipped('webhook', '未配置端点'));
        $failedId = $this->store->appendDelivery($eventId, 'approval:AP1', ConnectorResult::failed('notification', '网关超时'));

        $rows = $this->store->findFailedDeliveries();

        $this->assertCount(1, $rows, 'skipped 不该被补投（它代表"本就不该投"）');
        $this->assertSame($failedId, (int) $rows[0]['delivery_id']);
    }

    public function testPruneRefusesNothingButDeletesByTime(): void
    {
        $seed = $this->seedFailedDelivery();

        $deleted = $this->store->pruneBefore(date('Y-m-d H:i:s', time() + 3600));

        $this->assertSame(['events' => 1, 'deliveries' => 1], $deleted);
        $this->assertNull($this->store->findEvent((int) $seed['event_id']));
        $this->assertSame([], $this->store->deliveries);

        $this->seedFailedDelivery();

        $this->assertSame(['events' => 0, 'deliveries' => 0], $this->store->pruneBefore('2000-01-01 00:00:00'));
        $this->assertCount(1, $this->store->events);
    }
}


class RetryRecordingConnector implements ClosureConnectorInterface
{
    /** @var string[] */
    public array $seen = [];

    public ?\Throwable $throw = null;

    public function __construct(private string $connectorName)
    {
    }

    public function name(): string
    {
        return $this->connectorName;
    }

    public function supports(ClosureEvent $event): bool
    {
        return true;
    }

    public function dispatch(ClosureEvent $event): ConnectorResult
    {
        $this->seen[] = $event->traceId;

        if ($this->throw !== null) {
            throw $this->throw;
        }

        return ConnectorResult::ok($this->connectorName, 'target', 'ok');
    }
}
