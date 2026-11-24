<?php
declare(strict_types=1);

namespace wise\agent\test;

use PHPUnit\Framework\TestCase;
use wise\agent\bridge\NodeRegistry;
use wise\agent\command\PlanMaterialize;
use wise\agent\contract\RequestContext;
use wise\agent\contract\WorkflowContext;
use wise\agent\pipeline\PipelineStep;
use wise\agent\plan\PlanCapabilityLookupInterface;
use wise\agent\plan\PlanDefinition;
use wise\agent\plan\PlanExecutionResult;
use wise\agent\plan\PlanExecutor;
use wise\agent\plan\PlanNodeProvider;
use wise\agent\plan\PlanPlanResult;
use wise\agent\plan\PlanPlanner;
use wise\agent\plan\PlanResumeTool;
use wise\agent\plan\PlanRun;
use wise\agent\plan\PlanRunTool;
use wise\agent\plan\PlanShell;
use wise\agent\plan\PlanStatusTool;
use wise\agent\plan\PlanStep;
use wise\agent\plan\PlanStepRun;
use wise\agent\plan\PlanToolProvider;
use wise\agent\plan\store\PlanRunStoreInterface;
use wise\agent\tool\ToolRegistry;

/**
 * B1-4 回归：计划工具面 + 物化 + 安全闸门（附录五 验收 3、4、8、9）
 *
 * 全部离线：规划器/执行器/仓储都注入替身；队列与轨迹上报走 `protected` 接缝。
 */
class PlanToolTest extends TestCase
{
    private PlanToolArrayStore $store;

    protected function setUp(): void
    {
        $this->store = new PlanToolArrayStore();
    }

    /**
     * @param array<string, mixed> $options
     */
    private function runTool(
        array $options = [],
        ?PlanPlanner $planner = null,
        ?PlanExecutor $executor = null
    ): TestablePlanRunTool {
        return new TestablePlanRunTool($options, $planner, $executor ?? new PlanToolStubExecutor($options), $this->store);
    }

    private function resumeTool(array $options, PlanExecutor $executor): PlanResumeTool
    {
        return new PlanResumeTool($options, $executor, $this->store);
    }

    /**
     * @param array<int, array<string, mixed>> $steps
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
            'id' => $id, 'kind' => $kind, 'target' => $target, 'input' => $input, 'verify' => $verify,
        ], $extra);
    }

    private function decode(string $json): array
    {
        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded, '响应必须是 JSON');

        return $decoded;
    }


    public function testProviderIsGatedByEnabledSwitch(): void
    {
        $this->assertFalse((new PlanToolProvider())->isEnabled(), '默认关闭');
        $this->assertSame([], (new PlanToolProvider())->getTools(), '关闭时不注册任何工具');

        $provider = new PlanToolProvider(['enabled' => true]);

        $this->assertTrue($provider->isEnabled());
        $this->assertSame('plan', $provider->getNamespace());
    }

    public function testProviderRegistersThreeToolsUnderBuiltinKeys(): void
    {
        $registry = ToolRegistry::newInstance();
        $count    = (new PlanToolProvider(['enabled' => true]))->registerTo($registry);

        $this->assertSame(3, $count);

        foreach (['plan_run', 'plan_status', 'plan_resume'] as $name) {
            $this->assertTrue($registry->has($name), "必须用 builtin 精确键注册：{$name}");
        }
    }

    public function testProviderHonorsPerToolSwitch(): void
    {
        $provider = new PlanToolProvider(['enabled' => true, 'tools' => ['status' => false]]);

        $this->assertFalse($provider->isToolEnabled('status'));
        $this->assertSame(
            ['plan_run', 'plan_resume'],
            array_map(static fn(object $t): string => $t->getName(), $provider->getTools())
        );
    }

    public function testProviderExecuteRoutesToToolWithInjectedTools(): void
    {
        $provider = new PlanToolProvider(
            ['enabled' => true],
            [new PlanStatusTool([], $this->store)]
        );
        $context = new RequestContext(7, 'admin');

        $payload = $this->decode((string) $provider->execute('plan_status', ['run_id' => 4242], $context));

        $this->assertSame(1, $payload['code']);
        $this->assertStringContainsString('未找到计划运行', (string) $payload['msg']);

        $missing = $this->decode((string) $provider->execute('plan_nope', [], $context));

        $this->assertSame(1, $missing['code']);
        $this->assertStringContainsString('not found', (string) $missing['msg']);
    }

    /**
     * 当前用户是**在提供者这一层**从 `RequestContext` 注入的（fail-closed 与续跑身份都依赖它）
     */
    public function testProviderExecuteInjectsCurrentUserFromRequestContext(): void
    {
        $runId    = $this->seedPausedRun();
        $executor = new PlanToolStubExecutor();

        $provider = new PlanToolProvider(
            ['enabled' => true],
            [new PlanResumeTool([], $executor, $this->store)]
        );

        $payload = $this->decode((string) $provider->execute('plan_resume', ['run_id' => $runId], new RequestContext(9)));

        $this->assertSame(1, $payload['code']);
        $this->assertSame('guard', $payload['stage']);
        $this->assertSame([], $executor->resumed);

        $ok = $this->decode((string) $provider->execute('plan_resume', ['run_id' => $runId], new RequestContext(7)));

        $this->assertSame(0, $ok['code'], (string) $ok['msg']);
        $this->assertSame(7, $executor->resumed[0]['context']['user_id']);
    }


    public function testRunInManualModeExecutesSynchronously(): void
    {
        $planner  = new PlanToolStubPlanner();
        $planner->result = new PlanPlanResult(
            ok: true,
            plan: $this->plan([$this->step('s1', 'tool', 'lq_query', ['target' => 'smartstock.product'])]),
            attempts: 0,
        );

        $executor = new PlanToolStubExecutor();
        $tool     = $this->runTool(['planner' => 'manual'], $planner, $executor)->withUser(9, 'admin');

        $result = $this->decode($tool->execute([
            'goal'  => '盘点库存',
            'steps' => [['id' => 's1', 'kind' => 'tool', 'target' => 'lq_query']],
            'async' => false,
        ]));

        $this->assertSame(0, $result['code'], (string) $result['msg']);
        $this->assertSame('completed', $result['data']['status']);
        $this->assertCount(1, $executor->executed, '同步路径必须真正执行');
        $this->assertSame(9, $executor->executed[0]['context']['user_id'], '把当前用户传给执行器');
        $this->assertSame([], $tool->enqueued, '同步路径不入队');
    }

    public function testRunRejectsInvalidPlanWithRepairHint(): void
    {
        $planner  = new PlanToolStubPlanner();
        $planner->result = new PlanPlanResult(ok: false, error: 'plan_invalid_after_repairs');

        $tool   = $this->runTool([], $planner)->withUser(9);
        $result = $this->decode($tool->execute(['goal' => '盘点库存', 'async' => false]));

        $this->assertSame(1, $result['code']);
        $this->assertStringContainsString('规划失败', (string) $result['msg']);
        $this->assertSame('plan_invalid_after_repairs', $result['data']['error']);
    }

    public function testRunWithoutGoalOrStepsIsRejected(): void
    {
        $result = $this->decode($this->runTool([], new PlanToolStubPlanner())->execute([]));

        $this->assertSame(1, $result['code']);
        $this->assertStringContainsString('缺少 goal', (string) $result['msg']);
    }


    public function testWritePlanIsRejectedInSystemContextBeforeCreatingRun(): void
    {
        $planner  = new PlanToolStubPlanner();
        $planner->result = new PlanPlanResult(
            ok: true,
            plan: $this->plan([
                $this->step('s1', 'tool', 'menu.action.host.stock.save', ['product_id' => 1], [['type' => 'tool_ok']]),
            ]),
        );

        $executor = new PlanToolStubExecutor();
        $tool     = $this->runTool([], $planner, $executor)->withUser(0, 'admin');

        $result = $this->decode($tool->execute(['goal' => '改一下库存', 'async' => true]));

        $this->assertSame(1, $result['code']);
        $this->assertSame('guard', $result['stage']);
        $this->assertStringContainsString('系统上下文', (string) $result['msg']);
        $this->assertStringContainsString('登录用户', (string) $result['hint']);
        $this->assertSame([], $tool->enqueued, '被闸门拒绝时不得入队');
        $this->assertSame([], $this->store->runs, '也不得留下注定不执行的运行记录');
        $this->assertSame([], $executor->executed);
    }

    public function testReadOnlyPlanIsAllowedInSystemContext(): void
    {
        $planner  = new PlanToolStubPlanner();
        $planner->result = new PlanPlanResult(
            ok: true,
            plan: $this->plan([$this->step('s1', 'tool', 'lq_query', ['target' => 'x'])]),
        );

        $tool   = $this->runTool([], $planner)->withUser(0);
        $result = $this->decode($tool->execute(['goal' => '看一眼库存', 'async' => false]));

        $this->assertSame(0, $result['code'], (string) $result['msg']);
    }

    public function testExplicitlyOpenedSystemContextWritesAreNoted(): void
    {
        $planner  = new PlanToolStubPlanner();
        $planner->result = new PlanPlanResult(
            ok: true,
            plan: $this->plan([
                $this->step('s1', 'tool', 'menu.action.host.stock.save', ['product_id' => 1], [['type' => 'tool_ok']]),
            ]),
        );

        $executor = new PlanToolStubExecutor(['require_user_context_for_writes' => false]);
        $executor->next = new PlanExecutionResult(
            status: PlanRun::STATUS_COMPLETED,
            runId: 5,
            completed: 1,
            notes: [['code' => 'system_context_writes', 'message' => '系统上下文下执行了含写步骤的计划']],
        );

        $tool = $this->runTool(['require_user_context_for_writes' => false], $planner, $executor)->withUser(0);

        $result = $this->decode($tool->execute(['goal' => '改库存', 'async' => false]));

        $this->assertSame(0, $result['code'], (string) $result['msg']);
        $this->assertContains('system_context_writes', array_column($result['data']['notes'], 'code'));
    }


    public function testRunAsyncPersistsRunInputsAndEnqueues(): void
    {
        $planner  = new PlanToolStubPlanner();
        $planner->result = new PlanPlanResult(
            ok: true,
            plan: $this->plan([$this->step('s1', 'tool', 'lq_query', ['target' => '$input.table'])]),
            attempts: 1,
            usage: ['total_tokens' => 120],
        );

        $tool   = $this->runTool([], $planner, new PlanToolStubExecutor())->withUser(9);
        $result = $this->decode($tool->execute([
            'goal'   => '盘点库存',
            'inputs' => ['table' => 'smartstock.product'],
            'async'  => true,
        ]));

        $this->assertSame(0, $result['code'], (string) $result['msg']);
        $this->assertTrue($result['data']['async']);
        $this->assertGreaterThan(0, $result['data']['run_id']);
        $this->assertSame(1, count($tool->enqueued), '必须入队');
        $this->assertSame($result['data']['run_id'], $tool->enqueued[0]['runId']);

        $run = $this->store->findRun((int) $result['data']['run_id']);
        $this->assertSame(['table' => 'smartstock.product'], $run->plan['inputs']);
        $this->assertSame(PlanRun::STATUS_RUNNING, $run->status);
        $this->assertSame('track_stub', $run->trackingId);
    }


    private function seedPausedRun(): int
    {
        $runId = $this->store->createRun(new PlanRun(
            trackingId: 'track_1',
            sessionId: 'sess_1',
            userId: 7,
            goal: '盘点库存并生成补货建议',
            plan: $this->plan([
                $this->step('s1', 'tool', 'lq_query', []),
                $this->step('s2', 'tool', 'menu.action.host.stock.save', ['product_id' => 7], [['type' => 'tool_ok']]),
            ])->toArray(),
            status: PlanRun::STATUS_PAUSED,
            currentStep: 2,
            totalSteps: 2,
            totalTokens: 120,
            totalDuration: 1.5,
        ));

        $this->store->upsertStep(new PlanStepRun(
            runId: $runId,
            stepNo: 1,
            stepId: 's1',
            kind: 'tool',
            target: 'lq_query',
            write: false,
            arguments: ['target' => 'smartstock.product'],
            output: '{"code":0,"data":{"total":3}}',
            verifyResult: ['passed' => true, 'checked' => 1],
            status: PlanStepRun::STATUS_COMPLETED,
            attempts: 1,
            duration: 0.4,
        ));

        $this->store->upsertStep(new PlanStepRun(
            runId: $runId,
            stepNo: 2,
            stepId: 's2',
            kind: 'tool',
            target: 'menu.action.host.stock.save',
            write: true,
            arguments: ['product_id' => 7, 'qty' => 10],
            status: PlanStepRun::STATUS_BLOCKED,
            attempts: 1,
            duration: 0.2,
            error: '写操作需审批',
            approvalId: 42,
        ));

        return $runId;
    }

    public function testStatusReturnsStepByStepViewWithResumeHint(): void
    {
        $runId  = $this->seedPausedRun();
        $result = $this->decode((new PlanStatusTool([], $this->store))->withUser(7, 'admin')->execute(['run_id' => $runId]));

        $this->assertSame(0, $result['code'], (string) $result['msg']);
        $this->assertSame(PlanRun::STATUS_PAUSED, $result['data']['status']);
        $this->assertSame(2, $result['data']['total_steps']);
        $this->assertSame(0.5, $result['data']['progress'], '进度按已完成步骤算（不是 current_step/total，否则会误报 100%）');
        $this->assertSame(1, $result['data']['completed_steps']);
        $this->assertTrue($result['data']['paused']);
        $this->assertSame('s2', $result['data']['blocked_step']['step_id']);
        $this->assertStringContainsString('plan_resume', (string) $result['data']['resume_hint']);

        $steps = $result['data']['steps'];

        $this->assertCount(2, $steps);
        $this->assertSame(
            ['step_no', 'step_id', 'kind', 'target', 'label', 'write', 'status', 'attempts', 'duration',
                'verify_passed', 'verify_failures', 'approval_id', 'error', 'arguments', 'output'],
            array_keys($steps[0])
        );
        $this->assertTrue($steps[1]['write']);
        $this->assertSame(42, $steps[1]['approval_id']);
        $this->assertSame(PlanStepRun::STATUS_BLOCKED, $steps[1]['status']);
        $this->assertTrue($steps[0]['verify_passed']);
    }

    public function testStatusCanOmitOutputs(): void
    {
        $runId  = $this->seedPausedRun();
        $result = $this->decode(
            (new PlanStatusTool([], $this->store))->withUser(7, 'admin')->execute(['run_id' => $runId, 'with_outputs' => false])
        );

        $this->assertArrayNotHasKey('output', $result['data']['steps'][0]);
        $this->assertArrayHasKey('arguments', $result['data']['steps'][0], '参数快照是回放的基础，必须保留');
    }

    public function testStatusReportsMissingRunHonestly(): void
    {
        $nothing = $this->decode((new PlanStatusTool([], $this->store))->execute([]));
        $this->assertSame(1, $nothing['code']);
        $this->assertStringContainsString('缺少 run_id', (string) $nothing['msg']);

        $missing = $this->decode((new PlanStatusTool([], $this->store))->execute(['run_id' => 999]));
        $this->assertSame(1, $missing['code']);
        $this->assertStringContainsString('未找到计划运行', (string) $missing['msg']);
    }


    public function testResumeRestartsFromBlockedStepForSameUser(): void
    {
        $runId    = $this->seedPausedRun();
        $executor = new PlanToolStubExecutor();
        $executor->next = new PlanExecutionResult(status: PlanRun::STATUS_COMPLETED, runId: $runId, completed: 2);

        $result = $this->decode(
            $this->resumeTool([], $executor)->withUser(7, 'admin')->execute(['run_id' => $runId])
        );

        $this->assertSame(0, $result['code'], (string) $result['msg']);
        $this->assertSame('completed', $result['data']['status']);
        $this->assertSame('resume', $result['data']['action']);
        $this->assertCount(1, $executor->resumed);
        $this->assertSame($runId, $executor->resumed[0]['runId']);
        $this->assertSame(7, $executor->resumed[0]['context']['user_id'], '续跑带当前用户（审批单按申请人匹配）');
    }

    public function testResumeByAnotherUserIsRejected(): void
    {
        $runId    = $this->seedPausedRun();
        $executor = new PlanToolStubExecutor();

        $result = $this->decode(
            $this->resumeTool([], $executor)->withUser(9, 'admin')->execute(['run_id' => $runId])
        );

        $this->assertSame(1, $result['code']);
        $this->assertSame('guard', $result['stage']);
        $this->assertStringContainsString('不能续跑', (string) $result['msg']);
        $this->assertSame([], $executor->resumed, '身份不符时不得续跑');
    }

    public function testResumeOnFinishedRunIsNoop(): void
    {
        $runId = $this->store->createRun(new PlanRun(
            userId: 7,
            goal: '已经跑完',
            plan: $this->plan([$this->step('s1', 'tool', 'lq_query', [])])->toArray(),
            status: PlanRun::STATUS_COMPLETED,
            currentStep: 1,
            totalSteps: 1,
        ));

        $executor = new PlanToolStubExecutor();
        $result   = $this->decode($this->resumeTool([], $executor)->withUser(7)->execute(['run_id' => $runId]));

        $this->assertSame(0, $result['code']);
        $this->assertTrue($result['data']['noop']);
        $this->assertSame([], $executor->resumed);
    }

    public function testResumeOnMissingRunIsHonest(): void
    {
        $nothing = $this->decode($this->resumeTool([], new PlanToolStubExecutor())->execute([]));
        $this->assertSame(1, $nothing['code']);

        $missing = $this->decode($this->resumeTool([], new PlanToolStubExecutor())->execute(['run_id' => 888]));
        $this->assertSame(1, $missing['code']);
        $this->assertStringContainsString('未找到计划运行', (string) $missing['msg']);
    }


    public function testMaterializedDefinitionIsAcceptedByPipelineStepParser(): void
    {
        $plan = $this->plan([
            $this->step('s1', 'tool', 'lq_query', ['target' => 'smartstock.product']),
            $this->step('s2', 'action', 'menu.action.host.stock.save', ['product_id' => '$steps.s1.data.list.0.id']),
        ]);

        $run        = new PlanRun(runId: 33, goal: '盘点', plan: $plan->toArray(), status: PlanRun::STATUS_COMPLETED);
        $definition = PlanMaterialize::buildDefinition($run, 'plan_33');

        $this->assertSame('plan_33', $definition['name']);
        $this->assertSame('plan:33', $definition['source']);
        $this->assertCount(2, $definition['steps']);

        foreach ($definition['steps'] as $stepDefinition) {
            $this->assertTrue(PlanShell::isShellStep($stepDefinition));
            $this->assertSame(PlanShell::NODE, $stepDefinition['skill']);

            $parsed = PipelineStep::fromArray($stepDefinition);

            $this->assertSame(PlanShell::NODE, $parsed->skillName);
        }

        $this->assertSame('s1', $definition['steps'][0]['output_key']);
        $this->assertSame(
            '$steps.s1.data.list.0.id',
            $definition['steps'][1]['input_mapping']['input']['product_id'],
            '引用原样保留（由 PipelineEngine 在运行期解析）'
        );
    }

    public function testShellNodeNamespaceIsWhatMakesTheEngineTreatItAsNode(): void
    {
        NodeRegistry::reset();

        $this->assertFalse(NodeRegistry::instance()->hasProvider(PlanShell::NAMESPACE), '未注册时引擎会把它当 skill');

        NodeRegistry::instance()->registerProvider(new PlanNodeProvider());

        $this->assertTrue(NodeRegistry::instance()->hasProvider(PlanShell::NAMESPACE));
        $this->assertSame([PlanShell::NODE], array_map(
            static fn(\wise\agent\dto\NodeDefinition $d): string => $d->getFullType(),
            NodeRegistry::instance()->getNodeDefinitions()
        ));

        NodeRegistry::reset();
    }

    public function testNodeProviderDispatchesThroughExecutorAndReportsErrors(): void
    {
        $executor = new PlanToolStubExecutor();
        $provider = new PlanNodeProvider($executor);
        $context  = new WorkflowContext('wf', 'exec', 2, ['steps' => ['s1' => '{"code":0}']]);

        $ok = $provider->run('step', [], [
            'id'     => 's2',
            'kind'   => 'tool',
            'target' => 'lq_query',
            'input'  => ['target' => 'smartstock.product'],
        ], $context);

        $this->assertNull($ok['error']);
        $this->assertCount(1, $executor->stepsRan);
        $this->assertSame('lq_query', $executor->stepsRan[0]['step']->target);
        $this->assertSame(2, $executor->stepsRan[0]['stepNo'], '步骤序号来自工作流上下文');
        $this->assertSame(['s1' => '{"code":0}'], $executor->stepsRan[0]['stepsOutput'], '上游输出从 variables.steps 取');

        $badKind = $provider->run('step', [], ['kind' => 'sql', 'target' => 'x'], $context);
        $this->assertNotNull($badKind['error']);
        $this->assertStringContainsString('入参非法', (string) $badKind['error']);

        $badType = $provider->run('nope', [], ['kind' => 'tool', 'target' => 'x'], $context);
        $this->assertStringContainsString('不支持的节点类型', (string) $badType['error']);
    }

    public function testNodeProviderSurfacesExecutionErrorsAsNodeContract(): void
    {
        $executor = new PlanToolStubExecutor();
        $executor->throwOnStep = new \RuntimeException('能力执行失败');

        $result = (new PlanNodeProvider($executor))->run('step', [], ['kind' => 'tool', 'target' => 'x'], new WorkflowContext());

        $this->assertNull($result['data']);
        $this->assertSame('能力执行失败', $result['error'], '节点契约不允许抛异常');
    }
}


class TestablePlanRunTool extends PlanRunTool
{
    /** @var array<int, array{runId:int, trackingId:string}> */
    public array $enqueued = [];

    protected function enqueue(int $runId, string $trackingId): void
    {
        $this->enqueued[] = ['runId' => $runId, 'trackingId' => $trackingId];
    }

    protected function trackerStart(string $goal, int $userId, int $totalSteps): string
    {
        return 'track_stub';
    }
}

class PlanToolArrayStore implements PlanRunStoreInterface
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

class PlanToolStubPlanner extends PlanPlanner
{
    public ?PlanPlanResult $result = null;

    /** @var array<int, array{goal:string, inputs:array, limits:array}> */
    public array $calls = [];

    public function plan(string $goal, array $inputs = [], array $limits = []): PlanPlanResult
    {
        $this->calls[] = ['goal' => $goal, 'inputs' => $inputs, 'limits' => $limits];

        return $this->result ?? new PlanPlanResult(ok: false, error: 'stub_not_configured');
    }
}

class PlanToolStubExecutor extends PlanExecutor
{
    /** @var array<int, array{plan:PlanDefinition, inputs:array, context:array}> */
    public array $executed = [];

    /** @var array<int, array{runId:int, context:array}> */
    public array $resumed = [];

    /** @var array<int, array{step:PlanStep, arguments:array, stepsOutput:array, stepNo:int}> */
    public array $stepsRan = [];

    public ?PlanExecutionResult $next = null;

    public ?\Throwable $throwOnStep = null;

    /** @var array<string, bool> */
    public array $writeTargets = ['menu.action.host.stock.save' => true];

    protected function lookup(): PlanCapabilityLookupInterface
    {
        return new PlanToolLookupStub($this->writeTargets);
    }

    public function execute(PlanDefinition $plan, array $inputs = [], array $context = []): PlanExecutionResult
    {
        $this->executed[] = ['plan' => $plan, 'inputs' => $inputs, 'context' => $context];

        return $this->next ?? new PlanExecutionResult(
            status: PlanRun::STATUS_COMPLETED,
            runId: 1,
            completed: count($plan->steps)
        );
    }

    public function resume(int $runId, array $context = []): PlanExecutionResult
    {
        $this->resumed[] = ['runId' => $runId, 'context' => $context];

        return $this->next ?? new PlanExecutionResult(status: PlanRun::STATUS_COMPLETED, runId: $runId, completed: 1);
    }

    public function runStep(PlanStep $step, array $arguments = [], array $stepsOutput = [], int $stepNo = 0): mixed
    {
        if ($this->throwOnStep !== null) {
            throw $this->throwOnStep;
        }

        $this->stepsRan[] = ['step' => $step, 'arguments' => $arguments, 'stepsOutput' => $stepsOutput, 'stepNo' => $stepNo];

        return '{"code":0,"msg":"ok"}';
    }
}

class PlanToolLookupStub implements PlanCapabilityLookupInterface
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
