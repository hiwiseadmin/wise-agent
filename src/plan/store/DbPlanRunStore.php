<?php
declare(strict_types=1);

namespace wise\agent\plan\store;

use think\facade\Db;
use wise\agent\plan\PlanRun;
use wise\agent\plan\PlanStepRun;
use wise\agent\plan\PlanOptions;

/**
 * 计划运行的数据库实现（B1-3）
 *
 * 表结构见 `database/migrations/20261016000001_create_ai_plan_runs_table.php`
 * 与 `..._20261016000002_create_ai_plan_steps_table.php`；列名与
 * {@see PlanRun::toArray()} / {@see PlanStepRun::toArray()} **一一对应**。
 *
 * 设计取舍：
 * - 复杂字段（`plan` / `metadata` / `arguments` / `output` / `verify_result`）统一 JSON 编码；
 * - **JSON 编码失败不抛异常**，落 `null` 并让上层从「步骤记录缺失」里发现异常
 *   （计划执行不能因为「记不下日志」而中断业务）；
 * - `resetRunningSteps()` 是续跑的前提：上次进程被杀会留下 `running` 残留。
 */
class DbPlanRunStore implements PlanRunStoreInterface
{
    use PlanOptions;

    private const TABLE_RUNS  = 'ai_plan_runs';
    private const TABLE_STEPS = 'ai_plan_steps';

    /**
     * @param array<string, mixed> $options `plan.*`（支持 `plan.table.runs|steps` 覆盖表名）
     */
    public function __construct(array $options = [])
    {
        $this->setPlanOptions($options);
    }

    public function createRun(PlanRun $run): int
    {
        $data = $run->toArray();
        unset($data['run_id']);

        $data['plan']       = $this->encode($data['plan'] ?? []);
        $data['metadata']   = $this->encode($data['metadata'] ?? []);
        $data['create_time'] = $data['create_time'] !== '' ? $data['create_time'] : date('Y-m-d H:i:s');
        $data['update_time'] = $data['update_time'] !== '' ? $data['update_time'] : $data['create_time'];

        return (int) Db::name($this->tableRuns())->insertGetId($data);
    }

    public function updateRun(int $runId, array $fields): void
    {
        if ($runId <= 0 || $fields === []) {
            return;
        }

        foreach (['plan', 'metadata'] as $jsonKey) {
            if (array_key_exists($jsonKey, $fields)) {
                $fields[$jsonKey] = $this->encode($fields[$jsonKey]);
            }
        }

        $fields['update_time'] = date('Y-m-d H:i:s');

        Db::name($this->tableRuns())->where('run_id', $runId)->update($fields);
    }

    public function findRun(int $runId): ?PlanRun
    {
        if ($runId <= 0) {
            return null;
        }

        $row = Db::name($this->tableRuns())->where('run_id', $runId)->find();

        return is_array($row) ? PlanRun::fromArray($row) : null;
    }

    public function upsertStep(PlanStepRun $step): void
    {
        $data = $step->toArray();
        $data['output']        = $this->encode($data['output']);
        $data['arguments']     = $this->encode($data['arguments'] ?? []);
        $data['verify_result'] = $this->encode($data['verify_result'] ?? []);
        $data['update_time']   = date('Y-m-d H:i:s');

        $exists = Db::name($this->tableSteps())
            ->where('run_id', $step->runId)
            ->where('step_no', $step->stepNo)
            ->find();

        if (is_array($exists)) {
            unset($data['create_time']);

            Db::name($this->tableSteps())
                ->where('run_id', $step->runId)
                ->where('step_no', $step->stepNo)
                ->update($data);

            return;
        }

        $data['create_time'] = $step->createTime !== '' ? $step->createTime : date('Y-m-d H:i:s');
        Db::name($this->tableSteps())->insert($data);
    }

    public function findSteps(int $runId): array
    {
        if ($runId <= 0) {
            return [];
        }

        $rows = Db::name($this->tableSteps())
            ->where('run_id', $runId)
            ->order('step_no', 'asc')
            ->select()
            ->toArray();

        return array_map(static fn(array $row): PlanStepRun => PlanStepRun::fromArray($row), $rows);
    }

    public function resetRunningSteps(int $runId): void
    {
        if ($runId <= 0) {
            return;
        }

        Db::name($this->tableSteps())
            ->where('run_id', $runId)
            ->where('status', PlanStepRun::STATUS_RUNNING)
            ->update(['status' => PlanStepRun::STATUS_PENDING, 'update_time' => date('Y-m-d H:i:s')]);
    }

    protected function tableRuns(): string
    {
        return (string) $this->planOpt('table.runs', self::TABLE_RUNS);
    }

    protected function tableSteps(): string
    {
        return (string) $this->planOpt('table.steps', self::TABLE_STEPS);
    }

    /**
     * JSON 编码：失败返回 `null`（**不抛异常**，见类注释）
     */
    protected function encode(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            return $value;
        }

        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE);

        return $encoded === false ? null : $encoded;
    }
}
