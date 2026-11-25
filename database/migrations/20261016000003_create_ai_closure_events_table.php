<?php

declare(strict_types=1);

use think\migration\Migrator;
use think\migration\db\Column;

/**
 * B2-1：闭环事件表
 *
 * 与 `wise\agent\closure\ClosureEvent::toArray()` **一一对应**（列名即 DTO 键名），
 * 另加 `payload_hash`（载荷指纹）、`status` / `duration_ms` / `error`（派发汇总）。
 *
 * ⚠️ 与 B1 的两张表一致：**显式关闭自动 `id`**，主键叫 `event_id`，
 * 保证「DTO 键名 = 列名 = 接口字段」三者一致。
 *
 * `payload` 里存的是**已脱敏**的上下文摘要（`ClosureEventFactory` 入库前已过
 */
class CreateAiClosureEventsTable extends Migrator
{
    public function up(): void
    {
        $table = $this->table('ai_closure_events', [
            'id'          => false,
            'primary_key' => ['event_id'],
            'engine'      => 'InnoDB',
            'comment'     => 'AI 业务闭环事件（审批/执行/对话结束 → 通知/联动/审计）',
            'collation'   => 'utf8mb4_unicode_ci',
        ]);

        $table->addColumn('event_id', 'integer', ['limit' => 11, 'identity' => true, 'signed' => false, 'comment' => '事件ID'])
            ->addColumn('trace_id', 'string', ['limit' => 64, 'null' => false, 'comment' => '贯通键（回放主键）'])
            ->addColumn('scene', 'string', ['limit' => 32, 'null' => false, 'comment' => '场景: approval_requested/approval_decided/tool_executed/agent_completed'])
            ->addColumn('capability_kind', 'string', ['limit' => 20, 'null' => true, 'comment' => '能力种类: tool/skill/agent'])
            ->addColumn('capability_name', 'string', ['limit' => 200, 'null' => true, 'comment' => '能力名'])
            ->addColumn('access', 'string', ['limit' => 20, 'null' => true, 'comment' => '访问类型（不可得时为空）'])
            ->addColumn('risk', 'string', ['limit' => 20, 'null' => true, 'comment' => '风险等级（不可得时为空）'])
            ->addColumn('write', 'boolean', ['default' => false, 'comment' => '是否写操作（能力 access 推导）'])
            ->addColumn('result', 'string', ['limit' => 20, 'null' => true, 'comment' => '结论: pending/approved/success/failure/rejected/cancelled/unknown'])
            ->addColumn('reason', 'string', ['limit' => 500, 'null' => true, 'comment' => '人类可读原因'])
            ->addColumn('payload', 'text', ['null' => true, 'comment' => '上下文摘要 (JSON，已脱敏)'])
            ->addColumn('payload_hash', 'string', ['limit' => 64, 'null' => true, 'comment' => '载荷指纹 sha256'])
            ->addColumn('approval_id', 'integer', ['limit' => 11, 'default' => 0, 'comment' => '关联审批单ID'])
            ->addColumn('plan_run_id', 'integer', ['limit' => 11, 'default' => 0, 'comment' => '关联计划运行ID（B1）'])
            ->addColumn('session_id', 'string', ['limit' => 64, 'null' => true, 'comment' => '关联会话ID'])
            ->addColumn('tracking_id', 'string', ['limit' => 64, 'null' => true, 'comment' => '关联 ai_agent_logs.tracking_id'])
            ->addColumn('actor_id', 'integer', ['limit' => 11, 'default' => 0, 'comment' => '发起人ID（决策人见 payload）'])
            ->addColumn('actor_type', 'string', ['limit' => 20, 'default' => 'admin', 'comment' => '发起人类型'])
            ->addColumn('status', 'string', ['limit' => 20, 'default' => 'ok', 'comment' => '派发状态: ok/partial/failed/skipped'])
            ->addColumn('duration_ms', 'integer', ['limit' => 11, 'default' => 0, 'comment' => '派发总耗时(毫秒)'])
            ->addColumn('error', 'text', ['null' => true, 'comment' => '失败汇总'])
            ->addColumn('occurred_at', 'datetime', ['null' => true, 'comment' => '事件发生时间'])
            ->addColumn('create_time', 'datetime', ['null' => true, 'comment' => '创建时间'])
            ->addColumn('update_time', 'datetime', ['null' => true, 'comment' => '更新时间'])
            ->addIndex(['trace_id'], ['name' => 'idx_trace'])
            ->addIndex(['scene'], ['name' => 'idx_scene'])
            ->addIndex(['result'], ['name' => 'idx_result'])
            ->addIndex(['status'], ['name' => 'idx_status'])
            ->addIndex(['tracking_id'], ['name' => 'idx_tracking'])
            ->addIndex(['approval_id'], ['name' => 'idx_approval'])
            ->addIndex(['plan_run_id'], ['name' => 'idx_plan_run'])
            ->addIndex(['create_time'], ['name' => 'idx_create_time'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('ai_closure_events')) {
            $this->table('ai_closure_events')->drop()->save();
        }
    }
}
