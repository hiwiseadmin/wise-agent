<?php
declare(strict_types=1);

namespace wise\agent\feedback;

use think\facade\Db;
use wise\support\context\ContextualLogger;

/**
 * 反馈收集器
 *
 * 收集用户对 Skill 输出的评分和纠正反馈。
 * 数据持久化到数据库（ai_feedback 表），支持统计查询。
 */
class FeedbackCollector
{
    protected string $table;

    public function __construct(?string $table = null)
    {
        $this->table = $table ?? 'ai_feedback';
    }

    /**
     * 收集一条反馈
     */
    public function collect(Feedback $feedback): bool
    {
        try {
            $data = $feedback->toArray();
            $data['context_snapshot'] = json_encode($data['context'] ?? [], JSON_UNESCAPED_UNICODE);
            unset($data['context']);

            Db::name($this->table)->insert($data);

            ContextualLogger::info("[Feedback] Collected: {$feedback->skillName} rating={$feedback->rating}");

            return true;
        } catch (\Throwable $e) {
            ContextualLogger::error("[Feedback] Failed to collect: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * 获取 Skill 反馈统计
     *
     * @param string $skillName Skill 名称
     * @param string $period    时间范围: 7d / 30d / all
     * @return array
     */
    public function getStats(string $skillName, string $period = '30d'): array
    {
        try {
            $query = Db::name($this->table)->where('skill_name', $skillName);

            if ($period !== 'all') {
                $days = (int) $period;
                $since = date('Y-m-d H:i:s', strtotime("-{$days} days"));
                $query->where('create_time', '>=', $since);
            }

            $rows = $query->select()->toArray();

            $total = count($rows);
            if ($total === 0) {
                return [
                    'skill_name'  => $skillName,
                    'total'       => 0,
                    'avg_rating'  => 0,
                    'distribution' => [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0],
                    'comments'    => [],
                ];
            }

            $sum = 0;
            $distribution = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
            $comments = [];

            foreach ($rows as $row) {
                $rating = (int) ($row['rating'] ?? 0);
                $sum += $rating;
                if (isset($distribution[$rating])) {
                    $distribution[$rating]++;
                }
                if (!empty($row['comment'])) {
                    $comments[] = [
                        'rating'  => $rating,
                        'comment' => $row['comment'],
                        'date'    => $row['create_time'] ?? '',
                    ];
                }
            }

            return [
                'skill_name'   => $skillName,
                'total'        => $total,
                'avg_rating'   => round($sum / $total, 2),
                'distribution' => $distribution,
                'comments'     => array_slice($comments, 0, 20),
            ];
        } catch (\Throwable $e) {
            ContextualLogger::error("[Feedback] Stats failed: {$e->getMessage()}");
            return ['skill_name' => $skillName, 'total' => 0, 'avg_rating' => 0];
        }
    }

    /**
     * 获取低评分 Skill 列表
     *
     * @param float $threshold 平均评分阈值（低于此值视为低分）
     * @param int   $minCount  最少反馈数
     * @return array
     */
    public function getLowRatedSkills(float $threshold = 3.5, int $minCount = 5): array
    {
        try {
            $rows = Db::name($this->table)
                ->field('skill_name, COUNT(*) as total, AVG(rating) as avg_rating')
                ->group('skill_name')
                ->having('total >= ' . $minCount)
                ->having('avg_rating < ' . $threshold)
                ->order('avg_rating', 'asc')
                ->select()
                ->toArray();

            return array_map(function ($row) {
                return [
                    'skill_name' => $row['skill_name'],
                    'total'      => (int) $row['total'],
                    'avg_rating' => round((float) $row['avg_rating'], 2),
                ];
            }, $rows);
        } catch (\Throwable $e) {
            ContextualLogger::error("[Feedback] Low rated skills failed: {$e->getMessage()}");
            return [];
        }
    }

    /**
     * 获取最近的纠正反馈（可用于 Prompt 优化）
     */
    public function getRecentCorrections(string $skillName, int $limit = 10): array
    {
        try {
            $rows = Db::name($this->table)
                ->where('skill_name', $skillName)
                ->where('correction', '<>', '')
                ->whereNotNull('correction')
                ->order('create_time', 'desc')
                ->limit($limit)
                ->select()
                ->toArray();

            return array_map(function ($row) {
                $context = $row['context_snapshot'] ?? '{}';
                return [
                    'correction' => $row['correction'],
                    'context'    => is_string($context) ? json_decode($context, true) : $context,
                    'date'       => $row['create_time'] ?? '',
                ];
            }, $rows);
        } catch (\Throwable $e) {
            return [];
        }
    }
}
