<?php
declare(strict_types=1);

namespace wise\agent\test;

use PHPUnit\Framework\TestCase;
use wise\agent\closure\ClosureConnectorFactory;
use wise\agent\closure\ClosureConnectorInterface;
use wise\agent\closure\ClosureConnectorRegistry;
use wise\agent\closure\ClosureDispatchResult;
use wise\agent\closure\ClosureDispatcher;
use wise\agent\closure\ClosureEvent;
use wise\agent\closure\ClosureRouter;
use wise\agent\closure\ConnectorResult;
use wise\agent\closure\connector\AbstractConnector;
use wise\agent\closure\connector\AuditConnector;
use wise\agent\closure\store\ClosureTraceStoreInterface;
use wise\agent\security\CapabilityAccess;
use wise\operationlog\storage\StorageInterface;

/**
 * B2-2 回归：审计连接器 + 连接器装配（附录六 验收 4、7）
 *
 * 全部离线：存储驱动是替身，不连数据库；「零 schema 变更」用**读真实迁移文件**的方式守门。
 */
class ClosureAuditConnectorTest extends TestCase
{
    private AuditFakeStorage $storage;

    private AuditArrayStore $traceStore;

    protected function setUp(): void
    {
        $this->storage    = new AuditFakeStorage();
        $this->traceStore = new AuditArrayStore();
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function options(array $overrides = []): array
    {
        return array_merge([
            'connectors'              => ['audit'],
            'scenes'                  => ClosureRouter::DEFAULT_SCENES,
            'audit_scenes'            => ClosureRouter::DEFAULT_AUDIT_SCENES,
            'audit_write_only'        => true,
            'only_write_capabilities' => true,
            'risk_levels'             => ['medium', 'high'],
            'timeout_ms'              => 3000,
            'audit'                   => ['via' => 'storage', 'channel' => AuditConnector::DEFAULT_CHANNEL],
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function connector(array $options = [], ?AbstractConnector $custom = null): AbstractConnector
    {
        return $custom ?? new AuditConnector($options ?: $this->options(), null, $this->storage);
    }

    private function decisionEvent(string $result = ClosureEvent::RESULT_APPROVED): ClosureEvent
    {
        return new ClosureEvent(
            traceId: 'approval:AP1',
            scene: ClosureEvent::SCENE_APPROVAL_DECIDED,
            capabilityKind: 'tool',
            capabilityName: 'menu.action.host.stock.save',
            access: CapabilityAccess::WRITE,
            risk: CapabilityAccess::RISK_HIGH,
            write: true,
            result: $result,
            reason: '库存调整',
            payload: ['permission' => 'menu.action.host.stock.save', 'decider_id' => 9],
            approvalId: 42,
            actorId: 7,
            timestamp: microtime(true),
        );
    }


    public function testAuditWritesOperationLogRowWithClosureMarker(): void
    {
        $result = $this->connector()->dispatch($this->decisionEvent());

        $this->assertTrue($result->isOk(), $result->error);
        $this->assertSame(AuditConnector::DEFAULT_CHANNEL, $result->target);
        $this->assertCount(1, $this->storage->rows);

        $row = $this->storage->rows[0];

        $this->assertSame('ai.closure', $row['channel']);
        $this->assertSame('INFO', $row['level']);
        $this->assertStringContainsString('场景=approval_decided', $row['content']);
        $this->assertStringContainsString('能力=tool:menu.action.host.stock.save', $row['content']);
        $this->assertStringContainsString('结论=approved', $row['content']);
        $this->assertStringContainsString('trace=approval:AP1', $row['content']);

        $this->assertSame(AuditConnector::USER_AGENT_MARKER, $row['user_agent']);
        $this->assertSame('ai,closure,approval_decided,write,result=approved', $row['tags']);

        $extra = json_decode((string) $row['extra'], true);
        $this->assertSame('approval:AP1', $extra['trace_id']);
        $this->assertSame(42, $extra['approval_id']);
        $this->assertSame('approval_decided', $extra['scene']);
        $this->assertSame(64, strlen($extra['payload_hash']));

        $this->assertSame(7, $row['operator_id']);
        $this->assertSame('admin', $row['operator_name']);
        $this->assertNull($row['duration_ms'], '派发耗时记在投递行，不在审计行里编造');
    }

    /**
     * **零 schema 变更的守门测试**：直接读 wise-operation-log 的真实迁移文件取列清单
     */
    public function testLogRowKeysAreExactlyTheExistingColumns(): void
    {
        $columns = $this->actualOperationLogColumns();

        $this->assertNotEmpty($columns, '必须能读到 operation_log 的真实列（否则守门失效）');

        $this->connector()->dispatch($this->decisionEvent());

        $keys = array_keys($this->storage->rows[0]);

        $this->assertSame([], array_diff($keys, $columns), '写入数组里不得出现 operation_log 没有的列');
        $this->assertSame([], array_diff($columns, $keys), 'operation_log 的每一列都应被填上（缺失会落默认值）');
    }

    public function testAuditRemasksPayloadAtOutbound(): void
    {
        $event = new ClosureEvent(
            traceId: 't',
            scene: ClosureEvent::SCENE_TOOL_EXECUTED,
            capabilityKind: 'tool',
            capabilityName: 'x',
            access: CapabilityAccess::WRITE,
            write: true,
            result: ClosureEvent::RESULT_SUCCESS,
            payload: ['password' => 'p@ss', 'keyword' => '手机', 'total_tokens' => 120]
        );

        $this->connector()->dispatch($event);

        $params = json_decode((string) $this->storage->rows[0]['params'], true);

        $this->assertSame('***', $params['password'], '连接器出站前必须再脱敏一次（双保险）');
        $this->assertSame('手机', $params['keyword']);
        $this->assertSame(120, $params['total_tokens'], 'SAFE_FIELDS 豁免同样生效');
    }

    public function testLevelMapping(): void
    {
        foreach ([
            ClosureEvent::RESULT_SUCCESS  => 'INFO',
            ClosureEvent::RESULT_PENDING  => 'INFO',
            ClosureEvent::RESULT_APPROVED => 'INFO',
            ClosureEvent::RESULT_UNKNOWN  => 'INFO',
            ClosureEvent::RESULT_FAILURE  => 'WARNING',
            ClosureEvent::RESULT_REJECTED => 'WARNING',
            ClosureEvent::RESULT_CANCELLED => 'NOTICE',
        ] as $result => $level) {
            $this->storage->rows = [];

            $this->connector()->dispatch($this->decisionEvent($result));

            $this->assertSame($level, $this->storage->rows[0]['level'], '结论=' . $result);
        }
    }

    public function testAuditSupportsEveryScene(): void
    {
        $connector = $this->connector();

        foreach (ClosureEvent::SCENES as $scene) {
            $this->assertTrue(
                $connector->supports(new ClosureEvent('t', $scene)),
                $scene . '：审计认所有事件，"哪些场景要审计"由路由判定'
            );
        }
    }

    public function testMissingDownstreamIsSkippedNotFailed(): void
    {
        $connector = new AuditMissingDownstreamConnector($this->options(), null, $this->storage);

        $result = $connector->dispatch($this->decisionEvent());

        $this->assertTrue($result->isSkipped());
        $this->assertStringContainsString('不可用', $result->error);
        $this->assertSame([], $this->storage->rows, '跳过时不得写入');
    }

    public function testEventModeUsesSeamAndFlushes(): void
    {
        $connector = new AuditEventModeConnector(['audit' => ['via' => 'event']], null, $this->storage);

        $result = $connector->dispatch($this->decisionEvent());

        $this->assertTrue($result->isOk(), $result->error);
        $this->assertCount(1, $connector->eventModeRows, 'via=event ⇒ 走事件通道并**主动 flush**（常驻 worker 无 shutdown 回调）');
        $this->assertSame([], $this->storage->rows, '事件模式下不直存');
        $this->assertStringContainsString('"via":"event"', $result->response);
    }

    public function testInvalidViaIsFailedLoudly(): void
    {
        $result = (new AuditConnector(['audit' => ['via' => 'somewhere']], null, $this->storage))->dispatch($this->decisionEvent());

        $this->assertTrue($result->isFailed());
        $this->assertStringContainsString('via 取值非法', $result->error);
        $this->assertSame([], $this->storage->rows);
    }

    public function testStorageFailureIsReportedAsFailed(): void
    {
        $storage = new AuditFakeStorage();
        $storage->throwOnSave = new \RuntimeException('table missing');

        $result = (new AuditConnector($this->options(), null, $storage))->dispatch($this->decisionEvent());

        $this->assertTrue($result->isFailed());
        $this->assertStringContainsString('审计写入失败', $result->error);
        $this->assertStringContainsString('table missing', $result->error);
    }


    private function dispatcher(array $options): ClosureDispatcher
    {
        $registry = new ClosureConnectorRegistry();
        $registry->register(new AuditConnector($options, null, $this->storage));

        return new ClosureDispatcher($options, $registry, new ClosureRouter($options), $this->traceStore);
    }

    public function testWriteEventIsAuditedEndToEnd(): void
    {
        $options = $this->options();
        $result  = $this->dispatcher($options)->dispatch($this->decisionEvent());

        $this->assertSame(ClosureDispatchResult::STATUS_OK, $result->status, $result->summary());
        $this->assertCount(1, $this->storage->rows);
        $this->assertSame('approval:AP1', $this->traceStore->events[$result->eventId]['trace_id']);

        $this->assertSame(
            ['notification' => 'skipped', 'webhook' => 'skipped', 'audit' => 'ok'],
            array_column($this->traceStore->deliveries, 'status', 'connector')
        );
    }

    public function testReadEventIsNotAuditedByDefaultButIsWhenTurnedOff(): void
    {
        $read = new ClosureEvent(
            traceId: 'trace-read',
            scene: ClosureEvent::SCENE_TOOL_EXECUTED,
            capabilityKind: 'tool',
            capabilityName: 'lq_query',
            access: CapabilityAccess::READ,
            risk: CapabilityAccess::RISK_LOW,
            result: ClosureEvent::RESULT_SUCCESS,
        );

        $default = $this->dispatcher($this->options())->dispatch($read);

        $this->assertTrue($default->isSkipped());
        $this->assertSame([], $this->storage->rows);
        $this->assertSame(0, $default->eventId);

        $full = $this->dispatcher($this->options(['audit_write_only' => false]))->dispatch($read);

        $this->assertSame(ClosureDispatchResult::STATUS_OK, $full->status);
        $this->assertCount(1, $this->storage->rows);
        $this->assertSame('ai,closure,tool_executed,read,result=success', $this->storage->rows[0]['tags']);
    }

    public function testNotRegisteredConnectorsAreVisibleAsSkippedDeliveries(): void
    {
        $options = $this->options(['connectors' => ['notification', 'webhook', 'audit']]);

        $registry = new ClosureConnectorRegistry();
        $registry->register(new AuditConnector($options, null, $this->storage));

        $result = $this->dispatcher($options)->dispatch($this->decisionEvent());

        $approval = (new ClosureDispatcher($options, $registry, new ClosureRouter($options), $this->traceStore))
            ->dispatch(new ClosureEvent(
                traceId: 'approval:AP2',
                scene: ClosureEvent::SCENE_APPROVAL_REQUESTED,
                capabilityKind: 'tool',
                capabilityName: 'x',
                access: CapabilityAccess::WRITE,
                write: true,
                result: ClosureEvent::RESULT_PENDING,
                approvalId: 43,
            ));

        $this->assertSame(
            ['skipped', 'skipped'],
            array_column($approval->deliveries, 'status'),
            '未实现的连接器：明确 skipped，而不是假装投递成功'
        );
        $this->assertSame(
            ['connector_not_registered', 'connector_not_registered'],
            array_column($approval->deliveries, 'error')
        );
        $this->assertSame(ClosureDispatchResult::STATUS_OK, $result->status);
    }


    public function testFactoryRegistersOnlyImplementedConnectors(): void
    {
        $factory = new ClosureConnectorFactory($this->options(['connectors' => ['notification', 'webhook', 'audit']]));

        $this->assertSame(['notification', 'webhook', 'audit'], $factory->names());
        $this->assertSame([], $factory->unavailable());

        $missing = new ClosureConnectorFactory($this->options([
            'connectors' => ['wise\agent\closure\connector\NotThereYetConnector'],
        ]));

        $this->assertSame([], $missing->names());
        $this->assertStringContainsString('连接器类不存在', $missing->unavailable()[0]['reason']);
    }

    public function testFactoryHonoursConfiguredListAndReportsUnknownNames(): void
    {
        $this->assertSame(['audit'], (new ClosureConnectorFactory($this->options()))->names());

        $factory = new ClosureConnectorFactory($this->options(['connectors' => ['nope', 'audit']]));

        $this->assertSame(['audit'], $factory->names());
        $this->assertSame('nope', $factory->unavailable()[0]['connector']);
        $this->assertStringContainsString('未知连接器名', $factory->unavailable()[0]['reason']);
    }

    public function testFactorySupportsCustomConnectorClass(): void
    {
        $factory = new ClosureConnectorFactory($this->options(['connectors' => [AuditCustomConnector::class]]));

        $this->assertSame(['custom'], $factory->names());
        $this->assertSame([], $factory->unavailable());

        $bad = new ClosureConnectorFactory($this->options(['connectors' => [ClosureEvent::class]]));

        $this->assertSame([], $bad->names());
        $this->assertStringContainsString('未实现', $bad->unavailable()[0]['reason']);
    }

    /**
     * 从 wise-operation-log 的真实迁移里读出 `operation_log` 的列清单
     *
     * 这样"零 schema 变更"才是**可验证**的：若哪天给该表加/删列，本测试立刻能发现。
     *
     * @return string[]
     */
    private function actualOperationLogColumns(): array
    {
        $file = dirname(__DIR__, 2)
            . '/wise-operation-log/database/migrations/20251023000001_create_operation_log_table.php';

        if (!is_file($file)) {
            $this->markTestSkipped('wise-operation-log 不在工作区，无法守门；已跳过');
        }

        $source = (string) file_get_contents($file);

        preg_match_all("/addColumn\(\s*Column::[A-Za-z]+\(\s*'([a-z_]+)'/", $source, $matches);

        return $matches[1] ?? [];
    }
}


/**
 * 记录写入的假存储驱动（实现 wise-operation-log 的公共存储接口）
 */
class AuditFakeStorage implements StorageInterface
{
    /** @var array<int, array<string, mixed>> */
    public array $rows = [];

    public ?\Throwable $throwOnSave = null;

    public function save(array $log): void
    {
        if ($this->throwOnSave !== null) {
            throw $this->throwOnSave;
        }

        $this->rows[] = $log;
    }

    public function batchSave(array $logs): void
    {
        foreach ($logs as $log) {
            $this->save($log);
        }
    }
}

/**
 * 强制"下游缺失"的审计连接器
 */
class AuditMissingDownstreamConnector extends AuditConnector
{
    protected function requiredClasses(): array
    {
        return ['wise\operationlog\not\installed\Storage' => 'wise-operation-log 存储驱动'];
    }
}

/**
 * 把"事件通道"换成一个记录接缝（离线验证 via=event 的分支）
 */
class AuditEventModeConnector extends AuditConnector
{
    /** @var array<int, array<string, mixed>> */
    public array $eventModeRows = [];

    protected function saveViaEvent(array $logData): void
    {
        $this->eventModeRows[] = $logData;
    }
}

/**
 * 自定义连接器（验证装配支持类名）
 */
class AuditCustomConnector implements ClosureConnectorInterface
{
    /**
     * @param array<string, mixed> $options
     */
    public function __construct(private array $options = [], private mixed $masker = null)
    {
    }

    public function name(): string
    {
        return 'custom';
    }

    public function supports(ClosureEvent $event): bool
    {
        return true;
    }

    public function dispatch(ClosureEvent $event): ConnectorResult
    {
        return ConnectorResult::ok('custom', 'nowhere', 'ok');
    }
}

/**
 * 内存轨迹仓储
 */
class AuditArrayStore implements ClosureTraceStoreInterface
{
    /** @var array<int, array<string, mixed>> */
    public array $events = [];

    /** @var array<int, array<string, mixed>> */
    public array $deliveries = [];

    private int $eventSeq = 0;

    private int $deliverySeq = 0;

    public function createEvent(ClosureEvent $event, string $status): int
    {
        $id = ++$this->eventSeq;

        $this->events[$id] = $event->toArray() + [
            'event_id'    => $id,
            'status'      => $status,
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

    public function appendDelivery(int $eventId, string $traceId, ConnectorResult $result): int
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

    public function findFailedDeliveries(int $limit = 50): array
    {
        return array_slice(array_values(array_filter(
            $this->deliveries,
            static fn(array $d): bool => ($d['status'] ?? '') === ConnectorResult::STATUS_FAILED
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
        return array_values(array_filter($this->events, static fn(array $e): bool => $e['trace_id'] === $traceId));
    }

    public function findDeliveries(string $traceId, ?string $status = null, int $limit = 200): array
    {
        return array_values(array_filter(
            $this->deliveries,
            static fn(array $d): bool => $d['trace_id'] === $traceId && ($status === null || $d['status'] === $status)
        ));
    }

    public function findDelivery(int $deliveryId): ?array
    {
        return $this->deliveries[$deliveryId] ?? null;
    }

    public function listEvents(array $filter = [], int $limit = 20): array
    {
        return array_slice(array_values($this->events), 0, $limit);
    }
}
