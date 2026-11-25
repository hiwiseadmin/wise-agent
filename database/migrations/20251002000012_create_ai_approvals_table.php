<?php
declare(strict_types=1);

use think\migration\Migrator;
use think\migration\db\Column;

/**
 * AI 能力审批单表
 *
 * 「权限（permission）」解决「能不能调用」，「审批（approval）」解决「这一次调用是否需要人工介入」。
 * 当审批策略判定某次能力调用需要 confirm / workflow 时，生成一张审批单：
 *
 *   pending  —— 已申请，等待审批
 *   approved —— 已批准，尚未用尽（可消费）
 *   consumed —— 已用尽（达到 max_uses 后被消费）
 *   rejected —— 已驳回
 *   cancelled—— 申请人撤回
 *   expired  —— 超时失效
 *
 * 放行语义：批准后**不重放**历史调用，而是由申请人重新发起同一能力调用，
 * 校验时命中可用审批单并原子消费一次（见 wise\agent\security\ApprovalManager）。
 */
class CreateAiApprovalsTable extends Migrator
{
    protected function getTable(): string
    {
        return 'ai_approvals';
    }

    public function up(): void
    {
        $table = $this->table($this->getTable(), [
            'engine'    => 'InnoDB',
            'comment'   => 'AI 能力审批单表',
            'collation' => 'utf8mb4_unicode_ci',
        ]);

        if ($table->exists()) {
            return;
        }

        $table->addColumn(Column::string('ident', 40)->setComment('审批单号（唯一）'))
            ->addColumn(Column::string('capability_kind', 10)->setDefault('tool')->setComment('能力类型：tool/skill'))
            ->addColumn(Column::string('capability_name', 100)->setDefault('')->setComment('能力名称（工具名/技能名）'))
            ->addColumn(Column::string('permission', 120)->setDefault('')->setComment('权限标识'))
            ->addColumn(Column::string('access', 20)->setDefault('')->setComment('访问类型：read/write/execute/admin'))
            ->addColumn(Column::string('risk', 10)->setDefault('')->setComment('风险等级：low/medium/high'))
            ->addColumn(Column::string('mode', 20)->setDefault('confirm')->setComment('审批模式：confirm/workflow'))
            ->addColumn(Column::string('rule', 120)->setDefault('')->setComment('命中的审批规则'))
            ->addColumn(Column::string('reason', 255)->setDefault('')->setComment('需要审批的说明'))
            ->addColumn(Column::text('arguments')->setNullable()->setComment('调用参数快照（JSON）'))
            ->addColumn(Column::string('arguments_hash', 40)->setDefault('')->setComment('参数指纹（scope=arguments 时用于精确比对）'))
            ->addColumn(Column::integer('requester_id')->setDefault(0)->setComment('申请人用户ID'))
            ->addColumn(Column::string('requester_type', 30)->setDefault('admin')->setComment('申请人用户类型'))
            ->addColumn(Column::string('session_id', 64)->setDefault('')->setComment('发起会话ID（AI 会话）'))
            ->addColumn(Column::string('status', 20)->setDefault('pending')->setComment('状态：pending/approved/rejected/consumed/cancelled/expired'))
            ->addColumn(Column::integer('uses_total')->setDefault(1)->setComment('审批单可用次数'))
            ->addColumn(Column::integer('uses_left')->setDefault(1)->setComment('剩余可用次数'))
            ->addColumn(Column::integer('decider_id')->setDefault(0)->setComment('决策人用户ID'))
            ->addColumn(Column::string('decider_type', 30)->setDefault('')->setComment('决策人用户类型'))
            ->addColumn(Column::string('decision_note', 255)->setDefault('')->setComment('决策备注'))
            ->addColumn(Column::dateTime('decided_at')->setNullable()->setComment('决策时间'))
            ->addColumn(Column::dateTime('expire_time')->setNullable()->setComment('失效时间'))
            ->addColumn(Column::string('workflow_subject', 120)->setDefault('')->setComment('关联工作流实例标识'))
            ->addColumn(Column::dateTime('create_time')->setComment('创建时间'))
            ->addColumn(Column::dateTime('update_time')->setNullable()->setComment('更新时间'))
            ->addColumn(Column::dateTime('delete_time')->setNullable()->setComment('删除时间（软删除）'))
            ->addIndex('ident', ['unique' => true, 'name' => 'uk_ident'])
            ->addIndex('status', ['name' => 'idx_status'])
            ->addIndex('permission', ['name' => 'idx_permission'])
            ->addIndex(['requester_id', 'requester_type'], ['name' => 'idx_requester'])
            ->addIndex('expire_time', ['name' => 'idx_expire'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable($this->getTable())) {
            $this->table($this->getTable())->drop()->save();
        }
    }
}
