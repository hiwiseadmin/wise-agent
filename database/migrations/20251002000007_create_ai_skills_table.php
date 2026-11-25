<?php

declare(strict_types=1);

use think\migration\Migrator;
use think\migration\db\Column;

class CreateAiSkillsTable extends Migrator
{
    public function up(): void
    {
        $table = $this->table('ai_skills', [
            'engine'    => 'InnoDB',
            'comment'   => 'AI Skills 技能定义表',
            'collation' => 'utf8mb4_unicode_ci',
        ]);

        $table->addColumn('name', 'string', ['limit' => 100, 'null' => false, 'comment' => 'Skill 标识, 如 content.article_generate'])
            ->addColumn('category', 'string', ['limit' => 50, 'null' => false, 'comment' => '分类: content/data/code/business'])
            ->addColumn('source', 'string', ['limit' => 100, 'default' => 'builtin', 'comment' => '来源: builtin/plugin:xxx'])
            ->addColumn('config', 'text', ['null' => true, 'comment' => 'Skill 配置 (JSON: system_prompt/input_schema/output_format/tools)'])
            ->addColumn('version', 'integer', ['limit' => 11, 'default' => 1, 'comment' => '版本号'])
            ->addColumn('is_active', 'boolean', ['default' => true, 'comment' => '是否启用'])
            ->addColumn('create_time', 'datetime', ['null' => true, 'comment' => '创建时间'])
            ->addColumn('update_time', 'datetime', ['null' => true, 'comment' => '更新时间'])
            ->addIndex(['name'], ['unique' => true, 'name' => 'uk_name'])
            ->addIndex(['source'], ['name' => 'idx_source'])
            ->addIndex(['category'], ['name' => 'idx_category'])
            ->addIndex(['is_active'], ['name' => 'idx_is_active'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('ai_skills')) {
            $this->table('ai_skills')->drop()->save();
        }
    }
}
