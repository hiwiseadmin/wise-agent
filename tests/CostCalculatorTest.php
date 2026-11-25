<?php
declare(strict_types=1);

namespace wise\agent\test;

use PHPUnit\Framework\TestCase;
use wise\agent\cost\CostCalculator;

/**
 * C2-1：CostCalculator 估算口径（验收 7，纯离线）
 */
class CostCalculatorTest extends TestCase
{
    public function testEstimatePricedModelComputesPromptAndCompletionSeparately(): void
    {
        $calc = new CostCalculator([
            'openai' => ['gpt-4o-mini' => ['input' => 0.001, 'output' => 0.004, 'currency' => 'CNY']],
            '*:default' => ['input' => 0, 'output' => 0, 'currency' => 'CNY'],
        ]);

        $result = $calc->estimate('openai', 'gpt-4o-mini', [
            'prompt_tokens' => 1000,
            'completion_tokens' => 2000,
            'total_tokens' => 3000,
        ]);

        $this->assertTrue($result['priced']);
        $this->assertSame('ok', $result['reason']);
        $this->assertSame('CNY', $result['currency']);
        $this->assertSame(0.009, $result['cost']);
    }

    public function testEstimateUnpricedModelDoesNotFakeZeroPrice(): void
    {
        $calc = new CostCalculator([
            'openai' => ['gpt-4o-mini' => ['input' => 0.001, 'output' => 0.004, 'currency' => 'CNY']],
            '*:default' => ['input' => 0, 'output' => 0, 'currency' => 'CNY'],
        ]);

        $result = $calc->estimate('anthropic', 'claude-3', [
            'prompt_tokens' => 1000,
            'completion_tokens' => 500,
            'total_tokens' => 1500,
        ]);

        $this->assertFalse($result['priced']);
        $this->assertSame(0.0, $result['cost']);
        $this->assertSame('pricing_unknown', $result['reason']);
    }

    public function testEstimateUsageMissing(): void
    {
        $calc = new CostCalculator([
            'openai' => ['gpt-4o-mini' => ['input' => 0.001, 'output' => 0.004, 'currency' => 'CNY']],
        ]);

        $result = $calc->estimate('openai', 'gpt-4o-mini', [
            'prompt_tokens' => 0,
            'completion_tokens' => 0,
            'total_tokens' => 0,
        ]);

        $this->assertFalse($result['priced']);
        $this->assertSame('usage_missing', $result['reason']);
    }

    public function testEstimatePricingUnknownWhenPricingEmpty(): void
    {
        $calc = new CostCalculator([]);

        $result = $calc->estimate('openai', 'gpt-4o', [
            'prompt_tokens' => 100,
            'completion_tokens' => 50,
            'total_tokens' => 150,
        ]);

        $this->assertFalse($result['priced']);
        $this->assertSame('pricing_unknown', $result['reason']);
    }

    public function testEstimateUsesWildcardDefaultWhenModelNotListed(): void
    {
        $calc = new CostCalculator([
            'openai' => ['gpt-4o-mini' => ['input' => 0.001, 'output' => 0.004, 'currency' => 'CNY']],
            '*:default' => ['input' => 0.002, 'output' => 0.003, 'currency' => 'USD'],
        ]);

        $result = $calc->estimate('openai', 'some-unknown-model', [
            'prompt_tokens' => 1000,
            'completion_tokens' => 1000,
            'total_tokens' => 2000,
        ]);

        $this->assertTrue($result['priced']);
        $this->assertSame('USD', $result['currency']);
        $this->assertSame(0.005, $result['cost']);
    }

    public function testEstimateRoundsToSixDecimals(): void
    {
        $calc = new CostCalculator([
            'openai' => ['gpt-4o' => ['input' => 0.000001, 'output' => 0.000002, 'currency' => 'CNY']],
        ]);

        $result = $calc->estimate('openai', 'gpt-4o', [
            'prompt_tokens' => 333,
            'completion_tokens' => 777,
            'total_tokens' => 1110,
        ]);

        $this->assertTrue($result['priced']);
        $this->assertSame(0.000002, $result['cost'], '6 位小数舍入');
    }
}
