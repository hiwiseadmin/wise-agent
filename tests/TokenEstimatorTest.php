<?php
declare(strict_types=1);

namespace wise\agent\test;

use PHPUnit\Framework\TestCase;
use wise\agent\client\DeepSeekClient;
use wise\agent\client\OpenAiClient;
use wise\agent\client\TokenEstimator;

/**
 * B4-6：token 估算与成本预估
 *
 * 这些能力此前完全缺失，导致配额无法按 token 预算、后台无法列模型、
 * 账单只能事后对账。测试重点是「估算口径一致」与「未知模型不编造价格」。
 */
class TokenEstimatorTest extends TestCase
{
    public function testEmptyTextIsZero(): void
    {
        $this->assertSame(0, TokenEstimator::count(''));
    }

    public function testAsciiTextUsesFourCharsPerToken(): void
    {
        $this->assertSame(3, TokenEstimator::count('hello world'));
    }

    public function testCjkCharsCountAsOneTokenEach(): void
    {
        $this->assertSame(4, TokenEstimator::count('你好世界'));
    }

    public function testMixedTextCombinesBothRules(): void
    {
        $this->assertSame(6, TokenEstimator::count('你好世界abcdefgh'));
    }

    public function testMessageArrayAddsPerMessageOverhead(): void
    {
        $messages = [
            ['role' => 'system', 'content' => 'hi'],
            ['role' => 'user', 'content' => 'hello'],
        ];

        $count = TokenEstimator::count($messages);

        $this->assertGreaterThan(
            TokenEstimator::count('hi') + TokenEstimator::count('hello'),
            $count
        );
    }

    public function testMultimodalContentIsFlattened(): void
    {
        $messages = [[
            'role'    => 'user',
            'content' => [
                ['type' => 'text', 'text' => '你好'],
                ['type' => 'image_url', 'image_url' => ['url' => 'http://x/y.png']],
            ],
        ]];

        $this->assertGreaterThanOrEqual(2, TokenEstimator::count($messages));
    }

    /**
     * count() 只认「消息」（role/content）。工具 schema 交给它会退化成
     * 每条 7 token 的结构开销（name/description/parameters 全丢），必须用 countTools()。
     */
    public function testCountToolsCountsSchemaNotJustOverhead(): void
    {
        $tools = [[
            'type'     => 'function',
            'function' => [
                'name'        => 'cmd_demo',
                'description' => str_repeat('执行框架控制台命令并返回结构化结果。', 20),
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => ['day' => ['type' => 'string', 'description' => '目标日期']],
                ],
            ],
        ]];

        $viaMessages = TokenEstimator::count($tools);
        $viaTools    = TokenEstimator::countTools($tools);

        $this->assertSame(0, TokenEstimator::countTools([]));
        $this->assertGreaterThan($viaMessages * 5, $viaTools, '工具 schema 必须按 JSON 文本计数');
    }

    public function testCostForKnownModel(): void
    {
        $pricing = ['m' => ['input' => 2.0, 'output' => 8.0]];

        $this->assertEqualsWithDelta(10.0, TokenEstimator::cost($pricing, 'm', 1_000_000, 1_000_000), 1e-9);
        $this->assertEqualsWithDelta(0.002 + 0.004, TokenEstimator::cost($pricing, 'm', 1000, 500), 1e-9);
    }

    public function testUnknownModelCostsZeroRatherThanGuessing(): void
    {
        $this->assertSame(0.0, TokenEstimator::cost(['m' => ['input' => 1, 'output' => 1]], 'other', 100, 100));
    }

    public function testOpenAiClientExposesModelsAndCost(): void
    {
        $client = new OpenAiClient(['api_key' => 'test-key', 'model' => 'gpt-4o']);

        $this->assertContains('gpt-4o', $client->listModels());
        $this->assertGreaterThan(0, $client->countTokens('你好'));

        $this->assertEqualsWithDelta(2.5, $client->estimateCost(1_000_000, 0), 1e-9);
    }

    public function testConfiguredModelsOverrideBuiltinList(): void
    {
        $client = new OpenAiClient([
            'api_key' => 'test-key',
            'models'  => ['custom-a', 'custom-b'],
        ]);

        $this->assertSame(['custom-a', 'custom-b'], $client->listModels());
    }

    public function testDeepSeekUsesItsOwnPricing(): void
    {
        $openai   = new OpenAiClient(['api_key' => 'k', 'model' => 'deepseek-chat']);
        $deepseek = new DeepSeekClient(['api_key' => 'k']);

        $this->assertSame(0.0, $openai->estimateCost(1_000_000, 0));
        $this->assertGreaterThan(0, $deepseek->estimateCost(1_000_000, 0));

        $this->assertContains('deepseek-chat', $deepseek->listModels());
    }
}
