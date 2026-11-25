<?php
declare(strict_types=1);

namespace wise\agent\skill\builtin;

use wise\agent\skill\BaseSkill;

/**
 * SEO 优化 Skill
 *
 * 分析内容并给出 SEO 优化建议。
 *
 * 分类: content
 */
class SeoOptimizeSkill extends BaseSkill
{
    public function getName(): string
    {
        return 'content.seo_optimize';
    }

    public function getDescription(): string
    {
        return '分析内容并给出SEO优化建议，包括标题优化、关键词密度、元描述等';
    }

    public function getCategory(): string
    {
        return 'content';
    }

    public function getInputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'title' => [
                    'type'        => 'string',
                    'description' => '文章标题',
                ],
                'content' => [
                    'type'        => 'string',
                    'description' => '文章内容',
                ],
                'target_keywords' => [
                    'type'        => 'string',
                    'description' => '目标关键词，多个用逗号分隔',
                ],
                'action' => [
                    'type'        => 'string',
                    'description' => '操作类型',
                    'enum'        => ['分析建议', '优化改写', '生成元数据'],
                    'default'     => '分析建议',
                ],
            ],
            'required' => ['content'],
        ];
    }

    public function getSystemPrompt(): string
    {
        return <<<'PROMPT'
你是一个资深的 SEO 优化专家，精通搜索引擎优化策略。

优化要求：
- 操作类型: {action}
- 目标关键词: {target_keywords}

如果是"分析建议"模式：
1. 分析标题的 SEO 友好度（长度、关键词位置、吸引力）
2. 检查关键词密度是否合理（建议 2-5%）
3. 评估内容结构（H标签使用、段落长度）
4. 检查是否有重复内容或薄弱段落
5. 给出元描述建议（120-160字）
6. 建议内部链接和外链策略
7. 给出具体的改进方案，按优先级排序

如果是"优化改写"模式：
- 直接输出优化后的标题和内容
- 在改动处标注修改原因

如果是"生成元数据"模式：
- 输出优化后的 title 标签（重要关键词在前）
- 输出 meta description（120-160字，包含 CTA）
- 输出 meta keywords 建议

请根据操作类型输出相应的结果。
PROMPT;
    }
}
