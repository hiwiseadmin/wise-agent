<?php
declare(strict_types=1);

namespace wise\agent\schema\capability\builtin;

use wise\agent\schema\capability\BusinessCapability;
use wise\agent\schema\dto\PluginSchemaDTO;

/**
 * 销售分析业务能力（简化版）
 *
 * 提供销售统计、趋势分析、排行等 AI 能力。
 * getTools() 委托给 AiToolGenerator 自动生成标准 CRUD 工具。
 * getContextPrompt() 提供完整的销售领域知识。
 */
class SalesCapability extends BusinessCapability
{
    public function getTag(): string
    {
        return 'sales';
    }

    public function getRequiredTables(): array
    {
        return [
            'order' => ['order', 'orders', 'sale_order', 'sales_order'],
        ];
    }

    /**
     * 销售领域知识上下文
     */
    public function getContextPrompt(PluginSchemaDTO $schema): string
    {
        return <<<'PROMPT'

## 销售分析业务知识

你是一个专业的销售分析 AI 助手。你需要理解以下业务概念：

### 核心概念
- **销售额 (Sales Amount)**：成交金额 = 销售数量 × 销售单价
- **毛利 (Gross Profit)**：销售额 - 成本 = 利润额
- **毛利率 (Gross Margin)**：毛利 / 销售额 × 100%
- **客单价 (Average Order Value)**：销售额 / 成交客户数
- **复购率 (Repurchase Rate)**：重复购买客户数 / 总客户数 × 100%

### 关键指标
- **同比 (YoY)**：与去年同期对比
- **环比 (MoM)**：与上个月对比
- **达成率**：实际销售额 / 目标销售额 × 100%
- **退货率**：退货金额 / 销售额 × 100%

### 分析维度
1. 时间维度：日/周/月/季/年销售趋势
2. 商品维度：畅销排行、滞销排行、品类占比
3. 客户维度：客户分层（VIP/普通/新客）、购买频次分析
4. 渠道维度：线上/线下、各门店/区域销售对比
5. 员工维度：销售员业绩排行、目标达成率

### 分析建议
- 月末/季末重点关注达成率，提前预警未达标情况
- 节假日前后关注销售趋势变化，评估促销活动效果
- 新品上市后追踪首月销售表现，判断是否需调整策略

PROMPT;
    }

    /**
     * 销售工具：委托给 AiToolGenerator 生成，此处返回空
     * （实际使用时，标准 CRUD 工具由 AiToolGenerator 自动生成）
     */
    public function getTools(PluginSchemaDTO $schema): array
    {
        return [];
    }

    public function getRecommendedSkills(): array
    {
        return [
            'data.analyze',
            'data.trend_predict',
            'data.report_generate',
            'data.anomaly_detect',
        ];
    }

    public function getRecommendedPipelines(PluginSchemaDTO $schema): array
    {
        return [
            'sales_monthly_report' => [
                'name'        => '月度销售报告',
                'description' => '生成月度销售分析报告，包含趋势、排行、达成率',
                'steps'       => [
                    [
                        'skill'         => 'data.analyze',
                        'input_mapping' => [
                            'query' => '分析本月销售数据：总销售额、环比增长率、畅销商品 TOP10、滞销商品、品类占比',
                        ],
                    ],
                    [
                        'skill'         => 'data.report_generate',
                        'input_mapping' => [
                            'analysis' => '$steps.analysis.result',
                        ],
                    ],
                ],
            ],
            'sales_trend_forecast' => [
                'name'        => '销售趋势预测',
                'description' => '基于历史数据预测未来销售趋势',
                'steps'       => [
                    [
                        'skill'         => 'data.analyze',
                        'input_mapping' => [
                            'query' => '分析近 6 个月销售趋势，标注异常波动点和季节性规律',
                        ],
                    ],
                    [
                        'skill'         => 'data.trend_predict',
                        'input_mapping' => [
                            'data' => '$steps.analysis.data',
                        ],
                    ],
                ],
            ],
        ];
    }
}
