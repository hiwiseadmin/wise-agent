<?php
declare(strict_types=1);

namespace wise\agent\test;

use PHPUnit\Framework\TestCase;
use wise\agent\closure\ClosureDispatchResult;
use wise\agent\closure\ClosureDispatcher;
use wise\agent\closure\ClosureEvent;
use wise\agent\closure\ClosureEventFactory;
use wise\agent\closure\ClosureListener;
use wise\agent\closure\ClosurePlanResumer;
use wise\agent\event\AgentComplete;
use wise\agent\event\ApprovalDecided;
use wise\agent\event\ApprovalRequested;
use wise\agent\event\ToolExecute;

require_once __DIR__ . '/support/ClosureMemoryStore.php';

/**
 * B2-4 回归：事件接线＼�
 *
 * 离线：派发器与自动续跑都是替身，只验证「监听器把什么交给了谁」。
 */
class ClosureListenerTest extends TestCase
{
    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function options(array $overrides = []): array
    {
        return array_merge(['enabled' => true], $overrides);
    }

    private function listener(
        array $options,
        RecordingClosureDispatcher $dispatcher,
        ?ClosurePlanResumer $resumer = null
    ): ClosureListener {
        return new ClosureListener($options, $dispatcher, new ClosureEventFactory($options), $resumer);
    }

    private function approvalRequested(): ApprovalRequested
    {
        return new ApprovalRequested(
            approvalId: 42,
            ident: 'AP1',
            capabilityKind: 'tool',
            capabilityName: 'menu.action.host.stock.save',
            permission: 'p',
            access: 'write',
            risk: 'high',
            mode: 'workflow',
            rule: 'access',
            reason: '写操作需审批',
            requesterId: 7,
            requesterType: 'admin',
        );
    }

    public function testDisabledListenerIsFullyInert(): void
    {
        $dispatcher = new RecordingClosureDispatcher();

        $this->listener(['enabled' => false], $dispatcher)->handle($this->approvalRequested());

        $this->assertTrue($dispatcher->notCalled(), '关闭时零开销：不构建事件、不碰数据库');
    }

    public function testApprovalRequestedIsNormalizedAndDispatched(): void
    {
        $dispatcher = new RecordingClosureDispatcher();

        $this->listener($this->options(), $dispatcher)->handle($this->approvalRequested());

        $this->assertCount(1, $dispatcher->events);

        $event = $dispatcher->events[0];

        $this->assertSame(ClosureEvent::SCENE_APPROVAL_REQUESTED, $event->scene);
        $this->assertSame('approval:AP1', $event->traceId);
        $this->assertSame(42, $event->approvalId);
        $this->assertSame(7, $event->actorId, '发起人来自事件（不是请求上下文）');
        $this->assertSame('admin', $event->actorType);
        $this->assertTrue($event->write);
    }

    public function testToolExecuteBeforeIsIgnoredButAfterIsDispatched(): void
    {
        $dispatcher = new RecordingClosureDispatcher();
        $listener   = $this->listener($this->options(), $dispatcher);

        $listener->handle(new ToolExecute(ToolExecute::PHASE_BEFORE, 'some.tool', ['a' => 1], null));
        $this->assertTrue($dispatcher->notCalled(), 'BEFORE 归 ToolSecurityCheck，闭环不插手');

        $listener->handle(new ToolExecute(ToolExecute::PHASE_AFTER, 'some.tool', ['a' => 1], '{"code":0}'));
        $this->assertCount(1, $dispatcher->events);
        $this->assertSame(ClosureEvent::SCENE_TOOL_EXECUTED, $dispatcher->events[0]->scene);
        $this->assertSame(ClosureEvent::RESULT_SUCCESS, $dispatcher->events[0]->result);
    }

    public function testAgentCompleteUsesSessionTrace(): void
    {
        $dispatcher = new RecordingClosureDispatcher();

        $this->listener($this->options(), $dispatcher)->handle(
            new AgentComplete('S-9', '完成', 3, ['total_tokens' => 120], 1.5)
        );

        $this->assertSame('agent:S-9', $dispatcher->events[0]->traceId);
        $this->assertSame(ClosureEvent::SCENE_AGENT_COMPLETED, $dispatcher->events[0]->scene);
    }

    public function testApprovedDecisionCarriesResumeOutcomeIntoPayload(): void
    {
        $dispatcher = new RecordingClosureDispatcher();
        $resumer    = new RecordingClosureResumer([
            'handled' => true,
            'resumed' => false,
            'reason'  => '计划运行 #15 的状态是 completed（不是 paused），不自动续跑',
            'run_id'  => 15,
        ]);

        $this->listener($this->options(), $dispatcher, $resumer)->handle(
            new ApprovalDecided(42, 'AP1', 'approved', 'menu.action.host.stock.save', 'p', 7, 9, '同意')
        );

        $this->assertCount(1, $resumer->calls, '只有"审批通过"才尝试续跑');
        $this->assertSame(
            '计划运行 #15 的状态是 completed（不是 paused），不自动续跑',
            $dispatcher->events[0]->payload['resume']['reason'] ?? null,
            '续跑结论必须进入载荷 —— 这样同一条通知就能把原因告诉申请人（验收 8）'
        );
    }

    public function testRejectedDecisionNeverTouchesResumer(): void
    {
        $dispatcher = new RecordingClosureDispatcher();
        $resumer    = new RecordingClosureResumer();

        $this->listener($this->options(), $dispatcher, $resumer)->handle(
            new ApprovalDecided(42, 'AP1', 'rejected', 'x', 'p', 7, 9, '不批')
        );

        $this->assertSame([], $resumer->calls);
        $this->assertArrayNotHasKey('resume', $dispatcher->events[0]->payload);
        $this->assertSame(ClosureEvent::RESULT_REJECTED, $dispatcher->events[0]->result);
    }

    public function testUnhandledResumeOutcomeIsNotAttached(): void
    {
        $dispatcher = new RecordingClosureDispatcher();
        $resumer    = new RecordingClosureResumer([
            'handled' => false,
            'resumed' => false,
            'reason'  => '该审批单未关联任何计划步骤',
            'run_id'  => 0,
        ]);

        $this->listener($this->options(), $dispatcher, $resumer)->handle(
            new ApprovalDecided(42, 'AP1', 'approved', 'x', 'p', 7, 9, '同意')
        );

        $this->assertArrayNotHasKey('resume', $dispatcher->events[0]->payload);
    }

    public function testListenerNeverThrowsEvenIfDispatcherExplodes(): void
    {
        $dispatcher = new RecordingClosureDispatcher();
        $dispatcher->throw = new \RuntimeException('dispatcher exploded');

        $this->listener($this->options(), $dispatcher)->handle(
            new ToolExecute(ToolExecute::PHASE_AFTER, 'x', [], '{"code":0}')
        );

        $this->assertCount(1, $dispatcher->events, '异常被兜住，主链路不受影响');
    }

    public function testEventListIsTheSingleSourceOfTruth(): void
    {
        $this->assertSame([
            ApprovalRequested::class,
            ApprovalDecided::class,
            ToolExecute::class,
            AgentComplete::class,
        ], ClosureListener::events(), '注册与 discover 都读这一份清单，避免两处漂移');
    }
}


class RecordingClosureDispatcher extends ClosureDispatcher
{
    /** @var ClosureEvent[] */
    public array $events = [];

    public ?\Throwable $throw = null;

    public function dispatch(ClosureEvent $event): ClosureDispatchResult
    {
        $this->events[] = $event;

        if ($this->throw !== null) {
            throw $this->throw;
        }

        return new ClosureDispatchResult(ClosureDispatchResult::STATUS_OK, $event->traceId, $event->scene, 1);
    }

    public function notCalled(): bool
    {
        return $this->events === [];
    }
}

class RecordingClosureResumer extends ClosurePlanResumer
{
    /** @var ClosureEvent[] */
    public array $calls = [];

    /** @var array{handled:bool, resumed:bool, reason:string, run_id:int} */
    private array $outcome;

    /**
     * @param array{handled:bool, resumed:bool, reason:string, run_id:int} $outcome
     */
    public function __construct(array $outcome = ['handled' => false, 'resumed' => false, 'reason' => '', 'run_id' => 0])
    {
        parent::__construct([]);
        $this->outcome = $outcome;
    }

    public function maybeResume(ClosureEvent $event): array
    {
        $this->calls[] = $event;

        return $this->outcome;
    }
}
