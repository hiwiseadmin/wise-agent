<?php
declare(strict_types=1);

use think\migration\Migrator;
use think\migration\db\Column;

/**
 * 创建 AI 会话表
 */
class CreateAiSessionsTable extends Migrator
{
    public function up(): void
    {
        $config = $this->getTableConfig();
        $table = $this->table($config['sessions'] ?? 'ai_sessions', [
            'engine' => 'InnoDB',
            'comment' => 'AI 会话表',
            'collation' => 'utf8mb4_unicode_ci',
        ]);

        $table->addColumn(Column::string('session_id', 64)->setNull(false)->setComment('会话唯一标识符'))
            ->addColumn(Column::string('user_type', 32)->setDefault('admin')->setComment('用户类型（admin/member）'))
            ->addColumn(Column::integer('user_id')->setSigned(false)->setDefault(0)->setComment('用户ID'))
            ->addColumn(Column::string('agent_type', 64)->setDefault('default')->setComment('Agent类型（default/custom）'))
            ->addColumn(Column::string('title', 255)->setDefault('')->setComment('会话标题'))
            ->addColumn(Column::integer('status')->setLimit(1)->setDefault(1)->setComment('1=活跃 0=已归档'))
            ->addColumn(Column::integer('message_count')->setDefault(0)->setComment('消息数量'))
            ->addColumn(Column::integer('total_tokens')->setDefault(0)->setComment('总令牌数'))
            ->addColumn(Column::text('metadata')->setNull(true)->setComment('扩展元数据（JSON）'))
            ->addColumn(Column::dateTime('create_time')->setComment('创建时间'))
            ->addColumn(Column::dateTime('update_time')->setComment('更新时间'))
            ->addIndex(['session_id'], ['name' => 'idx_session'])
            ->addIndex(['user_type', 'user_id'], ['name' => 'idx_user'])
            ->create();
    }

    public function down(): void
    {
        $config = $this->getTableConfig();
        $this->dropTable($config['sessions'] ?? 'ai_sessions');
    }

    protected function getTableConfig(): array
    {
        if (function_exists('config')) {
            return config('wise-agent.tables', []);
        }
        return [];
    }
}
