<?php
declare(strict_types=1);

namespace wise\agent\test;

use PHPUnit\Framework\TestCase;
use wise\agent\plan\PlanDefinition;
use wise\agent\plan\PlanExecutionResult;
use wise\agent\plan\PlanExecutor;
use wise\agent\plan\PlanPlanResult;
use wise\agent\plan\PlanPlanner;
use wise\agent\plan\PlanRun;
use wise\agent\plan\PlanStepRun;
use wise\agent\plan\PlanVerifier;
use wise\agent\plan\store\PlanRunStoreInterface;
use wise\agent\tool\ToolRegistry;

/**
 * B1-3 回归：计划执行器Ｇ行」、5「Verify 三分支」、6「三重上限」、7「可观测」）
 *
 * 全部离线：能力分发走 `invoke*()` 接缝、落库走内存仓储、改道走脚本化规划器。
 */
class PlanExecutorTest extends TestCase
{
    private ArrayPlanRunStore $store;

    protected function setUp(): void
    {
        $this->store = new ArrayPlanRunStore();
    }

    private function executor(array $options = []): TestablePlanExecutor
    {
        return new TestablePlanExecutor($options, $this->store, new PlanVerifier($options));
    }

    /**
     * @param array<int, array<string, mixed>> $steps
     * @param array<string, mixed>             $limits
     */
    private function plan(array $steps, array $limits = []): PlanDefinition
    {
        return PlanDefinition::fromArray(['goal' => '盘点库存并生成补货建议', 'steps' => $steps, 'limits' => $limits]);
    }

    /**
     * @param array<string, mixed> $input
     * @param array<int, array<string, mixed>> $verify
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function step(string $id, string $kind, string $target, array $input = [], array $verify = [], array $extra = []): array
    {
        return array_merge([
            'id'     => $id,
            'kind'   => $kind,
            'target' => $target,
            'label'  => $id,
            'input'  => $input,
            'verify' => $verify,
        ], $extra);
    }

    private function readJson(string $data = '{"list":[{"id":7}],"total":3}', int $code = 0): string
    {
        return (string) json_encode(['code' => $code, 'msg' => 'ok', 'data' => json_decode($data, true)], JSON_UNESCAPED_UNICODE);
    }

    /** 审批闸门拦住时的载荷（与 ToolSecurityCheck::blockApproval 同结构） */
    private function pendingPayload(int $approvalId = 42): string
    {
        return (string) json_encode([
            'error'           => "Tool 'x' requires approval before execution",
            'required_action' => 'workflow',
            'approval_status' => 'pending',
            'approval_id'     => $approvalId,
            'approval_ident'  => 'AP1',
            'reason'          => '写操作需审批',
        ], JSON_UNESCAPED_UNICODE);
    }


    public function testRunsAllFourKindsAndRecordsEverything(): void
    {
        $executor = $this->executor();
        $executor->responses['lq_query'] = $this->readJson();
        $result   = $executor->execute($this->plan([
            $this->step('s1', 'tool', 'lq_query', ['target' => 'smartstock.product'], [['type' => 'count_gte', 'path' => 'data.total', 'value' => 0]]),
            $this->step('s2', 'action', 'menu.action.host.stock.save', ['product_id' => 7, 'qty' => 10], [['type' => 'tool_ok']]),
            $this->step('s3', 'skill', 'system.notify', ['to' => '采购']),
            $this->step('s4', 'node', 'plugin:demo:notify', ['channel' => 'mail']),
        ]));

        $this->assertTrue($result->isCompleted(), $result->summary());
        $this->assertSame(PlanRun::STATUS_COMPLETED, $result->status);
        $this->assertSame(4, $result->completed);
        $this->assertSame(0, $result->failed);
        $this->assertFalse($result->paused);
        $this->assertSame([], array_keys(array_filter($result->outputs, static fn($o): bool => $o === null)));

        $this->assertSame(
            [['tool', 'lq_query'], ['action', 'menu.action.host.stock.save'], ['skill', 'system.notify'], ['node', 'plugin:demo:notify']],
            array_map(static fn(array $c): array => [$c['kind'], $c['target']], $executor->calls)
        );

        $steps = $this->store->findSteps($result->runId);
        $this->assertCount(4, $steps);
        $this->assertSame(
            [PlanStepRun::STATUS_COMPLETED, PlanStepRun::STATUS_COMPLETED, PlanStepRun::STATUS_COMPLETED, PlanStepRun::STATUS_COMPLETED],
            array_map(static fn(PlanStepRun $s): string => $s->status, $steps)
        );
        $this->assertSame(['s1', 's2', 's3', 's4'], array_map(static fn(PlanStepRun $s): string => $s->stepId, $steps));
        $this->assertTrue($steps[1]->write, 'action 步骤按能力 access 推导为写步骤');

        $run = $this->store->findRun($result->runId);
        $this->assertNotNull($run);
        $this->assertSame(PlanRun::STATUS_COMPLETED, $run->status);
        $this->assertSame(4, $run->currentStep);
        $this->assertSame(4, $run->totalSteps);

        $this->assertSame([1, 2, 3, 4], $executor->heartbeats);
        $this->assertCount(1, $executor->finishes);
        $this->assertNull($executor->finishes[0]['error']);
    }

    public function testArgumentsAreResolvedAndSnapshottedAfterResolution(): void
    {
        $executor = $this->executor();
        $executor->responses['lq_query'] = $this->readJson();

        $result   = $executor->execute($this->plan([
            $this->step('s1', 'tool', 'lq_query', ['target' => 'smartstock.product']),
            $this->step('s2', 'tool', 'menu.action.host.stock.save', [
                'product_id' => '$steps.s1.data.list.0.id',
                'qty'        => '$input.qty',
                'note'       => '固定文本',
            ], [['type' => 'tool_ok']]),
        ], [], ), ['qty' => 10]);

        $this->assertTrue($result->isCompleted(), $result->summary());

        $this->assertSame(
            ['product_id' => 7, 'qty' => 10, 'note' => '固定文本'],
            $executor->calls[1]['arguments'],
            '引用必须解析为真实值'
        );

        $snapshot = $this->store->findSteps($result->runId)[1]->arguments;
        $this->assertSame(7, $snapshot['product_id'], '落库的是**解析后**的参数（续跑要靠它重建上下文）');
    }


    public function testVerifyFailureAbortsByDefault(): void
    {
        $executor = $this->executor();
        $executor->responses['lq_query'] = $this->readJson();

        $result = $executor->execute($this->plan([
            $this->step('s1', 'tool', 'lq_query', [], [['type' => 'count_gte', 'path' => 'data.total', 'value' => 99]]),
        ]));

        $this->assertTrue($result->isAborted());
        $this->assertSame(1, $result->failed);
        $this->assertStringContainsString(PlanExecutor::ABORT_STEP_FAILED, (string) $result->error);
        $this->assertStringContainsString('计数 3 < 期望 99', (string) $result->error);

        $this->assertSame(PlanRun::STATUS_ABORTED, $this->store->findRun($result->runId)->status);
        $this->assertSame(PlanStepRun::STATUS_FAILED, $this->store->findSteps($result->runId)[0]->status);
    }

    public function testRetryStepBranchSucceedsOnSecondAttempt(): void
    {
        $executor = $this->executor();
        $executor->responses['menu.action.host.stock.save'] = static fn(array $args, int $attempt): string => $attempt === 1
            ? '{"code":1,"msg":"库存服务忙"}'
            : '{"code":0,"msg":"ok"}';

        $result = $executor->execute($this->plan([
            $this->step('s1', 'tool', 'menu.action.host.stock.save', ['product_id' => 1, 'qty' => 1], [['type' => 'tool_ok']], ['retries' => 1]),
        ]));

        $this->assertTrue($result->isCompleted(), $result->summary());
        $this->assertSame(2, $executor->callsCount('menu.action.host.stock.save'));
        $this->assertSame(2, $this->store->findSteps($result->runId)[0]->attempts);
        $this->assertContains('plan.retry', array_column($result->trace, 'step'));
    }

    public function testRetryBudgetExhaustedAborts(): void
    {
        $executor = $this->executor();
        $executor->responses['menu.action.host.stock.save'] = '{"code":1,"msg":"一直失败"}';

        $result = $executor->execute($this->plan([
            $this->step('s1', 'tool', 'menu.action.host.stock.save', ['product_id' => 1], [['type' => 'tool_ok']], ['retries' => 2]),
        ]));

        $this->assertTrue($result->isAborted());
        $this->assertSame(3, $executor->callsCount('menu.action.host.stock.save'), '1 + retries 次尝试');
        $this->assertSame(3, $this->store->findSteps($result->runId)[0]->attempts);
    }

    public function testReplanBranchReplacesRemainingStepsWithValidatedPlan(): void
    {
        $executor = $this->executor();
        $executor->responses['menu.action.host.stock.save'] = '{"code":1,"msg":"库存服务不可用"}';
        $executor->responses['skill.retry_later']         = '{"code":0}';

        $planner = new StubPlanner();
        $planner->script[] = new PlanPlanResult(
            ok: true,
            plan: $this->plan([
                $this->step('r1', 'skill', 'skill.retry_later', ['reason' => '$input.completed.s1']),
            ]),
            attempts: 1,
            usage: ['total_tokens' => 120],
        );
        $executor->stubPlanner = $planner;

        $result = $executor->execute($this->plan([
            $this->step('s1', 'tool', 'lq_query', []),
            $this->step('s2', 'tool', 'menu.action.host.stock.save', ['product_id' => 1], [['type' => 'tool_ok']], ['on_verify_fail' => 'replan']),
        ]));

        $this->assertTrue($result->isCompleted(), $result->summary());
        $this->assertSame(1, $result->replans);
        $this->assertSame(120, $result->tokens);
        $this->assertContains('plan.replanned', array_column($result->trace, 'step'));
        $this->assertSame(1, $executor->callsCount('skill.retry_later'), '改道后的新步骤被真正执行');

        $this->assertStringContainsString('库存服务不可用', $planner->captured[0]['goal']);
        $this->assertArrayHasKey('completed', $planner->captured[0]['inputs']);
        $this->assertArrayHasKey('s1', $planner->captured[0]['inputs']['completed']);

        $steps = $this->store->findSteps($result->runId);
        $this->assertCount(2, $steps, '第 2 步被改道产物替换');
        $this->assertSame('r1', $steps[1]->stepId);
    }

    public function testReplanBudgetExhaustedAbortsWithoutCallingPlanner(): void
    {
        $executor = $this->executor();
        $executor->responses['menu.action.host.stock.save'] = '{"code":1,"msg":"失败"}';

        $planner = new StubPlanner();
        $executor->stubPlanner = $planner;

        $result = $executor->execute($this->plan([
            $this->step('s1', 'tool', 'menu.action.host.stock.save', ['product_id' => 1], [['type' => 'tool_ok']], ['on_verify_fail' => 'replan']),
        ], ['max_replans' => 0]));

        $this->assertTrue($result->isAborted());
        $this->assertSame(0, $result->replans);
        $this->assertSame([], $planner->captured, '改道预算为 0 时不得调用规划器');
    }

    public function testReplanUnavailableAbortsHonestly(): void
    {
        $executor = $this->executor();
        $executor->responses['menu.action.host.stock.save'] = '{"code":1,"msg":"失败"}';
        $executor->plannerUnavailable = true;

        $result = $executor->execute($this->plan([
            $this->step('s1', 'tool', 'menu.action.host.stock.save', ['product_id' => 1], [['type' => 'tool_ok']], ['on_verify_fail' => 'replan']),
        ]));

        $this->assertTrue($result->isAborted());
        $this->assertContains('plan.replan_unavailable', array_column($result->trace, 'step'));
    }

    public function testReplanIsNotUsedToBypassHumanRejection(): void
    {
        $executor = $this->executor();
        $executor->responses['menu.action.host.stock.save'] = (string) json_encode([
            'required_action' => 'rejected',
            'approval_status' => 'rejected',
            'reason'          => '库存调整需线下确认',
        ], JSON_UNESCAPED_UNICODE);

        $planner = new StubPlanner();
        $executor->stubPlanner = $planner;

        $result = $executor->execute($this->plan([
            $this->step('s1', 'tool', 'menu.action.host.stock.save', ['product_id' => 1], [['type' => 'tool_ok']], ['on_verify_fail' => 'replan']),
        ]));

        $this->assertTrue($result->isAborted());
        $this->assertStringContainsString(PlanExecutor::ABORT_REJECTED, (string) $result->error);
        $this->assertSame([], $planner->captured, '人工驳回后**不得**自动改道绕过');
    }


    public function testApprovalPendingPausesRunWithoutConsumingRetries(): void
    {
        $executor = $this->executor();
        $executor->responses['menu.action.host.stock.save'] = $this->pendingPayload(42);

        $result = $executor->execute($this->plan([
            $this->step('s1', 'tool', 'lq_query', []),
            $this->step('s2', 'tool', 'menu.action.host.stock.save', ['product_id' => 7, 'qty' => 10], [['type' => 'tool_ok']], ['retries' => 3]),
            $this->step('s3', 'skill', 'system.notify', ['to' => '采购']),
        ]));

        $this->assertTrue($result->paused);
        $this->assertSame(PlanRun::STATUS_PAUSED, $result->status);
        $this->assertFalse($result->isAborted(), '等待审批不是失败');
        $this->assertSame(1, $result->completed);

        $blocked = $result->blockedStep();
        $this->assertNotNull($blocked);
        $this->assertSame(2, $blocked->stepNo);
        $this->assertSame(42, $blocked->approvalId);
        $this->assertSame(1, $blocked->attempts, '等待审批不消耗重试预算（retries=3 仍为 1 次尝试）');

        $this->assertSame(0, $executor->callsCount('system.notify'), '暂停后不得继续执行后续步骤');

        $run = $this->store->findRun($result->runId);
        $this->assertSame(PlanRun::STATUS_PAUSED, $run->status);
        $this->assertSame(2, $run->currentStep);

        $this->assertContains('plan.awaiting_approval', array_column($result->notes, 'code'));
    }

    public function testPermissionDeniedIsAFailureNotAPause(): void
    {
        $executor = $this->executor();
        $executor->responses['menu.action.host.stock.save'] = (string) json_encode([
            'error'  => 'Permission denied: menu.action.host.stock.save',
            'tool'   => 'menu.action.host.stock.save',
            'reason' => 'no permission',
            'source' => 'rbac',
            'access' => 'write',
        ], JSON_UNESCAPED_UNICODE);

        $result = $executor->execute($this->plan([
            $this->step('s1', 'tool', 'menu.action.host.stock.save', ['product_id' => 1], [['type' => 'tool_ok']]),
        ]));

        $this->assertTrue($result->isAborted());
        $this->assertFalse($result->paused);
        $this->assertStringContainsString('permission_denied', (string) $result->error);
    }


    public function testTooManyStepsLimitAborts(): void
    {
        $executor = $this->executor();

        $result = $executor->execute($this->plan([
            $this->step('s1', 'tool', 'lq_query', []),
            $this->step('s2', 'tool', 'lq_query', []),
        ], ['max_steps' => 1]));

        $this->assertTrue($result->isAborted());
        $this->assertStringContainsString(PlanExecutor::ABORT_TOO_MANY_STEPS, (string) $result->error);
        $this->assertSame(1, $executor->callsCount('lq_query'), '超限时不执行第 2 步');
    }

    public function testPlanDurationLimitAborts(): void
    {
        $executor = $this->executor();

        $result = $executor->execute($this->plan(
            [$this->step('s1', 'tool', 'lq_query', [])],
            ['max_plan_duration' => 0]
        ));

        $this->assertTrue($result->isAborted());
        $this->assertStringContainsString(PlanExecutor::ABORT_PLAN_TIMEOUT, (string) $result->error);
        $this->assertSame([], $executor->calls, '时长超限时不得调用任何能力');
    }

    public function testStepTimeoutIsDetectedPostHocAndFailsTheStep(): void
    {
        $executor = $this->executor();

        $result = $executor->execute($this->plan(
            [$this->step('s1', 'tool', 'lq_query', [])],
            ['max_step_duration' => 0]
        ));

        $this->assertTrue($result->isAborted());
        $this->assertStringContainsString(PlanExecutor::ABORT_STEP_TIMEOUT, (string) $result->error);
        $this->assertStringContainsString('post-hoc', (string) $result->error);
        $this->assertSame(1, $executor->callsCount('lq_query'), '调用已经发生（无法抢占），故为事后判定');
    }

    public function testTokenLimitAbortsAfterReplan(): void
    {
        $executor = $this->executor();
        $executor->responses['menu.action.host.stock.save'] = '{"code":1,"msg":"失败"}';
        $executor->responses['skill.retry_later']         = '{"code":0}';

        $planner = new StubPlanner();
        $planner->script[] = new PlanPlanResult(
            ok: true,
            plan: $this->plan([$this->step('r1', 'skill', 'skill.retry_later', [])]),
            usage: ['total_tokens' => 5000],
        );
        $executor->stubPlanner = $planner;

        $result = $executor->execute($this->plan([
            $this->step('s1', 'tool', 'lq_query', []),
            $this->step('s2', 'tool', 'menu.action.host.stock.save', ['product_id' => 1], [['type' => 'tool_ok']], ['on_verify_fail' => 'replan']),
        ], ['max_plan_tokens' => 100]));

        $this->assertTrue($result->isAborted());
        $this->assertStringContainsString(PlanExecutor::ABORT_TOKENS, (string) $result->error);
        $this->assertSame(1, $result->replans);
        $this->assertSame(0, $executor->callsCount('skill.retry_later'), '超 token 预算后不再执行改道产物');
    }


    public function testConditionSkipsStepWithoutCallingIt(): void
    {
        $executor = $this->executor();
        $executor->responses['lq_query'] = $this->readJson('{"list":[],"total":null}');

        $result = $executor->execute($this->plan([
            $this->step('s1', 'tool', 'lq_query', []),
            $this->step('s2', 'skill', 'system.notify', [], [], ['condition' => '$steps.s1.data.total != null']),
        ]));

        $this->assertTrue($result->isCompleted(), $result->summary());
        $this->assertSame(0, $executor->callsCount('system.notify'));
        $this->assertSame(PlanStepRun::STATUS_SKIPPED, $this->store->findSteps($result->runId)[1]->status);
        $this->assertContains('plan.skip', array_column($result->trace, 'step'));
    }

    public function testResumeRestartsFromBlockedStepWithIdenticalArguments(): void
    {
        $executor = $this->executor();
        $executor->responses['lq_query']                   = $this->readJson();
        $executor->responses['menu.action.host.stock.save'] = $this->pendingPayload(77);

        $first = $executor->execute($this->plan([
            $this->step('s1', 'tool', 'lq_query', []),
            $this->step('s2', 'tool', 'menu.action.host.stock.save', ['product_id' => '$steps.s1.data.list.0.id', 'qty' => 10], [['type' => 'tool_ok']]),
        ]));

        $this->assertTrue($first->paused);
        $blockedArguments = $executor->calls[1]['arguments'];

        $executor->responses['menu.action.host.stock.save'] = '{"code":0,"msg":"ok"}';
        $resumed = $executor->resume($first->runId);

        $this->assertTrue($resumed->isCompleted(), $resumed->summary());
        $this->assertSame(1, $executor->callsCount('lq_query'), '已完成的步骤**不得**被重跑（否则重复副作用）');
        $this->assertSame(2, $executor->callsCount('menu.action.host.stock.save'), '阻塞步骤被重跑一次');
        $this->assertSame(
            $blockedArguments,
            $executor->calls[2]['arguments'],
            '续跑必须重发同一份参数（审批单按参数指纹匹配，参数漂移会导致「批了也消费不到」）'
        );
        $this->assertSame(PlanRun::STATUS_COMPLETED, $this->store->findRun($first->runId)->status);
    }

    public function testResumeOnMissingRunReportsHonestly(): void
    {
        $result = $this->executor()->resume(999);

        $this->assertTrue($result->isAborted());
        $this->assertSame('plan_run_not_found', $result->error);
        $this->assertSame(999, $result->runId, '如实回报被请求的运行号（不伪造）');
        $this->assertSame([], $result->steps);
    }


    public function testSystemContextWritePlanIsRejectedBeforeAnyStepRuns(): void
    {
        $executor = $this->executor();

        $result = $executor->execute($this->plan([
            $this->step('s1', 'tool', 'lq_query', []),
            $this->step('s2', 'tool', 'menu.action.host.stock.save', ['product_id' => 1], [['type' => 'tool_ok']]),
        ]), [], ['user_id' => 0]);

        $this->assertTrue($result->isAborted());
        $this->assertStringContainsString(PlanExecutor::ABORT_SYSTEM_CONTEXT, (string) $result->error);
        $this->assertSame([], $executor->calls, '拒绝时不得调用任何能力');
        $this->assertSame([], $this->store->runs, '也不得留下注定不执行的运行记录');
        $this->assertContains('plan.guard_rejected', array_column($result->trace, 'step'));
    }

    public function testSystemContextReadOnlyPlanStillRuns(): void
    {
        $executor = $this->executor();
        $executor->responses['lq_query'] = $this->readJson();

        $result = $executor->execute(
            $this->plan([$this->step('s1', 'tool', 'lq_query', [])]),
            [],
            ['user_id' => 0]
        );

        $this->assertTrue($result->isCompleted(), $result->summary());
        $this->assertSame(1, $executor->callsCount('lq_query'));
    }

    public function testSystemContextWritesAreAllowedWhenExplicitlyOpened(): void
    {
        $executor = new TestablePlanExecutor(['require_user_context_for_writes' => false], $this->store, new PlanVerifier());
        $executor->responses['menu.action.host.stock.save'] = '{"code":0,"msg":"ok"}';

        $result = $executor->execute($this->plan([
            $this->step('s1', 'tool', 'menu.action.host.stock.save', ['product_id' => 1], [['type' => 'tool_ok']]),
        ]), [], ['user_id' => 0]);

        $this->assertTrue($result->isCompleted(), $result->summary());
        $this->assertContains('system_context_writes', array_column($result->notes, 'code'), '放开时必须显式标注');
    }

    public function testStoreReceivesSkippedFailedAndCompletedStatesAcrossSteps(): void
    {
        $executor = $this->executor();
        $executor->responses['lq_query'] = $this->readJson();

        $result = $executor->execute($this->plan([
            $this->step('s1', 'tool', 'lq_query', []),
            $this->step('s2', 'skill', 'system.notify', [], [], ['condition' => '$input.never != null']),
            $this->step('s3', 'tool', 'lq_query', [], [['type' => 'count_gte', 'path' => 'data.total', 'value' => 99]]),
        ]));

        $this->assertTrue($result->isAborted(), '第 3 步断言失败 ⇒ 终止');
        $statuses = array_map(static fn(PlanStepRun $s): string => $s->status, $this->store->findSteps($result->runId));

        $this->assertSame([PlanStepRun::STATUS_COMPLETED, PlanStepRun::STATUS_SKIPPED, PlanStepRun::STATUS_FAILED], $statuses);
        $this->assertSame(1, $result->completed);
        $this->assertSame(1, $result->failed);
    }
}


/**
 * 内存仓储（离线验证「逐步落库 + 续跑」）
 */
class ArrayPlanRunStore implements PlanRunStoreInterface
{
    /** @var array<int, array<string, mixed>> */
    public array $runs = [];

    /** @var array<int, array<int, PlanStepRun>> */
    public array $steps = [];

    private int $sequence = 0;

    public function createRun(PlanRun $run): int
    {
        $id = ++$this->sequence;

        $this->runs[$id] = ['run_id' => $id] + $run->toArray();

        return $id;
    }

    public function updateRun(int $runId, array $fields): void
    {
        $this->runs[$runId] = array_merge($this->runs[$runId] ?? [], $fields);
    }

    public function findRun(int $runId): ?PlanRun
    {
        return isset($this->runs[$runId]) ? PlanRun::fromArray($this->runs[$runId]) : null;
    }

    public function upsertStep(PlanStepRun $step): void
    {
        $this->steps[$step->runId][$step->stepNo] = $step;
    }

    public function findSteps(int $runId): array
    {
        $steps = $this->steps[$runId] ?? [];
        ksort($steps);

        return array_values($steps);
    }

    public function resetRunningSteps(int $runId): void
    {
        foreach ($this->steps[$runId] ?? [] as $no => $step) {
            if ($step->status === PlanStepRun::STATUS_RUNNING) {
                $this->steps[$runId][$no] = PlanStepRun::fromArray(
                    array_merge($step->toArray(), ['status' => PlanStepRun::STATUS_PENDING])
                );
            }
        }
    }
}

/**
 * 可注入「能力响应 / 规划器」的执行器（离线）
 */
class TestablePlanExecutor extends PlanExecutor
{
    /**
     * `target => string|callable(array $arguments, int $attempt): mixed`
     *
     * @var array<string, mixed>
     */
    public array $responses = [];

    /** @var array<int, array{kind:string, target:string, arguments:array}> */
    public array $calls = [];

    /** @var int[] */
    public array $heartbeats = [];

    /** @var array<int, array{result:?string, error:?string}> */
    public array $finishes = [];

    public ?PlanPlanner $stubPlanner = null;

    public bool $plannerUnavailable = false;

    /**
     * 离线能力查询：只声明「哪些 target 是写能力」
     *
     * @var array<string, bool>
     */
    public array $writeTargets = ['menu.action.host.stock.save' => true];

    protected function lookup(): \wise\agent\plan\PlanCapabilityLookupInterface
    {
        return new ExecutorLookupStub($this->writeTargets);
    }

    /**
     * 默认模拟「登录用户发起」（`user_id=7`）
     *
     * B1-4 起执行器带 fail-closed 闸门：**系统上下文（`user_id<=0`）+ 含写步骤 ⇒ 拒绝执行**。
     * 本文件的多数用例都在跑含写步骤的计划，因此默认给一个登录用户；
     * 专门验证闸门的用例显式传 `['user_id' => 0]`。
     *
     * @param array<string, mixed> $context
     */
    public function execute(\wise\agent\plan\PlanDefinition $plan, array $inputs = [], array $context = []): PlanExecutionResult
    {
        if (!array_key_exists('user_id', $context)) {
            $context['user_id'] = 7;
            $context['user_type'] = 'admin';
        }

        return parent::execute($plan, $inputs, $context);
    }

    protected function invokeTool(string $target, array $arguments): mixed
    {
        return $this->respond('tool', $target, $arguments);
    }

    protected function invokeAction(string $target, array $arguments): mixed
    {
        return $this->respond('action', $target, $arguments);
    }

    protected function invokeSkill(string $target, array $input): mixed
    {
        return $this->respond('skill', $target, $input);
    }

    protected function invokeNode(string $target, array $input, array $stepsOutput = [], int $stepNo = 0): mixed
    {
        return $this->respond('node', $target, $input);
    }

    /** @var array<int, \wise\agent\event\ToolExecute> */
    public array $dispatchedEvents = [];

    /**
     * 事件派发接缝：离线测试不触发框架 Event facade，仅记录（供断言权限/审批链路被调用）
     */
    protected function dispatchEvent(\wise\agent\event\ToolExecute $event): void
    {
        $this->dispatchedEvents[] = $event;
    }

    protected function planner(): ?PlanPlanner
    {
        return $this->plannerUnavailable ? null : $this->stubPlanner;
    }

    protected function trackerHeartbeat(string $trackingId, int $stepNo, int $tokens, float $elapsed): void
    {
        $this->heartbeats[] = $stepNo;
    }

    protected function trackerFinish(string $trackingId, ?string $result, ?string $error, int $steps, int $tokens, float $duration): void
    {
        $this->finishes[] = ['result' => $result, 'error' => $error];
    }

    public function callsCount(string $target): int
    {
        return count(array_filter($this->calls, static fn(array $call): bool => $call['target'] === $target));
    }

    private function respond(string $kind, string $target, array $arguments): mixed
    {
        $this->calls[] = ['kind' => $kind, 'target' => $target, 'arguments' => $arguments];

        $scripted = $this->responses[$target] ?? null;

        if ($scripted === null) {
            return (string) json_encode(['code' => 0, 'msg' => 'ok', 'data' => ['list' => [], 'total' => 0]], JSON_UNESCAPED_UNICODE);
        }

        if (is_callable($scripted)) {
            return $scripted($arguments, $this->callsCount($target));
        }

        return $scripted;
    }
}

/**
 * 离线能力查询替身：只回答「哪些 target 是写能力」
 */
class ExecutorLookupStub implements \wise\agent\plan\PlanCapabilityLookupInterface
{
    /**
     * @param array<string, bool> $writeTargets
     */
    public function __construct(private array $writeTargets = [])
    {
    }

    public function exists(string $kind, string $target): bool
    {
        return true;
    }

    public function argumentNames(string $kind, string $target): ?array
    {
        return null;
    }

    public function isWrite(string $kind, string $target): bool
    {
        return (bool) ($this->writeTargets[$target] ?? false);
    }
}

/**
 * 脚本化规划器（只用于改道分支）
 */
class StubPlanner extends PlanPlanner
{
    /** @var PlanPlanResult[] */
    public array $script = [];

    /** @var array<int, array{goal:string, inputs:array, limits:array}> */
    public array $captured = [];

    public function plan(string $goal, array $inputs = [], array $limits = []): PlanPlanResult
    {
        $this->captured[] = ['goal' => $goal, 'inputs' => $inputs, 'limits' => $limits];

        return array_shift($this->script) ?? new PlanPlanResult(ok: false, error: 'stub_empty');
    }
}
