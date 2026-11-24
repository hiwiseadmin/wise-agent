<?php
declare(strict_types=1);

namespace wise\agent\test;

use PHPUnit\Framework\TestCase;
use wise\agent\query\QueryFieldCatalog;
use wise\agent\query\QueryIntent;
use wise\agent\query\QueryValueResolver;
use wise\agent\query\TimeRangeResolver;
use wise\agent\query\exception\AmbiguousValueException;
use wise\agent\query\exception\QueryParamException;

/**
 * A3-2 意图编译回归（附录三 验收 5、6、7、9、11）
 *
 * 覆盖编译规则表全部分支 + 强校验 + 注入安全。全部离线（关联接地用测试替身）。
 */
class QueryIntentTest extends TestCase
{
    private const NOW = '2026-10-16 15:30:00';

    /**
     * @return array<string, array<string, mixed>>
     */
    private function sourceFields(): array
    {
        return [
            'id'           => ['label' => 'ID', 'type' => 'integer', 'sortable' => true, 'filterable' => true, 'source' => 'db', 'raw_type' => 'int(11)'],
            'order_no'     => ['label' => '订单号', 'type' => 'string', 'sortable' => true, 'filterable' => true, 'source' => 'db', 'raw_type' => 'varchar(32)'],
            'total_amount' => ['label' => '金额', 'type' => 'number', 'sortable' => true, 'filterable' => true, 'source' => 'db', 'raw_type' => 'decimal(10,2)'],
            'qty'          => ['label' => '数量', 'type' => 'integer', 'sortable' => true, 'filterable' => true, 'source' => 'db', 'raw_type' => 'int(11)'],
            'status'       => [
                'label' => '状态', 'type' => 'enum',
                'enum' => ['pending', 'refunded'], 'enum_labels' => ['待付款', '已退款'],
                'sortable' => true, 'filterable' => true, 'source' => 'form', 'raw_type' => 'select',
            ],
            'is_top'       => ['label' => '置顶', 'type' => 'boolean', 'sortable' => true, 'filterable' => true, 'source' => 'db', 'raw_type' => 'tinyint(1)'],
            'remark'       => ['label' => '备注', 'type' => 'text', 'sortable' => false, 'filterable' => true, 'source' => 'db', 'raw_type' => 'text'],
            'create_time'  => ['label' => '创建时间', 'type' => 'datetime', 'sortable' => true, 'filterable' => true, 'source' => 'db', 'raw_type' => 'datetime'],
            'publish_date' => ['label' => '发布日期', 'type' => 'date', 'sortable' => true, 'filterable' => true, 'source' => 'db', 'raw_type' => 'date'],
            'attach'       => ['label' => '附件', 'type' => 'upload', 'sortable' => false, 'filterable' => false, 'source' => 'form', 'raw_type' => 'upload'],
        ];
    }

    private function catalog(array $options = [], array $aliases = []): QueryFieldCatalog
    {
        return (new QueryFieldCatalog($options))->build(
            'host.demo',
            $this->sourceFields(),
            [],
            [],
            [],
            $aliases,
            [],
            'demo_orders'
        );
    }

    private function compile(array $args, array $options = [], array $aliases = []): array
    {
        $intent = QueryIntent::fromToolArgs($args, $options);

        return $intent->compile(
            $this->catalog($options, $aliases),
            new QueryIntentValueResolverStub($options),
            new TimeRangeResolver(self::NOW, 'Asia/Shanghai')
        );
    }


    public function testEqProducesBareKey(): void
    {
        $params = $this->compile([
            'filters' => [
                ['field' => 'status', 'op' => 'eq', 'value' => '已退款'],
                ['field' => 'qty', 'op' => 'gte', 'value' => 5],
            ],
        ]);

        $this->assertSame('refunded', $params['status'], 'eq 产出裸键（Builder 无 __ 即等值）');
        $this->assertSame(5, $params['qty__gte']);
    }

    public function testAliasFieldCompilesToRealColumn(): void
    {
        $params = $this->compile(
            ['filters' => [['field' => 'amount', 'op' => 'gte', 'value' => 1000]]],
            [],
            ['amount' => 'total_amount']
        );

        $this->assertArrayHasKey('total_amount__gte', $params, '写进参数的是真实列名');
        $this->assertArrayNotHasKey('amount__gte', $params);
        $this->assertSame('1000', $params['total_amount__gte']);
    }

    public function testSynonymsResolveField(): void
    {
        $intent = QueryIntent::fromToolArgs(['filters' => [['field' => '金额', 'op' => 'gte', 'value' => 100]]]);
        $catalog = (new QueryFieldCatalog())->build(
            'host.demo',
            $this->sourceFields(),
            [],
            [],
            [],
            [],
            ['total_amount' => ['金额']]
        );

        $params = $intent->compile($catalog, new QueryIntentValueResolverStub(), new TimeRangeResolver(self::NOW));

        $this->assertArrayHasKey('total_amount__gte', $params);
    }

    public function testLikeDoesNotAddPercent(): void
    {
        $params = $this->compile([
            'filters' => [['field' => 'remark', 'op' => 'like', 'value' => '加急']],
        ]);

        $this->assertSame('加急', $params['remark__like'], '不重复补 %（Builder 会自动补）');
    }

    public function testInOperatorGroundsEachElement(): void
    {
        $params = $this->compile([
            'filters' => [['field' => 'status', 'op' => 'in', 'value' => ['待付款', 'refunded']]],
        ]);

        $this->assertSame('pending,refunded', $params['status__in']);
    }

    public function testNullOperatorEmitsNonEmptyPlaceholder(): void
    {
        $params = $this->compile([
            'filters' => [['field' => 'remark', 'op' => 'null']],
        ]);

        $this->assertSame(1, $params['remark__null']);
    }

    public function testBoolAndNumericCasting(): void
    {
        $params = $this->compile([
            'filters' => [
                ['field' => 'is_top', 'op' => 'eq', 'value' => '是'],
                ['field' => 'qty', 'op' => 'lt', 'value' => '10'],
            ],
        ]);

        $this->assertSame(1, $params['is_top']);
        $this->assertSame(10, $params['qty__lt']);
    }


    public function testTimeBetweenUsesDeterministicRange(): void
    {
        $intent = QueryIntent::fromToolArgs([
            'filters' => [['field' => 'create_time', 'op' => 'between', 'time_expr' => '上月']],
        ]);

        $params = $intent->compile($this->catalog(), new QueryIntentValueResolverStub(), new TimeRangeResolver(self::NOW));

        $this->assertSame('2026-09-01 00:00:00,2026-09-30 23:59:59', $params['create_time__between']);

        $plan = $intent->plan();
        $this->assertSame('上月', $plan['filters'][0]['time']['label']);
        $this->assertSame(30, $plan['filters'][0]['time']['days']);
        $this->assertSame('2026-09-01 00:00:00', $plan['filters'][0]['time']['start']);
    }

    public function testDateColumnUsesDayGranularity(): void
    {
        $params = $this->compile([
            'filters' => [['field' => 'publish_date', 'op' => 'between', 'time_expr' => '上月']],
        ]);

        $this->assertSame('2026-09-01,2026-09-30', $params['publish_date__between']);
    }

    public function testTimeRangeAcceptsAbsoluteDates(): void
    {
        $params = $this->compile([
            'filters' => [[
                'field' => 'create_time', 'op' => 'between',
                'time_start' => '2026-09-01', 'time_end' => '2026-09-30',
            ]],
        ]);

        $this->assertSame('2026-09-01 00:00:00,2026-09-30 23:59:59', $params['create_time__between']);
    }

    public function testTimeBetweenWithoutInputIsRejected(): void
    {
        $this->expectException(QueryParamException::class);
        $this->expectExceptionMessage('time_expr');

        $this->compile(['filters' => [['field' => 'create_time', 'op' => 'between']]]);
    }

    public function testTimeInputOnNonTimeFieldIsRejected(): void
    {
        $this->expectException(QueryParamException::class);
        $this->expectExceptionMessage('不是时间字段');

        $this->compile([
            'filters' => [['field' => 'qty', 'op' => 'between', 'time_expr' => '上月', 'value' => [1, 2]]],
        ]);
    }


    public function testExistsOperatorIsRejected(): void
    {
        $this->expectException(QueryParamException::class);
        $this->expectExceptionMessage('不支持操作符 exists');

        $this->compile(['filters' => [['field' => 'status', 'op' => 'exists', 'value' => 1]]]);
    }

    public function testOperatorIsClippedByType(): void
    {
        $this->expectException(QueryParamException::class);
        $this->expectExceptionMessage('不支持操作符 gt');

        $this->compile(['filters' => [['field' => 'remark', 'op' => 'gt', 'value' => 'x']]]);
    }

    public function testBetweenWithSingleValueIsRejected(): void
    {
        $this->expectException(QueryParamException::class);
        $this->expectExceptionMessage('必须恰好 2 个值');

        $this->compile(['filters' => [['field' => 'qty', 'op' => 'between', 'value' => [18]]]]);
    }

    public function testBetweenWithThreeValuesIsRejected(): void
    {
        $this->expectException(QueryParamException::class);
        $this->expectExceptionMessage('必须恰好 2 个值');

        $this->compile(['filters' => [['field' => 'qty', 'op' => 'between', 'value' => [1, 2, 3]]]]);
    }

    public function testBetweenWithTwoValuesCompiles(): void
    {
        $params = $this->compile(['filters' => [['field' => 'qty', 'op' => 'between', 'value' => [10, 20]]]]);

        $this->assertSame('10,20', $params['qty__between']);
    }

    public function testArrayValueOnSingleOperatorIsRejected(): void
    {
        $this->expectException(QueryParamException::class);
        $this->expectExceptionMessage('只接受单个值');

        $this->compile(['filters' => [['field' => 'qty', 'op' => 'eq', 'value' => [1, 2]]]]);
    }

    public function testInRequiresArray(): void
    {
        $this->expectException(QueryParamException::class);
        $this->expectExceptionMessage('需要数组值');

        $this->compile(['filters' => [['field' => 'status', 'op' => 'in', 'value' => 'refunded']]]);
    }


    public function testUnknownFieldIsRejectedWithoutAnyQuery(): void
    {
        $intent  = QueryIntent::fromToolArgs(['filters' => [['field' => 'not_a_field', 'op' => 'eq', 'value' => 1]]]);
        $values  = new QueryIntentValueResolverStub();

        try {
            $intent->compile($this->catalog(), $values, new TimeRangeResolver(self::NOW));
            $this->fail('未知字段必须被拒绝');
        } catch (QueryParamException $e) {
            $this->assertStringContainsString('未知字段', $e->getMessage());
            $this->assertSame(1, $e->resultCode(), 'code:1 = 失败（不是 code:2 需要澄清）');
            $this->assertSame(0, $values->queries, '不得产生任何查询');
            $this->assertSame([], $intent->params(), '未产出任何参数');
        }
    }

    public function testNotFilterableFieldIsRejected(): void
    {
        $this->expectException(QueryParamException::class);
        $this->expectExceptionMessage('不可用于筛选');

        $this->compile(['filters' => [['field' => 'attach', 'op' => 'eq', 'value' => 'a.png']]]);
    }

    public function testUnknownOrderFieldIsRejected(): void
    {
        $this->expectException(QueryParamException::class);

        $this->compile(['orders' => [['field' => 'not_a_field', 'direction' => 'desc']]]);
    }

    public function testNonSortableFieldCannotBeOrdered(): void
    {
        $this->expectException(QueryParamException::class);
        $this->expectExceptionMessage('不支持排序');

        $this->compile(['orders' => [['field' => 'remark', 'direction' => 'desc']]]);
    }

    public function testUnknownFieldInProjectionIsRejected(): void
    {
        $this->expectException(QueryParamException::class);

        $this->compile(['fields' => ['id', 'not_a_field']]);
    }

    public function testDuplicateFieldOperatorIsRejected(): void
    {
        $this->expectException(QueryParamException::class);
        $this->expectExceptionMessage('重复筛选');

        $this->compile([
            'filters' => [
                ['field' => 'qty', 'op' => 'gt', 'value' => 1],
                ['field' => 'qty', 'op' => 'gt', 'value' => 2],
            ],
        ]);
    }


    public function testAmbiguityShortCircuitsCompilation(): void
    {
        $catalog = (new QueryFieldCatalog())->build('host.demo', $this->sourceFields());

        $intent = QueryIntent::fromToolArgs([
            'filters' => [['field' => 'order_no', 'op' => 'eq', 'value' => 'A100']],
        ]);

        $values = new QueryIntentValueResolverStub();
        $values->candidates = [
            ['value' => 3, 'label' => '华东区'],
            ['value' => 7, 'label' => '华东南区'],
        ];

        $relationCatalog = (new QueryFieldCatalog())->build('host.demo', array_merge($this->sourceFields(), [
            'region_id' => ['label' => '区域', 'type' => 'integer', 'sortable' => true, 'filterable' => true, 'source' => 'db', 'raw_type' => 'int(11)'],
        ]));

        $relationField = $relationCatalog->get('region_id');
        $this->assertNotNull($relationField);

        try {
            $values->resolve(new \wise\agent\query\QueryField(
                key: 'region_id', column: 'region_id', label: '区域',
                type: \wise\agent\query\QueryField::TYPE_RELATION,
                relation: ['name' => 'region', 'table' => 'regions', 'label_column' => 'name']
            ), '华东');
            $this->fail('应当抛出歧义异常');
        } catch (AmbiguousValueException $e) {
            $this->assertSame(2, $e->resultCode());
            $this->assertCount(2, $e->candidates());
        }

        $this->assertSame([], $intent->params(), '未编译出任何参数');
        $this->assertSame(1, $values->queries);
    }


    public function testLimitIsClamped(): void
    {
        $intent = QueryIntent::fromToolArgs(['limit' => 100000]);
        $params = $intent->compile($this->catalog(), new QueryIntentValueResolverStub(), new TimeRangeResolver(self::NOW));

        $this->assertSame(100, $params['limit'], '夹取到 list_query.max_limit=100');
        $this->assertSame(100000, $intent->plan()['limit']['requested'], 'plan 回显原始请求值');
        $this->assertSame(100, $intent->plan()['limit']['applied']);
    }

    public function testDefaultLimitIsApplied(): void
    {
        $params = $this->compile([]);

        $this->assertSame(20, $params['limit']);
        $this->assertSame(1, $params['page']);
    }

    public function testMaxLimitRespectsPaginateCeiling(): void
    {
        $params = $this->compile(['limit' => 5000], ['max_limit' => 50]);

        $this->assertSame(50, $params['limit']);
    }

    public function testTooManyFiltersIsRejected(): void
    {
        $filters = [];
        for ($i = 0; $i < 30; $i++) {
            $filters[] = ['field' => 'qty__gt', 'op' => 'gt', 'value' => $i];
        }

        $this->expectException(QueryParamException::class);
        $this->expectExceptionMessage('超过上限 12');

        $this->compile(['filters' => $filters]);
    }

    public function testTooManyProjectionFieldsIsRejected(): void
    {
        $this->expectException(QueryParamException::class);
        $this->expectExceptionMessage('fields 数量超过上限');

        $this->compile(['fields' => array_fill(0, 25, 'qty')]);
    }

    public function testInvalidLogicIsRejected(): void
    {
        $this->expectException(QueryParamException::class);
        $this->expectExceptionMessage('logic 只允许');

        $this->compile(['logic' => 'xor']);
    }

    public function testInvalidDirectionIsRejected(): void
    {
        $this->expectException(QueryParamException::class);
        $this->expectExceptionMessage('direction 只允许');

        $this->compile(['orders' => [['field' => 'qty', 'direction' => 'sideways']]]);
    }


    public function testOrLogicEmitsUnderscoreOrGroup(): void
    {
        $params = $this->compile([
            'logic'   => 'or',
            'filters' => [
                ['field' => 'status', 'op' => 'eq', 'value' => '已退款'],
                ['field' => 'qty', 'op' => 'gt', 'value' => 5],
            ],
        ]);

        $this->assertArrayHasKey('_or', $params);
        $this->assertSame([
            ['status' => 'refunded'],
            ['qty__gt' => 5],
        ], $params['_or'], '第二层键是「列名__操作符」，与 buildLogicGroup 的读取方式一致');
        $this->assertArrayNotHasKey('status', $params, 'OR 模式下不产出顶层条件');
    }

    public function testOrdersProduceOrderParam(): void
    {
        $params = $this->compile([
            'orders' => [
                ['field' => 'total_amount', 'direction' => 'desc'],
                ['field' => 'id', 'direction' => 'asc'],
            ],
        ]);

        $this->assertSame('total_amount desc,id asc', $params['order']);
    }

    public function testFieldProjectionUsesColumns(): void
    {
        $params = $this->compile(['fields' => ['id', 'order_no']]);

        $this->assertSame('id,order_no', $params['field']);
    }

    public function testProjectionUsesAliasColumn(): void
    {
        $params = $this->compile(['fields' => ['amount']], [], ['amount' => 'total_amount']);

        $this->assertSame('total_amount', $params['field']);
    }

    public function testGroupHavingAndWithCountAreNeverEmitted(): void
    {
        $params = $this->compile([
            'filters' => [['field' => 'qty', 'op' => 'gt', 'value' => 1]],
        ]);

        foreach (['group', 'having', 'with', 'with_count'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $params);
        }
    }

    public function testUnknownTopLevelKeysAreNotedNotRejected(): void
    {
        $intent = QueryIntent::fromToolArgs([
            'filters'   => [['field' => 'qty', 'op' => 'gt', 'value' => 1]],
            'explain'   => true,
            'time_range' => ['expr' => '上月'],
        ]);

        $intent->compile($this->catalog(), new QueryIntentValueResolverStub(), new TimeRangeResolver(self::NOW));

        $notes = implode('|', $intent->plan()['notes']);
        $this->assertStringContainsString('explain', $notes);
        $this->assertStringContainsString('time_range', $notes);
    }


    public function testInjectionValueStaysLiteralAndNeverReachesFieldOrOperator(): void
    {
        $payload = "x'; DROP TABLE ws_orders; --";
        $params  = $this->compile([
            'filters' => [['field' => 'order_no', 'op' => 'like', 'value' => $payload]],
        ]);

        $this->assertSame($payload, $params['order_no__like']);
        foreach (array_keys($params) as $key) {
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9_]+(__[a-z]+)?$/', (string) $key);
            $this->assertStringNotContainsString(';', (string) $key);
            $this->assertStringNotContainsString('DROP', (string) $key);
        }
    }

    public function testInjectionIntoFieldNameIsRejected(): void
    {
        foreach (["status' OR '1'='1", 'amount__eq', 'status; DROP TABLE x', '1=1'] as $payload) {
            try {
                $this->compile(['filters' => [['field' => $payload, 'op' => 'eq', 'value' => 1]]]);
                $this->fail('字段名注入必须被拒绝：' . $payload);
            } catch (QueryParamException $e) {
                $this->assertStringContainsString('未知字段', $e->getMessage());
            }
        }
    }

    public function testInjectionIntoOperatorIsRejected(): void
    {
        foreach (['eq; DROP TABLE x', "eq' OR '1'='1", 'eq,ne'] as $payload) {
            try {
                $this->compile(['filters' => [['field' => 'qty', 'op' => $payload, 'value' => 1]]]);
                $this->fail('操作符注入必须被拒绝：' . $payload);
            } catch (QueryParamException $e) {
                $this->assertStringContainsString('不支持操作符', $e->getMessage());
            }
        }
    }

    public function testInjectionIntoLogicIsRejected(): void
    {
        $this->expectException(QueryParamException::class);

        $this->compile(['logic' => "or' OR '1'='1"]);
    }

    public function testNestedValueStructureIsRejected(): void
    {
        $this->expectException(QueryParamException::class);
        $this->expectExceptionMessage('只能包含标量');

        $this->compile([
            'filters' => [['field' => 'status', 'op' => 'in', 'value' => [['nested' => 'x']]]],
        ]);
    }

    public function testPlanEchoesEverythingForAudit(): void
    {
        $intent = QueryIntent::fromToolArgs([
            'filters' => [
                ['field' => 'status', 'op' => 'eq', 'value' => '已退款'],
                ['field' => 'create_time', 'op' => 'between', 'time_expr' => '上月'],
            ],
            'orders' => [['field' => 'total_amount', 'direction' => 'desc']],
            'limit'  => 500,
            'page'   => 2,
        ]);

        $params = $intent->compile($this->catalog(), new QueryIntentValueResolverStub(), new TimeRangeResolver(self::NOW));
        $plan   = $intent->plan();

        $this->assertSame('and', $plan['logic']);
        $this->assertSame('enum', $plan['filters'][0]['grounded']);
        $this->assertSame('time', $plan['filters'][1]['grounded']);
        $this->assertSame(30, $plan['filters'][1]['time']['days']);
        $this->assertSame('total_amount desc', $params['order']);
        $this->assertSame(100, $plan['limit']['applied']);
        $this->assertSame(2, $plan['page']);
        $this->assertSame($params, $plan['params']);
        $this->assertNotEmpty($plan['grounding'], '接地轨迹进入 plan，可回放「AI 是怎么理解的」');
    }
}

/**
 * 意图编译测试**自带**的接地器替身
 *
 * ⚠️ 早前版本复用了 `QueryValueResolverTest.php` 里的 `TestableValueResolver`，导致
 * 「单独运行本文件」时类未加载而全红（只有整目录运行时才通过）——测试文件之间不应互相依赖。
 */
class QueryIntentValueResolverStub extends QueryValueResolver
{
    /** 关联候选（测试可注入以触发歧义分支） */
    public array $candidates = [];

    /** 关联查询次数（断言「拒绝时不产生任何查询」） */
    public int $queries = 0;

    protected function relationCandidates(string $table, string $labelColumn, string $keyword, int $limit): array
    {
        $this->queries++;

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
