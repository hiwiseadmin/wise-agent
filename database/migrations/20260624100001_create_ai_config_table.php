<?php

declare(strict_types=1);

use think\migration\Migrator;
use think\migration\db\Column;

class CreateAiConfigTable extends Migrator
{
    public function up(): void
    {
        $table = $this->table('ws_ai_config', [
            'engine'    => 'InnoDB',
            'comment'   => 'AI Provider 配置表',
            'collation' => 'utf8mb4_unicode_ci',
        ]);

        if ($table->exists()) {
            return;
        }

        $table->addColumn(Column::string('provider', 50)->setComment('Provider 标识（openai/deepseek等）'))
            ->addColumn(Column::text('api_key')->setNull(true)->setComment('加密的 API Key'))
            ->addColumn(Column::string('api_base', 500)->setNull(true)->setComment('API 基础 URL'))
            ->addColumn(Column::string('model', 100)->setNull(true)->setComment('模型名称'))
            ->addColumn(Column::integer('max_tokens')->setDefault(4096)->setComment('最大 Token 数'))
            ->addColumn(Column::decimal('temperature', 3, 2)->setDefault(0.7)->setComment('温度参数'))
            ->addColumn(Column::boolean('is_active')->setDefault(1)->setComment('是否启用'))
            ->addColumn(Column::dateTime('create_time')->setNull(true)->setComment('创建时间'))
            ->addColumn(Column::dateTime('update_time')->setNull(true)->setComment('更新时间'))
            ->addIndex(['provider'], ['unique' => true, 'name' => 'uk_provider'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('ws_ai_config')) {
            $this->table('ws_ai_config')->drop();
        }
    }
}
