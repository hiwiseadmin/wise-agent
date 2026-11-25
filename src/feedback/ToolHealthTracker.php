<?php
declare(strict_types=1);

namespace wise\agent\feedback;

use think\facade\Db;

/**
 * 工具健康统计＼�
 *
 * 从两个**既有落库**按 `tool_name` 聚合工具级健康指标，**不新增采集点**：
 *   - `ai_plan_steps`（`kind ∈ {tool,action}` 且 `status ∈ {completed,failed}`），工具名 = `target`；
 *   - `ai_closure_events`（`scene = tool_executed` 且 `result ∈ {success,failure}`），工具名 = `capability_name`。
 *
 * 度量：`calls / success / failure / failure_rate / avg_duration / top_failure_reason`。
 * `avg_duration` 统一为**秒**（`ai_plan_steps.duration` 已是秒，`ai_closure_events.duration_ms` 毫秒换算）。
 *
 * ## 已知口径（如实登记）
 * 同一工具调用可能同时出现在两张表（B1 计划步骤 + B2 闭环事件），本类**不去重**
 * （两表字段不同、无统一贯通键，无法精确去重）；以 `closure_events` 为主、`plan_steps` 为补充。
 *
 * @package wise\agent
 */
final class ToolHealthTracker
{
    public function __construct(
        /** 可注入的计划步骤加载器（单测替身）：fn(): array */
        private readonly ?\Closure $planStepsLoader = null,
        /** 可注入的闭环事件加载器（单测替身）：fn(): array */
        private readonly ?\Closure $closureEventsLoader = null,
    ) {
    }

    /**
     * 统计（加载 + 聚合）
     *
     * @return array<int, array<string, mixed>>
     */
    public function stats(): array
    {
        return $this->aggregate($this->loadPlanSteps(), $this->loadClosureEvents());
    }

    /**
     * 聚合纯逻辑（可离线单测，验收 4）
     *
     * @param array<int, array<string, mixed>> $planSteps     `ai_plan_steps` 行
     * @param array<int, array<string, mixed>> $closureEvents `ai_closure_events` 行
     * @return array<int, array<string, mixed>>
     */
    public function aggregate(array $planSteps, array $closureEvents): array
    {
        $buckets = [];

        foreach ($planSteps as $step) {
            $kind = (string) ($step['kind'] ?? '');
            if ($kind !== 'tool' && $kind !== 'action') {
                continue;
            }
            $tool = trim((string) ($step['target'] ?? ''));
            if ($tool === '') {
                continue;
            }
            $status = (string) ($step['status'] ?? '');
            if ($status !== 'completed' && $status !== 'failed') {
                continue;
            }

            $bucket = &$this->bucket($buckets, $tool);
            $bucket['calls']++;
            $bucket['duration_total']  += (float) ($step['duration'] ?? 0);
            $bucket['duration_count']++;

            if ($status === 'completed') {
                $bucket['success']++;
            } else {
                $bucket['failure']++;
                $bucket['failures'][] = (string) ($step['error'] ?? '');
            }
        }
        unset($bucket);

        foreach ($closureEvents as $event) {
            if ((string) ($event['scene'] ?? '') !== 'tool_executed') {
                continue;
            }
            $tool = trim((string) ($event['capability_name'] ?? ''));
            if ($tool === '') {
                continue;
            }
            $result = (string) ($event['result'] ?? '');
            if ($result !== 'success' && $result !== 'failure') {
                continue;
            }

            $bucket = &$this->bucket($buckets, $tool);
            $bucket['calls']++;
            $bucket['duration_total']  += ((float) ($event['duration_ms'] ?? 0)) / 1000;
            $bucket['duration_count']++;

            if ($result === 'success') {
                $bucket['success']++;
            } else {
                $bucket['failure']++;
                $bucket['failures'][] = (string) ($event['reason'] ?? $event['error'] ?? '');
            }
        }
        unset($bucket);

        foreach ($buckets as &$bucket) {
            $bucket['avg_duration'] = $bucket['duration_count'] > 0
                ? round($bucket['duration_total'] / $bucket['duration_count'], 3)
                : 0.0;
            $bucket['failure_rate'] = $bucket['calls'] > 0
                ? round($bucket['failure'] / $bucket['calls'], 4)
                : 0.0;
            $bucket['top_failure_reason'] = $this->topReason($bucket['failures']);
            unset($bucket['duration_total'], $bucket['duration_count'], $bucket['failures']);
        }
        unset($bucket);

        return array_values($buckets);
    }

    /**
     * @param array<string, array<string, mixed>> $buckets
     * @return array<string, mixed>
     */
    private function &bucket(array &$buckets, string $tool): array
    {
        if (!isset($buckets[$tool])) {
            $buckets[$tool] = [
                'tool'          => $tool,
                'calls'         => 0,
                'success'       => 0,
                'failure'       => 0,
                'duration_total' => 0.0,
                'duration_count' => 0,
                'failures'      => [],
            ];
        }

        return $buckets[$tool];
    }

    /**
     * 失败原因 TOP1
     *
     * @param string[] $reasons
     */
    private function topReason(array $reasons): string
    {
        if ($reasons === []) {
            return '';
        }
        $counts = array_count_values($reasons);
        arsort($counts);

        return (string) array_key_first($counts);
    }

    private function loadPlanSteps(): array
    {
        if ($this->planStepsLoader !== null) {
            return (array) ($this->planStepsLoader)();
        }

        try {
            return Db::name('ai_plan_steps')
                ->whereIn('kind', ['tool', 'action'])
                ->whereIn('status', ['completed', 'failed'])
                ->select()
                ->toArray();
        } catch (\Throwable) {
            return [];
        }
    }

    private function loadClosureEvents(): array
    {
        if ($this->closureEventsLoader !== null) {
            return (array) ($this->closureEventsLoader)();
        }

        try {
            return Db::name('ai_closure_events')
                ->where('scene', 'tool_executed')
                ->whereIn('result', ['success', 'failure'])
                ->select()
                ->toArray();
        } catch (\Throwable) {
            return [];
        }
    }
}
