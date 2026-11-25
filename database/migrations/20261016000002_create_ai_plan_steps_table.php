<?php

declare(strict_types=1);

use think\migration\Migrator;
use think\migration\db\Column;

/**
 * B1-3：计划步骤表
 *
 * 与 `wise\agent\plan\PlanStepRun::toArray()` **一一对应**。
 *
 * 两个字段承担了 B1 的关键语义，不能省：
 * - `arguments`：**解析后**的参数快照 —— 续跑时用它重建 `$steps` 上下文；
 *   且审批单按参数指纹匹配（`approval.request.scope = arguments`）时，
 *   续跑必须重发**同一份参数**，否则「批了也消费不到」＼�；
 * - `approval_id`：该步生成的审批单 id（追溯「谁批的、批的哪一步」）。
 */
class CreateAiPlanStepsTable extends Migrator
{
    public function up(): void
    {
        $table = $this->table('ai_plan_steps', [
            'engine'    => 'InnoDB',
            'comment'   => 'AI 计划步骤执行记录（可回放 / 可续跑）',
            'collation' => 'utf8mb4_unicode_ci',
        ]);

        $table->addColumn('run_id', 'integer', ['limit' => 11, 'null' => false, 'comment' => '所属运行ID'])
            ->addColumn('step_no', 'integer', ['limit' => 11, 'null' => false, 'comment' => '步骤序号（1 基）'])
            ->addColumn('step_id', 'string', ['limit' => 64, 'null' => true, 'comment' => '计划内的步骤ID'])
            ->addColumn('kind', 'string', ['limit' => 20, 'null' => true, 'comment' => 'skill/tool/action/node'])
            ->addColumn('target', 'string', ['limit' => 200, 'null' => true, 'comment' => '被引用能力名'])
            ->addColumn('label', 'string', ['limit' => 200, 'null' => true, 'comment' => '人类可读标签'])
            ->addColumn('write', 'boolean', ['default' => false, 'comment' => '是否写步骤（能力 access 推导）'])
            ->addColumn('arguments', 'text', ['null' => true, 'comment' => '解析后的参数快照 (JSON)'])
            ->addColumn('output', 'text', ['null' => true, 'comment' => '步骤输出'])
            ->addColumn('verify_result', 'text', ['null' => true, 'comment' => '断言结论 (JSON)'])
            ->addColumn('status', 'string', ['limit' => 30, 'default' => 'pending', 'comment' => '状态: pending/running/completed/failed/skipped/blocked_pending_approval'])
            ->addColumn('attempts', 'integer', ['limit' => 11, 'default' => 0, 'comment' => '已尝试次数'])
            ->addColumn('duration', 'decimal', ['precision' => 10, 'scale' => 3, 'null' => true, 'comment' => '耗时(秒)'])
            ->addColumn('error', 'text', ['null' => true, 'comment' => '失败原因'])
            ->addColumn('approval_id', 'integer', ['limit' => 11, 'null' => true, 'comment' => '该步生成的审批单ID'])
            ->addColumn('create_time', 'datetime', ['null' => true, 'comment' => '创建时间'])
            ->addColumn('update_time', 'datetime', ['null' => true, 'comment' => '更新时间'])
            ->addIndex(['run_id'], ['name' => 'idx_run'])
            ->addIndex(['run_id', 'step_no'], ['unique' => true, 'name' => 'uk_run_step'])
            ->addIndex(['status'], ['name' => 'idx_status'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('ai_plan_steps')) {
            $this->table('ai_plan_steps')->drop()->save();
        }
    }
}
