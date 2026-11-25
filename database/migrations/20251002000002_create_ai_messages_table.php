<?php
declare(strict_types=1);

use think\migration\Migrator;
use think\migration\db\Column;

/**
 * 创建 AI 消息表
 */
class CreateAiMessagesTable extends Migrator
{
    public function up(): void
    {
        $table = $this->table('ai_messages', [
            'engine' => 'InnoDB',
            'comment' => 'AI 消息表',
            'collation' => 'utf8mb4_unicode_ci',
        ]);

        $table->addColumn(Column::string('session_id', 64)->setNull(false)->setComment('会话ID'))
            ->addColumn(Column::string('role', 32)->setNull(false)->setComment('角色（system/user/assistant/tool）'))
            ->addColumn(Column::text('content')->setNull(true)->setComment('消息内容'))
            ->addColumn(Column::text('tool_calls')->setNull(true)->setComment('工具调用记录（JSON）'))
            ->addColumn(Column::string('tool_call_id', 64)->setNull(true)->setComment('工具调用ID'))
            ->addColumn(Column::string('tool_name', 64)->setNull(true)->setComment('工具名称'))
            ->addColumn(Column::text('usage')->setNull(true)->setComment('令牌使用（JSON）'))
            ->addColumn(Column::dateTime('create_time')->setComment('创建时间'))
            ->addIndex(['session_id'], ['name' => 'idx_session'])
            ->addIndex(['create_time'], ['name' => 'idx_created'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('ai_messages')) {
            $this->table('ai_messages')->drop()->save();
        }
    }
}
