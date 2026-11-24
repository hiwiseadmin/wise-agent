<?php

declare(strict_types=1);

use think\migration\Migrator;
use think\migration\db\Column;

/**
 * B2-1：闭环投递表（**每次投递一行**）
 *
 * 与 `wise\agent\closure\ConnectorResult::toArray()` 一一对应（另加 `event_id` / `trace_id`）。
 *
 * 两个字段承担关键语义，不能省：
 *
 * - `slow`：单连接器耗时超过 `closure.timeout_ms` 的**告警标记**。
 *   ⚠️ 它**不影响 `status`** —— 一个"耗时 5s 但确实发送成功"的通知若被记为 `failed`，
 *   `closure retry` 会**再发一次**（附录六实现期修正，见 `ConnectorResult` 类注释）；
 * - `next_retry_time` + `attempts`：补投（`closure retry`）与死信判定用。
 */
class CreateAiClosureDeliveriesTable extends Migrator
{
    public function up(): void
    {
        $table = $this->table('ai_closure_deliveries', [
            'engine'    => 'InnoDB',
            'comment'   => 'AI 闭环投递记录（每次连接器投递一行，可补投）',
            'collation' => 'utf8mb4_unicode_ci',
        ]);

        $table->addColumn('event_id', 'integer', ['limit' => 11, 'default' => 0, 'comment' => '所属事件ID（事件未落库时为 0）'])
            ->addColumn('trace_id', 'string', ['limit' => 64, 'null' => false, 'comment' => '贯通键'])
            ->addColumn('connector', 'string', ['limit' => 32, 'null' => false, 'comment' => '连接器名: notification/webhook/audit'])
            ->addColumn('target', 'string', ['limit' => 500, 'null' => true, 'comment' => '投递目标（渠道/URL主机/审计channel）'])
            ->addColumn('status', 'string', ['limit' => 20, 'default' => 'pending', 'comment' => '状态: pending/ok/failed/skipped/dead'])
            ->addColumn('response', 'text', ['null' => true, 'comment' => '下游响应摘要'])
            ->addColumn('error', 'text', ['null' => true, 'comment' => '失败/跳过原因'])
            ->addColumn('duration_ms', 'integer', ['limit' => 11, 'default' => 0, 'comment' => '耗时(毫秒)'])
            ->addColumn('slow', 'boolean', ['default' => false, 'comment' => '是否超过超时阈值（告警，不影响 status）'])
            ->addColumn('attempts', 'integer', ['limit' => 11, 'default' => 1, 'comment' => '本次投递的尝试次数（补投会新增一行，故"补了几次"=同 trace+connector 的失败行数）'])
            ->addColumn('next_retry_time', 'datetime', ['null' => true, 'comment' => '下次可补投时间'])
            ->addColumn('create_time', 'datetime', ['null' => true, 'comment' => '创建时间'])
            ->addColumn('update_time', 'datetime', ['null' => true, 'comment' => '更新时间'])
            ->addIndex(['event_id'], ['name' => 'idx_event'])
            ->addIndex(['trace_id'], ['name' => 'idx_trace'])
            ->addIndex(['connector'], ['name' => 'idx_connector'])
            ->addIndex(['status'], ['name' => 'idx_status'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('ai_closure_deliveries')) {
            $this->table('ai_closure_deliveries')->drop()->save();
        }
    }
}
