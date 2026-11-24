<?php
declare(strict_types=1);

namespace wise\agent\test;

use PHPUnit\Framework\TestCase;
use wise\agent\query\TimeRangeResolver;

/**
 * A3-1 时间词解析回归（附录三 验收 1 / 2）
 *
 * 覆盖：相对词全覆盖、跨年进位、闰年 2 月、季度边界、月末夹取、绝对词、
 * 列类型适配（date vs datetime）、双通道兜底、非法词拒绝。
 *
 * 全部用例注入固定 `now`，不依赖运行时刻。
 */
class TimeRangeResolverTest extends TestCase
{
    private const NOW = '2026-10-16 15:30:00';

    private function resolver(string $now = self::NOW): TimeRangeResolver
    {
        return new TimeRangeResolver($now, 'Asia/Shanghai');
    }

    /**
     * @dataProvider relativeCases
     */
    public function testRelativeExpressions(string $expr, string $start, string $end, string $granularity, string $now = self::NOW): void
    {
        $range = $this->resolver($now)->resolve($expr);

        $this->assertSame($start, $range['start'], "{$expr} 起始");
        $this->assertSame($end, $range['end'], "{$expr} 结束");
        $this->assertSame($granularity, $range['granularity']);
        $this->assertSame($expr, $range['label']);
        $this->assertSame($start . ' 00:00:00', $range['start_time']);
        $this->assertSame($end . ' 23:59:59', $range['end_time']);
    }

    /**
     * @return array<string, array{0:string,1:string,2:string,3:string,4?:string}>
     */
    public static function relativeCases(): array
    {
        return [
            '今天'   => ['今天', '2026-10-16', '2026-10-16', TimeRangeResolver::GRANULARITY_DAY],
            '昨天'   => ['昨天', '2026-10-15', '2026-10-15', TimeRangeResolver::GRANULARITY_DAY],
            '前天'   => ['前天', '2026-10-14', '2026-10-14', TimeRangeResolver::GRANULARITY_DAY],

            '本周'   => ['本周', '2026-10-12', '2026-10-18', TimeRangeResolver::GRANULARITY_WEEK],
            '上周'   => ['上周', '2026-10-05', '2026-10-11', TimeRangeResolver::GRANULARITY_WEEK],

            '本月'   => ['本月', '2026-10-01', '2026-10-31', TimeRangeResolver::GRANULARITY_MONTH],
            '上月'   => ['上月', '2026-09-01', '2026-09-30', TimeRangeResolver::GRANULARITY_MONTH],

            '本季度' => ['本季度', '2026-10-01', '2026-12-31', TimeRangeResolver::GRANULARITY_QUARTER],
            '上季度' => ['上季度', '2026-07-01', '2026-09-30', TimeRangeResolver::GRANULARITY_QUARTER],

            '今年'   => ['今年', '2026-01-01', '2026-12-31', TimeRangeResolver::GRANULARITY_YEAR],
            '去年'   => ['去年', '2025-01-01', '2025-12-31', TimeRangeResolver::GRANULARITY_YEAR],
            '上半年' => ['上半年', '2026-01-01', '2026-06-30', TimeRangeResolver::GRANULARITY_YEAR],
            '下半年' => ['下半年', '2026-07-01', '2026-12-31', TimeRangeResolver::GRANULARITY_YEAR],

            '近7天'    => ['近7天', '2026-10-10', '2026-10-16', TimeRangeResolver::GRANULARITY_ROLLING],
            '近30天'   => ['近30天', '2026-09-17', '2026-10-16', TimeRangeResolver::GRANULARITY_ROLLING],
            '近3个月'  => ['近3个月', '2026-07-16', '2026-10-16', TimeRangeResolver::GRANULARITY_ROLLING],
            '近1年'    => ['近1年', '2025-10-16', '2026-10-16', TimeRangeResolver::GRANULARITY_ROLLING],

            '跨年_12月上月' => ['上月', '2026-11-01', '2026-11-30', TimeRangeResolver::GRANULARITY_MONTH, '2026-12-15 10:00:00'],
            '跨年_1月上月'  => ['上月', '2025-12-01', '2025-12-31', TimeRangeResolver::GRANULARITY_MONTH, '2026-01-15 10:00:00'],
            '跨年_本周'     => ['本周', '2025-12-29', '2026-01-04', TimeRangeResolver::GRANULARITY_WEEK, '2026-01-01 10:00:00'],
            '跨年_上周'     => ['上周', '2025-12-22', '2025-12-28', TimeRangeResolver::GRANULARITY_WEEK, '2026-01-01 10:00:00'],
            '跨年_上季度'   => ['上季度', '2025-10-01', '2025-12-31', TimeRangeResolver::GRANULARITY_QUARTER, '2026-01-15 10:00:00'],
            '跨年_去年'     => ['去年', '2025-01-01', '2025-12-31', TimeRangeResolver::GRANULARITY_YEAR, '2026-01-15 10:00:00'],

            '闰年_本月' => ['本月', '2024-02-01', '2024-02-29', TimeRangeResolver::GRANULARITY_MONTH, '2024-02-10 10:00:00'],
            '闰年_上月' => ['上月', '2024-02-01', '2024-02-29', TimeRangeResolver::GRANULARITY_MONTH, '2024-03-10 10:00:00'],
            '平年_上月' => ['上月', '2026-02-01', '2026-02-28', TimeRangeResolver::GRANULARITY_MONTH, '2026-03-10 10:00:00'],

            '季末_本季度' => ['本季度', '2026-01-01', '2026-03-31', TimeRangeResolver::GRANULARITY_QUARTER, '2026-03-31 23:00:00'],
            '季初_上季度' => ['上季度', '2026-01-01', '2026-03-31', TimeRangeResolver::GRANULARITY_QUARTER, '2026-04-01 00:10:00'],
            '年末_上季度' => ['上季度', '2026-07-01', '2026-09-30', TimeRangeResolver::GRANULARITY_QUARTER, '2026-10-01 00:10:00'],

            '月末夹取_近3个月' => ['近3个月', '2026-02-28', '2026-05-31', TimeRangeResolver::GRANULARITY_ROLLING, '2026-05-31 12:00:00'],
            '闰年夹取_近1年'   => ['近1年', '2023-02-28', '2024-02-29', TimeRangeResolver::GRANULARITY_ROLLING, '2024-02-29 12:00:00'],
        ];
    }

    /**
     * @dataProvider absoluteCases
     */
    public function testAbsoluteExpressions(string $expr, string $start, string $end, string $granularity): void
    {
        $range = $this->resolver()->resolve($expr);

        $this->assertSame($start, $range['start'], "{$expr} 起始");
        $this->assertSame($end, $range['end'], "{$expr} 结束");
        $this->assertSame($granularity, $range['granularity']);
    }

    /**
     * @return array<string, array{0:string,1:string,2:string,3:string}>
     */
    public static function absoluteCases(): array
    {
        return [
            '绝对月'       => ['2026-09', '2026-09-01', '2026-09-30', TimeRangeResolver::GRANULARITY_MONTH],
            '绝对月_单位数' => ['2026-9', '2026-09-01', '2026-09-30', TimeRangeResolver::GRANULARITY_MONTH],
            '绝对月_闰年'   => ['2024-02', '2024-02-01', '2024-02-29', TimeRangeResolver::GRANULARITY_MONTH],
            '绝对年'       => ['2026', '2026-01-01', '2026-12-31', TimeRangeResolver::GRANULARITY_YEAR],
            '绝对年_年'     => ['2026年', '2026-01-01', '2026-12-31', TimeRangeResolver::GRANULARITY_YEAR],
            '绝对年_年度'   => ['2026年度', '2026-01-01', '2026-12-31', TimeRangeResolver::GRANULARITY_YEAR],
            '绝对区间'     => ['2026-09-01~2026-09-30', '2026-09-01', '2026-09-30', TimeRangeResolver::GRANULARITY_RANGE],
            '绝对区间_波浪' => ['2026-09-01～2026-09-30', '2026-09-01', '2026-09-30', TimeRangeResolver::GRANULARITY_RANGE],
            '绝对区间_至'   => ['2026-09-01 至 2026-09-30', '2026-09-01', '2026-09-30', TimeRangeResolver::GRANULARITY_RANGE],
            '单个日期'     => ['2026-09-01', '2026-09-01', '2026-09-01', TimeRangeResolver::GRANULARITY_DAY],
            '跨月区间'     => ['2026-09-28~2026-10-03', '2026-09-28', '2026-10-03', TimeRangeResolver::GRANULARITY_RANGE],
        ];
    }

    public function testInvalidExpressionIsRejectedNotGuessed(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->resolver()->resolve('随便什么时候');
    }

    public function testSupports(): void
    {
        $resolver = $this->resolver();

        $this->assertTrue($resolver->supports('上月'));
        $this->assertTrue($resolver->supports('近15天'));
        $this->assertTrue($resolver->supports('2026-09'));
        $this->assertFalse($resolver->supports('下个月'));
        $this->assertFalse($resolver->supports(''));
    }

    public function testRollingDaysIsConfigurable(): void
    {
        $this->assertSame('2026-10-16', $this->resolver()->resolve('近1天')['start']);
        $this->assertSame('2026-10-16', $this->resolver()->resolve('近1天')['end']);
        $this->assertSame('2026-10-10', $this->resolver()->resolve('近7天')['start']);
        $this->assertSame('2026-09-01', $this->resolver()->resolve('近46天')['start']);
    }


    public function testColumnTypeAdaptation(): void
    {
        $range = $this->resolver()->resolve('上月');

        $this->assertSame('2026-09-01,2026-09-30', $this->resolver()->format($range, 'date'));
        $this->assertSame(
            '2026-09-01 00:00:00,2026-09-30 23:59:59',
            $this->resolver()->format($range, 'datetime')
        );
        $this->assertSame(
            '2026-09-01 00:00:00,2026-09-30 23:59:59',
            $this->resolver()->format($range, 'datetime(3)')
        );
        $this->assertSame(
            '2026-09-01 00:00:00,2026-09-30 23:59:59',
            $this->resolver()->format($range, 'timestamp')
        );
    }

    public function testColumnFormat(): void
    {
        $resolver = $this->resolver();

        $this->assertSame('date', $resolver->columnFormat('date'));
        $this->assertSame('datetime', $resolver->columnFormat('datetime'));
        $this->assertSame('datetime', $resolver->columnFormat('int(11)'));
        $this->assertSame('datetime', $resolver->columnFormat(''));
    }

    public function testDescribeEchoesResolvedRange(): void
    {
        $range = $this->resolver()->resolve('上月');

        $text = $this->resolver()->describe($range);

        $this->assertStringContainsString('上月', $text);
        $this->assertStringContainsString('2026-09-01', $text);
        $this->assertStringContainsString('2026-09-30', $text);
        $this->assertStringContainsString('共 30 天', $text);
    }


    public function testDoubleChannelPrefersExpression(): void
    {
        $resolver = $this->resolver();

        $range = $resolver->resolveRange('上月', '2000-01-01', '2000-01-31');
        $this->assertSame('2026-09-01', $range['start']);

        $absolute = $resolver->resolveRange(null, '2026-09-01', '2026-09-30');
        $this->assertSame('2026-09-01', $absolute['start']);
        $this->assertSame('2026-09-30', $absolute['end']);
        $this->assertSame('2026-09-01 00:00:00', $absolute['start_time']);
        $this->assertSame('2026-09-30 23:59:59', $absolute['end_time']);

        $single = $resolver->resolveRange(null, '2026-09-05', null);
        $this->assertSame('2026-09-05', $single['start']);
        $this->assertSame('2026-09-05', $single['end']);

        $swapped = $resolver->resolveRange(null, '2026-09-30', '2026-09-01');
        $this->assertSame('2026-09-01', $swapped['start']);

        $this->assertNull($resolver->resolveRange(null, null, null));
        $this->assertNull($resolver->resolveRange('', '', ''));
    }

    public function testTimeZoneIsHonored(): void
    {
        $utc = new TimeRangeResolver('2026-10-16 23:30:00', 'UTC');

        $this->assertSame('2026-10-16', $utc->resolve('今天')['start']);
        $this->assertSame('2026-10-16', $utc->resolve('今天', 'UTC')['start']);
    }

    public function testExpressionsConstantIsTheSchemaSource(): void
    {
        foreach (TimeRangeResolver::EXPRESSIONS as $expr) {
            $this->assertTrue($this->resolver()->supports($expr), "{$expr} 应可解析");
        }

        $this->assertContains('上月', TimeRangeResolver::EXPRESSIONS);
        $this->assertContains('近7天', TimeRangeResolver::EXPRESSIONS);
    }
}
