<?php
declare(strict_types=1);

use think\migration\Migrator;
use think\migration\db\Column;

/**
 * 为 ai_agent_logs 补充工具级审计字段
 *
 * 背景（收口清单 B2-4）：
 *   改造后 Agent / HTTP / MCP 三条链路共用同一个执行入口，工具调用需要
 *   统一的审计事实：**谁在哪个入口、调了哪个能力、参数指纹、结果状态、
 *   耗时、关联的审批单**。
 *
 *   原表只有 agent 级别的字段（session/user/tokens/duration），缺少
 *   `entry_source`（入口来源）与 `capability`（能力标识），导致
 *   "同一能力从 MCP 被调用"这类事实无法事后区分。
 *
 * 设计取舍：
 *   - 只存**参数指纹**（sha256）而非明文参数：避免把密码、令牌等写入日志；
 *   - 新增 `job_type = 'tool'` 区分工具级记录与 agent/pipeline 级记录，
 *     两者共用一张表但用该列分流，避免再建一张表增加关联成本。
 */
class AddToolAuditColumnsToAiAgentLogs extends Migrator
{
    private const TABLE = 'ai_agent_logs';

    public function up(): void
    {
        if (!$this->hasTable(self::TABLE)) {
            return;
        }

        $table = $this->table(self::TABLE);

        $columns = [
            'tool_name'        => Column::string('tool_name', 128)->setNullable()->setComment('工具名（工具级记录专用）'),
            'capability'       => Column::string('capability', 191)->setNullable()->setComment('能力标识（权限点）'),
            'entry_source'     => Column::string('entry_source', 32)->setNullable()->setComment('调用入口：agent/mcp/http'),
            'arguments_digest' => Column::string('arguments_digest', 64)->setNullable()->setComment('参数指纹 sha256（不存明文）'),
            'approval_id'      => Column::integer('approval_id')->setSigned(false)->setNullable()->setComment('关联审批单 ID'),
        ];

        foreach ($columns as $name => $column) {
            if (!$table->hasColumn($name)) {
                $table->addColumn($column);
            }
        }

        $table->addIndex(['entry_source', 'create_time'], ['name' => 'idx_entry_created'])
              ->update();
    }

    public function down(): void
    {
        if (!$this->hasTable(self::TABLE)) {
            return;
        }

        $table = $this->table(self::TABLE);

        foreach (['tool_name', 'capability', 'entry_source', 'arguments_digest', 'approval_id'] as $name) {
            if ($table->hasColumn($name)) {
                $table->removeColumn($name);
            }
        }

        $table->update();
    }
}
