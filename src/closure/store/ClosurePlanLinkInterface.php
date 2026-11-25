<?php
declare(strict_types=1);

namespace wise\agent\closure\store;

/**
 * 「审批单 → 计划运行」关联查询接缝＼�
 *
 * 自动续跑需要回答：**这张审批单，是不是某次计划运行「正在等」的那一步？**
 *
 * ## 为什么不做成 `PlanRunStoreInterface` 的方法
 *
 * B1 的运行仓储是「按 run_id 操作」的（`findRun` / `findSteps`），
 * 而这里是**反向查询**（按 `approval_id` 找 run）。塞进 B1 的接口会：
 * ① 让 B1 的测试替身被迫实现一个与它无关的方法；② 模糊「计划域」与「闭环域」的边界。
 * 因此单独一个接缝 + 单独一个 DB 实现。
 *
 * 关联键是 `ai_plan_steps.approval_id` —— **B1-3 落库时就写进去的**（被审批拦下的那一步），
 * 所以这里不需要任何新列、也不需要改 B1。
 */
interface ClosurePlanLinkInterface
{
    /**
     * 按审批单定位「仍在等这一步」的计划运行
     *
     * 只在**同时**满足下列条件时返回（否则返回 `null`，让调用方把它当成"非计划类审批"）：
     * - `ai_plan_steps.approval_id = $approvalId` 且该步状态为 `blocked_pending_approval`；
     * - 其所属运行存在。
     *
     * @return array{run_id:int, status:string, user_id:int, step_id:string, step_no:int, arguments:array<string,mixed>}|null
     */
    public function findPausedRunByApproval(int $approvalId): ?array;
}
