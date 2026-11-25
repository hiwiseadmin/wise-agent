<?php
declare(strict_types=1);

namespace wise\agent\skill\builtin;

use wise\agent\skill\BaseSkill;

/**
 * 报表生成 Skill
 *
 * 根据数据生成结构化的分析报表。
 *
 * 分类: data
 */
class ReportGenerateSkill extends BaseSkill
{
    public function getName(): string
    {
        return 'data.report_generate';
    }

    public function getDescription(): string
    {
        return '根据数据生成结构化的分析报表，支持Markdown和HTML格式';
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
                    'description' => '报表数据（JSON 或描述文本）',
                ],
                'report_type' => [
                    'type'        => 'string',
                    'description' => '报表类型',
                    'enum'        => ['销售报表', '库存报表', '用户分析', '财务概览', '运营报告', '自定义'],
                    'default'     => '自定义',
                ],
                'period' => [
                    'type'        => 'string',
                    'description' => '报表周期描述（如：本周、本月、2026年Q2）',
                ],
                'format' => [
                    'type'        => 'string',
                    'description' => '输出格式',
                    'enum'        => ['markdown', 'html'],
                    'default'     => 'markdown',
                ],
                'sections' => [
                    'type'        => 'string',
                    'description' => '需要包含的报表章节，多个用逗号分隔（空=自动选择）',
                ],
                'highlight_threshold' => [
                    'type'        => 'number',
                    'description' => '变化阈值百分比，超过此值的变化会被重点标注',
                    'default'     => 10,
                ],
            ],
            'required' => ['data'],
        ];
    }

    public function getSystemPrompt(): string
    {
        return <<<'PROMPT'
你是一个专业的数据报表分析师，擅长生成清晰易读的数据报表。

报表要求：
- 报表类型: {report_type}
- 报表周期: {period}
- 输出格式: {format}
- 重点章节: {sections}
- 重点标注阈值: 变化超过 {highlight_threshold}% 的数据点需要高亮

报表结构（如未指定章节，按以下默认结构）：
1. 报表概览 — 一句话总结核心发现
2. 关键指标 — 最重要的 3-5 个数字和同比/环比变化
3. 趋势分析 — 数据变化趋势和规律
4. 分项明细 — 按类别/维度的详细数据
5. 异常关注 — 异常值和需要重点关注的事项
6. 结论与建议 — 基于数据的可执行建议
7. 下期预测 — 基于当前趋势的预测（可选）

格式要求：
- 如果 format=markdown，使用 Markdown 表格和标题
- 如果 format=html，使用完整的 HTML 表格和样式（内联CSS，适配邮件）
- 数据变化超过阈值的使用 ⬆️⬇️ 或颜色标注
- 数字保留合理精度，大数使用千分位
- 添加数据来源和时间说明

提供的数据：
{data}

请按照以上要求生成报表。
PROMPT;
    }

    public function getOutputFormat(): string
    {
        return 'markdown';
    }
}
