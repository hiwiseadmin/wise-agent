<?php
declare(strict_types=1);

use think\migration\Migrator;
use think\migration\db\Column;

/**
 * 创建 AI 记忆表（长期记忆）
 */
class CreateAiMemoriesTable extends Migrator
{
    public function up(): void
    {
        $table = $this->table('ai_memories', [
            'engine' => 'InnoDB',
            'comment' => 'AI 记忆表',
            'collation' => 'utf8mb4_unicode_ci',
        ]);

        $table->addColumn(Column::string('session_id', 64)->setNull(false)->setDefault('')->setComment('会话ID'))
            ->addColumn(Column::string('user_type', 32)->setDefault('admin')->setComment('用户类型'))
            ->addColumn(Column::integer('user_id')->setSigned(false)->setDefault(0)->setComment('用户ID'))
            ->addColumn(Column::string('key', 255)->setNull(false)->setComment('记忆键值'))
            ->addColumn(Column::text('value')->setNull(true)->setComment('记忆值（JSON）'))
            ->addColumn(Column::text('tags')->setNull(true)->setComment('标签值（JSON）'))
            ->addColumn(Column::dateTime('create_time')->setComment('创建时间'))
            ->addColumn(Column::dateTime('update_time')->setComment('更新时间'))
            ->addIndex(['session_id', 'key'], ['unique' => true, 'name' => 'uk_session_key'])
            ->addIndex(['user_type', 'user_id'], ['name' => 'idx_user'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('ai_memories')) {
            $this->table('ai_memories')->drop()->save();
        }
    }
}
