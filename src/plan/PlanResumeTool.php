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
 * `plan_resume` —— 批准后从被阻塞的那一步续跑＼�
 *
 * ## 为什么是「续跑」而不是「重跑」
 *
 * `ApprovalManager` 的放行语义是「批准后**重新发起同一调用** → 命中审批单并原子消费一次」
 * （`src/security/ApprovalManager.php:19-24`）。对计划而言，「重新发起」必须是
 * **从被拦下的那一步继续**，否则前面已成功的**写步骤会被重复执行**（真实的重复副作用）。
 *
 * 因此本工具：① 只调 `PlanExecutor::resume()`；② 续跑用的是**已落库的解析后参数**，
 * 保证参数指纹不变（审批单按参数匹配）；③ 校验**同人续跑**。
 *
 * ## 身份一致性（硬校验）
 *
 * 审批单的匹配维度包含申请人（`ApprovalManager` 匹配维度），换个人续跑会出现
 * 「批了也消费不到」。因此当运行的发起人 `user_id > 0` 时，续跑者必须与之相同；
 * 系统上下文（`user_id <= 0`）的运行不在此列（它本就跳过审批，由 fail-closed 闸门管）。
 */
class PlanResumeTool extends BaseTool
{
    use PlanOptions;
    use PlanUserContext;
    use PlanResultMapping;

    public const NAME = 'plan_resume';

    private ?PlanExecutor $executor = null;

    private ?PlanRunStoreInterface $store = null;

    /**
     * @param array<string, mixed> $options `plan.*` 覆盖项
     */
    public function __construct(
        array $options = [],
        ?PlanExecutor $executor = null,
        ?PlanRunStoreInterface $store = null
    ) {
        $this->setPlanOptions($options);
        $this->executor = $executor;
        $this->store    = $store;
    }

    public function getDescription(): string
    {
        return '从被审批拦下的那一步续跑一个计划（批准后调用）。只会重跑「尚未完成」的部分，'
            . '已完成的步骤不会重复执行；续跑重发同一份参数以命中审批单。'
            . '必须是原发起人本人调用。';
    }

    /**
     * @return array<string, mixed>
     */
    public function getParameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'run_id' => ['type' => 'integer', 'description' => '要续跑的计划运行 id'],
            ],
            'required'   => ['run_id'],
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

    public function requireConfirmation(): bool
    {
        return false;
    }

    public function getPermission(): string
    {
        if (!(bool) $this->planOpt('require_ai_permission', false)) {
            return '';
        }

        return 'plan.resume';
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function execute(array $arguments): string
    {
        $runId = (int) ($arguments['run_id'] ?? 0);

        if ($runId <= 0) {
            return $this->encodePayload(MenuActionResult::failure('缺少 run_id', QueryParamException::CODE_INVALID, [
                'stage' => MenuActionResult::STAGE_ARGUMENTS,
            ]));
        }

        try {
            $run = $this->store()->findRun($runId);

            if ($run === null) {
                return $this->encodePayload(MenuActionResult::failure(
                    sprintf('未找到计划运行 #%d', $runId),
                    QueryParamException::CODE_INVALID,
                    ['run_id' => $runId, 'stage' => MenuActionResult::STAGE_ARGUMENTS]
                ));
            }

            if ($run->isFinished()) {
                return $this->encodePayload(MenuActionResult::normalize([
                    'code' => 0,
                    'msg'  => sprintf('运行 #%d 已结束（%s），无需续跑', $runId, $run->status),
                    'data' => [
                        'run_id'  => $runId,
                        'status'  => $run->status,
                        'noop'    => true,
                        'summary' => sprintf('运行 #%d 已是终态', $runId),
                    ],
                ]));
            }

            $currentUserId = $this->currentUserId();

            if ($run->userId > 0 && $currentUserId !== $run->userId) {
                return $this->encodePayload(MenuActionResult::failure(
                    sprintf(
                        '计划 #%d 由用户 #%d 发起，当前用户 #%d 不能续跑：审批单按申请人匹配，换人续跑无法消费审批单',
                        $runId,
                        $run->userId,
                        $currentUserId
                    ),
                    QueryParamException::CODE_INVALID,
                    [
                        'structured' => true,
                        'data'       => ['run_id' => $runId, 'requester_id' => $run->userId, 'current_user_id' => $currentUserId],
                        'text'       => '',
                        'stage'      => 'guard',
                        'hint'       => '请由原发起人续跑，或由原发起人重新发起一次计划。',
                    ]
                ));
            }

            $result = $this->executor()->resume($runId, [
                'user_id'   => $currentUserId,
                'user_type' => $this->currentUserType(),
            ]);

            return $this->encodeExecution($result, 'resume');
        } catch (\Throwable $e) {
            return $this->encodePayload(MenuActionResult::fromException($e, [
                'run_id' => $runId,
                'stage'  => MenuActionResult::STAGE_EXECUTION,
            ]));
        }
    }

    protected function executor(): PlanExecutor
    {
        return $this->executor ??= new PlanExecutor($this->planOptions(), $this->store());
    }

    protected function store(): PlanRunStoreInterface
    {
        return $this->store ??= new DbPlanRunStore($this->planOptions());
    }
}
