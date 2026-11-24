<?php
declare(strict_types=1);

namespace wise\agent\skill\builtin;

use wise\agent\skill\BaseSkill;

/**
 * 数据分析 Skill
 *
 * 对提供的数据进行分析，总结趋势、规律和洞察。
 *
 * 分类: data
 */
class DataAnalyzeSkill extends BaseSkill
{
    public function getName(): string
    {
        return 'data.analyze';
    }

    public function getDescription(): string
    {
        return '对数据进行多维度分析，总结趋势、规律和业务洞察';
    }

    public function getCategory(): string
    {
        return 'data';
    }

    public function getInputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'data' => [
                    'type'        => 'string',
                    'description' => '需要分析的数据（JSON、CSV文本或自然语言描述）',
                ],
                'query' => [
                    'type'        => 'string',
                    'description' => '分析需求或问题（自然语言）',
                ],
                'dimensions' => [
                    'type'        => 'string',
                    'description' => '重点关注的分析维度，多个用逗号分隔',
                ],
                'output_style' => [
                    'type'        => 'string',
                    'description' => '输出风格',
                    'enum'        => ['简要分析', '详细报告', '可执行建议'],
                    'default'     => '详细报告',
                ],
            ],
            'required' => ['query'],
        ];
    }

    public function getSystemPrompt(): string
    {
        return <<<'PROMPT'
你是一个资深的数据分析师，擅长从数据中发现规律和洞察。

分析要求：
- 分析需求: {query}
- 重点关注维度: {dimensions}
- 输出风格: {output_style}

分析原则：
1. 先理解数据结构和含义
2. 识别关键指标和趋势变化
3. 发现异常值和特殊模式
4. 进行同比、环比等对比分析
5. 给出数据驱动的结论和建议
6. 如果有具体数据，优先使用数据说话
7. 如果是简要分析，控制在 5 个要点以内
8. 如果是可执行建议，每条建议要有预期的效果描述

提供的数据：
{data}

请按照输出风格要求进行分析。
PROMPT;
    }
}
