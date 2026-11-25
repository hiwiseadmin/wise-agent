<?php
declare(strict_types=1);

namespace wise\agent\plan;

use think\facade\Event;
use wise\agent\bridge\NodeRegistry;
use wise\agent\contract\WorkflowContext;
use wise\agent\event\ToolExecute;
use wise\agent\execution\ExecutionTracker;
use wise\agent\plan\store\DbPlanRunStore;
use wise\agent\plan\store\PlanRunStoreInterface;
use wise\agent\skill\SkillManager;
use wise\agent\tool\ToolRegistry;

/**
 * 计划执行器＼�
 *
 * ## 一句话
 *
 * 按计划逐步**分发到既有能力**（skill / tool / action / node），逐步**落库 + 心跳**，
 * 每步执行完**用确定性断言自证**，失败按 `on_verify_fail` 处置（重试 / 改道 / 终止），
 * 遇到审批闸门则**暂停**等人工 —— 全部**不新增写路径、不新增授权判定**。
 *
 * ## 四条不可动摇的边界
 *
 * 1. **执行器不做授权**：写步骤走既有工具 ⇒ `ToolSecurityCheck`（权限 + 审批）与
 *    `MenuActionPipeline`（RBAC/审计）自动生效＼�；
 * 2. **`paused` 不是失败**：被审批闸门拦下的步骤记 `blocked_pending_approval`、
 *    运行记 `paused`，**不消耗重试预算**；人工驳回（`rejected`）则**直接终止**——
 *    人工的否定不应被「自动改道」绕过＼�；
 * 3. **改道不越过上限**：`replan` 次数受 `max_replans` 约束，且**改道产物会再过一遍
 *    `PlanValidator`**（`PlanPlanner` 内部已保证）；
 * 4. **三重上限每步前后各判一次**：步数 / 时长 / token＼�，
 *    防御式判定 —— 不依赖 LLM 自觉，也不依赖计划自报。
 *
 * ## 可测试性
 *
 * `store()` / `verifier()` / `planner()` / `values()` / `invoke*()` / `tracker*()` 都是
 * `protected` 接缝，因此本类**不加 `final`**，单测可注入内存仓储 + 假能力，
 * 完全离线（不需要 DB / 容器 / LLM）。
 */
class PlanExecutor
{
    use PlanOptions;

    /** 默认上限＼� */
    private const DEFAULTS = [
        'max_steps'         => 12,
        'max_step_duration' => 120,
        'max_plan_duration' => 180,
        'max_plan_tokens'   => 60000,
        'max_replans'       => 2,
    ];

    /** 终止原因（写入 `ai_plan_runs.error` / 结果的 `error`） */
    public const ABORT_STEP_FAILED   = 'step_failed';
    public const ABORT_STEP_TIMEOUT  = 'step_timeout';
    public const ABORT_PLAN_TIMEOUT  = 'plan_duration_exceeded';
    public const ABORT_TOKENS        = 'plan_tokens_exceeded';
    public const ABORT_TOO_MANY_STEPS = 'too_many_steps';
    public const ABORT_REJECTED      = 'approval_rejected';

    /** 系统上下文（`user_id<=0`）+ 含写步骤 ⇒ 计划级 fail-closed 拒绝＼� */
    public const ABORT_SYSTEM_CONTEXT = 'system_context_writes_forbidden';

    private ?PlanRunStoreInterface $store = null;

    private ?PlanVerifier $verifier = null;

    private ?PlanPlanner $planner = null;

    private ?PlanValueResolver $values = null;

    private ?ToolRegistry $toolRegistry = null;

    private ?SkillManager $skillManager = null;

    private ?PlanCapabilityLookupInterface $lookup = null;

    /**
     * @param array<string, mixed> $options `plan.*` 覆盖项
     */
    public function __construct(
        array $options = [],
        ?PlanRunStoreInterface $store = null,
        ?PlanVerifier $verifier = null,
        ?PlanPlanner $planner = null,
        ?ToolRegistry $toolRegistry = null
    ) {
        $this->setPlanOptions($options);
        $this->store       = $store;
        $this->verifier    = $verifier;
        $this->planner     = $planner;
        $this->toolRegistry = $toolRegistry;
    }

    /**
     * 执行一份计划
     *
     * @param array<string, mixed> $inputs  计划级输入（`$input.x` 的来源）
     * @param array<string, mixed> $context 运行上下文：
     *   `run_id`（复用已有运行）/ `from_step`（从第 N 步续跑）/ `steps_output`（已完成步骤输出）
     *   / `goal` / `session_id` / `user_id` / `user_type` / `source` / `tracking_id`
     */
    public function execute(PlanDefinition $plan, array $inputs = [], array $context = []): PlanExecutionResult
    {
        $startedAt   = microtime(true);
        $goal        = (string) ($context['goal'] ?? $plan->goal);
        $trackingId  = (string) ($context['tracking_id'] ?? '');
        $stepsOutput = is_array($context['steps_output'] ?? null) ? $context['steps_output'] : [];
        $fromStep    = max(1, (int) ($context['from_step'] ?? 1));
        $trace       = [];
        $notes       = [];

        if (!$this->requireUserContextForWrites()
            && (int) ($context['user_id'] ?? 0) <= 0
            && $this->hasWriteSteps($plan)
        ) {
            $notes[] = [
                'code'    => 'system_context_writes',
                'message' => '系统上下文（无登录用户）下执行了含写步骤的计划：既有审批链在该上下文会跳过审批'
                    . '（ApprovalManager.php:78-84），本计划的所有写操作都不会生成审批单。'
                    . '这由 plan.require_user_context_for_writes=false 显式放开。',
            ];
        }

        $limits = [
            'max_steps'         => $this->planLimit('max_steps', self::DEFAULTS['max_steps'], $plan->limits),
            'max_step_duration' => $this->planLimit('max_step_duration', self::DEFAULTS['max_step_duration'], $plan->limits),
            'max_plan_duration' => $this->planLimit('max_plan_duration', self::DEFAULTS['max_plan_duration'], $plan->limits),
            'max_plan_tokens'   => $this->planLimit('max_plan_tokens', self::DEFAULTS['max_plan_tokens'], $plan->limits),
            'max_replans'       => $this->planLimit('max_replans', self::DEFAULTS['max_replans'], $plan->limits),
        ];

        $steps = array_values($plan->steps);

        $runId = (int) ($context['run_id'] ?? 0);

        $guard = $this->guardReason($plan, $context);

        if ($guard !== null) {
            $trace[] = ['step' => 'plan.guard_rejected', 'message' => $guard];

            if ($runId > 0) {
                $this->store()->updateRun($runId, [
                    'status' => PlanRun::STATUS_ABORTED,
                    'error'  => self::ABORT_SYSTEM_CONTEXT . '：' . $guard,
                ]);
            }

            $this->trackerFinish($trackingId, null, $guard, 0, 0, 0.0);

            return new PlanExecutionResult(
                status: PlanRun::STATUS_ABORTED,
                runId: $runId,
                trackingId: $trackingId,
                trace: $trace,
                error: self::ABORT_SYSTEM_CONTEXT . '：' . $guard,
            );
        }

        if ($runId <= 0) {
            $runId = $this->store()->createRun(new PlanRun(
                trackingId: $trackingId,
                sessionId: (string) ($context['session_id'] ?? ''),
                userType: (string) ($context['user_type'] ?? 'admin'),
                userId: (int) ($context['user_id'] ?? 0),
                goal: $goal,
                plan: $plan->withInputs($inputs)->toArray(),
                status: PlanRun::STATUS_RUNNING,
                currentStep: $fromStep,
                totalSteps: count($steps),
                source: (string) ($context['source'] ?? 'chat'),
                planner: $plan->planner,
            ));
        } else {
            $this->store()->updateRun($runId, ['status' => PlanRun::STATUS_RUNNING, 'current_step' => $fromStep]);
        }

        $trace[] = ['step' => 'plan.start', 'message' => sprintf(
            '开始执行：共 %d 步，从第 %d 步开始（上限：步数 %d / 时长 %ds / token %d / 改道 %d）',
            count($steps),
            $fromStep,
            $limits['max_steps'],
            $limits['max_plan_duration'],
            $limits['max_plan_tokens'],
            $limits['max_replans']
        )];

        $completed  = 0;
        $failed     = 0;
        $replans    = 0;
        $tokens     = 0;
        $recorded   = [];

        $index = $fromStep - 1;

        while ($index < count($steps)) {
            $step = $steps[$index];
            $no   = $index + 1;

            if ($no > $limits['max_steps']) {
                return $this->abort(
                    $runId, $trackingId, $recorded, $stepsOutput, $completed, $failed, $replans, $tokens, $startedAt,
                    self::ABORT_TOO_MANY_STEPS,
                    sprintf('步骤数超过上限 %d（改道后步骤变多时会命中）', $limits['max_steps']),
                    $trace
                );
            }

            if ((microtime(true) - $startedAt) > $limits['max_plan_duration']) {
                return $this->abort(
                    $runId, $trackingId, $recorded, $stepsOutput, $completed, $failed, $replans, $tokens, $startedAt,
                    self::ABORT_PLAN_TIMEOUT,
                    sprintf('计划时长超过上限 %d 秒', $limits['max_plan_duration']),
                    $trace
                );
            }

            if ($tokens > $limits['max_plan_tokens']) {
                return $this->abort(
                    $runId, $trackingId, $recorded, $stepsOutput, $completed, $failed, $replans, $tokens, $startedAt,
                    self::ABORT_TOKENS,
                    sprintf('计划 token 超过上限 %d', $limits['max_plan_tokens']),
                    $trace
                );
            }

            $write = $this->writeFlagOf($step);

            if ($step->condition !== null && trim($step->condition) !== '') {
                $condition = $this->conditionMet($step->condition, $inputs, $stepsOutput);

                if ($condition !== true) {
                    $trace[] = ['step' => 'plan.skip', 'message' => sprintf('步骤 %s 条件未满足，跳过：%s', $step->id, (string) $condition)];

                    $skipped = new PlanStepRun(
                        runId: $runId,
                        stepNo: $no,
                        stepId: $step->id,
                        kind: $step->kind,
                        target: $step->target,
                        label: $step->label,
                        write: $write,
                        status: PlanStepRun::STATUS_SKIPPED,
                        error: is_string($condition) ? $condition : null,
                    );

                    $this->store()->upsertStep($skipped);
                    $recorded[] = $skipped;
                    $index++;

                    continue;
                }
            }

            $arguments = $this->values()->resolveMapping($step->input, $inputs, $stepsOutput);

            $stepStarted = microtime(true);
            $stepRun     = new PlanStepRun(
                runId: $runId,
                stepNo: $no,
                stepId: $step->id,
                kind: $step->kind,
                target: $step->target,
                label: $step->label,
                write: $write,
                arguments: $arguments,
                status: PlanStepRun::STATUS_RUNNING,
                createTime: date('Y-m-d H:i:s'),
            );

            $this->store()->upsertStep($stepRun);
            $this->store()->updateRun($runId, ['current_step' => $no]);
            $this->trackerHeartbeat($trackingId, $no, $tokens, microtime(true) - $startedAt);

            $attempts    = 0;
            $maxAttempts = max(1, $step->retries + 1);
            $output      = null;
            $verify      = PlanVerifyResult::skipped();
            $failure     = null;
            $blocked     = null;

            while (true) {
                $attempts++;

                $failure = null;
                $verify  = PlanVerifyResult::skipped();

                try {
                    $output = $this->dispatch($step, $arguments, $stepsOutput, $no);
                } catch (\Throwable $e) {
                    $output  = null;
                    $failure = ['code' => 'step_exception', 'message' => $e->getMessage()];
                }

                $signal = $output === null ? ['kind' => PlanApprovalSignal::KIND_NONE] : PlanApprovalSignal::of($output);

                if ($signal['kind'] === PlanApprovalSignal::KIND_PENDING) {
                    $blocked = $signal;
                    break;
                }

                if ($signal['kind'] === PlanApprovalSignal::KIND_REJECTED) {
                    $failure = ['code' => self::ABORT_REJECTED, 'message' => '审批被驳回：' . (string) $signal['reason']];
                    break;
                }

                if ($signal['kind'] === PlanApprovalSignal::KIND_PERMISSION_DENIED) {
                    $failure = ['code' => 'permission_denied', 'message' => (string) $signal['reason']];
                    break;
                }

                if ($failure !== null) {
                } elseif ($step->hasVerify()) {
                    $verify = $this->verifier()->verify($step, $output, $inputs, $stepsOutput);

                    if (!$verify->passed) {
                        $failure = ['code' => 'verify_failed', 'message' => $verify->firstFailure()];
                    }
                }

                if ($failure === null) {
                    break;
                }

                if ($attempts >= $maxAttempts) {
                    break;
                }

                $trace[] = ['step' => 'plan.retry', 'message' => sprintf(
                    '步骤 %s 第 %d 次失败（%s），重试（剩余 %d 次）',
                    $step->id,
                    $attempts,
                    (string) $failure['message'],
                    $maxAttempts - $attempts
                )];
            }

            $stepDuration = microtime(true) - $stepStarted;

            if ($blocked !== null) {
                $blockedRun = new PlanStepRun(
                    runId: $runId,
                    stepNo: $no,
                    stepId: $step->id,
                    kind: $step->kind,
                    target: $step->target,
                    label: $step->label,
                    write: $write,
                    arguments: $arguments,
                    output: $output,
                    status: PlanStepRun::STATUS_BLOCKED,
                    attempts: $attempts,
                    duration: round($stepDuration, 3),
                    error: (string) $blocked['reason'],
                    approvalId: $blocked['approval_id'],
                    createTime: $stepRun->createTime,
                );

                $this->store()->upsertStep($blockedRun);
                $this->store()->updateRun($runId, [
                    'status'       => PlanRun::STATUS_PAUSED,
                    'current_step' => $no,
                    'total_tokens' => $tokens,
                    'total_duration' => round(microtime(true) - $startedAt, 3),
                ]);

                $recorded[] = $blockedRun;
                $trace[]    = ['step' => 'plan.paused', 'message' => sprintf(
                    '步骤 %s 被审批闸门拦下（%s，审批单 #%s），运行进入 paused',
                    $step->id,
                    (string) $blocked['mode'],
                    (string) $blocked['approval_id']
                )];

                return new PlanExecutionResult(
                    status: PlanRun::STATUS_PAUSED,
                    runId: $runId,
                    trackingId: $trackingId,
                    steps: $recorded,
                    outputs: $stepsOutput,
                    completed: $completed,
                    failed: $failed,
                    paused: true,
                    replans: $replans,
                    tokens: $tokens,
                    duration: round(microtime(true) - $startedAt, 3),
                    trace: $trace,
                    notes: array_merge($notes, [[
                        'code'    => 'plan.awaiting_approval',
                        'message' => '计划已暂停，等待人工审批；批准后从该步续跑（plan_resume），续跑会用同一份参数以命中审批单。',
                    ]]),
                );
            }

            if ($failure === null && $stepDuration > $limits['max_step_duration']) {
                $failure = [
                    'code'    => self::ABORT_STEP_TIMEOUT,
                    'message' => sprintf(
                        '单步耗时 %.3fs 超过上限 %ds（post-hoc 判定：PHP 同步执行无法中途抢占）',
                        $stepDuration,
                        $limits['max_step_duration']
                    ),
                ];
                $trace[] = ['step' => 'plan.step_timeout', 'message' => (string) $failure['message']];
            }

            if ($failure === null) {
                $stepsOutput[$step->id] = $output;

                $done = new PlanStepRun(
                    runId: $runId,
                    stepNo: $no,
                    stepId: $step->id,
                    kind: $step->kind,
                    target: $step->target,
                    label: $step->label,
                    write: $write,
                    arguments: $arguments,
                    output: $output,
                    verifyResult: $verify->toArray(),
                    status: PlanStepRun::STATUS_COMPLETED,
                    attempts: $attempts,
                    duration: round($stepDuration, 3),
                    createTime: $stepRun->createTime,
                );

                $this->store()->upsertStep($done);
                $recorded[] = $done;
                $completed++;
                $trace[] = ['step' => 'plan.step_done', 'message' => sprintf(
                    '步骤 %s（%s:%s）完成，用时 %.3fs，断言 %d 条%s',
                    $step->id,
                    $step->kind,
                    $step->target,
                    $stepDuration,
                    $verify->checked,
                    $verify->passed ? '' : '（未全通过）'
                )];

                $index++;

                continue;
            }

            $failedRun = new PlanStepRun(
                runId: $runId,
                stepNo: $no,
                stepId: $step->id,
                kind: $step->kind,
                target: $step->target,
                label: $step->label,
                write: $write,
                arguments: $arguments,
                output: $output,
                verifyResult: $verify->toArray(),
                status: PlanStepRun::STATUS_FAILED,
                attempts: $attempts,
                duration: round($stepDuration, 3),
                error: (string) $failure['message'],
                createTime: $stepRun->createTime,
            );

            $this->store()->upsertStep($failedRun);
            $recorded[] = $failedRun;
            $failed++;

            $trace[] = ['step' => 'plan.step_failed', 'message' => sprintf(
                '步骤 %s 失败（%s）：%s',
                $step->id,
                (string) $failure['code'],
                (string) $failure['message']
            )];

            if ((string) $failure['code'] === self::ABORT_REJECTED) {
                return $this->abort(
                    $runId, $trackingId, $recorded, $stepsOutput, $completed, $failed, $replans, $tokens, $startedAt,
                    self::ABORT_REJECTED,
                    (string) $failure['message'],
                    $trace
                );
            }

            if ($step->onVerifyFail === PlanStep::REPLAN && $replans < $limits['max_replans']) {
                $replanned = $this->tryReplan($plan, $index, $inputs, $stepsOutput, (string) $failure['message']);

                if ($replanned !== null) {
                    $replans++;
                    $tokens += $replanned['tokens'];

                    $steps   = array_merge(array_slice($steps, 0, $index), $replanned['steps']);
                    $trail   = [];
                    $trace[] = ['step' => 'plan.replanned', 'message' => sprintf(
                        '第 %d 次改道：剩余步骤被替换为 %d 步（消耗 token %d）',
                        $replans,
                        count($replanned['steps']),
                        $replanned['tokens']
                    )];

                    foreach ($replanned['notes'] as $note) {
                        $trail[] = $note;
                    }

                    $notes = array_merge($notes, $trail);

                    continue;
                }

                $trace[] = ['step' => 'plan.replan_unavailable', 'message' => '改道不可用（规划器未就绪或改道产物未通过校验），转为终止'];
            }

            $reason = $step->onVerifyFail === PlanStep::REPLAN
                ? sprintf('步骤 %s 失败且改道不可用/超预算', $step->id)
                : sprintf('步骤 %s 失败（%s）', $step->id, (string) $failure['code']);

            return $this->abort(
                $runId, $trackingId, $recorded, $stepsOutput, $completed, $failed, $replans, $tokens, $startedAt,
                self::ABORT_STEP_FAILED,
                $reason . '：' . (string) $failure['message'],
                $trace
            );
        }

        $duration = round(microtime(true) - $startedAt, 3);

        $this->store()->updateRun($runId, [
            'status'         => PlanRun::STATUS_COMPLETED,
            'current_step'   => count($steps),
            'total_tokens'   => $tokens,
            'total_duration' => $duration,
            'error'          => null,
        ]);
        $this->trackerFinish($trackingId, sprintf('计划完成：%d 步', $completed), null, $completed, $tokens, $duration);

        $trace[] = ['step' => 'plan.completed', 'message' => sprintf('计划完成：%d 步成功，用时 %.3fs', $completed, $duration)];

        return new PlanExecutionResult(
            status: PlanRun::STATUS_COMPLETED,
            runId: $runId,
            trackingId: $trackingId,
            steps: $recorded,
            outputs: $stepsOutput,
            completed: $completed,
            failed: $failed,
            paused: false,
            replans: $replans,
            tokens: $tokens,
            duration: $duration,
            trace: $trace,
            notes: $notes,
        );
    }

    /**
     * 计划级**执行前闸门**＼�
     *
     * 既有事实：`ApprovalManager::gate()` 对 `user_id <= 0`（控制台 / 队列 / 定时任务）
     * **直接跳过审批**（`src/security/ApprovalManager.php:78-84`）。也就是说，
     * 异步执行的计划里写操作**不会有审批单** —— 这与 B1 的验收标准「每个写操作可审批」冲突。
     *
     * B1 的处置：**不动全局行为，只在计划这一层加严** ——
     *
     * - `plan.require_user_context_for_writes = true`（默认）：含写步骤 + 系统上下文 ⇒ **拒绝执行**；
     * - 置 `false`：允许执行，由调用方在 note 里标注 `system_context_writes`（不做「静默跳过」）。
     *
     * 执行器与「异步入队前的预检」**共用这一个实现**，避免两处判定漂移。
     *
     * @param array<string, mixed> $context
     * @return string|null `null` = 放行；字符串 = 拒绝原因
     */
    public function guardReason(PlanDefinition $plan, array $context = []): ?string
    {
        if (!$this->requireUserContextForWrites()) {
            return null;
        }

        $userId = (int) ($context['user_id'] ?? 0);

        if ($userId > 0) {
            return null;
        }

        if (!$this->hasWriteSteps($plan)) {
            return null;
        }

        return sprintf(
            '计划含写步骤，但当前是系统上下文（user_id=%d）：既有审批链在此上下文下会跳过审批'
            . '（ApprovalManager.php:78-84），因此按 fail-closed 拒绝执行。'
            . '请由登录用户发起，或显式设置 plan.require_user_context_for_writes=false。',
            $userId
        );
    }

    /**
     * 是否要求「含写步骤的计划必须有登录用户上下文」（默认 true）
     */
    public function requireUserContextForWrites(): bool
    {
        return (bool) $this->planOpt('require_user_context_for_writes', true);
    }

    /**
     * 计划是否**实际**含写步骤
     *
     * 用 {@see self::writeFlagOf()} 而非 `PlanStep::$write`：后者在「未过校验的计划」上是 `false`，
     * 会让闸门被绕过。
     */
    public function hasWriteSteps(PlanDefinition $plan): bool
    {
        foreach ($plan->steps as $step) {
            if ($step instanceof PlanStep && $this->writeFlagOf($step)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 执行**单个**步骤（供物化外壳 `plan:step` 节点复用同一条分发逻辑）
     *
     * 这是「物化产物与计划执行是同一实现」的落点：外壳节点不重写分发，而是调这里。
     *
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $stepsOutput
     */
    public function runStep(PlanStep $step, array $arguments = [], array $stepsOutput = [], int $stepNo = 0): mixed
    {
        return $this->dispatch($step, $arguments, $stepsOutput, $stepNo);
    }

    /**
     * 从已落库的运行续跑（B1-4 的 `plan_resume` 直接用这个方法）
     *
     * 续跑点由**已完成步骤**决定：最后一个 `completed` 之后；
     * 若存在 `blocked_pending_approval` 的步骤，则**从该步重跑**（这是 `ApprovalManager`
     * 「批准后重新发起同一调用」语义在计划上的落地）。
     */
    public function resume(int $runId, array $context = []): PlanExecutionResult
    {
        $run = $this->store()->findRun($runId);

        if ($run === null) {
            return new PlanExecutionResult(
                status: PlanRun::STATUS_ABORTED,
                runId: $runId,
                error: 'plan_run_not_found',
                trace: [['step' => 'plan.resume', 'message' => sprintf('运行 #%d 不存在', $runId)]],
            );
        }

        $plan     = PlanDefinition::fromArray($run->plan);
        $outputs  = [];
        $fromStep = 1;

        foreach ($this->store()->findSteps($runId) as $stepRun) {
            if ($stepRun->status === PlanStepRun::STATUS_COMPLETED) {
                $outputs[$stepRun->stepId] = $stepRun->output;

                if ($stepRun->stepNo >= $fromStep) {
                    $fromStep = $stepRun->stepNo + 1;
                }
            } elseif ($stepRun->isBlocked() && $stepRun->stepNo < $fromStep) {
                $fromStep = $stepRun->stepNo;
            }
        }

        $this->store()->resetRunningSteps($runId);

        return $this->execute($plan, is_array($run->plan['inputs'] ?? null) ? $run->plan['inputs'] : [], $context + [
            'run_id'       => $runId,
            'tracking_id'  => $run->trackingId,
            'from_step'    => $fromStep,
            'steps_output' => $outputs,
            'goal'         => $run->goal,
            'session_id'   => $run->sessionId,
            'user_id'      => $run->userId,
            'user_type'    => $run->userType,
            'source'       => $run->source,
        ]);
    }


    /**
     * 按 `kind` 分发到既有能力
     *
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $stepsOutput
     */
    protected function dispatch(PlanStep $step, array $arguments, array $stepsOutput, int $stepNo): mixed
    {
        $before = new ToolExecute(ToolExecute::PHASE_BEFORE, $step->target, $arguments);
        $this->dispatchEvent($before);

        if ($before->skip) {
            return $before->mockResult
                ?? json_encode(['error' => "Step '{$step->target}' blocked"], JSON_UNESCAPED_UNICODE);
        }

        $result = match ($step->kind) {
            PlanStep::KIND_SKILL  => $this->invokeSkill($step->target, $arguments),
            PlanStep::KIND_NODE   => $this->invokeNode($step->target, $arguments, $stepsOutput, $stepNo),
            PlanStep::KIND_ACTION => $this->invokeAction($step->target, $arguments),
            default               => $this->invokeTool($step->target, $arguments),
        };

        $after = new ToolExecute(ToolExecute::PHASE_AFTER, $step->target, $arguments, $result);
        $this->dispatchEvent($after);

        return $after->result ?? $result;
    }

    /**
     * 事件派发接缝（protected，测试可 override 注入假派发器，避免依赖框架 Event facade）
     */
    protected function dispatchEvent(ToolExecute $event): void
    {
        Event::trigger($event);
    }

    /**
     * Skill 步骤（`SkillManager::execute()`；执行前会过 Skill 级权限/审批）
     *
     * @param array<string, mixed> $input
     */
    protected function invokeSkill(string $target, array $input): mixed
    {
        $manager = $this->skillManager ??= app(SkillManager::class);

        return $manager->execute($target, $input)->getOutput();
    }

    /**
     * 工具步骤（`ToolRegistry::execute()`；**审批/权限/RBAC 都在这一层生效**）
     *
     * @param array<string, mixed> $arguments
     */
    protected function invokeTool(string $target, array $arguments): mixed
    {
        return ($this->toolRegistry ??= ToolRegistry::instance())->execute($target, $arguments);
    }

    /**
     * 声明式动作步骤（默认与工具同路：`ActionTool` 本就注册在 `ToolRegistry`）
     *
     * @param array<string, mixed> $arguments
     */
    protected function invokeAction(string $target, array $arguments): mixed
    {
        return $this->invokeTool($target, $arguments);
    }

    /**
     * 插件节点步骤（与 `PipelineEngine::executePluginNode()` 同一套语义）
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed> $stepsOutput
     */
    protected function invokeNode(string $target, array $input, array $stepsOutput = [], int $stepNo = 0): mixed
    {
        $context = new WorkflowContext(
            'plan',
            uniqid('exec_', true),
            $stepNo,
            array_merge($input, ['steps' => $stepsOutput])
        );

        $config = is_array($input['_config'] ?? null) ? $input['_config'] : [];
        $result = NodeRegistry::instance()->runNode($target, $config, $input, $context);

        if (($result['error'] ?? null) !== null) {
            throw new \RuntimeException('插件节点执行失败：' . (string) $result['error']);
        }

        return is_string($result['data']) ? $result['data'] : json_encode($result['data'], JSON_UNESCAPED_UNICODE);
    }


    /**
     * 带「已完成轨迹」重新规划**剩余步骤**＼�
     *
     * 诚实记录一处语义约束：改道产物是**一份新计划**，`PlanValidator` 只校验计划**内部**的引用，
     * 因此新步骤**不能**直接 `$steps.<已完成步骤>` 引用旧步骤；
     * 执行器把已完成步骤的输出放进 `inputs['completed']`，改道计划应通过
     * `$input.completed.<步骤id>.<路径>` 取用。
     *
     * @param array<string, mixed> $inputs
     * @param array<string, mixed> $stepsOutput
     * @return array{steps: PlanStep[], tokens:int, notes:array}|null 改道不可用/不可行时返回 null
     */
    protected function tryReplan(PlanDefinition $plan, int $failedIndex, array $inputs, array $stepsOutput, string $reason): ?array
    {
        $planner = $this->planner();

        if ($planner === null) {
            return null;
        }

        $goal = sprintf(
            "%s\n\n【改道要求】上一步未通过自证（原因：%s）。请只规划**剩余**的工作，不要重复已经完成的步骤；"
            . "已完成步骤的输出可通过 `\$input.completed.<步骤id>.<路径>` 取用。",
            $plan->goal,
            $reason
        );

        $result = $planner->plan($goal, array_merge($inputs, ['completed' => $stepsOutput]), $plan->limits);

        if (!$result->ok || $result->plan === null || $result->plan->steps === []) {
            return null;
        }

        return [
            'steps'  => array_values($result->plan->steps),
            'tokens' => $result->totalTokens(),
            'notes'  => [[
                'code'    => 'plan.replanned',
                'message' => sprintf(
                    '第 %d 步失败后触发改道：新剩余计划 %d 步（LLM 调用 %d 次，消耗 token %d）',
                    $failedIndex + 1,
                    count($result->plan->steps),
                    $result->attempts,
                    $result->totalTokens()
                ),
            ]],
        ];
    }


    protected function store(): PlanRunStoreInterface
    {
        return $this->store ??= new DbPlanRunStore($this->planOptions());
    }

    protected function verifier(): PlanVerifier
    {
        return $this->verifier ??= new PlanVerifier($this->planOptions());
    }

    /**
     * 改道用的规划器（`null` = 未注入且无法构造 ⇒ 改道不可用，调用方转为终止）
     */
    protected function planner(): ?PlanPlanner
    {
        try {
            return $this->planner ??= new PlanPlanner($this->planOptions());
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * 该步骤是否写步骤
     *
     * 计划已过 `PlanValidator` 时，`PlanStep::$write` 已是「按能力 access 推导」的结果；
     * 但执行器可能被直接调用（未过校验的计划），此时**自行推导**兜底 ——
     * 落库的 `write` 标记用于审计，失真比多查一次注册表更糟。
     */
    protected function writeFlagOf(PlanStep $step): bool
    {
        if ($step->write) {
            return true;
        }

        try {
            return $this->lookup()->isWrite($step->kind, $step->target);
        } catch (\Throwable) {
            return false;
        }
    }

    protected function lookup(): PlanCapabilityLookupInterface
    {
        return $this->lookup ??= new RegistryCapabilityLookup();
    }

    protected function values(): PlanValueResolver
    {
        return $this->values ??= new PlanValueResolver();
    }

    protected function trackerHeartbeat(string $trackingId, int $stepNo, int $tokens, float $elapsed): void
    {
        if ($trackingId === '') {
            return;
        }

        try {
            ExecutionTracker::heartbeat($trackingId, [
                'steps'        => $stepNo,
                'total_tokens' => $tokens,
                'duration'     => round($elapsed, 3),
            ]);
        } catch (\Throwable) {
        }
    }

    protected function trackerFinish(string $trackingId, ?string $result, ?string $error, int $steps, int $tokens, float $duration): void
    {
        if ($trackingId === '') {
            return;
        }

        try {
            ExecutionTracker::finish($trackingId, $result, $error, [
                'steps'        => $steps,
                'total_tokens' => $tokens,
                'duration'     => $duration,
            ]);
        } catch (\Throwable) {
        }
    }


    /**
     * @param array<int, PlanStepRun> $recorded
     * @param array<string, mixed>    $stepsOutput
     * @param array<int, array{step:string, message:string}> $trace
     */
    private function abort(
        int $runId,
        string $trackingId,
        array $recorded,
        array $stepsOutput,
        int $completed,
        int $failed,
        int $replans,
        int $tokens,
        float $startedAt,
        string $code,
        string $reason,
        array $trace = []
    ): PlanExecutionResult {
        $duration = round(microtime(true) - $startedAt, 3);

        $this->store()->updateRun($runId, [
            'status'         => PlanRun::STATUS_ABORTED,
            'total_tokens'   => $tokens,
            'total_duration' => $duration,
            'error'          => $code . '：' . $reason,
        ]);
        $this->trackerFinish($trackingId, null, $code . '：' . $reason, $completed, $tokens, $duration);

        $trace[] = ['step' => 'plan.aborted', 'message' => sprintf('计划终止（%s）：%s', $code, $reason)];

        return new PlanExecutionResult(
            status: PlanRun::STATUS_ABORTED,
            runId: $runId,
            trackingId: $trackingId,
            steps: $recorded,
            outputs: $stepsOutput,
            completed: $completed,
            failed: $failed,
            paused: false,
            replans: $replans,
            tokens: $tokens,
            duration: $duration,
            trace: $trace,
            error: $code . '：' . $reason,
        );
    }

    /**
     * 条件求值（与 `PipelineEngine::evaluateCondition()` 同一子集）
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed> $stepsOutput
     * @return bool|string `true` = 满足；`false` 或**原因字符串** = 不满足/无法判定
     */
    private function conditionMet(string $condition, array $input, array $stepsOutput): bool|string
    {
        $expr = trim($condition);

        if (preg_match('/^(\$[A-Za-z0-9_.\-]+)\s*(==|!=)\s*(.+)$/', $expr, $matches) !== 1) {
            return true;
        }

        [, $path, $operator, $rawExpected] = $matches;

        $left     = $this->values()->resolve($path, $input, $stepsOutput);
        $expected = trim($rawExpected);

        if (strcasecmp($expected, 'null') === 0) {
            $expected = null;
        } elseif ($expected === '""' || $expected === "''") {
            $expected = '';
        } elseif (preg_match('/^"([^"]*)"$/', $expected, $string) === 1) {
            $expected = $string[1];
        } elseif (preg_match("/^'([^']*)'$/", $expected, $string) === 1) {
            $expected = $string[1];
        } else {
            $expected = is_numeric($expected) ? $expected + 0 : $expected;
        }

        $matched = $operator === '==' ? ($left == $expected) : ($left != $expected);

        return $matched ? true : sprintf('条件不满足：%s %s %s', $path, $operator, is_scalar($expected) ? (string) $expected : 'null');
    }
}
