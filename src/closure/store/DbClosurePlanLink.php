<?php
declare(strict_types=1);

namespace wise\agent\closure\store;

use think\facade\Db;
use wise\agent\closure\ClosureOptions;
use wise\agent\plan\PlanRun;
use wise\agent\plan\PlanStepRun;

/**
 * 「审批单 → 计划运行」关联查询的数据库实现（B2-4）
 *
 * 表名从 `plan.table.*` 读（**与 B1 同一套配置键**）—— 不另立一套表名约定。
 */
class DbClosurePlanLink implements ClosurePlanLinkInterface
{
    use ClosureOptions;

    private const TABLE_STEPS = 'ai_plan_steps';
    private const TABLE_RUNS  = 'ai_plan_runs';

    /**
     * @param array<string, mixed> $options `closure.*` + `plan.table.*` 覆盖项
     */
    public function __construct(array $options = [])
    {
        $this->setClosureOptions($options);
    }

    public function findPausedRunByApproval(int $approvalId): ?array
    {
        if ($approvalId <= 0) {
            return null;
        }

        try {
            $step = Db::name($this->tableSteps())
                ->where('approval_id', $approvalId)
                ->where('status', PlanStepRun::STATUS_BLOCKED)
                ->order('step_no', 'desc')
                ->find();

            if (!is_array($step) || (int) ($step['run_id'] ?? 0) <= 0) {
                return null;
            }

            $run = Db::name($this->tableRuns())->where('run_id', (int) $step['run_id'])->find();

            if (!is_array($run)) {
                return null;
            }

            return [
                'run_id'    => (int) $run['run_id'],
                'status'    => (string) ($run['status'] ?? ''),
                'user_id'   => (int) ($run['user_id'] ?? 0),
                'step_id'   => (string) ($step['step_id'] ?? ''),
                'step_no'   => (int) ($step['step_no'] ?? 0),
                'arguments' => $this->decode($step['arguments'] ?? null),
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    protected function tableSteps(): string
    {
        return (string) $this->closureOpt('table.steps', self::TABLE_STEPS);
    }

    protected function tableRuns(): string
    {
        return (string) $this->closureOpt('table.runs', self::TABLE_RUNS);
    }

    /**
     * @return array<string, mixed>
     */
    protected function decode(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }

        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * 便于调用方断言「计划运行确实处于 paused」（用 B1 的常量，不写字面量）
     */
    public static function pausedStatus(): string
    {
        return PlanRun::STATUS_PAUSED;
    }
}
