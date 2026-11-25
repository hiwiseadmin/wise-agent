<?php
declare(strict_types=1);

namespace wise\agent\test;

use PHPUnit\Framework\TestCase;
use wise\agent\query\QueryField;
use wise\agent\query\QueryValueResolver;
use wise\agent\query\exception\AmbiguousValueException;
use wise\agent\query\exception\QueryParamException;

/**
 * A3-2 取值接地回归（附录三 验收 5、11 的接地部分）
 *
 * 三级接地全部离线覆盖：关联查询由 `TestableValueResolver` 覆写接缝，缓存用内存数组。
 */
class QueryValueResolverTest extends TestCase
{
    private function enumField(): QueryField
    {
        return new QueryField(
            key: 'status',
            column: 'status',
            label: '状态',
            type: QueryField::TYPE_ENUM,
            enum: [
                ['value' => 'pending', 'label' => '待付款'],
                ['value' => 'refunded', 'label' => '已退款'],
                ['value' => 'refund_part', 'label' => '部分退款'],
            ],
        );
    }

    private function relationField(): QueryField
    {
        return new QueryField(
            key: 'region_id',
            column: 'region_id',
            label: '区域',
            type: QueryField::TYPE_RELATION,
            relation: ['name' => 'region', 'table' => 'regions', 'label_column' => 'name'],
        );
    }


    public function testEnumExactMatch(): void
    {
        $hit = (new TestableValueResolver())->resolve($this->enumField(), '已退款');

        $this->assertSame('refunded', $hit['value']);
        $this->assertSame(QueryValueResolver::GROUNDED_ENUM, $hit['grounded']);
        $this->assertSame('已退款', $hit['matched']);
    }

    public function testEnumContainsMatchWhenUnique(): void
    {
        $hit = (new TestableValueResolver())->resolve($this->enumField(), '部分');

        $this->assertSame('refund_part', $hit['value']);
        $this->assertSame(QueryValueResolver::GROUNDED_ENUM, $hit['grounded']);
    }

    public function testEnumAmbiguityRaisesCodeTwo(): void
    {
        $resolver = new TestableValueResolver();

        try {
            $resolver->resolve($this->enumField(), '退款');
            $this->fail('应当抛出歧义异常');
        } catch (AmbiguousValueException $e) {
            $this->assertSame(2, $e->resultCode());
            $this->assertSame('status', $e->field());
            $this->assertSame('退款', $e->keyword());
            $this->assertGreaterThanOrEqual(2, count($e->candidates()));
            $this->assertSame(QueryValueResolver::GROUNDED_ENUM, $e->source());

            $result = $e->toResult();
            $this->assertSame(2, $result['code']);
            $this->assertSame('ambiguous', $result['msg']);
            $this->assertStringContainsString('不要猜测', $result['hint']);
            $this->assertArrayHasKey('candidates', $result['data']);
        }
    }

    public function testEnumMissFallsThroughToLiteral(): void
    {
        $hit = (new TestableValueResolver())->resolve($this->enumField(), '不存在的状态');

        $this->assertSame('不存在的状态', $hit['value']);
        $this->assertSame(QueryValueResolver::GROUNDED_LITERAL, $hit['grounded']);
    }


    public function testRelationUniqueHitResolvesToId(): void
    {
        $resolver = new TestableValueResolver();
        $resolver->candidates = [['value' => 3, 'label' => '华东区']];

        $hit = $resolver->resolve($this->relationField(), '华东区');

        $this->assertSame(3, $hit['value']);
        $this->assertSame(QueryValueResolver::GROUNDED_RELATION, $hit['grounded']);
        $this->assertSame(1, $resolver->queries, '唯一命中只查一次');
        $this->assertSame(1, $resolver->lookups());
    }

    public function testRelationAmbiguityRaisesCodeTwoWithCandidates(): void
    {
        $resolver = new TestableValueResolver();
        $resolver->candidates = [
            ['value' => 3, 'label' => '华东区'],
            ['value' => 7, 'label' => '华东南区'],
        ];

        try {
            $resolver->resolve($this->relationField(), '华东');
            $this->fail('应当抛出歧义异常');
        } catch (AmbiguousValueException $e) {
            $this->assertSame(2, $e->resultCode());
            $this->assertSame('region_id', $e->field());
            $this->assertSame('华东', $e->keyword());
            $this->assertCount(2, $e->candidates());
            $this->assertSame(QueryValueResolver::GROUNDED_RELATION, $e->source());
            $this->assertSame([3, 7], array_column($e->candidates(), 'value'));
            $this->assertSame(1, $resolver->queries);
        }
    }

    public function testRelationMissDoesNotGuess(): void
    {
        $resolver = new TestableValueResolver();
        $resolver->candidates = [];

        $hit = $resolver->resolve($this->relationField(), '不存在的区域');

        $this->assertSame('不存在的区域', $hit['value'], '0 命中时按自由值处理，不取第一个候选');
        $this->assertSame(QueryValueResolver::GROUNDED_LITERAL, $hit['grounded']);

        $trace = implode('|', array_map(static fn(array $s): string => $s['step'], $resolver->trace()));
        $this->assertStringContainsString('value.relationMiss', $trace);
    }

    public function testRelationNumericValueSkipsLookup(): void
    {
        $resolver = new TestableValueResolver();
        $resolver->candidates = [['value' => 99, 'label' => '不应被使用']];

        $hit = $resolver->resolve($this->relationField(), '3');

        $this->assertSame(3, $hit['value'], '数值直接作为外键 id');
        $this->assertSame(0, $resolver->queries, '不产生候选查询');
        $this->assertSame(QueryValueResolver::GROUNDED_RELATION, $hit['grounded']);
    }

    public function testRelationLookupIsCached(): void
    {
        $resolver = new TestableValueResolver();
        $resolver->candidates = [['value' => 3, 'label' => '华东区']];

        $resolver->resolve($this->relationField(), '华东区');
        $hit = $resolver->resolve($this->relationField(), '华东区');

        $this->assertSame(3, $hit['value']);
        $this->assertSame(1, $resolver->queries, '第二次命中缓存，不再查库');

        $trace = implode('|', array_map(static fn(array $s): string => $s['step'], $resolver->trace()));
        $this->assertStringContainsString('value.cache', $trace);
    }

    public function testRelationLookupsAreCapped(): void
    {
        $resolver = new TestableValueResolver(['max_lookups' => 1]);
        $resolver->candidates = [['value' => 3, 'label' => '华东区']];

        $resolver->resolve($this->relationField(), '华东区');

        $this->expectException(QueryParamException::class);
        $this->expectExceptionMessage('关联接地次数已达上限');

        try {
            $resolver->resolve($this->relationField(), '华南区');
        } finally {
        }
    }

    public function testLikeWildcardsAreEscaped(): void
    {
        $resolver = new TestableValueResolver();
        $resolver->candidates = [];

        $resolver->resolve($this->relationField(), '100%_a');

        $this->assertSame('100\%\_a', $resolver->lastPattern, '关键词里的 % 与 _ 必须转义为字面量');
    }


    public function testIntFieldCastsAndRejectsNonNumeric(): void
    {
        $resolver = new TestableValueResolver();
        $field    = new QueryField('qty', 'qty', '数量', QueryField::TYPE_INT);

        $this->assertSame(5, $resolver->resolve($field, '5')['value']);
        $this->assertSame(5, $resolver->resolve($field, 5)['value']);

        $this->expectException(QueryParamException::class);
        $this->expectExceptionMessage('需要数字');
        $resolver->resolve($field, '五');
    }

    public function testDecimalKeepsStringForm(): void
    {
        $field = new QueryField('amount', 'amount', '金额', QueryField::TYPE_DECIMAL);

        $hit = (new TestableValueResolver())->resolve($field, '1000.55');

        $this->assertSame('1000.55', $hit['value'], '保留字符串形态，避免 float 精度损失');
    }

    public function testBoolNormalization(): void
    {
        $resolver = new TestableValueResolver();
        $field    = new QueryField('is_top', 'is_top', '置顶', QueryField::TYPE_BOOL);

        $this->assertSame(1, $resolver->resolve($field, '是')['value']);
        $this->assertSame(1, $resolver->resolve($field, 'true')['value']);
        $this->assertSame(0, $resolver->resolve($field, '否')['value']);
        $this->assertSame(0, $resolver->resolve($field, '0')['value']);

        $this->expectException(QueryParamException::class);
        $resolver->resolve($field, '也许');
    }

    public function testEmptyValueIsRejectedNotSilentlyDropped(): void
    {
        $resolver = new TestableValueResolver();
        $field    = new QueryField('order_no', 'order_no', '订单号', QueryField::TYPE_STRING);

        $this->expectException(QueryParamException::class);
        $this->expectExceptionMessage('静默忽略');

        $resolver->resolve($field, '   ');
    }

    public function testResolveListGroundsEachElement(): void
    {
        $resolver = new TestableValueResolver();

        $hit = $resolver->resolveList($this->enumField(), ['待付款', 'refunded']);

        $this->assertSame(['pending', 'refunded'], $hit['values']);
        $this->assertSame([QueryValueResolver::GROUNDED_ENUM, QueryValueResolver::GROUNDED_ENUM], $hit['grounded']);
    }

    public function testResolveListRejectsEmptyArray(): void
    {
        $this->expectException(QueryParamException::class);

        (new TestableValueResolver())->resolveList($this->enumField(), []);
    }

    public function testNonScalarValueIsRejected(): void
    {
        $this->expectException(QueryParamException::class);

        (new TestableValueResolver())->resolve($this->enumField(), ['嵌套' => '结构']);
    }

    public function testResetClearsCounters(): void
    {
        $resolver = new TestableValueResolver();
        $resolver->candidates = [['value' => 3, 'label' => '华东区']];

        $resolver->resolve($this->relationField(), '华东区');
        $this->assertSame(1, $resolver->lookups());

        $resolver->reset();

        $this->assertSame(0, $resolver->lookups());
        $this->assertSame([], $resolver->trace());
    }
}

/**
 * 测试替身：关联候选与缓存用内存实现，完全离线
 */
class TestableValueResolver extends QueryValueResolver
{
    /** @var array<int, array{value:mixed, label:string}> */
    public array $candidates = [];

    public int $queries = 0;

    public string $lastPattern = '';

    /** @var array<string, mixed> */
    private array $memory = [];

    protected function relationCandidates(string $table, string $labelColumn, string $keyword, int $limit): array
    {
        $this->queries++;
        $this->lastPattern = $this->escapeLike($keyword);

        return $this->candidates;
    }

    protected function cacheGet(string $key): mixed
    {
        return $this->memory[$key] ?? null;
    }

    protected function cacheSet(string $key, mixed $value): void
    {
        $this->memory[$key] = $value;
    }
}
