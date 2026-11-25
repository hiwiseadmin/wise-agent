<?php
declare(strict_types=1);

namespace wise\agent\skill\builtin;

use wise\agent\skill\BaseSkill;

/**
 * 标题生成 Skill
 *
 * 根据主题/关键词生成多个 SEO 友好的标题候选。
 *
 * 分类: content
 */
class TitleGenerateSkill extends BaseSkill
{
    public function getName(): string
    {
        return 'content.title_generate';
    }

    public function getDescription(): string
    {
        return '根据关键词或主题生成多个SEO友好的标题候选';
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
                'topic' => [
                    'type'        => 'string',
                    'description' => '文章主题或核心话题',
                ],
                'keywords' => [
                    'type'        => 'string',
                    'description' => '目标关键词，多个用逗号分隔',
                ],
                'count' => [
                    'type'        => 'integer',
                    'description' => '生成标题数量',
                    'default'     => 5,
                    'minimum'     => 1,
                    'maximum'     => 10,
                ],
                'style' => [
                    'type'        => 'string',
                    'description' => '标题风格',
                    'enum'        => ['专业', '轻松', '营销', '学术', '新闻'],
                    'default'     => '专业',
                ],
                'target_audience' => [
                    'type'        => 'string',
                    'description' => '目标受众',
                    'default'     => '通用',
                ],
            ],
            'required' => ['topic'],
        ];
    }

    public function getSystemPrompt(): string
    {
        return <<<'PROMPT'
你是一个专业的标题撰写专家，擅长创作吸引人且 SEO 友好的标题。

任务要求：
1. 根据提供的主题和关键词，生成 {count} 个标题候选
2. 标题风格应为: {style}
3. 目标受众: {target_audience}

标题创作原则：
- 长度控制在 15-30 字之间
- 包含核心关键词
- 引起读者好奇心或解决痛点
- 避免标题党，保持真实可信
- 每个标题应各有侧重，避免重复
- 如果风格是"营销"，适当使用数字和情感词
- 如果风格是"学术"，保持严谨和专业

输出格式：生成 {count} 个标题，每个标题占一行，以编号开头。
格式示例：
1. [标题内容]
2. [标题内容]
...

请直接输出标题列表，无需额外说明。
PROMPT;
    }
}
