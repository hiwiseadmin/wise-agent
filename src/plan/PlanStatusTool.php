<?php
declare(strict_types=1);

namespace wise\agent\plan;

use wise\agent\menu\MenuActionResult;
use wise\agent\plan\store\DbPlanRunStore;
use wise\agent\plan\store\PlanRunStoreInterface;
use wise\agent\query\exception\QueryParamException;
use wise\agent\security\CapabilityAccess;
use wise\agent\tool\BaseTool;

/**
 * `plan_status` —— 计划的逐步状态（B1-4 / 附录五 验收 7）
 *
 * 只读查询，不触碰任何能力。返回：
 *
 * - 运行级：`run_id / status / current_step / total_steps / goal / error / replans?`
 * - 步骤级：`step_no / step_id / kind / target / write / status / attempts / duration / approval_id / error`
 *   + 断言结论（`verify_result.passed` / `failures`）
 * - 可直接行动的信息：`paused` + `resume_hint`（批准后用 `plan_resume`）。
 *
 * 步骤级明细是「可回放」的基础：每步的**解析后参数**（`arguments`）与输出都在落库行里。
 */
class PlanStatusTool extends BaseTool
{
    use PlanOptions;
    use PlanUserContext;

    public const NAME = 'plan_status';

    private ?PlanRunStoreInterface $store = null;

    /**
     * @param array<string, mixed> $options `plan.*` 覆盖项
     */
    public function __construct(array $options = [], ?PlanRunStoreInterface $store = null)
    {
        $this->setPlanOptions($options);
        $this->store = $store;
    }

    public function getDescription(): string
    {
        return '查询一次计划运行的逐步状态（只读）：每一步的种类/目标/是否写/状态/耗时/审批单号与断言结论；'
            . '若某步在等审批，会给出续跑提示。计划默认异步执行，用本工具跟进进度。';
    }

    /**
     * @return array<string, mixed>
     */
    public function getParameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'run_id'       => ['type' => 'integer', 'description' => '计划运行 id（plan_run 的返回值）'],
                'with_outputs' => [
                    'type'        => 'boolean',
                    'description' => '是否回传每一步的输出（默认 true；输出可能较大，可按需关闭）',
                ],
            ],
            'required'   => ['run_id'],
        ];
    }

    public function getAccess(): string
    {
        return CapabilityAccess::READ;
    }

    public function getRiskLevel(): string
    {
        return CapabilityAccess::RISK_LOW;
    }

    public function getPermission(): string
    {
        if (!(bool) $this->planOpt('require_ai_permission', false)) {
            return '';
        }

        return 'plan.status';
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function execute(array $arguments): string
    {
        $runId = (int) ($arguments['run_id'] ?? 0);

        if ($runId <= 0) {
            return $this->json(MenuActionResult::failure('缺少 run_id', QueryParamException::CODE_INVALID, [
                'stage' => MenuActionResult::STAGE_ARGUMENTS,
            ]));
        }

        try {
            $run = $this->store()->findRun($runId);

            if ($run === null) {
                return $this->json(MenuActionResult::failure(
                    sprintf('未找到计划运行 #%d（可能已过期清理，或 run_id 有误）', $runId),
                    QueryParamException::CODE_INVALID,
                    ['run_id' => $runId, 'stage' => MenuActionResult::STAGE_ARGUMENTS]
                ));
            }

            $currentUserId = $this->currentUserId();
            if ($run->userId > 0 && $currentUserId !== $run->userId) {
                return $this->json(MenuActionResult::failure(
                    sprintf('计划 #%d 由用户 #%d 发起，当前用户 #%d 无权查看', $runId, $run->userId, $currentUserId),
                    QueryParamException::CODE_INVALID,
                    ['run_id' => $runId, 'requester_id' => $run->userId, 'current_user_id' => $currentUserId]
                ));
            }

            $withOutputs = !array_key_exists('with_outputs', $arguments) || (bool) $arguments['with_outputs'];
            $steps       = [];
            $completed   = 0;

            foreach ($this->store()->findSteps($runId) as $stepRun) {
                if ($stepRun->status === PlanStepRun::STATUS_COMPLETED) {
                    $completed++;
                }

                $row = [
                    'step_no'       => $stepRun->stepNo,
                    'step_id'       => $stepRun->stepId,
                    'kind'          => $stepRun->kind,
                    'target'        => $stepRun->target,
                    'label'         => $stepRun->label,
                    'write'         => $stepRun->write,
                    'status'        => $stepRun->status,
                    'attempts'      => $stepRun->attempts,
                    'duration'      => $stepRun->duration,
                    'verify_passed' => $stepRun->verifyResult['passed'] ?? null,
                    'verify_failures' => $stepRun->verifyResult['failures'] ?? [],
                    'approval_id'   => $stepRun->approvalId,
                    'error'         => $stepRun->error,
                    'arguments'     => $stepRun->arguments,
                ];

                if ($withOutputs) {
                    $row['output'] = $stepRun->output;
                }

                $steps[] = $row;
            }

            $paused  = $run->status === PlanRun::STATUS_PAUSED;
            $blocked = null;

            foreach ($this->store()->findSteps($runId) as $stepRun) {
                if ($stepRun->isBlocked()) {
                    $blocked = ['step_id' => $stepRun->stepId, 'approval_id' => $stepRun->approvalId];

                    break;
                }
            }

            $payload = [
                'code' => 0,
                'msg'  => sprintf('运行 #%d：%s（%d/%d 步）', $runId, $run->status, $run->currentStep, $run->totalSteps),
                'data' => [
                    'run_id'       => $runId,
                    'status'       => $run->status,
                    'goal'         => $run->goal,
                    'current_step' => $run->currentStep,
                    'total_steps'  => $run->totalSteps,
                    'total_tokens' => $run->totalTokens,
                    'duration'     => $run->totalDuration,
                    'error'        => $run->error,
                    'progress'     => $run->totalSteps > 0 ? round($completed / $run->totalSteps, 4) : 0.0,
                    'completed_steps' => $completed,
                    'paused'       => $paused,
                    'blocked_step' => $blocked,
                    'resume_hint'  => $paused
                        ? sprintf(
                            '第 %s 步在等人工审批（审批单 #%s）。批准后调用 plan_resume(run_id=%d) 从该步续跑。',
                            (string) ($blocked['step_id'] ?? '?'),
                            (string) ($blocked['approval_id'] ?? '?'),
                            $runId
                        )
                        : null,
                    'steps'        => $steps,
                ],
            ];

            return $this->json(MenuActionResult::normalize($payload));
        } catch (\Throwable $e) {
            return $this->json(MenuActionResult::fromException($e, [
                'run_id' => $runId,
                'stage'  => MenuActionResult::STAGE_EXECUTION,
            ]));
        }
    }

    protected function store(): PlanRunStoreInterface
    {
        return $this->store ??= new DbPlanRunStore($this->planOptions());
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function json(array $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_UNICODE) ?: '{"code":1,"msg":"json encode failed"}';
    }
}
