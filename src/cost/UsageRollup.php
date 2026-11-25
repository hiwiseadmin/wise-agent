<?php
declare(strict_types=1);

namespace wise\agent\cost;

/**
 * 用量日汇总的**聚合纯逻辑**＼�
 *
 * 输入 `ai_agent_logs` 的行数组，按 `(user_type, user_id, provider, model)` 维度聚合，
 * 输出 `ai_usage_daily` 的度量。不碰 DB，方便离线单测（验收 8 的幂等与 unpriced_calls）。
 *
 * 数据来源（不新增采集点）：
 *  - `total_tokens`：`ai_agent_logs.total_tokens` 列；
 *  - `prompt_tokens` / `completion_tokens`：`metadata.usage`（C2-2 由 AgentManager 写入）；
 *  - `cost` / `unpriced_calls`：`metadata.cost`（C2-1 由 CostCalculator 估算写入）。
 */
final class UsageRollup
{
    /**
     * 按维度聚合
     *
     * @param array<int, array<string, mixed>> $rows `ai_agent_logs` 某日的原始行
     * @return array<int, array<string, mixed>> 聚合后的桶（不含 day，由 buildRows 补）
     */
    public function aggregate(array $rows): array
    {
        $buckets = [];

        foreach ($rows as $row) {
            $key = $this->dimensionKey($row);

            if (!isset($buckets[$key])) {
                $buckets[$key] = [
                    'user_type'         => (string) ($row['user_type'] ?? ''),
                    'user_id'           => (int) ($row['user_id'] ?? 0),
                    'provider'          => (string) ($row['provider'] ?? ''),
                    'model'             => (string) ($row['model'] ?? ''),
                    'calls'             => 0,
                    'prompt_tokens'     => 0,
                    'completion_tokens' => 0,
                    'total_tokens'      => 0,
                    'cost'              => 0.0,
                    'currency'          => 'CNY',
                    'unpriced_calls'    => 0,
                ];
            }

            $bucket = &$buckets[$key];
            $bucket['calls']++;
            $bucket['total_tokens'] += (int) ($row['total_tokens'] ?? 0);

            $metadata = $this->decodeMetadata($row['metadata'] ?? null);
            $usage    = is_array($metadata['usage'] ?? null) ? $metadata['usage'] : [];
            $cost     = is_array($metadata['cost'] ?? null) ? $metadata['cost'] : null;

            $bucket['prompt_tokens']     += (int) ($usage['prompt_tokens'] ?? 0);
            $bucket['completion_tokens'] += (int) ($usage['completion_tokens'] ?? 0);

            if (is_array($cost)) {
                $bucket['cost'] += (float) ($cost['cost'] ?? 0.0);
                if ($bucket['currency'] === 'CNY' && !empty($cost['currency'])) {
                    $bucket['currency'] = (string) $cost['currency'];
                }
                if (empty($cost['priced'])) {
                    $bucket['unpriced_calls']++;
                }
            } else {
                $bucket['unpriced_calls']++;
            }
        }

        return array_values($buckets);
    }

    /**
     * 把聚合桶转成 `ai_usage_daily` 的插入行（补 day 与时间戳）
     *
     * @param array<int, array<string, mixed>> $buckets
     * @return array<int, array<string, mixed>>
     */
    public function buildRows(array $buckets, string $day): array
    {
        $now  = date('Y-m-d H:i:s');
        $rows = [];

        foreach ($buckets as $bucket) {
            $rows[] = [
                'day'               => $day,
                'user_type'         => $bucket['user_type'],
                'user_id'           => $bucket['user_id'],
                'provider'          => $bucket['provider'],
                'model'             => $bucket['model'],
                'calls'             => $bucket['calls'],
                'prompt_tokens'     => $bucket['prompt_tokens'],
                'completion_tokens' => $bucket['completion_tokens'],
                'total_tokens'      => $bucket['total_tokens'],
                'cost'              => $bucket['cost'],
                'currency'          => $bucket['currency'],
                'unpriced_calls'    => $bucket['unpriced_calls'],
                'create_time'       => $now,
                'update_time'       => $now,
            ];
        }

        return $rows;
    }

    private function dimensionKey(array $row): string
    {
        return implode('|', [
            (string) ($row['user_type'] ?? ''),
            (int) ($row['user_id'] ?? 0),
            (string) ($row['provider'] ?? ''),
            (string) ($row['model'] ?? ''),
        ]);
    }

    /**
     * @param mixed $metadata `ai_agent_logs.metadata`（text JSON 或数组）
     * @return array<string, mixed>
     */
    private function decodeMetadata(mixed $metadata): array
    {
        if (is_array($metadata)) {
            return $metadata;
        }
        if (is_string($metadata) && $metadata !== '') {
            $decoded = json_decode($metadata, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }
}
