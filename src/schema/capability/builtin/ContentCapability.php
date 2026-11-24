<?php
declare(strict_types=1);

namespace wise\agent\schema\capability\builtin;

use wise\agent\schema\capability\BusinessCapability;
use wise\agent\schema\dto\PluginSchemaDTO;

/**
 * 内容管理业务能力（简化版）
 *
 * 提供内容查询、批量操作、SEO 分析等 AI 能力。
 */
class ContentCapability extends BusinessCapability
{
    public function getTag(): string
    {
        return 'content';
    }

    public function getRequiredTables(): array
    {
        return [
            'article'   => ['article', 'post', 'news', 'content'],
            'category'  => ['category', 'article_category', 'content_category'],
        ];
    }

    public function getContextPrompt(PluginSchemaDTO $schema): string
    {
        return <<<'PROMPT'

## 内容管理业务知识

你是一个专业的内容管理 AI 助手。你需要理解以下业务概念：

### 核心概念
- **文章 (Article/Post)**：发布的内容单元，含标题、正文、摘要、标签
- **分类 (Category)**：内容的分类体系，通常支持多级
- **标签 (Tag)**：内容的标签体系，跨分类关联
- **发布状态**：草稿 (draft)、已发布 (published)、已归档 (archived)
- **SEO 优化**：标题、关键词、描述、URL 结构优化

### 关键指标
- **内容产出量**：按时间统计的文章发布数量
- **阅读量/访问量**：文章被阅读的次数
- **平均阅读时长**：用户在一篇文章上的停留时间
- **互动率**：评论数 / 阅读量 × 100%
- **SEO 排名**：关键词在搜索引擎中的排名位置

### 分析维度
1. 内容热度排行：按阅读量排序，识别热门内容
2. 分类分布：各分类下的内容数量和产出趋势
3. SEO 健康度：标题长度、关键词密度、图片 alt 属性等检测
4. 内容时效性：识别过时内容，建议更新或归档

### 操作建议
- 发布前检查：标题长度 < 60 字符、正文 > 300 字、包含至少 1 张图片
- 定期更新高频搜索关键词相关内容
- 将低质/过时内容归档而非删除

PROMPT;
    }

    public function getTools(PluginSchemaDTO $schema): array
    {
        return [];
    }

    public function getRecommendedSkills(): array
    {
        return [
            'article.generate',
            'seo.optimize',
            'content.summarize',
            'rewrite.humanize',
        ];
    }

    public function getRecommendedPipelines(PluginSchemaDTO $schema): array
    {
        return [
            'content_audit' => [
                'name'        => '内容质量审查',
                'description' => '审查存量内容质量，识别低质文章',
                'steps'       => [
                    [
                        'skill'         => 'data.analyze',
                        'input_mapping' => [
                            'query' => '分析内容质量：识别正文少于 300 字的文章、缺少摘要的文章、阅读量低于 10 的文章',
                        ],
                    ],
                ],
            ],
            'seo_health_check' => [
                'name'        => 'SEO 健康检查',
                'description' => '检查全站内容的 SEO 健康状况',
                'steps'       => [
                    [
                        'skill'         => 'data.analyze',
                        'input_mapping' => [
                            'query' => '检查 SEO 健康度：标题过长(>60字)的文章、缺少 meta 描述的文章、重复标题的文章',
                        ],
                    ],
                    [
                        'skill'         => 'seo.optimize',
                        'input_mapping' => [
                            'articles' => '$steps.analysis.data',
                        ],
                    ],
                ],
            ],
        ];
    }
}
