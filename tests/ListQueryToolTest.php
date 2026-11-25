<?php
declare(strict_types=1);

namespace wise\agent\test;

use PHPUnit\Framework\TestCase;
use wise\agent\query\ListQueryFieldsTool;
use wise\agent\query\ListQueryTarget;
use wise\agent\query\ListQueryTargetResolver;
use wise\agent\query\ListQueryTool;
use wise\agent\query\ListQueryToolProvider;
use wise\agent\query\QueryFieldCatalog;
use wise\agent\query\QueryValueResolver;
use wise\agent\query\executor\ListQueryExecutorInterface;
use wise\agent\security\CapabilityAccess;
use wise\agent\tool\ToolRegistry;

/**
 * A3-3 回归：只读工具 + 参数上限 + Prompt 预算（附录三 验收 8、9、10）
 *
 * 全部离线：目标解析器、字段目录、取值接地器、执行器都是注入的测试替身。
 */
class ListQueryToolTest extends TestCase
{
    private const TARGET = 'host.demo';

    protected function setUp(): void
    {
        ListQueryTargetResolver::resetCache();
    }


    /**
     * @return array<string, array<string, mixed>>
     */
    private function sourceFields(): array
    {
        return [
            'id'          => ['label' => 'ID', 'type' => 'integer', 'sortable' => true, 'filterable' => true, 'source' => 'db', 'raw_type' => 'int(11)'],
            'order_no'    => ['label' => '订单号', 'type' => 'string', 'sortable' => true, 'filterable' => true, 'source' => 'db', 'raw_type' => 'varchar(32)'],
            'amount'      => ['label' => '金额', 'type' => 'number', 'sortable' => true, 'filterable' => true, 'source' => 'db', 'raw_type' => 'decimal(10,2)'],
            'status'      => [
                'label' => '状态', 'type' => 'enum',
                'enum' => ['pending', 'refunded'], 'enum_labels' => ['待付款', '已退款'],
                'sortable' => true, 'filterable' => true, 'source' => 'form', 'raw_type' => 'select',
            ],
            'region_id'   => ['label' => '区域', 'type' => 'integer', 'sortable' => true, 'filterable' => true, 'source' => 'db', 'raw_type' => 'int(11)'],
            'create_time' => ['label' => '创建时间', 'type' => 'datetime', 'sortable' => true, 'filterable' => true, 'source' => 'db', 'raw_type' => 'datetime'],
        ];
    }

    private function catalog(): QueryFieldCatalog
    {
        return (new QueryFieldCatalog())->build(
            self::TARGET,
            $this->sourceFields(),
            [],
            [],
            [],
            [],
            [],
            'demo_orders'
        );
    }

    /**
     * @return array{0: TestableListQueryTool, 1: RecordingListQueryExecutor, 2: FakeListQueryTargetResolver}
     */
    private function makeTool(array $options = [], ?QueryValueResolver $resolver = null): array
    {
        $target   = new ListQueryTarget(self::TARGET, 'app\\admin\\controller\\Demo', ListQueryTarget::MODE_BUILDER, 'search', '/admin/demo/index', '演示列表');
        $resolver2 = new FakeListQueryTargetResolver([self::TARGET => $target]);
        $executor = new RecordingListQueryExecutor();

        $tool = new TestableListQueryTool($options, $resolver2, $executor);
        $tool->catalogs[self::TARGET] = $this->catalog();
        if ($resolver !== null) {
            $tool->resolver = $resolver;
        }

        return [$tool, $executor, $resolver2];
    }

    private function decode(string $json): array
    {
        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded, '响应必须是 JSON');

        return $decoded;
    }


    public function testToolIsReadOnly(): void
    {
        [$tool] = $this->makeTool();

        $this->assertSame(ListQueryTool::NAME, $tool->getName());
        $this->assertSame(CapabilityAccess::READ, $tool->getAccess());
        $this->assertSame(CapabilityAccess::RISK_LOW, $tool->getRiskLevel());
        $this->assertFalse($tool->requireConfirmation(), '只读工具不需要人工确认');
        $this->assertSame('', $tool->getPermission(), 'deny-only：默认不产生人工没有的权限面');
    }

    public function testStrictModeExposesPermissionOnlyWhenAsked(): void
    {
        [$tool] = $this->makeTool(['require_ai_permission' => true]);

        $this->assertSame('list_query.query', $tool->getPermission());
    }

    public function testParameterSchemaHasNoWriteSurface(): void
    {
        [$tool] = $this->makeTool();
        $schema = $tool->getParameters();

        $this->assertSame(['target'], $schema['required']);
        $this->assertSame(
            ['target', 'filters', 'logic', 'orders', 'fields', 'page', 'limit'],
            array_keys($schema['properties']),
            '参数面上不存在任何写入口'
        );

        $operators = $schema['properties']['filters']['items']['properties']['op']['enum'];
        $this->assertNotContains('exists', $operators);
        $this->assertNotContains('delete', $operators);

        $this->assertContains('上月', $schema['properties']['filters']['items']['properties']['time_expr']['enum']);
    }

    public function testExecutorAlwaysReceivesGetListTarget(): void
    {
        [$tool, $executor] = $this->makeTool();

        $tool->execute(['target' => self::TARGET]);
        $tool->execute(['target' => self::TARGET, 'limit' => 5]);

        $this->assertCount(2, $executor->calls);
        foreach ($executor->calls as $call) {
            $target = $call['target'];
            $this->assertSame('getList', $target->getListAction, '执行面只有 getList');
            $this->assertTrue($target->usesBuilderMode());
        }
    }


    public function testSuccessfulQueryReturnsParamsAndPlan(): void
    {
        [$tool, $executor] = $this->makeTool();

        $result = $this->decode($tool->execute([
            'target'  => self::TARGET,
            'filters' => [
                ['field' => 'status', 'op' => 'eq', 'value' => '已退款'],
                ['field' => 'create_time', 'op' => 'between', 'time_expr' => '上月'],
            ],
            'orders'  => [['field' => 'amount', 'direction' => 'desc']],
            'limit'   => 20,
        ]));

        $this->assertSame(0, $result['code']);
        $this->assertSame(self::TARGET, $result['target']);
        $this->assertSame(ListQueryTarget::MODE_BUILDER, $result['mode']);
        $this->assertSame('refunded', $result['params']['status']);
        $this->assertArrayHasKey('create_time__between', $result['params']);
        $this->assertSame('amount desc', $result['params']['order']);
        $this->assertSame('enum', $result['plan']['filters'][0]['grounded']);
        $this->assertSame('time', $result['plan']['filters'][1]['grounded']);

        $this->assertSame($executor->calls[0]['params'], $result['params']);
    }

    public function testUnknownTargetReturnsCodeOne(): void
    {
        [$tool] = $this->makeTool();

        $result = $this->decode($tool->execute(['target' => 'not.a.target']));

        $this->assertSame(1, $result['code']);
        $this->assertSame([self::TARGET], $result['available_targets']);
    }

    public function testMissingTargetReturnsCodeOne(): void
    {
        [$tool] = $this->makeTool();

        $result = $this->decode($tool->execute([]));

        $this->assertSame(1, $result['code']);
        $this->assertStringContainsString('缺少 target', $result['msg']);
    }

    public function testUnknownFieldReturnsCodeOne(): void
    {
        [$tool, $executor] = $this->makeTool();

        $result = $this->decode($tool->execute([
            'target'  => self::TARGET,
            'filters' => [['field' => 'not_a_field', 'op' => 'eq', 'value' => 1]],
        ]));

        $this->assertSame(1, $result['code']);
        $this->assertStringContainsString('未知字段', $result['msg']);
        $this->assertSame([], $executor->calls, '编译失败时绝不执行');
    }

    public function testAmbiguityReturnsCodeTwoWithCandidates(): void
    {
        $resolver = new FakeListQueryValueResolver();
        $resolver->candidates = [
            ['value' => 3, 'label' => '华东区'],
            ['value' => 7, 'label' => '华东南区'],
        ];

        [$tool, $executor] = $this->makeTool([], $resolver);

        $tool->catalogs[self::TARGET] = (new QueryFieldCatalog())->build(self::TARGET, array_merge($this->sourceFields(), [
            'region_id' => ['label' => '区域', 'type' => 'integer', 'sortable' => true, 'filterable' => true, 'source' => 'db', 'raw_type' => 'int(11)'],
        ]), [
            \wise\agent\schema\dto\RelationInfo::fromArray([
                'name' => 'region', 'type' => 'belongsTo', 'target_table' => 'regions',
                'foreign_key' => 'region_id', 'local_key' => 'id',
            ]),
        ]);

        $result = $this->decode($tool->execute([
            'target'  => self::TARGET,
            'filters' => [['field' => 'region_id', 'op' => 'eq', 'value' => '华东']],
        ]));

        $this->assertSame(2, $result['code'], 'code:2 = 需要澄清');
        $this->assertSame('ambiguous', $result['msg'], '歧义场景的 msg 固定为 ambiguous');
        $this->assertCount(2, $result['data']['candidates']);
        $this->assertSame('region_id', $result['data']['field']);
        $this->assertStringContainsString('不要猜测', $result['hint']);
        $this->assertStringContainsString('region_id', $result['detail'], '人类可读细节放 detail');
        $this->assertSame([], $executor->calls, '歧义时绝不执行查询');
    }


    public function testLimitIsClampedInExecution(): void
    {
        [$tool, $executor] = $this->makeTool(['max_limit' => 100]);

        $result = $this->decode($tool->execute(['target' => self::TARGET, 'limit' => 100000]));

        $this->assertSame(0, $result['code']);
        $this->assertSame(100, $executor->calls[0]['params']['limit']);
        $this->assertSame(100000, $result['plan']['limit']['requested']);
        $this->assertSame(100, $result['plan']['limit']['applied']);
    }

    public function testTooManyFiltersReturnsCodeOne(): void
    {
        [$tool, $executor] = $this->makeTool(['max_filters' => 3]);

        $filters = [];
        for ($i = 0; $i < 10; $i++) {
            $filters[] = ['field' => 'id', 'op' => 'ne', 'value' => $i];
        }

        $result = $this->decode($tool->execute(['target' => self::TARGET, 'filters' => $filters]));

        $this->assertSame(1, $result['code']);
        $this->assertStringContainsString('超过上限', $result['msg']);
        $this->assertSame([], $executor->calls);
    }

    public function testBetweenAcceptsCommaString(): void
    {
        [$tool, $executor] = $this->makeTool();

        $result = $this->decode($tool->execute([
            'target'  => self::TARGET,
            'filters' => [['field' => 'amount', 'op' => 'between', 'value' => '100,200']],
        ]));

        $this->assertSame(0, $result['code']);
        $this->assertSame('100,200', $executor->calls[0]['params']['amount__between']);
    }


    public function testFieldsToolReturnsFullCatalog(): void
    {
        [, , $resolver] = $this->makeTool();

        $catalog = $this->catalog();
        $tool    = new TestableListQueryFieldsTool([], new TestableListQueryTool([], $resolver, new RecordingListQueryExecutor()), $resolver);
        $tool->catalogs[self::TARGET] = $catalog;

        $result = $this->decode($tool->execute(['target' => self::TARGET]));

        $this->assertSame(0, $result['code'], (string) ($result['msg'] ?? ''));
        $this->assertSame(ListQueryFieldsTool::NAME, $tool->getName());
        $this->assertSame(CapabilityAccess::READ, $tool->getAccess());
        $this->assertFalse($tool->requireConfirmation());

        $fields = $result['data']['fields'];
        $this->assertCount(count($catalog->all()), $fields);
        $this->assertSame('refunded', $fields['status']['enum'][1]['value']);
        $this->assertSame('已退款', $fields['status']['enum'][1]['label']);
        $this->assertContains('between', $fields['create_time']['operators']);
        $this->assertContains('amount', $result['data']['sortable']);
    }

    public function testFieldsToolUnknownTargetReturnsCodeOne(): void
    {
        [, , $resolver] = $this->makeTool();
        $tool = new TestableListQueryFieldsTool([], null, $resolver);

        $result = $this->decode($tool->execute(['target' => 'nope']));

        $this->assertSame(1, $result['code']);
        $this->assertSame([self::TARGET], $result['available_targets']);
    }


    public function testPromptIncludesDimensionsWhenBudgetAllows(): void
    {
        [, , $resolver] = $this->makeTool();

        $provider = new TestableListQueryProvider(['max_prompt_chars' => 4000], $resolver, null);
        $provider->catalogs[self::TARGET] = $this->catalog();

        $prompt = $provider->toPrompt(4000, [self::TARGET => new ListQueryTarget(self::TARGET, 'app\\admin\\controller\\Demo', ListQueryTarget::MODE_BUILDER, 'search', '/admin/demo/index', '演示列表')]);

        $this->assertStringContainsString('可查询列表', $prompt);
        $this->assertStringContainsString(self::TARGET, $prompt);
        $this->assertStringContainsString('演示列表', $prompt);
        $this->assertStringContainsString('lq_fields', $prompt, '提示模型按需拉取完整目录');
        $this->assertStringContainsString('待付款(pending)', $prompt, '枚举候选精简注入');
    }

    public function testPromptDegradesToTargetNamesWhenOverBudget(): void
    {
        [, , $resolver] = $this->makeTool();
        $provider = new TestableListQueryProvider(['max_prompt_chars' => 200], $resolver, null);
        $provider->catalogs[self::TARGET] = $this->catalog();
        $targets  = [self::TARGET => new ListQueryTarget(self::TARGET, 'app\\admin\\controller\\Demo', ListQueryTarget::MODE_BUILDER, 'search', '', '演示列表')];

        $prompt = $provider->toPrompt(200, $targets);

        $this->assertStringContainsString(self::TARGET, $prompt, '超预算时仍保留目标名');
        $this->assertStringNotContainsString('可排序:', $prompt, '维度被裁掉');
        $this->assertLessThanOrEqual(200, mb_strlen($prompt, 'UTF-8'));
    }

    public function testPromptIsHardTruncatedAndEmptyWithoutTargets(): void
    {
        [, , $resolver] = $this->makeTool();
        $provider = new TestableListQueryProvider([], $resolver, null);

        $this->assertSame('', $provider->toPrompt(4000, []));

        $targets = [self::TARGET => new ListQueryTarget(self::TARGET, 'app\\admin\\controller\\Demo')];
        $prompt  = $provider->toPrompt(80, $targets);

        $this->assertLessThanOrEqual(80, mb_strlen($prompt, 'UTF-8'));
    }

    public function testPromptRespectsMaxTargets(): void
    {
        $targets = [];
        for ($i = 1; $i <= 5; $i++) {
            $targets['host.t' . $i] = new ListQueryTarget('host.t' . $i, 'app\\admin\\controller\\T' . $i, ListQueryTarget::MODE_BUILDER, 'search', '', '目标' . $i);
        }

        $provider = new TestableListQueryProvider(['max_targets' => 2, 'max_prompt_chars' => 4000], new FakeListQueryTargetResolver($targets), null);
        $prompt   = $provider->toPrompt(4000, $targets);

        $this->assertStringContainsString('host.t1', $prompt);
        $this->assertStringContainsString('host.t2', $prompt);
        $this->assertStringNotContainsString('host.t4', $prompt);
        $this->assertStringContainsString('其余 3 个目标', $prompt);
    }


    public function testProviderRegistersBothToolsOnlyWhenEnabled(): void
    {
        [, , $resolver] = $this->makeTool();

        $disabled = new ListQueryToolProvider([], $resolver, null);
        $this->assertFalse($disabled->isEnabled());
        $this->assertSame([], $disabled->getTools());

        $enabled = new ListQueryToolProvider(['enabled' => true], $resolver, null);
        $tools   = $enabled->getTools();

        $this->assertCount(2, $tools);
        $this->assertSame([ListQueryTool::NAME, ListQueryFieldsTool::NAME], array_map(static fn($t): string => $t->getName(), $tools));

        $registry = ToolRegistry::instance();
        $this->assertSame(2, $enabled->registerTo($registry), '用 builtin 键注册，保证 LLM 回调名一致');
        $this->assertSame(ListQueryTool::NAME, $registry->get(ListQueryTool::NAME)->getName());
    }

    public function testContextProviderSkipsWhenDisabledOrEmpty(): void
    {
        [, , $resolver] = $this->makeTool();

        $context = $this->createMock(\wise\agent\agent\AgentContext::class);

        $disabled = new \wise\agent\query\ListQueryContextProvider(new ListQueryToolProvider([], $resolver, null), 28);
        $this->assertSame([], $disabled->getContext($context), '未启用时不注入');

        $enabled = new \wise\agent\query\ListQueryContextProvider(
            new ListQueryToolProvider(['enabled' => true], $resolver, null),
            28
        );
        $messages = $enabled->getContext($context);

        $this->assertCount(1, $messages);
        $this->assertSame('system', $messages[0]['role']);
        $this->assertStringContainsString('可查询列表', $messages[0]['content']);
        $this->assertSame(28, $enabled->getPriority());
        $this->assertSame(\wise\agent\query\ListQueryContextProvider::SOURCE, $enabled->getSource());
    }
}


class FakeListQueryTargetResolver extends ListQueryTargetResolver
{
    /** @param array<string, ListQueryTarget> $targets */
    public function __construct(private array $targets)
    {
        parent::__construct(null, []);
    }

    public function resolve(bool $force = false): array
    {
        return $this->targets;
    }
}

class RecordingListQueryExecutor implements ListQueryExecutorInterface
{
    /** @var array<int, array{target:ListQueryTarget, params:array<string, mixed>}> */
    public array $calls = [];

    public function execute(ListQueryTarget $target, array $params): array
    {
        $this->calls[] = ['target' => $target, 'params' => $params];

        return [
            'code'       => 0,
            'msg'        => 'ok',
            'structured' => true,
            'data'       => ['total' => 0, 'list' => []],
            'text'       => '',
            'truncated'  => false,
        ];
    }
}

class FakeListQueryValueResolver extends QueryValueResolver
{
    /** @var array<int, array{value:mixed, label:string}> */
    public array $candidates = [];

    protected function relationCandidates(string $table, string $labelColumn, string $keyword, int $limit): array
    {
        return $this->candidates;
    }

    protected function cacheGet(string $key): mixed
    {
        return null;
    }

    protected function cacheSet(string $key, mixed $value): void
    {
    }
}

class TestableListQueryTool extends ListQueryTool
{
    /** @var array<string, QueryFieldCatalog> */
    public array $catalogs = [];

    public ?QueryValueResolver $resolver = null;

    public function catalog(ListQueryTarget $target): QueryFieldCatalog
    {
        return $this->catalogs[$target->name] ?? parent::catalog($target);
    }

    protected function valueResolver(): QueryValueResolver
    {
        return $this->resolver ?? parent::valueResolver();
    }
}

class TestableListQueryFieldsTool extends ListQueryFieldsTool
{
    /** @var array<string, QueryFieldCatalog> */
    public array $catalogs = [];

    public function catalog(ListQueryTarget $target): QueryFieldCatalog
    {
        return $this->catalogs[$target->name] ?? parent::catalog($target);
    }
}

/**
 * 注入字段目录的提供者（Prompt 预算与「精简维度」行为的离线验证入口）
 */
class TestableListQueryProvider extends ListQueryToolProvider
{
    /** @var array<string, QueryFieldCatalog> */
    public array $catalogs = [];

    protected function catalogFor(ListQueryTarget $target): QueryFieldCatalog
    {
        return $this->catalogs[$target->name] ?? parent::catalogFor($target);
    }
}


