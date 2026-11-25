<?php
declare(strict_types=1);

namespace wise\agent\test;

use PHPUnit\Framework\TestCase;
use wise\agent\query\QueryField;
use wise\agent\query\QueryFieldCatalog;
use wise\agent\schema\dto\RelationInfo;

/**
 * A3-1 字段目录回归（附录三 验收 4 及目录能力）
 *
 * `QueryFieldCatalog::build()` 是纯函数（输入全部显式传入），因此本测试完全离线，
 * 不依赖容器与数据库。
 */
class QueryFieldCatalogTest extends TestCase
{
    /**
     * 模拟 A1-1 `MenuActionSchemaResolver::fields()` 的输出
     *
     * @return array<string, array<string, mixed>>
     */
    private function sourceFields(): array
    {
        return [
            'id'          => ['label' => 'ID', 'type' => 'integer', 'sortable' => true, 'filterable' => true, 'source' => 'db', 'raw_type' => 'int(11)'],
            'order_no'    => ['label' => '订单号', 'type' => 'string', 'sortable' => true, 'filterable' => true, 'source' => 'db', 'raw_type' => 'varchar(32)'],
            'total_amount' => ['label' => '金额', 'type' => 'number', 'sortable' => true, 'filterable' => true, 'source' => 'db', 'raw_type' => 'decimal(10,2)'],
            'qty'         => ['label' => '数量', 'type' => 'integer', 'sortable' => true, 'filterable' => true, 'source' => 'db', 'raw_type' => 'int(11)'],
            'status'      => [
                'label' => '状态', 'type' => 'enum',
                'enum' => ['pending', 'refunded'], 'enum_labels' => ['待付款', '已退款'],
                'sortable' => true, 'filterable' => true, 'source' => 'form', 'raw_type' => 'select',
            ],
            'is_top'      => ['label' => '置顶', 'type' => 'boolean', 'sortable' => true, 'filterable' => true, 'source' => 'db', 'raw_type' => 'tinyint(1)'],
            'remark'      => ['label' => '备注', 'type' => 'text', 'sortable' => false, 'filterable' => true, 'source' => 'db', 'raw_type' => 'text'],
            'create_time' => ['label' => '创建时间', 'type' => 'datetime', 'sortable' => true, 'filterable' => true, 'source' => 'db', 'raw_type' => 'datetime'],
            'region_id'   => ['label' => '区域', 'type' => 'integer', 'sortable' => true, 'filterable' => true, 'source' => 'db', 'raw_type' => 'int(11)'],
        ];
    }

    private function catalog(array $options = []): QueryFieldCatalog
    {
        return new QueryFieldCatalog($options);
    }

    private function built(
        array $relations = [],
        array $searchable = [],
        array $modelSearchKeys = [],
        array $aliases = [],
        array $synonyms = [],
        array $options = []
    ): QueryFieldCatalog {
        return $this->catalog($options)->build(
            'host.demo',
            $this->sourceFields(),
            $relations,
            $searchable,
            $modelSearchKeys,
            $aliases,
            $synonyms,
            'demo_orders'
        );
    }


    public function testBaselineTypesAndCapabilities(): void
    {
        $catalog = $this->built();

        $this->assertSame('demo_orders', $catalog->tableName());
        $this->assertSame(QueryField::TYPE_INT, $catalog->get('qty')->type);
        $this->assertSame(QueryField::TYPE_DECIMAL, $catalog->get('total_amount')->type);
        $this->assertSame(QueryField::TYPE_BOOL, $catalog->get('is_top')->type);
        $this->assertSame(QueryField::TYPE_DATETIME, $catalog->get('create_time')->type);
        $this->assertSame(QueryField::TYPE_ENUM, $catalog->get('status')->type);
        $this->assertSame('datetime', $catalog->get('create_time')->rawType);

        $this->assertFalse($catalog->get('remark')->sortable);
        $this->assertNotContains('remark', $catalog->sortableKeys());
        $this->assertContains('total_amount', $catalog->sortableKeys());

        $this->assertTrue($catalog->get('create_time')->isTime());
        $this->assertFalse($catalog->get('qty')->isTime());
    }


    public function testEnumCandidatesAndGrounding(): void
    {
        $catalog = $this->built();
        $field   = $catalog->get('status');

        $this->assertSame([
            ['value' => 'pending', 'label' => '待付款'],
            ['value' => 'refunded', 'label' => '已退款'],
        ], $field->enum);
        $this->assertSame($field->enum, $catalog->enumOf('status'));

        $this->assertSame(['value' => 'refunded', 'label' => '已退款'], $field->resolveEnum('已退款'));
        $this->assertSame(['value' => 'pending', 'label' => '待付款'], $field->resolveEnum('待付款'));
        $this->assertSame(['value' => 'refunded', 'label' => '已退款'], $field->resolveEnum('refunded'));
        $this->assertNull($field->resolveEnum('不存在的状态'));
    }

    public function testNumericEnumCommentIsCastToInt(): void
    {
        $catalog = $this->catalog()->build('host.demo', [
            'status' => [
                'label' => '状态', 'type' => 'integer',
                'enum' => ['1', '0'], 'enum_labels' => ['启用', '禁用'],
                'sortable' => true, 'filterable' => true, 'source' => 'db', 'raw_type' => 'tinyint(4)',
            ],
        ]);

        $field = $catalog->get('status');

        $this->assertSame(QueryField::TYPE_ENUM, $field->type, '有枚举候选即视为 enum');
        $this->assertSame([
            ['value' => 1, 'label' => '启用'],
            ['value' => 0, 'label' => '禁用'],
        ], $field->enum, '数字串收敛为 int（与 parseValue 的类型推断一致）');
        $this->assertSame(1, $field->resolveEnum('启用')['value']);
        $this->assertStringContainsString('启用(1)', $field->describe());
    }

    public function testEnumLabelEqualsValueIsNotDuplicated(): void
    {
        $catalog = $this->catalog()->build('host.demo', [
            'level' => [
                'label' => '等级', 'type' => 'enum',
                'enum' => ['gold', 'silver'], 'enum_labels' => ['gold', 'silver'],
                'filterable' => true, 'source' => 'db',
            ],
        ]);

        $this->assertSame('', $catalog->get('level')->enum[0]['label']);
        $this->assertSame(['gold', 'silver'], $catalog->get('level')->enumValues(), '不出现 gold(gold)');
    }


    public function testBelongsToBecomesFilterableRelationField(): void
    {
        $relation = RelationInfo::fromArray([
            'name' => 'region', 'type' => 'belongsTo', 'target_table' => 'regions',
            'target_class' => 'app\\admin\\model\\Region', 'foreign_key' => 'region_id', 'local_key' => 'id',
        ]);

        $catalog = $this->built([$relation]);
        $field   = $catalog->get('region_id');

        $this->assertSame(QueryField::TYPE_RELATION, $field->type);
        $this->assertSame('region_id', $field->column);
        $this->assertTrue($field->filterable);
        $this->assertTrue($field->sortable, '外键列可排序');
        $this->assertSame('regions', $field->relation['table']);
        $this->assertSame('name', $field->relation['label_column']);
        $this->assertContains('region', $field->aliases, '关系名作为别名（「区域」→ region_id）');
    }

    public function testHasManyIsMetadataOnlyNotAFilterField(): void
    {
        $hasMany = RelationInfo::fromArray([
            'name' => 'items', 'type' => 'hasMany', 'target_table' => 'order_items',
            'foreign_key' => 'order_id', 'local_key' => 'id',
        ]);

        $catalog = $this->built([$hasMany]);

        $this->assertFalse($catalog->has('items'));
        $this->assertArrayHasKey('items', $catalog->relations());
        $trace = implode('|', array_map(static fn(array $s): string => $s['step'], $catalog->trace()));
        $this->assertStringContainsString('catalog.relations', $trace);
    }


    public function testSearchableKeysPreferAttributeMark(): void
    {
        $marked = $this->built([], ['order_no', 'qty']);

        $this->assertSame(['order_no', 'qty'], $marked->searchableKeys());
        $this->assertTrue($marked->get('qty')->searchable, 'AiSearchable 可标记非 string 字段');

        $fallback = $this->built();
        $this->assertContains('order_no', $fallback->searchableKeys());
        $this->assertContains('remark', $fallback->searchableKeys());
        $this->assertNotContains('qty', $fallback->searchableKeys());
    }

    public function testModelSearchHintsMarkSource(): void
    {
        $catalog = $this->built([], [], ['qty', 'status', 'not_a_field']);

        $this->assertSame('model_search', $catalog->get('qty')->source);
        $this->assertTrue($catalog->get('qty')->filterable);
        $this->assertArrayNotHasKey('not_a_field', $catalog->all(), '未声明的键不会被凭空创建');
    }


    public function testKeyAliasesMapPublicNameToRealColumn(): void
    {
        $catalog = $this->built([], [], [], ['amount' => 'total_amount']);

        $this->assertTrue($catalog->has('amount'));
        $this->assertFalse($catalog->has('total_amount'), '原键让位给对外键名');

        $field = $catalog->get('amount');
        $this->assertSame('total_amount', $field->column);
        $this->assertSame('金额', $field->label, '叠加时保留标签');
        $this->assertSame(QueryField::TYPE_DECIMAL, $field->type);
        $this->assertContains('total_amount', $field->aliases, '原键成为别名');
    }

    public function testKeyAliasMissIsTracedNotSilent(): void
    {
        $catalog = $this->built([], [], [], ['foo' => 'bar']);

        $this->assertFalse($catalog->has('foo'));
        $trace = implode('|', array_map(static fn(array $s): string => $s['step'] . ':' . $s['message'], $catalog->trace()));
        $this->assertStringContainsString('alias.miss', $trace);
    }

    public function testSynonymsResolveToField(): void
    {
        $catalog = $this->built([], [], [], [], [
            'total_amount' => ['金额', '总额', 'money'],
            'status'       => ['状态'],
        ]);

        $this->assertSame('total_amount', $catalog->resolveAlias('金额')->key);
        $this->assertSame('total_amount', $catalog->resolveAlias('总额')->key);
        $this->assertSame('total_amount', $catalog->resolveAlias('money')->key);
        $this->assertSame('status', $catalog->resolveAlias('状态')->key);
        $this->assertNull($catalog->resolveAlias('不存在'));

        $this->assertSame('total_amount', $catalog->find('金额')[0]->key);
    }

    public function testSynonymsCombineWithKeyAliases(): void
    {
        $catalog = $this->built([], [], [], ['amount' => 'total_amount'], [
            'amount' => ['金额', '总额'],
        ]);

        $this->assertSame('amount', $catalog->resolveAlias('金额')->key);
        $this->assertSame('total_amount', $catalog->get('amount')->column);
        $this->assertContains('金额', $catalog->get('amount')->aliases);
        $this->assertContains('total_amount', $catalog->get('amount')->aliases, '原键仍可命中');
    }

    public function testFindReturnsAllCandidatesWithoutGuessing(): void
    {
        $catalog = $this->built([], [], [], [], ['region_id' => ['区域', '华东区']]);

        $hits = $catalog->find('区域');
        $this->assertCount(1, $hits);
        $this->assertSame('region_id', $hits[0]->key);

        $this->assertSame([], $catalog->find('没有这个维度'));
    }


    public function testDescribeCarriesEnumAndSortHint(): void
    {
        $catalog = $this->built();
        $text    = $catalog->get('status')->describe();

        $this->assertStringContainsString('status', $text);
        $this->assertStringContainsString('（状态）', $text);
        $this->assertStringContainsString('enum', $text);
        $this->assertStringContainsString('待付款(pending)', $text);
        $this->assertStringContainsString('可排序', $text);
    }

    public function testDescribeLimitsEnumValues(): void
    {
        $many = [];
        for ($i = 1; $i <= 30; $i++) {
            $many[] = 'v' . $i;
        }

        $catalog = $this->catalog(['max_enum_values' => 5])->build('host.demo', [
            'level' => ['label' => '等级', 'type' => 'enum', 'enum' => $many, 'enum_labels' => $many, 'filterable' => true],
        ]);

        $text = $catalog->get('level')->describe(5);

        $this->assertStringContainsString('30 个', $text);
        $this->assertStringNotContainsString('v30', $text);
    }

    public function testPromptIncludesDimensionsAndSortables(): void
    {
        $catalog = $this->built();
        $text    = $catalog->toPrompt();

        $this->assertStringContainsString('host.demo', $text);
        $this->assertStringContainsString('demo_orders', $text);
        $this->assertStringContainsString('total_amount（金额）', $text);
        $this->assertStringContainsString('可排序:', $text);
    }

    public function testPromptTruncatesFields(): void
    {
        $catalog = $this->built([], [], [], [], [], ['max_filter_fields' => 3]);
        $text    = $catalog->toPrompt(4000, 3);

        $this->assertStringContainsString('其余', $text);
        $this->assertStringContainsString('lq_fields', $text);

        $lines = array_filter(explode(PHP_EOL, $text), static fn(string $l): bool => str_starts_with(trim($l), 'id ')
            || str_starts_with(trim($l), 'order_no ')
            || str_starts_with(trim($l), 'total_amount '));
        $this->assertLessThanOrEqual(4, count($lines));
    }

    public function testPromptHardTruncationKeepsTargetNameOnly(): void
    {
        $catalog = $this->built();
        $text    = $catalog->toPrompt(60);

        $this->assertStringContainsString('host.demo', $text);
        $this->assertStringContainsString('lq_fields', $text);
        $this->assertStringNotContainsString('total_amount', $text);

        $trace = implode('|', array_map(static fn(array $s): string => $s['step'], $catalog->trace()));
        $this->assertStringContainsString('prompt.truncated', $trace);
    }


    public function testJsonSerializableRoundTrip(): void
    {
        $catalog = $this->built();
        $decoded = json_decode(json_encode($catalog), true);

        $this->assertSame('host.demo', $decoded['target']);
        $this->assertSame('demo_orders', $decoded['table']);
        $this->assertArrayHasKey('status', $decoded['fields']);
        $this->assertSame('已退款', $decoded['fields']['status']['enum'][1]['label']);

        $this->assertSame($catalog->toArray(), json_decode(json_encode($catalog), true));
    }

    public function testTargetNameOf(): void
    {
        $catalog = new ExposedCatalog();

        $this->assertSame('host.menu', $catalog->targetOf('app\\admin\\controller\\Menu'));
        $this->assertSame('host.login_log', $catalog->targetOf('app\\admin\\controller\\LoginLog'));
        $this->assertSame('host.market.plugin', $catalog->targetOf('app\\admin\\controller\\Market\\Plugin'));
        $this->assertSame('smartstock.product', $catalog->targetOf('plugin\\smartstock\\controller\\Product'));
        $this->assertSame('smartstock.admin.warehouse', $catalog->targetOf('plugin\\smartstock\\controller\\admin\\Warehouse'));
    }
}

/**
 * 暴露 `protected` 接缝的测试替身
 */
class ExposedCatalog extends QueryFieldCatalog
{
    public function targetOf(string $controllerClass): string
    {
        return $this->targetNameOf($controllerClass);
    }
}
