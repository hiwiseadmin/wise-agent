<?php
declare(strict_types=1);

use think\migration\Migrator;
use think\migration\db\Column;

/**
 * 创建 AI 工具注册表
 */
class CreateAiToolsTable extends Migrator
{
    public function up(): void
    {
        $table = $this->table('ai_tools', [
            'engine' => 'InnoDB',
            'comment' => 'AI 工具注册表',
            'collation' => 'utf8mb4_unicode_ci',
        ]);

        $table->addColumn(Column::string('name', 64)->setNull(false)->setComment('工具唯一名称'))
            ->addColumn(Column::string('class', 255)->setNull(false)->setComment('实现类'))
            ->addColumn(Column::string('description', 500)->setDefault('')->setComment('工具描述'))
            ->addColumn(Column::string('source', 64)->setDefault('builtin')->setComment('来源: builtin/plugin/plugin_xxx'))
            ->addColumn(Column::string('permission', 128)->setDefault('')->setComment('权限标识符'))
            ->addColumn(Column::integer('require_confirm')->setLimit(1)->setDefault(0)->setComment('是否确认执行'))
            ->addColumn(Column::integer('enabled')->setLimit(1)->setDefault(1)->setComment('是否启用标志'))
            ->addColumn(Column::dateTime('create_time')->setComment('创建时间'))
            ->addIndex(['name'], ['unique' => true, 'name' => 'uk_name'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('ai_tools')) {
            $this->table('ai_tools')->drop()->save();
        }
    }
}
