<?php
declare(strict_types=1);

namespace wise\agent\test;

use PHPUnit\Framework\TestCase;
use wise\agent\closure\ClosureDispatchResult;
use wise\agent\closure\ClosureDispatcher;
use wise\agent\closure\ClosureEvent;
use wise\agent\closure\ClosureEventFactory;
use wise\agent\closure\ClosureConnectorInterface;
use wise\agent\closure\ClosureConnectorRegistry;
use wise\agent\closure\ClosureRouter;
use wise\agent\closure\store\ClosureTraceStoreInterface;
use wise\agent\closure\ConnectorResult;
use wise\agent\closure\PayloadMasker;
use wise\agent\event\AgentComplete;
use wise\agent\event\ApprovalDecided;
use wise\agent\event\ApprovalRequested;
use wise\agent\event\ToolExecute;
use wise\agent\security\CapabilityAccess;
use wise\agent\tool\BaseTool;
use wise\agent\tool\ToolRegistry;

/**
 * B2-1 回归：闭环事件归一化 + 场景路由 + 隔离派发 + 留痕（附录六 验收 1、3）
 *
 * 全部离线：连接器与仓储都是替身，不需要通知/webhook 实例，也不需要数据库。
 */
class ClosureDispatcherTest extends TestCase
{
    private const WRITE_TOOL = 'closure_test.write_tool';

    private ClosureArrayStore $store;

    protected function setUp(): void
    {
        $this->store = new ClosureArrayStore();
    }

    protected function tearDown(): void
    {
        ToolRegistry::instance()->unregister(self::WRITE_TOOL);
    }

    /**
     * 注册一个「写 + 高危」的假工具（用于验证 access/risk/write 的推导）
     */
    private function registerWriteTool(): void
    {
        ToolRegistry::instance()->register(new ClosureWriteFakeTool(), self::WRITE_TOOL, 'builtin');
    }

    /**
     * @param array<string, mixed> $options
     */
    private function dispatcher(
        array $options = [],
        ?ClosureConnectorRegistry $registry = null,
        ?ClosureTraceStoreInterface $store = null
    ): ClosureDispatcher {
        return new ClosureDispatcher(
            $options,
            $registry ?? new ClosureConnectorRegistry(),
            new ClosureRouter($options),
            $store ?? $this->store
        );
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function options(array $overrides = []): array
    {
        return array_merge([
            'scenes'                  => ClosureRouter::DEFAULT_SCENES,
            'audit_scenes'            => ClosureRouter::DEFAULT_AUDIT_SCENES,
            'only_write_capabilities' => true,
            'audit_write_only'        => true,
            'risk_levels'             => ['medium', 'high'],
            'timeout_ms'              => 3000,
        ], $overrides);
    }

    private function approvalRequested(): ApprovalRequested
    {
        return new ApprovalRequested(
            approvalId: 42,
            ident: 'AP20261016001',
            capabilityKind: 'tool',
            capabilityName: self::WRITE_TOOL,
            permission: 'menu.action.host.stock.save',
            access: 'write',
            risk: 'high',
            mode: 'workflow',
            rule: 'access',
            reason: '写操作需审批',
            requesterId: 7,
            requesterType: 'admin',
        );
    }


    public function testApprovalRequestedIsNormalized(): void
    {
        $event = (new ClosureEventFactory($this->options()))->fromApprovalRequested($this->approvalRequested());

        $this->assertSame('approval:AP20261016001', $event->traceId, '同一次审批的申请与决策共用一个 trace');
        $this->assertSame(ClosureEvent::SCENE_APPROVAL_REQUESTED, $event->scene);
        $this->assertSame('tool', $event->capabilityKind);
        $this->assertSame(self::WRITE_TOOL, $event->capabilityName);
        $this->assertSame(CapabilityAccess::WRITE, $event->access);
        $this->assertSame(CapabilityAccess::RISK_HIGH, $event->risk);
        $this->assertTrue($event->write, 'access=write ⇒ write=true');
        $this->assertSame(ClosureEvent::RESULT_PENDING, $event->result);
        $this->assertSame(42, $event->approvalId);
        $this->assertSame(7, $event->actorId);
        $this->assertSame('写操作需审批', $event->reason);
        $this->assertSame('menu.action.host.stock.save', $event->payload['permission']);
        $this->assertSame('workflow', $event->payload['mode']);
    }

    public function testApprovalDecidedKeepsDecisionAndLeavesUnknownFieldsEmpty(): void
    {
        $factory = new ClosureEventFactory($this->options());

        foreach (['approved', 'rejected', 'cancelled'] as $decision) {
            $event = $factory->fromApprovalDecided(new ApprovalDecided(
                approvalId: 42,
                ident: 'AP20261016001',
                decision: $decision,
                capabilityName: self::WRITE_TOOL,
                permission: 'menu.action.host.stock.save',
                requesterId: 7,
                deciderId: 9,
                note: '处理完毕',
            ));

            $this->assertSame($decision, $event->result, '决策结论原样保留（不映射成 success/failure）');
            $this->assertSame('approval:AP20261016001', $event->traceId, '与申请共用 trace，回放能串成一条线');
            $this->assertSame('', $event->access, '该事件不带 access ⇒ 留空，不编造');
            $this->assertSame('', $event->risk, '该事件不带 risk ⇒ 留空');
            $this->assertFalse($event->write);
            $this->assertSame(9, $event->payload['decider_id']);
        }

        $unknown = $factory->fromApprovalDecided(new ApprovalDecided(1, 'AP2', 'deadlocked', 'x', 'p'));

        $this->assertSame(ClosureEvent::RESULT_UNKNOWN, $unknown->result, '未知决策值 ⇒ unknown（不猜）');
    }

    public function testToolExecuteAfterIsNormalizedWithCapabilityDescriptor(): void
    {
        $this->registerWriteTool();

        $event = (new ClosureEventFactory($this->options()))->fromToolExecute(
            new ToolExecute(ToolExecute::PHASE_AFTER, self::WRITE_TOOL, ['product_id' => 7], '{"code":0,"msg":"ok"}'),
            ['trace_id' => 'trace-1', 'user_id' => 7, 'session_id' => 'S1']
        );

        $this->assertSame('trace-1', $event->traceId, '上下文注入的 trace 优先');
        $this->assertSame(ClosureEvent::SCENE_TOOL_EXECUTED, $event->scene);
        $this->assertSame(CapabilityAccess::WRITE, $event->access, 'access 来自 AccessResolver（与授权/审批同源）');
        $this->assertSame(CapabilityAccess::RISK_HIGH, $event->risk);
        $this->assertTrue($event->write);
        $this->assertSame(ClosureEvent::RESULT_SUCCESS, $event->result);
        $this->assertSame(7, $event->actorId);
        $this->assertSame('S1', $event->sessionId);
    }

    public function testUnknownCapabilityLeavesAccessAndRiskEmpty(): void
    {
        $event = (new ClosureEventFactory($this->options()))->fromToolExecute(
            new ToolExecute(ToolExecute::PHASE_AFTER, 'not.registered.tool', [], '{"code":0}')
        );

        $this->assertSame('', $event->access, '未注册能力 ⇒ access 留空（不猜）');
        $this->assertSame('', $event->risk);
        $this->assertFalse($event->write);
        $this->assertStringStartsWith('cl_', $event->traceId, '无上下文时生成兜底 trace');
    }

    public function testToolOutcomeParsingCoversEnvelopeAndBlockedPayloads(): void
    {
        $factory = new ClosureEventFactory($this->options());

        $this->assertSame(ClosureEvent::RESULT_SUCCESS, $factory->outcomeOf('{"code":0}')['result']);
        $this->assertSame(ClosureEvent::RESULT_FAILURE, $factory->outcomeOf('{"code":1,"msg":"字段缺失"}')['result']);
        $this->assertSame('字段缺失', $factory->outcomeOf('{"code":1,"msg":"字段缺失"}')['reason']);

        $pending = $factory->outcomeOf((string) json_encode([
            'error'           => "Tool 'x' requires approval before execution",
            'required_action' => 'workflow',
            'approval_status' => 'pending',
            'approval_id'     => 88,
            'reason'          => '写操作需审批',
        ], JSON_UNESCAPED_UNICODE));

        $this->assertSame(ClosureEvent::RESULT_PENDING, $pending['result'], '被审批拦住 ⇒ pending（不是失败）');
        $this->assertSame(88, $pending['approval_id'], '能把审批单号带进闭环事件');

        $rejected = $factory->outcomeOf('{"required_action":"rejected","approval_status":"rejected","reason":"线下确认"}');
        $this->assertSame(ClosureEvent::RESULT_REJECTED, $rejected['result']);

        $denied = $factory->outcomeOf('{"error":"Permission denied: x","source":"rbac","access":"write"}');
        $this->assertSame(ClosureEvent::RESULT_FAILURE, $denied['result'], '权限拒绝 ⇒ failure');

        $this->assertSame(ClosureEvent::RESULT_UNKNOWN, $factory->outcomeOf('纯文本结果')['result'], '非契约 ⇒ unknown');
        $this->assertSame(ClosureEvent::RESULT_UNKNOWN, $factory->outcomeOf('')['result']);
    }

    public function testToolExecuteBeforeAndUnknownEventsAreIgnored(): void
    {
        $factory = new ClosureEventFactory($this->options());

        $this->assertNull(
            $factory->fromEvent(new ToolExecute(ToolExecute::PHASE_BEFORE, 'x', [], null)),
            'BEFORE 阶段归 ToolSecurityCheck 管，B2 不插手'
        );
        $this->assertNull($factory->fromEvent(new \stdClass()), '未知事件 ⇒ null（不抛异常）');

        $this->assertNull(
            $this->dispatcher($this->options())->dispatchEvent(new ToolExecute(ToolExecute::PHASE_BEFORE, 'x')),
            '事件入口对 BEFORE 也返回 null'
        );
    }

    public function testAgentCompleteUsesSessionAsTrace(): void
    {
        $event = (new ClosureEventFactory($this->options()))->fromEvent(
            new AgentComplete('S-9', '完成', 3, ['total_tokens' => 120], 1.5)
        );

        $this->assertNotNull($event);
        $this->assertSame('agent:S-9', $event->traceId);
        $this->assertSame(ClosureEvent::SCENE_AGENT_COMPLETED, $event->scene);
        $this->assertSame(ClosureEvent::RESULT_SUCCESS, $event->result);
        $this->assertSame(3, $event->payload['steps']);
        $this->assertSame(120, $event->payload['total_tokens']);
        $this->assertSame(1.5, $event->payload['duration']);
    }

    public function testPayloadIsMaskedBeforeItCanReachStorage(): void
    {
        $event = (new ClosureEventFactory($this->options()))->fromToolExecute(
            new ToolExecute(ToolExecute::PHASE_AFTER, 'lq_query', [
                'keyword'  => '手机',
                'password' => 'p@ssw0rd',
                'nested'   => ['access_token' => 'abc', 'keep' => 'ok'],
                'items'    => [['phone' => '13800000000']],
            ], '{"code":0}')
        );

        $this->assertSame('手机', $event->payload['arguments']['keyword'], '非敏感字段保留');
        $this->assertSame(PayloadMasker::MASK, $event->payload['arguments']['password']);
        $this->assertSame(PayloadMasker::MASK, $event->payload['arguments']['nested']['access_token']);
        $this->assertSame('ok', $event->payload['arguments']['nested']['keep']);
        $this->assertSame(PayloadMasker::MASK, $event->payload['arguments']['items'][0]['phone']);
    }

    public function testPayloadHashIsStableRegardlessOfKeyOrder(): void
    {
        $a = new ClosureEvent('t', ClosureEvent::SCENE_TOOL_EXECUTED, payload: ['a' => 1, 'b' => 2]);
        $b = new ClosureEvent('t', ClosureEvent::SCENE_TOOL_EXECUTED, payload: ['b' => 2, 'a' => 1]);

        $this->assertSame($a->payloadHash(), $b->payloadHash());
        $this->assertSame(64, strlen($a->payloadHash()));
    }


    public function testReadOnlyToolIsNotRoutedByDefault(): void
    {
        $router = new ClosureRouter($this->options());

        $read = new ClosureEvent('t', ClosureEvent::SCENE_TOOL_EXECUTED, access: CapabilityAccess::READ, risk: 'low');

        $this->assertSame([], $router->connectorsFor($read), '读操作默认不触发闭环（避免一次对话刷出几十条通知）');
        $this->assertSame('not_consequential', $router->explain($read)['dropped'][0]['reason']);
    }

    public function testUnknownAccessIsConservativelyIncluded(): void
    {
        $router = new ClosureRouter($this->options());

        $unknown = new ClosureEvent('t', ClosureEvent::SCENE_TOOL_EXECUTED, capabilityKind: 'tool', capabilityName: 'x');

        $this->assertSame(['audit'], $router->connectorsFor($unknown), 'access 未知 ⇒ 保守纳入（漏审计代价更高）');
        $this->assertContains('closure.access_unknown', array_column($router->explain($unknown)['notes'], 'code'));
    }

    public function testAuditIsIndependentOfRiskFilter(): void
    {
        $router = new ClosureRouter($this->options(['risk_levels' => ['high']]));

        $lowRiskWrite = new ClosureEvent(
            't',
            ClosureEvent::SCENE_APPROVAL_DECIDED,
            capabilityKind: 'tool',
            capabilityName: 'x',
            access: CapabilityAccess::WRITE,
            risk: CapabilityAccess::RISK_LOW,
            write: true,
            result: ClosureEvent::RESULT_APPROVED
        );

        $this->assertSame(
            ['audit'],
            $router->connectorsFor($lowRiskWrite),
            '风险不达标的写操作：不发通知/webhook，但**仍要审计**（审计不该被风险阈值关掉）'
        );
        $this->assertSame(
            ['notification', 'webhook'],
            array_column($router->explain($lowRiskWrite)['dropped'], 'connector')
        );
    }

    public function testAuditWriteOnlyCanBeTurnedOffForFullAudit(): void
    {
        $read = new ClosureEvent('t', ClosureEvent::SCENE_TOOL_EXECUTED, access: CapabilityAccess::READ, risk: 'low');

        $this->assertSame([], (new ClosureRouter($this->options()))->connectorsFor($read));

        $full = new ClosureRouter($this->options(['audit_write_only' => false, 'only_write_capabilities' => false]));

        $this->assertSame(['audit'], $full->connectorsFor($read), '显式开启后可获得"全量审计"');
    }

    public function testUnconfiguredSceneNeverTriggers(): void
    {
        $router  = new ClosureRouter(['scenes' => []]);
        $event   = new ClosureEvent('t', ClosureEvent::SCENE_APPROVAL_REQUESTED, access: 'write');
        $explain = $router->explain($event);

        $this->assertSame([], $explain['kept']);
        $this->assertSame('scene_not_configured', $explain['reason']);
        $this->assertContains('closure.scene_not_configured', array_column($explain['notes'], 'code'));
    }


    public function testConnectorExceptionIsIsolatedAndNeverEscapes(): void
    {
        $registry = new ClosureConnectorRegistry();
        $registry->register(new ClosureFakeConnector('notification', throw: new \RuntimeException('下游炸了')));
        $registry->register(new ClosureFakeConnector('webhook'));

        $result = $this->dispatcher($this->options(), $registry)->dispatchEvent(new ApprovalRequested(
            approvalId: 42, ident: 'AP1', capabilityKind: 'tool', capabilityName: self::WRITE_TOOL,
            permission: 'p', access: 'write', risk: 'high', mode: 'workflow', rule: 'access',
            reason: '写操作需审批', requesterId: 7
        ));

        $this->assertNotNull($result);
        $this->assertSame(ClosureDispatchResult::STATUS_PARTIAL, $result->status);
        $this->assertSame(1, $result->failedCount());
        $this->assertSame(1, $result->okCount());
        $this->assertSame(['notification'], $result->failedConnectors());
        $this->assertStringContainsString('下游炸了', $result->deliveries[0]->error);
        $this->assertTrue($result->deliveries[1]->isOk(), '前一个连接器失败不影响后一个');
    }

    public function testAllFailedIsReportedWithReasons(): void
    {
        $registry = new ClosureConnectorRegistry();
        $registry->register(new ClosureFakeConnector('notification', ok: false));
        $registry->register(new ClosureFakeConnector('webhook', ok: false));

        $result = $this->dispatcher($this->options(), $registry)->dispatchEvent($this->approvalRequested());

        $this->assertNotNull($result);
        $this->assertSame(ClosureDispatchResult::STATUS_FAILED, $result->status);
        $this->assertSame(2, $result->failedCount());
        $this->assertStringContainsString('notification: boom', $this->store->events[$result->eventId]['error']);
    }

    public function testUnregisteredAndUnsupportedConnectorsAreSkippedNotFailed(): void
    {
        $registry = new ClosureConnectorRegistry();
        $registry->register(new ClosureFakeConnector('notification', support: false));

        $result = $this->dispatcher($this->options(), $registry)->dispatchEvent($this->approvalRequested());

        $this->assertNotNull($result);
        $this->assertTrue($result->isSkipped(), '全部 skipped ⇒ 事件状态 skipped（不是 failed）');
        $this->assertSame(0, $result->failedCount(), '"没触发"与"触发失败"必须能区分');
        $this->assertSame(2, $result->skippedCount());
        $this->assertGreaterThan(0, $result->eventId, '被路由到过连接器 ⇒ 仍要留痕');
        $this->assertSame(
            ['unsupported_event', 'connector_not_registered'],
            array_map(static fn(ConnectorResult $r): string => $r->error, $result->deliveries)
        );
    }

    public function testSlowConnectorIsFlaggedButStatusStaysOk(): void
    {
        $registry = new ClosureConnectorRegistry();
        $registry->register(new ClosureFakeConnector('notification', sleepMs: 30));

        $result = $this->dispatcher($this->options(['timeout_ms' => 5]), $registry)->dispatchEvent($this->approvalRequested());

        $this->assertNotNull($result);
        $this->assertSame(ClosureDispatchResult::STATUS_OK, $result->status, '慢 ≠ 失败（否则补投会造成重复发送）');
        $this->assertTrue($result->deliveries[0]->slow);
        $this->assertGreaterThan(
            5,
            $result->deliveries[0]->durationMs,
            '计时只需证明超过了阈值（不依赖 usleep 的精确睡眠时长）'
        );
        $this->assertContains('closure.connector_slow', array_column($result->notes, 'code'));
    }

    public function testEventAndDeliveriesArePersistedWithTrace(): void
    {
        $registry = new ClosureConnectorRegistry();
        $registry->register(new ClosureFakeConnector('notification'));
        $registry->register(new ClosureFakeConnector('webhook'));

        $result = $this->dispatcher($this->options(), $registry)->dispatchEvent($this->approvalRequested());

        $this->assertNotNull($result);
        $this->assertGreaterThan(0, $result->eventId);

        $event = $this->store->events[$result->eventId];

        $this->assertSame('approval:AP20261016001', $event['trace_id']);
        $this->assertSame(ClosureDispatchResult::STATUS_OK, $event['status']);
        $this->assertSame(64, strlen($event['payload_hash']));

        $deliveries = array_values($this->store->deliveries);

        $this->assertCount(2, $deliveries);
        $this->assertSame(
            ['notification', 'webhook'],
            array_column($deliveries, 'connector')
        );
        $this->assertSame($result->eventId, $deliveries[0]['event_id']);
        $this->assertSame('approval:AP20261016001', $deliveries[0]['trace_id']);
    }

    public function testNotRoutedEventIsNotPersistedAtAll(): void
    {
        $readTool = 'closure_test.read_tool';
        ToolRegistry::instance()->register(new ClosureReadFakeTool(), $readTool, 'builtin');

        try {
            $result = $this->dispatcher($this->options())->dispatchEvent(
                new ToolExecute(ToolExecute::PHASE_AFTER, $readTool, [], '{"code":0}'),
                ['trace_id' => 'trace-read']
            );
        } finally {
            ToolRegistry::instance()->unregister($readTool);
        }

        $this->assertNotNull($result);
        $this->assertTrue($result->isSkipped());
        $this->assertSame(0, $result->eventId, '只读查询不该在审计表里留行');
        $this->assertSame([], $this->store->events);
        $this->assertSame([], $this->store->deliveries);
        $this->assertContains('closure.not_routed', array_column($result->notes, 'code'));
    }

    public function testStoreFailureDoesNotBlockDispatch(): void
    {
        $store = new ClosureArrayStore();
        $store->throwOnCreate = new \RuntimeException('db down');

        $registry = new ClosureConnectorRegistry();
        $registry->register(new ClosureFakeConnector('notification'));

        $result = $this->dispatcher($this->options(), $registry, $store)->dispatchEvent($this->approvalRequested());

        $this->assertNotNull($result);
        $this->assertSame(ClosureDispatchResult::STATUS_OK, $result->status, '记不下日志不该影响投递本身');
        $this->assertSame(0, $result->eventId);
        $this->assertContains('closure.event_store_failed', array_column($result->notes, 'code'));
    }

    public function testDispatcherCatchesEvenCatastrophicFailures(): void
    {
        $dispatcher = new ClosureCatastrophicDispatcher($this->options(), new ClosureConnectorRegistry(), null, $this->store);

        $result = $dispatcher->dispatch(new ClosureEvent('t', ClosureEvent::SCENE_APPROVAL_REQUESTED, access: 'write'));

        $this->assertSame(ClosureDispatchResult::STATUS_FAILED, $result->status);
        $this->assertStringContainsString('dispatcher_exception', $result->error);
    }

    public function testRegistryBasicsAndOverride(): void
    {
        $registry = new ClosureConnectorRegistry();

        $registry->register(new ClosureFakeConnector('audit'));
        $registry->register(new ClosureFakeConnector('notification'));
        $registry->register(new ClosureFakeConnector('audit', ok: false));

        $this->assertSame(['audit', 'notification'], $registry->names(), '注册顺序保持，同名以最后一次为准');
        $this->assertTrue($registry->has('audit'));
        $this->assertSame(2, $registry->count());
        $this->assertFalse($registry->get('audit')->dispatch(new ClosureEvent('t', 'x'))->isOk(), '覆盖生效');
        $this->assertNull($registry->get('nope'));
    }
}


/**
 * 「写 + 高危」假工具（用于验证 access/risk/write 推导链路）
 */
class ClosureWriteFakeTool extends BaseTool
{
    public function getName(): string
    {
        return 'write_tool';
    }

    public function getDescription(): string
    {
        return '写工具（测试替身）';
    }

    /**
     * @return array<string, mixed>
     */
    public function getParameters(): array
    {
        return ['type' => 'object', 'properties' => ['product_id' => ['type' => 'integer']]];
    }

    public function getAccess(): string
    {
        return CapabilityAccess::WRITE;
    }

    public function getRiskLevel(): string
    {
        return CapabilityAccess::RISK_HIGH;
    }

    public function execute(array $arguments): string
    {
        return '{"code":0,"msg":"ok"}';
    }
}

/**
 * 「只读」假工具（验证读操作不被路由）
 */
class ClosureReadFakeTool extends BaseTool
{
    public function getName(): string
    {
        return 'read_tool';
    }

    public function getDescription(): string
    {
        return '只读工具（测试替身）';
    }

    /**
     * @return array<string, mixed>
     */
    public function getParameters(): array
    {
        return ['type' => 'object', 'properties' => []];
    }

    public function getAccess(): string
    {
        return CapabilityAccess::READ;
    }

    public function getRiskLevel(): string
    {
        return CapabilityAccess::RISK_LOW;
    }

    public function execute(array $arguments): string
    {
        return '{"code":0,"msg":"ok"}';
    }
}

/**
 * 脚本化连接器
 */
class ClosureFakeConnector implements ClosureConnectorInterface
{
    /** @var string[] 收到的事件 trace id */
    public array $seen = [];

    public function __construct(
        private string $connectorName,
        private ?\Throwable $throw = null,
        private bool $support = true,
        private bool $ok = true,
        private int $sleepMs = 0
    ) {
    }

    public function name(): string
    {
        return $this->connectorName;
    }

    public function supports(ClosureEvent $event): bool
    {
        return $this->support;
    }

    public function dispatch(ClosureEvent $event): ConnectorResult
    {
        $this->seen[] = $event->traceId;

        if ($this->sleepMs > 0) {
            usleep($this->sleepMs * 1000);
        }

        if ($this->throw !== null) {
            throw $this->throw;
        }

        return $this->ok
            ? ConnectorResult::ok($this->connectorName, 'target-' . $this->connectorName, 'resp')
            : ConnectorResult::failed($this->connectorName, 'boom');
    }
}

/**
 * 内存仓储
 */
class ClosureArrayStore implements ClosureTraceStoreInterface
{
    /** @var array<int, array<string, mixed>> */
    public array $events = [];

    /** @var array<int, array<string, mixed>> */
    public array $deliveries = [];

    public ?\Throwable $throwOnCreate = null;

    private int $eventSeq = 0;

    private int $deliverySeq = 0;

    public function createEvent(ClosureEvent $event, string $status): int
    {
        if ($this->throwOnCreate !== null) {
            throw $this->throwOnCreate;
        }

        $id = ++$this->eventSeq;

        $this->events[$id] = $event->toArray() + [
            'event_id'     => $id,
            'status'       => $status,
            'payload_hash' => $event->payloadHash(),
            'duration_ms'  => 0,
            'error'        => '',
            'create_time'  => date('Y-m-d H:i:s'),
        ];

        return $id;
    }

    public function updateEventStatus(int $eventId, string $status, array $fields = []): void
    {
        if (!isset($this->events[$eventId])) {
            return;
        }

        $this->events[$eventId] = array_merge($this->events[$eventId], $fields, ['status' => $status]);
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
        return array_values(array_filter(
            $this->events,
            static fn(array $e): bool => $e['trace_id'] === $traceId
        ));
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

/**
 * 故意在路由阶段炸掉的派发器（验证兜底不抛）
 */
class ClosureCatastrophicDispatcher extends ClosureDispatcher
{
    protected function router(): ClosureRouter
    {
        return new class extends ClosureRouter {
            public function explain(ClosureEvent $event): array
            {
                throw new \RuntimeException('router exploded');
            }
        };
    }
}
