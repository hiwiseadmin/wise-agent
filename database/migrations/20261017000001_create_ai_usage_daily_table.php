<?php

declare(strict_types=1);

use think\migration\Migrator;

/**
 * C2-2：AI 用量日汇总表
 *
 * 成本**聚合**落这里，热表 `ai_agent_logs` 只写 `metadata.cost` / `metadata.usage`（不扩列）。
 * 由 `php think wise:agent:usage-rollup --day=YYYY-MM-DD` 幂等重建（先删该日该维度再插入）。
 *
 * 维度联合唯一 ⇒ 同一 `--day` 重跑结果一致（验收 8）；`unpriced_calls` 让"估算覆盖率"可见。
 */
class CreateAiUsageDailyTable extends Migrator
{
    public function up(): void
    {
        $table = $this->table('ai_usage_daily', [
            'engine'    => 'InnoDB',
            'comment'   => 'AI 用量日汇总（按 day+用户+模型维度预聚合，供仪表盘读小表）',
            'collation' => 'utf8mb4_unicode_ci',
        ]);

        $table->addColumn('day', 'date', ['null' => false, 'comment' => '汇总日期'])
            ->addColumn('user_type', 'string', ['limit' => 32, 'default' => '', 'comment' => '用户类型'])
            ->addColumn('user_id', 'integer', ['limit' => 11, 'default' => 0, 'comment' => '用户ID'])
            ->addColumn('provider', 'string', ['limit' => 64, 'default' => '', 'comment' => '提供方'])
            ->addColumn('model', 'string', ['limit' => 128, 'default' => '', 'comment' => '模型'])
            ->addColumn('calls', 'integer', ['limit' => 11, 'default' => 0, 'comment' => '调用次数'])
            ->addColumn('prompt_tokens', 'integer', ['limit' => 11, 'default' => 0, 'comment' => '输入 token 合计'])
            ->addColumn('completion_tokens', 'integer', ['limit' => 11, 'default' => 0, 'comment' => '输出 token 合计'])
            ->addColumn('total_tokens', 'integer', ['limit' => 11, 'default' => 0, 'comment' => '总 token 合计'])
            ->addColumn('cost', 'float', ['default' => 0, 'comment' => '估算成本合计'])
            ->addColumn('currency', 'string', ['limit' => 16, 'default' => 'CNY', 'comment' => '币种'])
            ->addColumn('unpriced_calls', 'integer', ['limit' => 11, 'default' => 0, 'comment' => '未定价调用数'])
            ->addColumn('create_time', 'datetime', ['null' => true, 'comment' => '创建时间'])
            ->addColumn('update_time', 'datetime', ['null' => true, 'comment' => '更新时间'])
            ->addIndex(['day', 'user_type', 'user_id', 'provider', 'model'], ['unique' => true, 'name' => 'uk_day_dims'])
            ->addIndex(['day'], ['name' => 'idx_day'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('ai_usage_daily')) {
            $this->table('ai_usage_daily')->drop()->save();
        }
    }
}
