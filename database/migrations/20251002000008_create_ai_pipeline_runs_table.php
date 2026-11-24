<?php

declare(strict_types=1);

use think\migration\Migrator;
use think\migration\db\Column;

class CreateAiPipelineRunsTable extends Migrator
{
    public function up(): void
    {
        $table = $this->table('ai_pipeline_runs', [
            'engine'    => 'InnoDB',
            'comment'   => 'AI Pipeline 执行历史',
            'collation' => 'utf8mb4_unicode_ci',
        ]);

        $table->addColumn('pipeline_name', 'string', ['limit' => 100, 'null' => false, 'comment' => 'Pipeline 名称'])
            ->addColumn('session_id', 'string', ['limit' => 64, 'null' => true, 'comment' => '关联会话ID'])
            ->addColumn('input', 'text', ['null' => true, 'comment' => '输入参数 (JSON)'])
            ->addColumn('output', 'text', ['null' => true, 'comment' => '最终输出 (JSON)'])
            ->addColumn('steps_result', 'text', ['null' => true, 'comment' => '各步骤结果 (JSON)'])
            ->addColumn('status', 'string', ['limit' => 20, 'default' => 'running', 'comment' => '状态: running/completed/failed/cancelled'])
            ->addColumn('total_duration', 'decimal', ['precision' => 10, 'scale' => 3, 'null' => true, 'comment' => '总耗时(秒)'])
            ->addColumn('total_tokens', 'integer', ['limit' => 11, 'default' => 0, 'comment' => '总 Token 消耗'])
            ->addColumn('error', 'text', ['null' => true, 'comment' => '错误信息'])
            ->addColumn('source', 'string', ['limit' => 100, 'null' => true, 'comment' => '触发来源'])
            ->addColumn('create_time', 'datetime', ['null' => true, 'comment' => '创建时间'])
            ->addColumn('update_time', 'datetime', ['null' => true, 'comment' => '更新时间'])
            ->addIndex(['pipeline_name'], ['name' => 'idx_pipeline'])
            ->addIndex(['session_id'], ['name' => 'idx_session'])
            ->addIndex(['status'], ['name' => 'idx_status'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('ai_pipeline_runs')) {
            $this->table('ai_pipeline_runs')->drop()->save();
        }
    }
}
