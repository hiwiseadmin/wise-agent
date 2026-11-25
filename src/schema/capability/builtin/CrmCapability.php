<?php
declare(strict_types=1);

namespace wise\agent\schema\capability\builtin;

use wise\agent\schema\capability\BusinessCapability;
use wise\agent\schema\dto\PluginSchemaDTO;

/**
 * 客户管理业务能力（简化版）
 *
 * 提供客户查询、跟进记录、线索评分等 AI 能力。
 */
class CrmCapability extends BusinessCapability
{
    public function getTag(): string
    {
        return 'crm';
    }

    public function getRequiredTables(): array
    {
        return [
            'customer' => ['customer', 'client', 'user', 'member', 'contact'],
        ];
    }

    public function getContextPrompt(PluginSchemaDTO $schema): string
    {
        return <<<'PROMPT'

## 客户管理业务知识

你是一个专业的客户关系管理（CRM）AI 助手。你需要理解以下业务概念：

### 核心概念
- **线索 (Lead)**：潜在客户，尚未确认购买意向
- **客户 (Customer)**：已成交或有明确购买意向的联系人
- **跟进 (Follow-up)**：与客户或线索的每次沟通记录
- **转化率 (Conversion Rate)**：线索转化为客户的比率
- **客户生命周期 (Customer Lifecycle)**：潜在→意向→成交→活跃→流失

### 关键指标
- **线索转化率** = 转化为客户的线索数 / 总线索数 × 100%
- **客户留存率** = 期末活跃客户数 / 期初客户数 × 100%
- **客户流失率** = 流失客户数 / 总客户数 × 100%
- **客户平均跟进次数** = 总跟进次数 / 客户数
- **线索响应时间**：从创建线索到首次跟进的平均时间

### 分析维度
1. 线索质量分析：线索来源渠道效果对比
2. 客户分层：按购买金额/频次分为 VIP、普通、低活跃
3. 跟进效果：不同跟进方式的转化率对比
4. 流失预警：识别长时间未互动的高价值客户

PROMPT;
    }

    public function getTools(PluginSchemaDTO $schema): array
    {
        return [];
    }

    public function getRecommendedSkills(): array
    {
        return [
            'data.analyze',
            'data.trend_predict',
            'anomaly.detect',
        ];
    }

    public function getRecommendedPipelines(PluginSchemaDTO $schema): array
    {
        return [
            'customer_insight' => [
                'name'        => '客户洞察分析',
                'description' => '分析客户数据，输出客户画像和分层建议',
                'steps'       => [
                    [
                        'skill'         => 'data.analyze',
                        'input_mapping' => [
                            'query' => '分析客户数据：客户分层（VIP/普通/低活跃）、购买偏好、流失风险识别',
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
            'follow_up_reminder' => [
                'name'        => '跟进提醒',
                'description' => '识别需要跟进的客户并生成提醒',
                'steps'       => [
                    [
                        'skill'         => 'data.analyze',
                        'input_mapping' => [
                            'query' => '识别超过 7 天未跟进的活跃客户和高价值客户',
                        ],
                    ],
                ],
            ],
        ];
    }
}
