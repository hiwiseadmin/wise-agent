<?php
declare(strict_types=1);

namespace wise\agent\plan\store;

use wise\agent\plan\PlanRun;
use wise\agent\plan\PlanStepRun;

/**
 * 计划运行落库接缝＼�
 *
 * 抽成接口有两个实打实的理由（不是为抽象而抽象）：
 *
 * 1. **离线可测**：执行器要写 `ai_plan_runs` / `ai_plan_steps`，但单测环境没有数据库；
 *    注入内存实现即可完整验证「逐步落库 + 从第 N 步续跑」的行为；
 * 2. **未来可换**：C1（统一任务中心）要收口任务落库时，只换实现，不动执行器。
 */
interface PlanRunStoreInterface
{
    /**
     * 创建一次运行，返回 `run_id`
     */
    public function createRun(PlanRun $run): int;

    /**
     * 更新运行字段（只更新传入的键）
     *
     * @param array<string, mixed> $fields
     */
    public function updateRun(int $runId, array $fields): void;

    public function findRun(int $runId): ?PlanRun;

    /**
     * 写入/更新一个步骤（按 `run_id + step_no` 唯一）
     */
    public function upsertStep(PlanStepRun $step): void;

    /**
     * 某次运行的全部步骤（按 `step_no` 升序）
     *
     * @return PlanStepRun[]
     */
    public function findSteps(int $runId): array;

    /**
     * 重置某次运行的步骤状态（续跑前把 `running` 的残留步骤打回 `pending`）
     */
    public function resetRunningSteps(int $runId): void;
}
