<?php
declare(strict_types=1);

namespace wise\agent\test;

use PHPUnit\Framework\TestCase;
use wise\agent\closure\ClosureEvent;
use wise\agent\closure\ClosurePlanResumer;
use wise\agent\closure\store\ClosurePlanLinkInterface;
use wise\agent\plan\PlanExecutionResult;
use wise\agent\plan\PlanExecutor;
use wise\agent\plan\PlanRun;
use wise\agent\security\CapabilityAccess;

/**
 * B2-4 回归：批准后自动续跑（附录六 验收 8）
 *
 * 五道判定逐条覆盖：**每条不满足都必须给出可读原因且不执行**。
 */
class ClosurePlanResumerTest extends TestCase
{
    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function options(array $overrides = []): array
    {
        return array_merge(['auto_resume_plans' => true], $overrides);
    }

    private function event(string $result = ClosureEvent::RESULT_APPROVED, int $actorId = 7, int $approvalId = 42): ClosureEvent
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
            approvalId: $approvalId,
            actorId: $actorId,
            actorType: 'admin',
        );
    }

    /**
     * @param array<string, mixed>|null $link
     */
    private function resumer(array $options, ?array $link, ?PlanExecutor $executor = null): ClosurePlanResumer
    {
        return new ClosurePlanResumer($options, new FakeClosurePlanLink($link), $executor ?? new RecordingPlanExecutor());
    }

    /**
     * @return array<string, mixed> 一条"可续跑"的关联
     */
    private function resumableLink(): array
    {
        return [
            'run_id'    => 15,
            'status'    => PlanRun::STATUS_PAUSED,
            'user_id'   => 7,
            'step_id'   => 's2',
            'step_no'   => 2,
            'arguments' => ['product_id' => 7, 'qty' => 10],
        ];
    }


    public function testDisabledByDefaultAndSaysSo(): void
    {
        $executor = new RecordingPlanExecutor();
        $outcome  = $this->resumer([], $this->resumableLink(), $executor)->maybeResume($this->event());

        $this->assertFalse($outcome['handled']);
        $this->assertFalse($outcome['resumed']);
        $this->assertStringContainsString('auto_resume_plans=false', $outcome['reason']);
        $this->assertSame([], $executor->resumed, '默认关：绝不动手');
    }

    public function testOnlyApprovedDecisionTriggers(): void
    {
        foreach ([ClosureEvent::RESULT_REJECTED, ClosureEvent::RESULT_PENDING, ClosureEvent::RESULT_UNKNOWN] as $result) {
            $outcome = $this->resumer($this->options(), $this->resumableLink())->maybeResume($this->event($result));

            $this->assertFalse($outcome['handled'], '结论=' . $result);
        }
    }

    public function testMissingApprovalIdIsReported(): void
    {
        $outcome = $this->resumer($this->options(), $this->resumableLink())->maybeResume($this->event(ClosureEvent::RESULT_APPROVED, 7, 0));

        $this->assertTrue($outcome['handled']);
        $this->assertFalse($outcome['resumed']);
        $this->assertStringContainsString('没有 approval_id', $outcome['reason']);
    }

    public function testNonPlanApprovalIsNotAHandledCase(): void
    {
        $outcome = $this->resumer($this->options(), null)->maybeResume($this->event());

        $this->assertFalse($outcome['handled'], '绝大多数审批都不关计划 ⇒ 不该拿原因去打扰用户');
        $this->assertStringContainsString('未关联任何计划步骤', $outcome['reason']);
    }


    public function testNonPausedRunIsNotResumedWithReason(): void
    {
        $executor = new RecordingPlanExecutor();
        $link     = $this->resumableLink();
        $link['status'] = PlanRun::STATUS_COMPLETED;

        $outcome = $this->resumer($this->options(), $link, $executor)->maybeResume($this->event());

        $this->assertTrue($outcome['handled']);
        $this->assertFalse($outcome['resumed']);
        $this->assertStringContainsString('completed', $outcome['reason']);
        $this->assertStringContainsString('#15', $outcome['reason']);
        $this->assertSame([], $executor->resumed);
    }

    public function testDifferentRequesterIsRefused(): void
    {
        $executor = new RecordingPlanExecutor();
        $link     = $this->resumableLink();
        $link['user_id'] = 9;

        $outcome = $this->resumer($this->options(), $link, $executor)->maybeResume($this->event(ClosureEvent::RESULT_APPROVED, 7));

        $this->assertTrue($outcome['handled']);
        $this->assertFalse($outcome['resumed']);
        $this->assertStringContainsString('不同人', $outcome['reason']);
        $this->assertStringContainsString('消费不到', $outcome['reason']);
        $this->assertSame([], $executor->resumed);
    }

    public function testStepWithoutPersistedArgumentsIsRefused(): void
    {
        $executor = new RecordingPlanExecutor();
        $link     = $this->resumableLink();
        $link['arguments'] = [];

        $outcome = $this->resumer($this->options(), $link, $executor)->maybeResume($this->event());

        $this->assertTrue($outcome['handled']);
        $this->assertFalse($outcome['resumed']);
        $this->assertStringContainsString('没有持久化参数', $outcome['reason']);
        $this->assertSame([], $executor->resumed, '参数漂移会导致审批单消费不到 ⇒ 宁可不动手');
    }


    public function testResumesPausedRunOfSameRequester(): void
    {
        $executor = new RecordingPlanExecutor();
        $executor->next = new PlanExecutionResult(status: PlanRun::STATUS_COMPLETED, runId: 15, completed: 2);

        $outcome = $this->resumer($this->options(), $this->resumableLink(), $executor)->maybeResume($this->event());

        $this->assertTrue($outcome['handled']);
        $this->assertTrue($outcome['resumed']);
        $this->assertSame(15, $outcome['run_id']);
        $this->assertSame([15], $executor->resumed, '走的是与人工 plan_resume 完全相同的方法');
        $this->assertSame(7, $executor->contexts[0]['user_id'], '以审批单申请人的身份续跑（审批单按申请人匹配）');
        $this->assertSame('closure_auto_resume', $executor->contexts[0]['source']);
        $this->assertStringContainsString('completed', $outcome['reason']);
    }

    public function testResumeOutcomeIsReportedHonestlyWhenNotCompleted(): void
    {
        $executor = new RecordingPlanExecutor();
        $executor->next = new PlanExecutionResult(status: PlanRun::STATUS_PAUSED, runId: 15, paused: true, error: null);

        $outcome = $this->resumer($this->options(), $this->resumableLink(), $executor)->maybeResume($this->event());

        $this->assertTrue($outcome['handled']);
        $this->assertFalse($outcome['resumed'], '续跑后仍暂停（例如又撞上另一个审批）⇒ 不算成功');
        $this->assertStringContainsString('paused', $outcome['reason']);
    }

    /**
     * 两层兜底的分工（实现里刻意做成两层，这里把它钉住）：
     *
     * - **内层**（`DbClosurePlanLink::findPausedRunByApproval()`）自己 try/catch，
     *   把查询失败折算成「没有关联」⇒ 生产环境下这里表现为"不是计划类审批"；
     * - **外层**（本类 `maybeResume()`）兜住**任何其它**异常（例如续跑本身炸了），
     *   并给出可读原因 —— 它挂在审批决策路径上，绝不能抛。
     *
     * 本用例注入的是"会抛异常的 link 替身"，因此命中的是**外层**兜底。
     */
    public function testThrowingLinkIsCaughtByOuterNet(): void
    {
        $resumer = new ClosurePlanResumer($this->options(), new ThrowingClosurePlanLink(), new RecordingPlanExecutor());

        $outcome = $resumer->maybeResume($this->event());

        $this->assertTrue($outcome['handled']);
        $this->assertFalse($outcome['resumed']);
        $this->assertStringContainsString('已兜住', $outcome['reason']);
        $this->assertStringContainsString('query failed', $outcome['reason']);
    }

    public function testExecutorExceptionIsCaughtAndReported(): void
    {
        $executor = new RecordingPlanExecutor();
        $executor->throw = new \RuntimeException('db down');

        $outcome = $this->resumer($this->options(), $this->resumableLink(), $executor)->maybeResume($this->event());

        $this->assertTrue($outcome['handled']);
        $this->assertFalse($outcome['resumed']);
        $this->assertStringContainsString('已兜住', $outcome['reason'], '它挂在审批决策路径上，绝不能抛');
    }
}


class FakeClosurePlanLink implements ClosurePlanLinkInterface
{
    /**
     * @param array<string, mixed>|null $row
     */
    public function __construct(private ?array $row = null)
    {
    }

    public function findPausedRunByApproval(int $approvalId): ?array
    {
        return $this->row;
    }
}

class ThrowingClosurePlanLink implements ClosurePlanLinkInterface
{
    public function findPausedRunByApproval(int $approvalId): ?array
    {
        throw new \RuntimeException('query failed');
    }
}

class RecordingPlanExecutor extends PlanExecutor
{
    /** @var int[] */
    public array $resumed = [];

    /** @var array<int, array<string, mixed>> */
    public array $contexts = [];

    public ?PlanExecutionResult $next = null;

    public ?\Throwable $throw = null;

    public function resume(int $runId, array $context = []): PlanExecutionResult
    {
        if ($this->throw !== null) {
            throw $this->throw;
        }

        $this->resumed[]  = $runId;
        $this->contexts[] = $context;

        return $this->next ?? new PlanExecutionResult(status: PlanRun::STATUS_COMPLETED, runId: $runId, completed: 1);
    }
}
