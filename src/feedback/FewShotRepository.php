<?php
declare(strict_types=1);

namespace wise\agent\feedback;

use wise\agent\execution\ExecutionTracker;
use wise\agent\task\TaskQueryService;

/**
 * 少样本仓库＼�
 *
 * 历史**成功**执行沉淀为 few-shot 注入：只取 `status=completed`（成功）用例，
 * 数量 ≤ `feedback.few_shot.max_shots`。**失败用例不进 few-shot**＼�。
 *
 * @package wise\agent
 */
final class FewShotRepository
{
    public function __construct(
        /** 可注入的成功用例加载器（单测替身）：fn(): array */
        private readonly ?\Closure $successLoader = null,
    ) {
    }

    /**
     * 获取 few-shot 条目（只成功用例，数量 ≤ max_shots）
     *
     * @return array<int, array{task: string, provider: string, model: string}>
     */
    public function shots(): array
    {
        $maxShots = $this->maxShots();
        $shots    = [];

        foreach ($this->loadSuccess() as $row) {
            $shot = $this->toShot($row);
            if ($shot !== null) {
                $shots[] = $shot;
            }
            if (count($shots) >= $maxShots) {
                break;
            }
        }

        return $shots;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadSuccess(): array
    {
        if ($this->successLoader !== null) {
            try {
                return (array) ($this->successLoader)();
            } catch (\Throwable) {
                return [];
            }
        }

        try {
            $page = (new TaskQueryService())->paginate([
                'status' => ExecutionTracker::STATUS_COMPLETED,
            ], 1, 50);

            return $page['rows'] ?? [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return array{task: string, provider: string, model: string}|null
     */
    private function toShot(array $row): ?array
    {
        $task = trim((string) ($row['task'] ?? ''));
        if ($task === '') {
            return null;
        }

        return [
            'task'     => $task,
            'provider' => (string) ($row['provider'] ?? ''),
            'model'    => (string) ($row['model'] ?? ''),
        ];
    }

    private function maxShots(): int
    {
        try {
            return max(1, (int) config('wise-agent.feedback.few_shot.max_shots', 5));
        } catch (\Throwable) {
            return 5;
        }
    }
}
