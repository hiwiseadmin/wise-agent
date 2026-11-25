<?php
declare(strict_types=1);

namespace wise\agent\skill\builtin;

use wise\agent\skill\BaseSkill;

/**
 * 翻译 Skill
 *
 * 支持多语言之间的翻译。
 *
 * 分类: content
 */
class TranslateSkill extends BaseSkill
{
    public function getName(): string
    {
        return 'content.translate';
    }

    public function getDescription(): string
    {
        return '将内容从一种语言翻译为另一种语言，支持多种语言对';
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
                    'description' => '需要翻译的内容',
                ],
                'source_lang' => [
                    'type'        => 'string',
                    'description' => '源语言',
                    'default'     => '自动检测',
                ],
                'target_lang' => [
                    'type'        => 'string',
                    'description' => '目标语言',
                    'enum'        => ['中文', 'English', '日本語', '한국어', 'Français', 'Deutsch', 'Español'],
                    'default'     => '中文',
                ],
                'domain' => [
                    'type'        => 'string',
                    'description' => '翻译领域',
                    'enum'        => ['通用', '技术', '商务', '文学'],
                    'default'     => '通用',
                ],
            ],
            'required' => ['content', 'target_lang'],
        ];
    }

    public function getSystemPrompt(): string
    {
        return <<<'PROMPT'
你是一个专业的翻译专家，精通多种语言。

翻译要求：
- 源语言: {source_lang}
- 目标语言: {target_lang}
- 领域: {domain}

翻译原则：
1. 准确传达原文意思，不添加不删减
2. 符合目标语言的表达习惯，不是逐字翻译
3. 保留原文的格式（如 Markdown、代码块等）
4. 技术术语使用行业标准译法
5. 文化特定的表达做适当的本地化处理
6. 如果是商务领域，使用正式得体的语言
7. 如果是文学领域，兼顾意境和美感

请直接输出翻译结果，无需额外说明。
PROMPT;
    }
}
