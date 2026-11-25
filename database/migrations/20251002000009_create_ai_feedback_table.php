<?php

declare(strict_types=1);

use think\migration\Migrator;
use think\migration\db\Column;

class CreateAiFeedbackTable extends Migrator
{
    public function up(): void
    {
        $table = $this->table('ai_feedback', [
            'engine'    => 'InnoDB',
            'comment'   => 'AI 用户反馈表',
            'collation' => 'utf8mb4_unicode_ci',
        ]);

        $table->addColumn('skill_name', 'string', ['limit' => 100, 'null' => false, 'comment' => 'Skill 名称'])
            ->addColumn('pipeline_name', 'string', ['limit' => 100, 'null' => true, 'comment' => 'Pipeline 名称'])
            ->addColumn('session_id', 'string', ['limit' => 64, 'null' => true, 'comment' => '关联会话ID'])
            ->addColumn('rating', 'integer', ['limit' => 1, 'null' => false, 'comment' => '评分 1-5'])
            ->addColumn('comment', 'text', ['null' => true, 'comment' => '用户评语'])
            ->addColumn('correction', 'text', ['null' => true, 'comment' => '用户纠正/期望输出'])
            ->addColumn('context_snapshot', 'text', ['null' => true, 'comment' => '上下文快照 (JSON)'])
            ->addColumn('user_id', 'integer', ['limit' => 11, 'default' => 0, 'comment' => '用户ID'])
            ->addColumn('user_type', 'string', ['limit' => 20, 'default' => 'admin', 'comment' => '用户类型'])
            ->addColumn('create_time', 'datetime', ['null' => true, 'comment' => '创建时间'])
            ->addIndex(['skill_name'], ['name' => 'idx_skill'])
            ->addIndex(['rating'], ['name' => 'idx_rating'])
            ->addIndex(['user_type', 'user_id'], ['name' => 'idx_user'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('ai_feedback')) {
            $this->table('ai_feedback')->drop()->save();
        }
    }
}
