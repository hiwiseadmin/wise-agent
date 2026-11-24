<?php
declare(strict_types=1);

namespace wise\agent\plan;

use wise\agent\menu\MenuActionResult;
use wise\agent\query\exception\QueryParamException;

/**
 * 计划执行结果 → 工具响应（B1-4）
 *
 * `plan_run` / `plan_resume` 的结果形态必须**完全一致**（同一个运行可能先 run 后 resume），
 * 因此映射只有这一份实现。
 *
 * ## 三种状态的响应语义
 *
 * | 执行状态 | 工具响应 |
 * |---|---|
 * | `completed` | `code:0` + 逐步结果 + 轨迹 |
 * | `paused` | **`code:0`** + `paused:true` + 续跑提示 —— 等待审批不是失败＼� |
 * | `aborted` | `code:1` + `stage`：系统上下文闸门 ⇒ `guard`，其余 ⇒ `execution` |
 */
trait PlanResultMapping
{
    /**
     * @param string $action `run` | `resume`（只影响文案）
     */
    protected function encodeExecution(PlanExecutionResult $result, string $action = 'run'): string
    {
        $data = $result->toArray() + [
            'action' => $action,
            'summary' => $result->summary(),
        ];

        if ($result->isCompleted()) {
            return $this->encodePayload(MenuActionResult::normalize([
                'code' => 0,
                'msg'  => $result->summary(),
                'data' => $data,
            ]));
        }

        if ($result->paused) {
            $blocked = $result->blockedStep();

            return $this->encodePayload(MenuActionResult::normalize([
                'code' => 0,
                'msg'  => $result->summary(),
                'data' => $data + [
                    'paused'        => true,
                    'blocked_step'  => $blocked?->stepId,
                    'approval_id'   => $blocked?->approvalId,
                    'resume_hint'   => sprintf(
                        '该步在等人工审批（审批单 #%s）。批准后调用 plan_resume（run_id=%d）从该步续跑；'
                        . '续跑会重发同一份参数以命中审批单。',
                        (string) $blocked?->approvalId,
                        $result->runId
                    ),
                ],
            ]));
        }

        $reason = (string) ($result->error ?? '计划已终止');

        return $this->encodePayload(MenuActionResult::failure(
            '计划执行失败：' . $reason,
            QueryParamException::CODE_INVALID,
            [
                'structured' => true,
                'data'       => $data,
                'text'       => '',
                'stage'      => str_contains($reason, PlanExecutor::ABORT_SYSTEM_CONTEXT) ? 'guard' : 'execution',
                'hint'       => str_contains($reason, PlanExecutor::ABORT_SYSTEM_CONTEXT)
                    ? '含写步骤的计划必须在登录用户上下文中执行（plan.require_user_context_for_writes）。'
                    : '可查看 data.steps 里失败步骤的 error 与 verify_result，或让 AI 重新规划。',
            ]
        ));
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function encodePayload(array $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_UNICODE) ?: '{"code":1,"msg":"json encode failed"}';
    }
}
