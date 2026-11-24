<?php

declare(strict_types=1);

use think\migration\Migrator;
use think\migration\db\Column;

/**
 * B1-3：计划运行表
 *
 * 与 `wise\agent\plan\PlanRun::toArray()` **一一对应**（列名即 DTO 键名）。
 *
 * ⚠️ 与同目录的 `ai_pipeline_runs` 不同：这里**显式关闭自动 `id`**，把主键命名为 `run_id`
 * —— 因为 B1 的「按步暂停/续跑/查询」都以 `run_id` 为定位键，显式主键能让
 * 「DTO 键名 = 列名 = 接口字段」三者一致，避免 `id`/`run_id` 两套叫法互相翻译。
 */
class CreateAiPlanRunsTable extends Migrator
{
    public function up(): void
    {
        $table = $this->table('ai_plan_runs', [
            'id'          => false,
            'primary_key' => ['run_id'],
            'engine'      => 'InnoDB',
            'comment'     => 'AI 计划运行（Plan → Execute → Verify → Close）',
            'collation'   => 'utf8mb4_unicode_ci',
        ]);

        $table->addColumn('run_id', 'integer', ['limit' => 11, 'identity' => true, 'signed' => false, 'comment' => '运行ID'])
            ->addColumn('tracking_id', 'string', ['limit' => 64, 'null' => true, 'comment' => '关联 ai_agent_logs.tracking_id'])
            ->addColumn('session_id', 'string', ['limit' => 64, 'null' => true, 'comment' => '关联会话ID'])
            ->addColumn('user_type', 'string', ['limit' => 20, 'default' => 'admin', 'comment' => '发起人类型'])
            ->addColumn('user_id', 'integer', ['limit' => 11, 'default' => 0, 'comment' => '发起人ID（<=0 = 系统上下文）'])
            ->addColumn('goal', 'text', ['null' => true, 'comment' => '原始目标'])
            ->addColumn('plan', 'text', ['null' => true, 'comment' => '计划快照 (JSON)'])
            ->addColumn('status', 'string', ['limit' => 20, 'default' => 'running', 'comment' => '状态: running/paused/replanning/completed/aborted'])
            ->addColumn('current_step', 'integer', ['limit' => 11, 'default' => 0, 'comment' => '当前步骤序号（续跑定位）'])
            ->addColumn('total_steps', 'integer', ['limit' => 11, 'default' => 0, 'comment' => '步骤总数'])
            ->addColumn('total_tokens', 'integer', ['limit' => 11, 'default' => 0, 'comment' => '累计 Token（仅改道消耗）'])
            ->addColumn('total_duration', 'decimal', ['precision' => 10, 'scale' => 3, 'null' => true, 'comment' => '总耗时(秒)'])
            ->addColumn('error', 'text', ['null' => true, 'comment' => '终止原因'])
            ->addColumn('source', 'string', ['limit' => 100, 'null' => true, 'comment' => '触发来源: chat/api/cli/materialized'])
            ->addColumn('planner', 'string', ['limit' => 20, 'default' => 'llm', 'comment' => '规划者: llm/manual/materialized'])
            ->addColumn('metadata', 'text', ['null' => true, 'comment' => '附加信息 (JSON)'])
            ->addColumn('create_time', 'datetime', ['null' => true, 'comment' => '创建时间'])
            ->addColumn('update_time', 'datetime', ['null' => true, 'comment' => '更新时间'])
            ->addIndex(['tracking_id'], ['name' => 'idx_tracking'])
            ->addIndex(['session_id'], ['name' => 'idx_session'])
            ->addIndex(['status'], ['name' => 'idx_status'])
            ->addIndex(['user_id'], ['name' => 'idx_user'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('ai_plan_runs')) {
            $this->table('ai_plan_runs')->drop()->save();
        }
    }
}
