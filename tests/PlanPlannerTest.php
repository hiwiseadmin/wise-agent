<?php
declare(strict_types=1);

namespace wise\agent\test;

use PHPUnit\Framework\TestCase;
use wise\agent\client\Feature;
use wise\agent\contract\AiClientInterface;
use wise\agent\plan\PlanCatalog;
use wise\agent\plan\PlanCatalogBuilder;
use wise\agent\plan\PlanCapabilityLookupInterface;
use wise\agent\plan\PlanPlanner;
use wise\agent\plan\PlanStep;
use wise\agent\plan\PlanValidator;

/**
 * 全部离线：LLM 走脚本化客户端（同一思路与 `client/FakeClient` 一致，
 * 但 FakeClient 的脚本是「先调工具再答话」，与本用例需要的「按次返回 JSON」不同型，
 * 因此本文件自带脚本化替身）。
 */
class PlanPlannerTest extends TestCase
{
    private const TOOL_READ  = 'lq_query';
    private const TOOL_WRITE = 'menu.action.host.stock.save';
    private const SKILL      = 'system.orchestrate';

    private function lookup(): PlannerLookupStub
    {
        return new PlannerLookupStub();
    }

    private function validator(array $options = []): PlanValidator
    {
        return new PlanValidator($options, $this->lookup());
    }

    /**
     * 测试用目录（3 个能力：读工具 / 写工具 / 技能）
     */
    private function catalog(): PlanCatalog
    {
        return PlanCatalog::of([
            new \wise\agent\plan\PlanCatalogEntry('tool', self::TOOL_READ, '自然语言列表查询', ['target', 'filters', 'limit'], false),
            new \wise\agent\plan\PlanCatalogEntry('tool', self::TOOL_WRITE, '保存库存', ['product_id', 'qty'], true),
            new \wise\agent\plan\PlanCatalogEntry('skill', self::SKILL, '动态编排', ['goal'], false),
        ]);
    }

    private function planner(
        array $options,
        ScriptedPlanClient $client,
        ?PlanCatalog $catalog = null
    ): TestablePlanPlanner {
        return new TestablePlanPlanner($options, $client, $this->validator($options), $catalog ?? $this->catalog());
    }

    /**
     * 一份合法计划（读 → 写，写步骤带断言）
     */
    private function validPlanJson(): string
    {
        return (string) json_encode([
            'goal'  => '盘点库存并生成补货建议',
            'steps' => [
                [
                    'id'     => 's1',
                    'kind'   => 'tool',
                    'target' => self::TOOL_READ,
                    'label'  => '查询低库存商品',
                    'input'  => ['target' => 'smartstock.product', 'filters' => ['stock__lte' => 10]],
                    'verify' => [['type' => 'count_gte', 'path' => 'data.total', 'value' => 0]],
                ],
                [
                    'id'     => 's2',
                    'kind'   => 'tool',
                    'target' => self::TOOL_WRITE,
                    'label'  => '生成补货建议',
                    'input'  => ['product_id' => '$steps.s1.data.list.0.id', 'qty' => 10],
                    'verify' => [['type' => 'tool_ok']],
                ],
            ],
        ], JSON_UNESCAPED_UNICODE);
    }


    public function testPlanSucceedsOnFirstAttempt(): void
    {
        $client = new ScriptedPlanClient([$this->validPlanJson()]);
        $result = $this->planner([], $client)->plan('盘点库存并生成补货建议');

        $this->assertTrue($result->ok, (string) $result->error);
        $this->assertNotNull($result->plan);
        $this->assertSame(1, $result->attempts);
        $this->assertSame(2, count($result->plan->steps));
        $this->assertSame(1, count($result->plan->writeSteps()), '写步骤被正确推导');
        $this->assertSame(3, $result->catalogSize);
        $this->assertSame(15, $result->totalTokens());
        $this->assertStringContainsString('规划成功', $result->summary());
        $this->assertSame('plan.llm', $result->trace[0]['step']);
        $this->assertSame('plan.passed', $result->trace[count($result->trace) - 1]['step']);
    }

    public function testSystemPromptCarriesContractAndCatalog(): void
    {
        $client = new ScriptedPlanClient([$this->validPlanJson()]);
        $this->planner([], $client)->plan('目标');

        $system = (string) $client->capturedMessages[0][0]['content'];

        $this->assertSame('system', $client->capturedMessages[0][0]['role']);
        $this->assertStringContainsString('skill|tool|action|node', $system, '输出契约必须给出 kind 枚举');
        $this->assertStringContainsString('tool_ok', $system, '输出契约必须给出断言闭集');
        $this->assertStringContainsString('写步骤', $system, '必须讲清写步骤要 verify');
        $this->assertStringContainsString('不得编造', $system);
        $this->assertStringContainsString(self::TOOL_WRITE, $system, '目录必须进 Prompt');
        $this->assertStringContainsString('【写】', $system, '目录必须标出写能力');

        $user = (string) $client->capturedMessages[0][1]['content'];
        $this->assertStringContainsString('目标：目标', $user);
    }

    public function testGoalIsCallerAuthoritativeEvenIfModelEchoesAnother(): void
    {
        $client = new ScriptedPlanClient([
            (string) json_encode([
                'goal'  => '模型自己改写的目标',
                'steps' => [['id' => 's1', 'kind' => 'tool', 'target' => self::TOOL_READ, 'input' => ['target' => 'x']]],
            ], JSON_UNESCAPED_UNICODE),
        ]);

        $result = $this->planner([], $client)->plan('调用方给的目标');

        $this->assertTrue($result->ok);
        $this->assertSame('调用方给的目标', $result->plan->goal, '目标以调用方为准');
    }

    public function testJsonFenceIsParsed(): void
    {
        $client = new ScriptedPlanClient(["好的，计划如下：\n```json\n" . $this->validPlanJson() . "\n```\n以上。"]);
        $result = $this->planner([], $client)->plan('目标');

        $this->assertTrue($result->ok, (string) $result->error);
        $this->assertSame(1, $result->attempts);
    }

    public function testJsonWrappedInProseWithoutFenceIsParsed(): void
    {
        $client = new ScriptedPlanClient(['先说明一下思路。' . $this->validPlanJson() . '以上是我的计划。']);
        $result = $this->planner([], $client)->plan('目标');

        $this->assertTrue($result->ok, (string) $result->error);
        $this->assertSame(1, $result->attempts);
    }

    /**
     * 目录**不得**泄漏完整 JSON Schema：那是第 ② 层按需拉取的事
     * （现状 `AgentManager` 每步全量喂 Schema，`AgentManager.php:206` —— B1 不重复这个错误）
     */
    public function testCatalogNeverLeaksFullJsonSchema(): void
    {
        $catalog = $this->catalog();

        foreach ([$catalog->toPrompt(2000), $catalog->toPrompt(120), $catalog->toPrompt(40)] as $text) {
            $this->assertStringNotContainsString('properties', $text);
            $this->assertStringNotContainsString('required', $text);
            $this->assertStringNotContainsString('"type":', $text);
        }

        $this->assertStringNotContainsString(
            'properties',
            (string) json_encode($catalog->toArray(), JSON_UNESCAPED_UNICODE)
        );
    }


    public function testRepairLoopRecoversFromInvalidPlan(): void
    {
        $invalid = (string) json_encode([
            'goal'  => 'x',
            'steps' => [['id' => 's1', 'kind' => 'tool', 'target' => 'menu.action.host.nope.save', 'input' => []]],
        ], JSON_UNESCAPED_UNICODE);

        $client = new ScriptedPlanClient([$invalid, $this->validPlanJson()]);
        $result = $this->planner(['max_plan_repairs' => 2], $client)->plan('目标');

        $this->assertTrue($result->ok, (string) $result->error);
        $this->assertSame(2, $result->attempts, '第一次不合法 ⇒ 第二次修复成功');
        $this->assertContains('plan.invalid', array_column($result->trace, 'step'));

        $secondRound = $client->capturedMessages[1];
        $lastMessage = (string) $secondRound[count($secondRound) - 1]['content'];
        $this->assertStringContainsString('unknown_capability', $lastMessage);
        $this->assertStringContainsString('计划未通过校验', $lastMessage);
    }

    public function testRepairBudgetExhaustedRejectsWithoutPlan(): void
    {
        $invalid = (string) json_encode([
            'goal'  => 'x',
            'steps' => [['id' => 's1', 'kind' => 'tool', 'target' => 'nope', 'input' => []]],
        ], JSON_UNESCAPED_UNICODE);

        $client = new ScriptedPlanClient([$invalid, $invalid, $invalid, $invalid]);
        $result = $this->planner(['max_plan_repairs' => 2], $client)->plan('目标');

        $this->assertFalse($result->ok);
        $this->assertNull($result->plan, '规划失败不得交出半成品计划（一步都不许执行）');
        $this->assertSame(3, $result->attempts, '1 次初判 + 2 次修复');
        $this->assertSame(3, $client->calls);
        $this->assertSame('plan_invalid_after_repairs', $result->error);
        $this->assertNotNull($result->report);
        $this->assertContains('unknown_capability', $result->report->codes());
        $this->assertStringContainsString('未执行任何步骤', $result->summary() . json_encode($result->trace, JSON_UNESCAPED_UNICODE));
    }

    public function testUnparsableJsonTriggersJsonRepair(): void
    {
        $client = new ScriptedPlanClient(['抱歉，我无法输出 JSON。', $this->validPlanJson()]);
        $result = $this->planner(['max_plan_repairs' => 1], $client)->plan('目标');

        $this->assertTrue($result->ok, (string) $result->error);
        $this->assertSame(2, $result->attempts);
        $this->assertContains('plan.unparsable', array_column($result->trace, 'step'));

        $lastMessage = (string) $client->capturedMessages[1][count($client->capturedMessages[1]) - 1]['content'];
        $this->assertStringContainsString('不是合法 JSON', $lastMessage);
    }

    public function testClientFailureIsReportedHonestly(): void
    {
        $client = new ScriptedPlanClient([]);
        $client->throw = new \RuntimeException('provider 502');

        $result = $this->planner([], $client)->plan('目标');

        $this->assertFalse($result->ok);
        $this->assertNull($result->plan);
        $this->assertSame(1, $result->attempts);
        $this->assertStringContainsString('provider 502', (string) $result->error);
        $this->assertSame('plan.error', $result->trace[0]['step']);
    }

    public function testEmptyCatalogRejectsWithoutCallingLlm(): void
    {
        $client = new ScriptedPlanClient([$this->validPlanJson()]);
        $result = $this->planner([], $client, PlanCatalog::of([]))->plan('目标');

        $this->assertFalse($result->ok);
        $this->assertSame('plan_no_catalog', $result->error);
        $this->assertSame(0, $client->calls, '目录为空时不浪费一次 LLM 调用');
        $this->assertSame('plan.no_catalog', $result->trace[0]['step']);
    }


    public function testCallerLimitsOverridePlanDeclaredLimits(): void
    {
        $twoSteps = (string) json_encode([
            'goal'   => 'x',
            'limits' => ['max_steps' => 99],
            'steps'  => [
                ['id' => 's1', 'kind' => 'tool', 'target' => self::TOOL_READ, 'input' => ['target' => 'a']],
                ['id' => 's2', 'kind' => 'tool', 'target' => self::TOOL_READ, 'input' => ['target' => 'b']],
            ],
        ], JSON_UNESCAPED_UNICODE);

        $client = new ScriptedPlanClient([$twoSteps, $twoSteps]);
        $result = $this->planner(['max_plan_repairs' => 0], $client)
            ->plan('目标', [], ['max_steps' => 1]);

        $this->assertFalse($result->ok, '调用方上限 1 步 ⇒ 2 步计划必须被拒');
        $this->assertContains('too_many_steps', $result->report->codes());
    }

    public function testDeclaredLimitsAreNotedButNotTrusted(): void
    {
        $client = new ScriptedPlanClient([
            (string) json_encode([
                'goal'   => 'x',
                'limits' => ['max_steps' => 3],
                'steps'  => [['id' => 's1', 'kind' => 'tool', 'target' => self::TOOL_READ, 'input' => ['target' => 'a']]],
            ], JSON_UNESCAPED_UNICODE),
        ]);

        $result = $this->planner([], $client)->plan('目标');

        $this->assertTrue($result->ok);
        $this->assertContains('plan.declared_limits', array_column($result->notes, 'code'));
        $this->assertSame(3, $result->plan->limits['max_steps'], '未被调用方覆盖的声明值仍保留（只可能被收紧）');
    }

    public function testDefaultRetriesAppliesOnlyToStepsWithoutDeclaration(): void
    {
        $client = new ScriptedPlanClient([
            (string) json_encode([
                'goal'  => 'x',
                'steps' => [
                    ['id' => 's1', 'kind' => 'tool', 'target' => self::TOOL_READ, 'input' => ['target' => 'a']],
                    ['id' => 's2', 'kind' => 'tool', 'target' => self::TOOL_WRITE, 'input' => ['product_id' => 1, 'qty' => 1], 'retries' => 3, 'verify' => [['type' => 'tool_ok']]],
                ],
            ], JSON_UNESCAPED_UNICODE),
        ]);

        $result = $this->planner(['default_retries' => 2], $client)->plan('目标');

        $this->assertTrue($result->ok, (string) $result->error);
        $this->assertSame(2, $result->plan->steps[0]->retries, '未声明 ⇒ 用默认值');
        $this->assertSame(3, $result->plan->steps[1]->retries, '显式声明不被覆盖');
    }

    public function testJsonModeOptionIsOnlySentWhenSupported(): void
    {
        $supported = new ScriptedPlanClient([$this->validPlanJson()]);
        $this->planner([], $supported)->plan('目标');
        $this->assertSame(['type' => 'json_object'], $supported->capturedOptions[0]['response_format'] ?? null);
        $this->assertSame(0.2, $supported->capturedOptions[0]['temperature'], '规划温度默认 0.2');

        $unsupported = new ScriptedPlanClient([$this->validPlanJson()]);
        $unsupported->jsonMode = false;
        $this->planner([], $unsupported)->plan('目标');
        $this->assertArrayNotHasKey('response_format', $unsupported->capturedOptions[0]);
    }

    public function testUsageIsAccumulatedAcrossAttempts(): void
    {
        $invalid = '{"goal":"x","steps":[]}';
        $client  = new ScriptedPlanClient([$invalid, $this->validPlanJson()]);
        $client->usagePerCall = [
            ['prompt_tokens' => 100, 'completion_tokens' => 20, 'total_tokens' => 120],
            ['prompt_tokens' => 50, 'completion_tokens' => 10, 'total_tokens' => 60],
        ];

        $result = $this->planner(['max_plan_repairs' => 1], $client)->plan('目标');

        $this->assertTrue($result->ok);
        $this->assertSame(2, $result->attempts);
        $this->assertSame(180, $result->totalTokens());
        $this->assertSame(150, $result->usage['prompt_tokens']);
    }


    public function testManualModeUsesExplicitStepsAndSkipsLlm(): void
    {
        $client = new ScriptedPlanClient([]);
        $result = $this->planner(['planner' => 'manual'], $client)->plan('目标', [
            'steps' => [
                ['id' => 's1', 'kind' => 'tool', 'target' => self::TOOL_READ, 'input' => ['target' => 'a']],
            ],
        ]);

        $this->assertTrue($result->ok, (string) $result->error);
        $this->assertSame(0, $result->attempts);
        $this->assertSame(0, $client->calls, 'manual 模式不调用 LLM');
        $this->assertSame('manual', $result->plan->planner);
    }

    public function testManualModeWithoutStepsOrWithInvalidStepsFailsHonestly(): void
    {
        $client = new ScriptedPlanClient([]);
        $planner = $this->planner(['planner' => 'manual'], $client);

        $missing = $planner->plan('目标');
        $this->assertFalse($missing->ok);
        $this->assertSame('manual_steps_required', $missing->error);

        $invalid = $planner->plan('目标', ['steps' => [['id' => 's1', 'kind' => 'tool', 'target' => 'nope']]]);
        $this->assertFalse($invalid->ok);
        $this->assertNull($invalid->plan);
        $this->assertSame('manual_plan_invalid', $invalid->error);
        $this->assertContains('unknown_capability', $invalid->report->codes());
    }


    public function testCatalogPromptDegradesWhenBudgetIsTight(): void
    {
        $catalog = $this->catalog();

        $rich = $catalog->toPrompt(2000);
        $this->assertStringContainsString('参数:target/filters/limit', $rich);
        $this->assertStringContainsString('自然语言列表查询', $rich);
        $this->assertStringContainsString('【写】', $rich);

        $lean = $catalog->toPrompt(120);
        $this->assertStringNotContainsString('自然语言列表查询', $lean, '预算不足时先丢描述');
        $this->assertStringContainsString('tool:' . self::TOOL_READ, $lean, '能力名必须保住');
        $this->assertStringContainsString('skill:' . self::SKILL, $lean);
        $this->assertLessThanOrEqual(120, mb_strlen($lean, 'UTF-8'));

        $tight = $catalog->toPrompt(40);
        $this->assertStringNotContainsString('参数:', $tight);
        $this->assertLessThanOrEqual(40, mb_strlen($tight, 'UTF-8'));
        $this->assertStringContainsString('tool:', $tight);
    }

    public function testCatalogPromptHardTruncatesWhenNothingFits(): void
    {
        $entries = [];
        for ($i = 0; $i < 40; $i++) {
            $entries[] = new \wise\agent\plan\PlanCatalogEntry('tool', 'plugin.smartstock.tool_' . $i, 'desc ' . $i, ['a', 'b'], false);
        }

        $text = (new PlanCatalog($this->keyed($entries)))->toPrompt(80);

        $this->assertLessThanOrEqual(80, mb_strlen($text, 'UTF-8'));
        $this->assertStringContainsString('tool:', $text);
    }

    public function testCatalogBuilderAggregatesRegistriesAndHonoursPolicy(): void
    {
        $builder = new FixturePlanCatalogBuilder([], $this->lookup());

        $catalog = $builder->build();

        $this->assertTrue($catalog->has('tool', self::TOOL_READ));
        $this->assertTrue($catalog->has('action', 'menu.action.host.stock.save'), 'ActionTool ⇒ action');
        $this->assertTrue($catalog->has('skill', self::SKILL));
        $this->assertTrue($catalog->has('node', 'plugin:demo:notify'));
        $this->assertTrue($catalog->get('tool', self::TOOL_READ)->write === false);
        $this->assertTrue($catalog->get('action', 'menu.action.host.stock.save')->write);
        $this->assertSame(['target', 'filters', 'limit'], $catalog->get('tool', self::TOOL_READ)->args);

        $denied = (new FixturePlanCatalogBuilder(['deny_capabilities' => ['plugin.*'], 'allowed_kinds' => ['tool']], $this->lookup()))->build();
        $this->assertFalse($denied->has('tool', 'plugin.smartstock.bad_tool'));
        $this->assertSame(1, $denied->hiddenCount());
        $this->assertStringContainsString('deny_capabilities', $denied->toPrompt(4000));
    }

    public function testCatalogBuilderTruncatesByEntryLimit(): void
    {
        $builder = new FixturePlanCatalogBuilder(['max_catalog_entries' => 1], $this->lookup());
        $catalog = $builder->build();

        $this->assertSame(1, $catalog->size());
        $this->assertGreaterThan(0, $catalog->hiddenCount(), '被截断的条目要计入 hidden，而不是假装不存在');
    }

    public function testCatalogBuilderAllowedKindsRestrictsSources(): void
    {
        $builder = new FixturePlanCatalogBuilder(['allowed_kinds' => ['skill']], $this->lookup());
        $catalog = $builder->build();

        $this->assertSame(['skill'], array_values(array_unique(array_map(
            static fn(\wise\agent\plan\PlanCatalogEntry $e): string => $e->kind,
            $catalog->all()
        ))));
    }

    public function testCatalogToArrayExposesHiddenReason(): void
    {
        $catalog = PlanCatalog::of([
            new \wise\agent\plan\PlanCatalogEntry('tool', 'a', '', null, false),
        ], 2);

        $array = $catalog->toArray();

        $this->assertArrayHasKey('tool:a', $array);
        $this->assertSame(2, $array['_hidden']['count']);
        $this->assertSame('plan.deny_capabilities', $array['_hidden']['reason']);
    }

    /**
     * @param \wise\agent\plan\PlanCatalogEntry[] $entries
     * @return array<string, \wise\agent\plan\PlanCatalogEntry>
     */
    private function keyed(array $entries): array
    {
        $map = [];
        foreach ($entries as $entry) {
            $map[$entry->key()] = $entry;
        }

        return $map;
    }
}


/**
 * 脚本化 LLM 客户端：按顺序吐出预设的 `content`
 *
 * 与 `client/FakeClient` 同一思路（零网络），但 FakeClient 的脚本固定为
 * 「先 tool_calls 再答话」，无法覆盖「按次返回 JSON / 返回不可解析文本」这些用例。
 */
class ScriptedPlanClient implements AiClientInterface
{
    /** @var string[] */
    private array $script;

    /** @var array<int, array<int, array<string, mixed>>> 每次调用的 messages */
    public array $capturedMessages = [];

    /** @var array<int, array<string, mixed>> 每次调用的 options */
    public array $capturedOptions = [];

    /** @var array<int, array<string, int>> */
    public array $usagePerCall = [];

    public int $calls = 0;

    public ?\Throwable $throw = null;

    public bool $jsonMode = true;

    /**
     * @param string[] $script
     */
    public function __construct(array $script)
    {
        $this->script = array_values($script);
    }

    public function chat(array $messages, array $tools = [], array $options = []): array
    {
        $this->calls++;
        $this->capturedMessages[] = $messages;
        $this->capturedOptions[]  = $options;

        if ($this->throw !== null) {
            throw $this->throw;
        }

        $content = array_shift($this->script) ?? '';

        return [
            'content'    => $content,
            'tool_calls' => [],
            'usage'      => $this->usagePerCall[$this->calls - 1]
                ?? ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
        ];
    }

    public function chatStream(array $messages, array $tools = [], array $options = []): \Generator
    {
        yield from [];
    }

    public function getProviderName(): string
    {
        return 'scripted';
    }

    public function getModelName(): string
    {
        return 'scripted-1';
    }

    public function supports(string $feature): bool
    {
        return $feature === Feature::JSON_MODE && $this->jsonMode;
    }

    public function listModels(): array
    {
        return ['scripted-1'];
    }

    public function countTokens(string|array $input): int
    {
        return 0;
    }

    public function estimateCost(int $promptTokens, int $completionTokens): float
    {
        return 0.0;
    }
}

/**
 * 可注入目录的规划器
 */
class TestablePlanPlanner extends PlanPlanner
{
    public function __construct(
        array $options = [],
        ?AiClientInterface $client = null,
        ?PlanValidator $validator = null,
        private ?PlanCatalog $injectedCatalog = null
    ) {
        parent::__construct($options, $client, $validator);
    }

    protected function catalog(): PlanCatalog
    {
        return $this->injectedCatalog ?? parent::catalog();
    }
}

/**
 * 能力查询替身（与本文件目录保持一致）
 */
class PlannerLookupStub implements PlanCapabilityLookupInterface
{
    /**
     * @var array<string, array{args:string[]|null, write:bool}>
     */
    private array $map = [
        'tool:lq_query'                        => ['args' => ['target', 'filters', 'limit'], 'write' => false],
        'tool:menu.action.host.stock.save'     => ['args' => ['product_id', 'qty'], 'write' => true],
        'action:menu.action.host.stock.save'   => ['args' => ['product_id', 'qty'], 'write' => true],
        'skill:system.orchestrate'             => ['args' => ['goal'], 'write' => false],
        'node:plugin:demo:notify'              => ['args' => ['channel', 'message'], 'write' => false],
    ];

    public function exists(string $kind, string $target): bool
    {
        return array_key_exists($kind . ':' . $target, $this->map);
    }

    public function argumentNames(string $kind, string $target): ?array
    {
        return $this->map[$kind . ':' . $target]['args'] ?? null;
    }

    public function isWrite(string $kind, string $target): bool
    {
        return (bool) ($this->map[$kind . ':' . $target]['write'] ?? false);
    }
}

/**
 * 用夹具代替真实注册表的目录构建器（离线）
 */
class FixturePlanCatalogBuilder extends PlanCatalogBuilder
{
    protected function toolNames(): array
    {
        return [
            'lq_query',
            'menu.action.host.stock.save',
            'plugin.smartstock.bad_tool',
        ];
    }

    protected function kindOfTool(string $name): string
    {
        return $name === 'menu.action.host.stock.save' ? PlanStep::KIND_ACTION : PlanStep::KIND_TOOL;
    }

    protected function toolDescription(string $name): string
    {
        return $name === 'lq_query' ? '自然语言列表查询' : '工具 ' . $name;
    }

    protected function skillOverview(): array
    {
        return [
            ['name' => 'system.orchestrate', 'description' => '动态编排', 'source' => 'builtin', 'output_format' => 'text'],
        ];
    }

    /**
     * @return \wise\agent\dto\NodeDefinition[]
     */
    protected function nodeDefinitions(): array
    {
        return [
            new \wise\agent\dto\NodeDefinition('notify', 'plugin:demo', '通知', '发送通知', '', ['properties' => ['channel' => [], 'message' => []]]),
        ];
    }
}
