<?php
declare(strict_types=1);

namespace wise\agent\skill\builtin;

use wise\agent\skill\BaseSkill;

/**
 * AI 去痕迹 Skill
 *
 * 将 AI 生成的内容改写得更加自然、人性化，消除 AI 写作痕迹。
 *
 * 分类: content
 */
class RewriteHumanizeSkill extends BaseSkill
{
    public function getName(): string
    {
        return 'content.rewrite_humanize';
    }

    public function getDescription(): string
    {
        return '将AI生成的内容改写得更加自然、人性化，消除机器感';
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
                'content' => [
                    'type'        => 'string',
                    'description' => '需要去 AI 痕迹的原始内容',
                ],
                'style' => [
                    'type'        => 'string',
                    'description' => '目标风格',
                    'enum'        => ['自然口语', '专业商务', '个人博客'],
                    'default'     => '自然口语',
                ],
                'intensity' => [
                    'type'        => 'string',
                    'description' => '改写强度',
                    'enum'        => ['轻度', '中度', '重度'],
                    'default'     => '中度',
                ],
            ],
            'required' => ['content'],
        ];
    }

    public function getSystemPrompt(): string
    {
        return <<<'PROMPT'
你是一个专业的文字润色师，擅长将 AI 生成的内容改写得更像人类写作。

改写要求：
- 目标风格: {style}
- 改写强度: {intensity}

需要消除的 AI 痕迹特征：
1. 过度使用"首先...其次...最后...""综上所述"等模板化表达
2. 每段都以"在当今""随着...的发展""值得注意的是"开头
3. 过于工整对称的句子结构
4. 缺乏个人观点的中立表述
5. 空洞的总结和废话
6. 异常的排比和重复强调
7. "不仅...而且...""既...又..."等生硬转折

改写技巧：
- 加入适当的个人观点和感受（适度）
- 变换句型长短，打破对称结构
- 使用更具体的例子替代抽象概括
- 保留原文的核心信息和数据
- 可以用"我""我们"等第一人称（如果风格合适）
- 偶尔使用口语化表达、反问句增加人情味
- 如果是"轻度"改写，保留大部分原文结构，只做微调
- 如果是"重度"改写，可以重组段落顺序，用全新表达

重要：不要添加原文中没有的信息，不要改变原文的事实和观点。

请直接输出改写后的内容，无需额外说明。
PROMPT;
    }
}
