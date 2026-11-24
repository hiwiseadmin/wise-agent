<?php

declare(strict_types=1);

use think\migration\Migrator;
use think\migration\db\Column;

class CreateAiTasksTable extends Migrator
{
    public function up(): void
    {
        $table = $this->table('ai_tasks', [
            'engine'    => 'InnoDB',
            'comment'   => 'AI 定时任务表',
            'collation' => 'utf8mb4_unicode_ci',
        ]);

        $table->addColumn('task_id', 'string', ['limit' => 100, 'null' => false, 'comment' => '任务标识'])
            ->addColumn('name', 'string', ['limit' => 200, 'null' => false, 'comment' => '任务名称'])
            ->addColumn('type', 'string', ['limit' => 20, 'null' => false, 'comment' => '类型: pipeline/skill/chat'])
            ->addColumn('target', 'string', ['limit' => 100, 'null' => false, 'comment' => '目标 pipeline/skill 名'])
            ->addColumn('input', 'text', ['null' => true, 'comment' => '固定输入参数 (JSON)'])
            ->addColumn('schedule', 'string', ['limit' => 100, 'null' => false, 'comment' => '调度: cron表达式/event:xxx/interval:3600'])
            ->addColumn('source', 'string', ['limit' => 100, 'default' => 'builtin', 'comment' => '来源'])
            ->addColumn('status', 'string', ['limit' => 20, 'default' => 'active', 'comment' => '状态: active/paused/disabled'])
            ->addColumn('last_run', 'datetime', ['null' => true, 'comment' => '上次执行时间'])
            ->addColumn('next_run', 'datetime', ['null' => true, 'comment' => '下次执行时间'])
            ->addColumn('run_count', 'integer', ['limit' => 11, 'default' => 0, 'comment' => '已执行次数'])
            ->addColumn('create_time', 'datetime', ['null' => true, 'comment' => '创建时间'])
            ->addColumn('update_time', 'datetime', ['null' => true, 'comment' => '更新时间'])
            ->addIndex(['task_id'], ['unique' => true, 'name' => 'uk_task_id'])
            ->addIndex(['status'], ['name' => 'idx_status'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('ai_tasks')) {
            $this->table('ai_tasks')->drop()->save();
        }
    }
}
