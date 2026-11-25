<?php
declare(strict_types=1);

namespace wise\agent\skill\builtin;

use wise\agent\skill\BaseSkill;

/**
 * 异常检测 Skill
 *
 * 从数据中识别异常值和异常模式。
 *
 * 分类: data
 */
class AnomalyDetectSkill extends BaseSkill
{
    public function getName(): string
    {
        return 'data.anomaly_detect';
    }

    public function getDescription(): string
    {
        return '从数据中识别异常值和异常模式，适用于库存异常、销售突变等场景';
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
                    'description' => '需要检测的数据（JSON、CSV或文本描述）',
                ],
                'metric' => [
                    'type'        => 'string',
                    'description' => '检测的目标指标',
                ],
                'sensitivity' => [
                    'type'        => 'string',
                    'description' => '检测灵敏度',
                    'enum'        => ['严格', '标准', '宽松'],
                    'default'     => '标准',
                ],
                'context' => [
                    'type'        => 'string',
                    'description' => '业务上下文（有助于判断异常的严重程度）',
                ],
                'output_style' => [
                    'type'        => 'string',
                    'description' => '输出风格',
                    'enum'        => ['仅列表', '详细分析'],
                    'default'     => '详细分析',
                ],
            ],
            'required' => ['data'],
        ];
    }

    public function getSystemPrompt(): string
    {
        return <<<'PROMPT'
你是一个专业的数据异常检测分析师，擅长从数据中发现异常信号。

检测要求：
- 目标指标: {metric}
- 检测灵敏度: {sensitivity}
- 业务上下文: {context}
- 输出风格: {output_style}

检测维度：
1. 数值异常 — 远超或远低于正常范围的值
2. 趋势突变 — 方向或速度的突然变化
3. 频率异常 — 事件发生频率的异常变化
4. 模式偏离 — 偏离预期的周期性规律
5. 关联异常 — 多个指标之间的异常关系

灵敏度说明：
- 严格: 只标记明显的异常，避免误报
- 标准: 标记中等及以上的异常
- 宽松: 标记所有可能的异常，可能有误报

输出格式：
## 异常概览
总共发现 N 个异常点，其中严重异常 M 个

## 严重异常（需立即关注）
| 指标 | 数值 | 正常范围 | 偏差 | 可能原因 | 建议 |
每个异常包含以上信息

## 一般异常（建议关注）
简要列出

## 整体评估
数据质量的总体评价和建议

如果是仅列表模式，只输出异常列表。

提供的数据：
{data}

请按格式输出检测结果。
PROMPT;
    }

    public function getOutputFormat(): string
    {
        return 'markdown';
    }
}
