<?php
declare(strict_types=1);

namespace wise\agent\plan;

use think\facade\Queue;
use wise\agent\execution\ExecutionTracker;
use wise\agent\job\PlanJob;
use wise\agent\menu\MenuActionResult;
use wise\agent\plan\store\DbPlanRunStore;
use wise\agent\plan\store\PlanRunStoreInterface;
use wise\agent\query\exception\QueryParamException;
use wise\agent\security\CapabilityAccess;
use wise\agent\tool\BaseTool;
use wise\support\context\ContextualLogger;

/**
 * `plan_run` —— 目标 → 计划 → 执行＼�
 *
 * ## 流程（每一步失败都如实回报，不产出半成品）
 *
 * 1. **规划**：`PlanPlanner::plan()`（`planner=manual` 时直接用调用方给的 steps，不调 LLM）；
 * 2. **计划不合法** ⇒ `code:1` + 逐条错误 + `repairHint`（**一步都不执行**，验收 1/13）；
 * 3. **入队前预检**：系统上下文 + 含写步骤 ⇒ 直接拒绝（与执行器**同一个** `guardReason()`），
 *    避免在队列里留下一条注定失败的运行；
 * 4. **同步执行**（`async:false`）或**异步入队**（默认，见 `plan.default_async`）。
 *
 * ## 权限与风险（可直接核对）
 *
 * - `getAccess() = EXECUTE`（编排类，与 `BaseSkill` 对未知分类的保守取值一致）；
 * - `requireConfirmation() = false`：**刻意不在这里二次确认** —— 计划里的写步骤
 *   各自受 `ToolSecurityCheck` 的权限与审批约束（确认/审批在**能力层**生效）；
 *   在计划层再加一道会让「一次计划」产生两个确认，且不增加实际安全性；
 * - `getPermission()` deny-only（`plan.require_ai_permission=false` 时返回空串）。
 *
 * ## 可测试性
 * `planner()` / `executor()` / `store()` / `trackerStart()` 是 `protected` 接缝。
 */
class PlanRunTool extends BaseTool
{
    use PlanOptions;
    use PlanUserContext;
    use PlanResultMapping;

    public const NAME = 'plan_run';

    private ?PlanPlanner $planner = null;

    private ?PlanExecutor $executor = null;

    private ?PlanRunStoreInterface $store = null;

    /**
     * @param array<string, mixed> $options `plan.*` 覆盖项
     */
    public function __construct(
        array $options = [],
        ?PlanPlanner $planner = null,
        ?PlanExecutor $executor = null,
        ?PlanRunStoreInterface $store = null
    ) {
        $this->setPlanOptions($options);
        $this->planner  = $planner;
        $this->executor = $executor;
        $this->store    = $store;
    }

    public function getDescription(): string
    {
        return '把一句业务目标拆成多步计划并执行：LLM 只负责「想做什么」（产出受校验的步骤），'
            . '执行只走既有能力（工具/技能/声明式动作/工作流节点），因此权限、审批、审计全部继承既有链路。'
            . '写步骤各自需要审批；被审批拦下时计划会**暂停**（不是失败），批准后用 plan_resume 续跑。'
            . '默认异步执行，用 plan_status 查进度。';
    }

    /**
     * @return array<string, mixed>
     */
    public function getParameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'goal'   => [
                    'type'        => 'string',
                    'description' => '要达成的业务目标（自然语言）。例如「盘点低库存商品并生成补货建议，然后通知采购」。',
                ],
                'steps'  => [
                    'type'        => 'array',
                    'description' => '可选：显式给出步骤（给出后跳过 LLM 规划）。仅在明确知道要做什么时使用。',
                    'items'       => ['type' => 'object'],
                ],
                'inputs' => [
                    'type'        => 'object',
                    'description' => '计划级输入，步骤里可用 `$input.键名` 引用。',
                ],
                'limits' => [
                    'type'        => 'object',
                    'description' => '可选上限（如 {"max_steps":6}）。**只能收紧**配置上限，不能放宽。',
                ],
                'async'  => [
                    'type'        => 'boolean',
                    'description' => '是否异步执行（默认按 plan.default_async）。异步时返回 run_id，用 plan_status 查询。',
                ],
            ],
            'required'   => ['goal'],
        ];
    }

    public function getAccess(): string
    {
        return CapabilityAccess::EXECUTE;
    }

    public function getRiskLevel(): string
    {
        return CapabilityAccess::RISK_MEDIUM;
    }

    /**
     * 刻意不在计划层二次确认（写步骤各自受审批约束，见类注释）
     */
    public function requireConfirmation(): bool
    {
        return false;
    }

    public function getPermission(): string
    {
        if (!(bool) $this->planOpt('require_ai_permission', false)) {
            return '';
        }

        return 'plan.run';
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function execute(array $arguments): string
    {
        $goal          = trim((string) ($arguments['goal'] ?? ''));
        $explicitSteps = is_array($arguments['steps'] ?? null) ? $arguments['steps'] : null;
        $inputs        = is_array($arguments['inputs'] ?? null) ? $arguments['inputs'] : [];
        $limits        = is_array($arguments['limits'] ?? null) ? $arguments['limits'] : [];
        $async         = array_key_exists('async', $arguments)
            ? (bool) $arguments['async']
            : (bool) $this->planOpt('default_async', true);

        if ($goal === '' && $explicitSteps === null) {
            return $this->encodePayload(MenuActionResult::failure('缺少 goal（或用 steps 显式给出计划步骤）', QueryParamException::CODE_INVALID, [
                'stage' => MenuActionResult::STAGE_ARGUMENTS,
            ]));
        }

        $planInputs = $explicitSteps !== null ? array_merge($inputs, ['steps' => $explicitSteps]) : $inputs;

        try {
            $planned = $this->planner()->plan($goal, $planInputs, $limits);
        } catch (\Throwable $e) {
            return $this->encodePayload(MenuActionResult::fromException($e, ['stage' => MenuActionResult::STAGE_EXECUTION]));
        }

        if (!$planned->ok || $planned->plan === null) {
            return $this->encodePayload(MenuActionResult::failure(
                '规划失败：' . ($planned->error ?? '未知原因'),
                QueryParamException::CODE_INVALID,
                [
                    'structured' => true,
                    'data'       => $planned->toArray(),
                    'text'       => '',
                    'stage'      => MenuActionResult::STAGE_ARGUMENTS,
                    'hint'       => $planned->report !== null
                        ? $planned->report->repairHint()
                        : '可换一种说法描述目标，或在 inputs 里补充已知信息。',
                ]
            ));
        }

        $plan    = $planned->plan;
        $context = [
            'goal'       => $goal !== '' ? $goal : $plan->goal,
            'session_id' => (string) ($arguments['_session_id'] ?? ''),
            'user_id'    => $this->currentUserId(),
            'user_type'  => $this->currentUserType(),
            'source'     => 'chat',
        ];

        $guard = $this->executor()->guardReason($plan, $context);

        if ($guard !== null) {
            return $this->encodePayload(MenuActionResult::failure(
                '计划被安全闸门拒绝：' . $guard,
                QueryParamException::CODE_INVALID,
                [
                    'structured' => true,
                    'data'       => ['plan' => $plan->toArray(), 'run_id' => 0],
                    'text'       => '',
                    'stage'      => 'guard',
                    'hint'       => '含写步骤的计划必须由登录用户发起（plan.require_user_context_for_writes）。',
                ]
            ));
        }

        if ($async) {
            return $this->dispatchAsync($plan, $inputs, $context, $planned);
        }

        return $this->encodeExecution($this->executor()->execute($plan, $inputs, $context), 'run');
    }


    protected function planner(): PlanPlanner
    {
        return $this->planner ??= new PlanPlanner($this->planOptions());
    }

    protected function executor(): PlanExecutor
    {
        return $this->executor ??= new PlanExecutor($this->planOptions(), $this->store());
    }

    protected function store(): PlanRunStoreInterface
    {
        return $this->store ??= new DbPlanRunStore($this->planOptions());
    }

    /**
     * 入队（`protected` 接缝：单测可离线断言「确实入队了」而不依赖队列驱动）
     */
    protected function enqueue(int $runId, string $trackingId): void
    {
        Queue::push(PlanJob::class, ['run_id' => $runId, 'tracking_id' => $trackingId]);
    }

    protected function trackerStart(string $goal, int $userId, int $totalSteps): string
    {
        try {
            return ExecutionTracker::start('plan', [
                'task'      => $goal,
                'agent_type' => 'plan',
                'user_id'   => $userId,
                'user_type' => $this->currentUserType(),
                'metadata'  => ['total_steps' => $totalSteps],
            ], ExecutionTracker::STATUS_QUEUED);
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * @param array<string, mixed> $inputs
     * @param array<string, mixed> $context
     */
    protected function dispatchAsync(PlanDefinition $plan, array $inputs, array $context, PlanPlanResult $planned): string
    {
        $userId = (int) $context['user_id'];

        $runId = $this->store()->createRun(new PlanRun(
            trackingId: '',
            sessionId: (string) $context['session_id'],
            userType: (string) $context['user_type'],
            userId: $userId,
            goal: (string) $context['goal'],
            plan: $plan->withInputs($inputs)->toArray(),
            status: PlanRun::STATUS_RUNNING,
            currentStep: 1,
            totalSteps: count($plan->steps),
            source: (string) $context['source'],
            planner: $plan->planner,
        ));

        $trackingId = $this->trackerStart((string) $context['goal'], $userId, count($plan->steps));

        if ($trackingId !== '') {
            $this->store()->updateRun($runId, ['tracking_id' => $trackingId]);
        }

        try {
            $this->enqueue($runId, $trackingId);
        } catch (\Throwable $e) {
            $this->store()->updateRun($runId, [
                'status' => PlanRun::STATUS_ABORTED,
                'error'  => 'enqueue_failed：' . $e->getMessage(),
            ]);

            ContextualLogger::error("[Plan] 计划入队失败（run_id={$runId}）：{$e->getMessage()}");

            return $this->encodePayload(MenuActionResult::failure(
                '计划已生成但入队失败，请稍后重试或联系管理员',
                QueryParamException::CODE_INVALID,
                [
                    'structured' => true,
                    'data'       => ['run_id' => $runId, 'plan' => $plan->toArray()],
                    'text'       => '',
                    'stage'      => MenuActionResult::STAGE_EXECUTION,
                    'hint'       => '检查队列配置（wise-agent.queue）。也可改用 async=false 同步执行。',
                ]
            ));
        }

        return $this->encodePayload(MenuActionResult::normalize([
            'code' => 0,
            'msg'  => sprintf('计划已入队：%d 步（run_id=%d）', count($plan->steps), $runId),
            'data' => [
                'run_id'      => $runId,
                'tracking_id' => $trackingId,
                'status'      => PlanRun::STATUS_RUNNING,
                'async'       => true,
                'total_steps' => count($plan->steps),
                'write_steps' => array_map(
                    static fn(PlanStep $step): string => $step->id,
                    $plan->writeSteps()
                ),
                'plan'        => $plan->toArray(),
                'plan_attempts' => $planned->attempts,
                'plan_tokens'   => $planned->totalTokens(),
                'notes'         => array_merge($planned->notes, [[
                    'code'    => 'plan.async',
                    'message' => '计划在队列中执行；用 plan_status(run_id) 查逐步状态，'
                        . '若某步在等审批则批准后用 plan_resume(run_id) 续跑。',
                ]]),
            ],
        ]));
    }

}
