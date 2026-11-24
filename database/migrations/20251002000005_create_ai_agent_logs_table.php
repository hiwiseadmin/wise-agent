<?php
declare(strict_types=1);

use think\migration\Migrator;
use think\migration\db\Column;

/**
 * 创建 AI Agent 运行日志表
 */
class CreateAiAgentLogsTable extends Migrator
{
    public function up(): void
    {
        $table = $this->table('ai_agent_logs', [
            'engine' => 'InnoDB',
            'comment' => 'AI Agent 运行日志表',
            'collation' => 'utf8mb4_unicode_ci',
        ]);

        $table->addColumn(Column::string('session_id', 64)->setNull(false)->setComment('Session ID'))
            ->addColumn(Column::string('user_type', 32)->setDefault('admin')->setComment('用户类型（admin/member）'))
            ->addColumn(Column::integer('user_id')->setSigned(false)->setDefault(0)->setComment('用户ID'))
            ->addColumn(Column::string('tracking_id', 64)->setNull(true)->setComment('执行追踪ID（唯一标识每次执行）'))
            ->addColumn(Column::string('agent_type', 64)->setDefault('default')->setComment('Agent类型'))
            ->addColumn(Column::string('job_type', 32)->setDefault('agent')->setComment('任务类型: agent/pipeline/skill'))
            ->addColumn(Column::text('task')->setNull(true)->setComment('任务描述'))
            ->addColumn(Column::text('result')->setNull(true)->setComment('最终结果'))
            ->addColumn(Column::integer('steps')->setDefault(0)->setComment('执行步骤数'))
            ->addColumn(Column::integer('total_tokens')->setDefault(0)->setComment('总token数'))
            ->addColumn(Column::float('duration')->setDefault(0)->setComment('执行时间（秒）'))
            ->addColumn(Column::string('provider', 64)->setDefault('')->setComment('提供方名称（如OpenAI）'))
            ->addColumn(Column::string('model', 128)->setDefault('')->setComment('模型名称（如gpt-3.5-turbo）'))
            ->addColumn(Column::tinyInteger('status')->setLimit(2)->setDefault(1)->setComment('状态（0=排队 1=运行中 2=完成 3=失败 4=超时 5=取消'))
            ->addColumn(Column::text('error')->setNull(true)->setComment('错误信息'))
            ->addColumn(Column::text('metadata')->setNull(true)->setComment('扩展元数据（JSON）'))
            ->addColumn(Column::dateTime('heartbeat')->setNull(true)->setComment('心跳时间（用于超时检测）'))
            ->addColumn(Column::dateTime('create_time')->setComment('创建时间'))
            ->addIndex(['session_id'], ['name' => 'idx_session'])
            ->addIndex(['user_type', 'user_id'], ['name' => 'idx_user'])
            ->addIndex(['tracking_id'], ['unique' => true, 'name' => 'uk_tracking'])
            ->addIndex(['create_time'], ['name' => 'idx_created'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('ai_agent_logs')) {
            $this->table('ai_agent_logs')->drop()->save();
        }
    }
}
