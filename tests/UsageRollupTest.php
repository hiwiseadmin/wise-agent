<?php
declare(strict_types=1);

namespace wise\agent\test;

use PHPUnit\Framework\TestCase;
use wise\agent\cost\UsageRollup;

/**
 * C2-2：UsageRollup 聚合纯逻辑（验收 8 的可离线部分）
 */
class UsageRollupTest extends TestCase
{
    private function logRow(
        string $userType,
        int $userId,
        string $provider,
        string $model,
        int $totalTokens,
        array $metadata
    ): array {
        return [
            'user_type'    => $userType,
            'user_id'      => $userId,
            'provider'     => $provider,
            'model'        => $model,
            'total_tokens' => $totalTokens,
            'metadata'     => json_encode($metadata, JSON_UNESCAPED_UNICODE),
        ];
    }

    public function testAggregateGroupsByDimensions(): void
    {
        $rows = [
            $this->logRow('admin', 1, 'openai', 'gpt-4o-mini', 100, ['usage' => ['prompt_tokens' => 50, 'completion_tokens' => 50]]),
            $this->logRow('admin', 2, 'openai', 'gpt-4o-mini', 200, ['usage' => ['prompt_tokens' => 100, 'completion_tokens' => 100]]),
            $this->logRow('admin', 1, 'anthropic', 'claude-3', 300, ['usage' => ['prompt_tokens' => 150, 'completion_tokens' => 150]]),
        ];

        $buckets = (new UsageRollup())->aggregate($rows);

        $this->assertCount(3, $buckets);
    }

    public function testAggregateSumsCallsAndTotalTokens(): void
    {
        $rows = [
            $this->logRow('admin', 1, 'openai', 'gpt-4o-mini', 100, []),
            $this->logRow('admin', 1, 'openai', 'gpt-4o-mini', 200, []),
        ];

        $buckets = (new UsageRollup())->aggregate($rows);

        $this->assertCount(1, $buckets);
        $this->assertSame(2, $buckets[0]['calls']);
        $this->assertSame(300, $buckets[0]['total_tokens']);
    }

    public function testAggregateExtractsPromptAndCompletionFromMetadata(): void
    {
        $rows = [
            $this->logRow('admin', 1, 'openai', 'gpt-4o-mini', 3000, [
                'usage' => ['prompt_tokens' => 1000, 'completion_tokens' => 2000, 'total_tokens' => 3000],
            ]),
        ];

        $buckets = (new UsageRollup())->aggregate($rows);

        $this->assertSame(1000, $buckets[0]['prompt_tokens']);
        $this->assertSame(2000, $buckets[0]['completion_tokens']);
    }

    public function testAggregateCountsUnpricedCalls(): void
    {
        $rows = [
            $this->logRow('admin', 1, 'openai', 'gpt-4o-mini', 100, [
                'cost' => ['cost' => 0.009, 'currency' => 'CNY', 'priced' => true, 'reason' => 'ok'],
            ]),
            $this->logRow('admin', 1, 'openai', 'gpt-4o-mini', 200, [
                'cost' => ['cost' => 0.0, 'currency' => 'CNY', 'priced' => false, 'reason' => 'pricing_unknown'],
            ]),
            $this->logRow('admin', 1, 'openai', 'gpt-4o-mini', 300, []),
        ];

        $buckets = (new UsageRollup())->aggregate($rows);

        $this->assertSame(3, $buckets[0]['calls']);
        $this->assertSame(2, $buckets[0]['unpriced_calls'], '未定价调用数 = priced=false + 缺 cost');
    }

    public function testAggregateSumsCost(): void
    {
        $rows = [
            $this->logRow('admin', 1, 'openai', 'gpt-4o-mini', 100, ['cost' => ['cost' => 0.005, 'currency' => 'CNY', 'priced' => true]]),
            $this->logRow('admin', 1, 'openai', 'gpt-4o-mini', 200, ['cost' => ['cost' => 0.007, 'currency' => 'CNY', 'priced' => true]]),
        ];

        $buckets = (new UsageRollup())->aggregate($rows);

        $this->assertSame(0.012, $buckets[0]['cost']);
        $this->assertSame('CNY', $buckets[0]['currency']);
    }

    public function testAggregateIsDeterministic(): void
    {
        $rows = [
            $this->logRow('admin', 1, 'openai', 'gpt-4o-mini', 100, ['cost' => ['cost' => 0.001, 'currency' => 'CNY', 'priced' => true]]),
            $this->logRow('admin', 2, 'openai', 'gpt-4o-mini', 200, []),
        ];

        $first  = (new UsageRollup())->aggregate($rows);
        $second = (new UsageRollup())->aggregate($rows);

        $this->assertSame($first, $second, '纯函数：同一输入结果一致（幂等聚合）');
    }

    public function testBuildRowsAddsDayAndTimestamps(): void
    {
        $buckets = [[
            'user_type' => 'admin', 'user_id' => 1, 'provider' => 'openai', 'model' => 'gpt-4o-mini',
            'calls' => 1, 'prompt_tokens' => 10, 'completion_tokens' => 20, 'total_tokens' => 30,
            'cost' => 0.001, 'currency' => 'CNY', 'unpriced_calls' => 0,
        ]];

        $rows = (new UsageRollup())->buildRows($buckets, '2026-10-17');

        $this->assertCount(1, $rows);
        $this->assertSame('2026-10-17', $rows[0]['day']);
        $this->assertArrayHasKey('create_time', $rows[0]);
        $this->assertArrayHasKey('update_time', $rows[0]);
        $this->assertSame(1, $rows[0]['calls']);
        $this->assertSame(30, $rows[0]['total_tokens']);
    }
}
