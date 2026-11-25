<?php
declare(strict_types=1);

namespace wise\agent\feedback;

/**
 * Skill Prompt 优化器
 *
 * 基于反馈数据生成 Prompt 优化建议。
 * 不自动修改 Prompt，只生成建议供管理员审核。
 */
class SkillOptimizer
{
    protected FeedbackCollector $collector;

    public function __construct(FeedbackCollector $collector)
    {
        $this->collector = $collector;
    }

    /**
     * 分析 Skill 表现并生成优化建议
     *
     * @param string $skillName
     * @return array
     */
    public function analyze(string $skillName): array
    {
        $stats = $this->collector->getStats($skillName, 'all');
        $corrections = $this->collector->getRecentCorrections($skillName, 20);

        $suggestions = [];
        $issues = [];

        if ($stats['avg_rating'] > 0 && $stats['avg_rating'] < 3.5) {
            $issues[] = "平均评分偏低（{$stats['avg_rating']}/5），建议优化 Prompt";
            $suggestions[] = "检查 system prompt 是否清晰表达了任务目标";
        }

        $dist = $stats['distribution'] ?? [];
        $lowCount = ($dist[1] ?? 0) + ($dist[2] ?? 0);
        $total = $stats['total'] ?? 0;
        if ($total > 0 && $lowCount / $total > 0.3) {
            $issues[] = "超过 30% 的反馈为低评分（1-2 分）";
            $suggestions[] = "考虑增加输出格式的约束，或提高 prompt 中的示例质量";
        }

        if (count($corrections) > 0) {
            $issues[] = "收到 " . count($corrections) . " 条用户纠正";
            $suggestions[] = "分析高频纠正模式，调整 system prompt 中的对应指令";

            $commonPatterns = $this->extractPatterns($corrections);
            if (!empty($commonPatterns)) {
                $suggestions[] = "常见问题模式: " . implode('、', $commonPatterns);
            }
        }

        return [
            'skill_name'  => $skillName,
            'stats'       => $stats,
            'issues'      => $issues,
            'suggestions' => $suggestions,
            'correction_samples' => array_slice($corrections, 0, 5),
        ];
    }

    /**
     * 批量分析所有 Skill（从反馈数据中提取有反馈的 Skill）
     */
    public function analyzeAll(): array
    {
        $lowRated = $this->collector->getLowRatedSkills(4.0, 3);
        $results = [];

        foreach ($lowRated as $skill) {
            $results[] = $this->analyze($skill['skill_name']);
        }

        return $results;
    }

    /**
     * 从纠正数据中提取常见模式
     */
    protected function extractPatterns(array $corrections): array
    {
        $keywords = [
            '太啰嗦'    => '输出过于冗长',
            '太短'      => '输出不够详细',
            '不准确'    => '信息不准确',
            '格式'      => '输出格式问题',
            '重复'      => '内容重复',
            '遗漏'      => '遗漏关键信息',
            'AI痕迹'    => 'AI 痕迹明显',
            '不符合'    => '不符合预期要求',
            '偏题'      => '偏离主题',
            '生硬'      => '语言生硬',
        ];

        $matched = [];
        foreach ($corrections as $c) {
            $text = $c['correction'] ?? '';
            foreach ($keywords as $kw => $label) {
                if (mb_strpos($text, $kw) !== false && !in_array($label, $matched)) {
                    $matched[] = $label;
                }
            }
        }

        return $matched;
    }
}
