<?php
declare(strict_types=1);

namespace wise\agent\query;

use DateTimeImmutable;
use DateTimeZone;

/**
 * 时间词解析器（A3-1）
 *
 * 转成确定的 `[start, end]`。让 LLM 算日期必然出错，这是 A3 的硬边界之一。
 *
 * ## 口径（必须写清，否则单测没有依据）
 *
 * | 词 | 口径 |
 * |---|---|
 * | `今天/昨天/前天` | 单个自然日 |
 * | `本周/上周` | **周一 ~ 周日**（与后台筛选人的直觉一致） |
 * | `本月/上月` | 自然月；跨年自动进位（`2026-01` 的上月 = `2025-12`） |
 * | `本季度/上季度` | 自然季度（Q1=1~3 月…Q4=10~12 月），跨年自动进位 |
 * | `今年/去年` / `上半年/下半年` | 自然年 / 半年 |
 * | `近N天` | **含今天**的滚动区间：`今天-(N-1) ~ 今天` |
 * | `近3个月` / `近1年` | 含今天的滚动区间；起点做**月末夹取**（`2026-05-31` 近 3 个月 → `2026-02-28`，绝不溢出成 `03-03`） |
 * | `2026-09` / `2026年度` / `2026-09-01~2026-09-30` | 绝对词 |
 *
 * ## 返回结构与列类型适配（验收 1 / 2）
 *
 * `resolve()` 同时给出**日级**与**秒级**边界，调用方按列类型取用（{@see self::format()}）：
 *   - `date` 列 → `2026-09-01,2026-09-30`
 *   - `datetime` 列 → `2026-09-01 00:00:00,2026-09-30 23:59:59`
 *
 * ## 时区
 *
 * 默认取 `config('app.default_timezone')`。输出是**本地墙钟时间**，与 `create_time` 这类
 * `datetime` 列的存储口径一致（数据库存的就是本地时间，不做 UTC 转换）。
 */
class TimeRangeResolver
{
    public const GRANULARITY_DAY     = 'day';
    public const GRANULARITY_WEEK    = 'week';
    public const GRANULARITY_MONTH   = 'month';
    public const GRANULARITY_QUARTER = 'quarter';
    public const GRANULARITY_YEAR    = 'year';
    public const GRANULARITY_ROLLING = 'rolling';
    public const GRANULARITY_RANGE   = 'range';

    /** 相对时间词全集（工具 Schema 的 `enum` 来源） */
    public const EXPRESSIONS = [
        '今天', '昨天', '前天',
        '本周', '上周',
        '本月', '上月',
        '本季度', '上季度',
        '今年', '去年',
        '上半年', '下半年',
        '近7天', '近30天', '近3个月', '近1年',
    ];

    /** 滚动区间的天数写法：`近7天` / `近15天` … */
    private const PATTERN_ROLLING_DAYS = '/^近(\d+)天$/u';

    /** 滚动区间的月数写法：`近3个月` / `近6个月` … */
    private const PATTERN_ROLLING_MONTHS = '/^近(\d+)个?月$/u';

    /** 滚动区间的年数写法：`近1年` / `近2年` … */
    private const PATTERN_ROLLING_YEARS = '/^近(\d+)年$/u';

    /** 绝对月：`2026-09` */
    private const PATTERN_ABSOLUTE_MONTH = '/^(\d{4})-(\d{1,2})$/';

    /** 绝对年：`2026` / `2026年` / `2026年度` */
    private const PATTERN_ABSOLUTE_YEAR = '/^(\d{4})(?:年|年度)?$/u';

    /** 绝对区间分隔符（`~` / `～` / `至` / `到`） */
    private const PATTERN_RANGE_SEPARATOR = '/\s*(?:~|～|至|到)\s*/u';

    private ?string $now;

    private ?string $timeZone;

    /**
     * @param string|null $now 基准时刻（`Y-m-d H:i:s`；null = 当前时刻）。单测注入以保证确定性
     * @param string|null $timeZone 时区（null = `config('app.default_timezone')`）
     */
    public function __construct(?string $now = null, ?string $timeZone = null)
    {
        $this->now      = $now;
        $this->timeZone = $timeZone;
    }

    /**
     * 解析相对/绝对时间词
     *
     * @param string      $expr 时间词（{@see self::EXPRESSIONS} 或 `近N天/近N个月/2026-09/2026-09-01~2026-09-30`）
     * @param string|null $timeZone 覆盖时区
     * @return array{expr:string, label:string, granularity:string, start:string, end:string, start_time:string, end_time:string, days:int}
     * @throws \InvalidArgumentException 无法识别的时间词（**绝不猜**）
     */
    public function resolve(string $expr, ?string $timeZone = null): array
    {
        $raw = trim($expr);
        if ($raw === '') {
            throw new \InvalidArgumentException('时间词不能为空');
        }

        $tz = new DateTimeZone($this->resolveTimeZone($timeZone));
        $now = $this->baseNow($tz);

        $range = $this->matchRelative($raw, $now, $tz)
            ?? $this->matchRolling($raw, $now)
            ?? $this->matchAbsolute($raw, $tz);

        if ($range === null) {
            throw new \InvalidArgumentException(sprintf(
                '无法识别的时间词「%s」；支持：%s、近N天/近N个月/近N年、2026-09、2026-09-01~2026-09-30',
                $raw,
                implode('/', self::EXPRESSIONS)
            ));
        }

        return $this->build($raw, $range[0], $range[1], $range[2]);
    }

    /**
     * 该时间词是否可被解析（不抛异常）
     */
    public function supports(string $expr): bool
    {
        try {
            $this->resolve($expr);

            return true;
        } catch (\InvalidArgumentException) {
            return false;
        }
    }

    /**
     * @return array{expr:string, label:string, granularity:string, start:string, end:string, start_time:string, end_time:string, days:int}|null
     */
    public function resolveRange(?string $expr, ?string $start = null, ?string $end = null, ?string $timeZone = null): ?array
    {
        $expr = $expr === null ? '' : trim($expr);
        if ($expr !== '') {
            return $this->resolve($expr, $timeZone);
        }

        $start = $start === null ? '' : trim($start);
        $end   = $end === null ? '' : trim($end);

        if ($start === '' && $end === '') {
            return null;
        }

        $start = $start === '' ? $end : $start;
        $end   = $end === '' ? $start : $end;

        $tz = new DateTimeZone($this->resolveTimeZone($timeZone));

        return $this->build(
            $start . '~' . $end,
            $this->parseMoment($start, $tz, false),
            $this->parseMoment($end, $tz, true),
            self::GRANULARITY_RANGE
        );
    }

    /**
     * 按列类型适配为 Builder 的 `between` 取值（验收 2）
     *
     * - `date` 列 → `2026-09-01,2026-09-30`
     * - `datetime` / `timestamp` 列 → `2026-09-01 00:00:00,2026-09-30 23:59:59`
     *
     * @param array{start_time:string, end_time:string} $range
     * @param string $rawType 列的原始类型（`FieldInfo::rawType`）或归一化类型
     */
    public function format(array $range, string $rawType): string
    {
        if ($this->columnFormat($rawType) === 'date') {
            return substr((string) $range['start_time'], 0, 10) . ',' . substr((string) $range['end_time'], 0, 10);
        }

        return $range['start_time'] . ',' . $range['end_time'];
    }

    /**
     * 列类型 → 边界格式（`date` | `datetime`）
     */
    public function columnFormat(string $rawType): string
    {
        $type = strtolower(trim($rawType));

        if ($type === 'date' || str_starts_with($type, 'date(')) {
            return 'date';
        }

        return 'datetime';
    }

    /**
     * @param array<string, mixed> $range
     */
    public function describe(array $range): string
    {
        return sprintf(
            '%s → %s ~ %s（%s，共 %d 天）',
            (string) ($range['label'] ?? $range['expr'] ?? ''),
            substr((string) ($range['start_time'] ?? ''), 0, 10),
            substr((string) ($range['end_time'] ?? ''), 0, 10),
            (string) ($range['granularity'] ?? ''),
            (int) ($range['days'] ?? 0)
        );
    }


    /**
     * @return array{0:DateTimeImmutable, 1:DateTimeImmutable, 2:string}|null
     */
    private function matchRelative(string $raw, DateTimeImmutable $now, DateTimeZone $tz): ?array
    {
        return match ($raw) {
            '今天'   => [$now->setTime(0, 0, 0), $now->setTime(23, 59, 59), self::GRANULARITY_DAY],
            '昨天'   => [$this->day($now, -1), $this->day($now, -1)->setTime(23, 59, 59), self::GRANULARITY_DAY],
            '前天'   => [$this->day($now, -2), $this->day($now, -2)->setTime(23, 59, 59), self::GRANULARITY_DAY],

            '本周'   => [$this->weekStart($now), $this->weekStart($now)->modify('+6 days')->setTime(23, 59, 59), self::GRANULARITY_WEEK],
            '上周'   => [$this->weekStart($now)->modify('-7 days'), $this->weekStart($now)->modify('-1 days')->setTime(23, 59, 59), self::GRANULARITY_WEEK],

            '本月'   => [$now->modify('first day of this month')->setTime(0, 0, 0), $now->modify('last day of this month')->setTime(23, 59, 59), self::GRANULARITY_MONTH],
            '上月'   => [$now->modify('first day of last month')->setTime(0, 0, 0), $now->modify('last day of last month')->setTime(23, 59, 59), self::GRANULARITY_MONTH],

            '本季度' => $this->quarterRange($now, 0),
            '上季度' => $this->quarterRange($now, -1),

            '今年'   => $this->yearRange((int) $now->format('Y'), $tz),
            '去年'   => $this->yearRange((int) $now->format('Y') - 1, $tz),

            '上半年' => [$this->moment(sprintf('%d-01-01', (int) $now->format('Y')), $tz, false), $this->moment(sprintf('%d-06-30', (int) $now->format('Y')), $tz, true), self::GRANULARITY_YEAR],
            '下半年' => [$this->moment(sprintf('%d-07-01', (int) $now->format('Y')), $tz, false), $this->moment(sprintf('%d-12-31', (int) $now->format('Y')), $tz, true), self::GRANULARITY_YEAR],

            default  => null,
        };
    }

    /**
     * @return array{0:DateTimeImmutable, 1:DateTimeImmutable, 2:string}|null
     */
    private function matchRolling(string $raw, DateTimeImmutable $now): ?array
    {
        if (preg_match(self::PATTERN_ROLLING_DAYS, $raw, $m) === 1) {
            $days = max(1, (int) $m[1]);
            return [$this->day($now, -($days - 1)), $now->setTime(23, 59, 59), self::GRANULARITY_ROLLING];
        }

        if (preg_match(self::PATTERN_ROLLING_MONTHS, $raw, $m) === 1) {
            $months = max(1, (int) $m[1]);

            return [$this->subMonthsClamped($now, $months), $now->setTime(23, 59, 59), self::GRANULARITY_ROLLING];
        }

        if (preg_match(self::PATTERN_ROLLING_YEARS, $raw, $m) === 1) {
            $years = max(1, (int) $m[1]);

            return [$this->subMonthsClamped($now, $years * 12), $now->setTime(23, 59, 59), self::GRANULARITY_ROLLING];
        }

        return null;
    }

    /**
     * @return array{0:DateTimeImmutable, 1:DateTimeImmutable, 2:string}|null
     */
    private function matchAbsolute(string $raw, DateTimeZone $tz): ?array
    {
        if (preg_match(self::PATTERN_RANGE_SEPARATOR, $raw) === 1) {
            $parts = preg_split(self::PATTERN_RANGE_SEPARATOR, $raw, 2);
            if ($parts === false || count($parts) !== 2) {
                return null;
            }

            [$left, $right] = [trim($parts[0]), trim($parts[1])];

            return [
                $this->parseMoment($left, $tz, false),
                $this->parseMoment($right, $tz, true),
                self::GRANULARITY_RANGE,
            ];
        }

        if (preg_match(self::PATTERN_ABSOLUTE_MONTH, $raw, $m) === 1) {
            $year  = (int) $m[1];
            $month = (int) $m[2];

            if ($month < 1 || $month > 12) {
                return null;
            }

            return $this->monthRange($year, $month, $tz);
        }

        if (preg_match(self::PATTERN_ABSOLUTE_YEAR, $raw, $m) === 1) {
            return $this->yearRange((int) $m[1], $tz);
        }

        if (preg_match('/^\d{4}-\d{1,2}-\d{1,2}(?:[ T]\d{1,2}:\d{1,2}(?::\d{1,2})?)?$/', $raw) === 1) {
            $start = $this->parseMoment($raw, $tz, false);

            return [$start, $start->setTime(23, 59, 59), self::GRANULARITY_DAY];
        }

        return null;
    }


    /**
     * @return array{expr:string, label:string, granularity:string, start:string, end:string, start_time:string, end_time:string, days:int}
     */
    private function build(string $expr, DateTimeImmutable $start, DateTimeImmutable $end, string $granularity): array
    {
        if ($end < $start) {
            [$start, $end] = [$end, $start];
        }

        return [
            'expr'        => $expr,
            'label'       => $expr,
            'granularity' => $granularity,
            'start'       => $start->format('Y-m-d'),
            'end'         => $end->format('Y-m-d'),
            'start_time'  => $start->format('Y-m-d H:i:s'),
            'end_time'    => $end->format('Y-m-d H:i:s'),
            'days'        => (int) $start->diff($end)->days + 1,
        ];
    }

    /**
     * @return array{0:DateTimeImmutable, 1:DateTimeImmutable, 2:string}
     */
    private function quarterRange(DateTimeImmutable $now, int $offset): array
    {
        $year    = (int) $now->format('Y');
        $quarter = intdiv((int) $now->format('n') - 1, 3) + 1 + $offset;

        while ($quarter < 1) {
            $quarter += 4;
            $year--;
        }
        while ($quarter > 4) {
            $quarter -= 4;
            $year++;
        }

        $startMonth = ($quarter - 1) * 3 + 1;
        $endMonth   = $startMonth + 2;

        $tz = $now->getTimezone();

        return [
            $this->moment(sprintf('%d-%02d-01', $year, $startMonth), $tz, false),
            $this->moment(sprintf('%d-%02d-01', $year, $endMonth), $tz, false)->modify('last day of this month')->setTime(23, 59, 59),
            self::GRANULARITY_QUARTER,
        ];
    }

    /**
     * @return array{0:DateTimeImmutable, 1:DateTimeImmutable, 2:string}
     */
    private function monthRange(int $year, int $month, DateTimeZone $tz): array
    {
        return [
            $this->moment(sprintf('%d-%02d-01', $year, $month), $tz, false),
            $this->moment(sprintf('%d-%02d-01', $year, $month), $tz, false)->modify('last day of this month')->setTime(23, 59, 59),
            self::GRANULARITY_MONTH,
        ];
    }

    /**
     * @return array{0:DateTimeImmutable, 1:DateTimeImmutable, 2:string}
     */
    private function yearRange(int $year, DateTimeZone $tz): array
    {
        return [
            $this->moment(sprintf('%d-01-01', $year), $tz, false),
            $this->moment(sprintf('%d-12-31', $year), $tz, true),
            self::GRANULARITY_YEAR,
        ];
    }

    /**
     * 今天所在周的周一（`N`：1=周一 … 7=周日）
     */
    private function weekStart(DateTimeImmutable $now): DateTimeImmutable
    {
        $weekday = (int) $now->format('N');

        return $now->setTime(0, 0, 0)->modify('-' . ($weekday - 1) . ' days');
    }

    private function day(DateTimeImmutable $now, int $offset): DateTimeImmutable
    {
        return $now->modify($offset >= 0 ? "+{$offset} days" : "{$offset} days")->setTime(0, 0, 0);
    }

    /**
     * 往前推 N 个自然月并**夹取到目标月的最后一天**
     *
     * 直接用 `modify('-3 months')` 会溢出：`2026-05-31 - 3 months` → `2026-03-03`
     * （PHP 的日期溢出语义），这不是用户想要的「近 3 个月」。故按年月计算后夹取日。
     */
    private function subMonthsClamped(DateTimeImmutable $now, int $months): DateTimeImmutable
    {
        $year  = (int) $now->format('Y');
        $month = (int) $now->format('n') - $months;

        while ($month < 1) {
            $month += 12;
            $year--;
        }

        $day         = (int) $now->format('j');
        $daysInMonth = (int) (new DateTimeImmutable(sprintf('%d-%02d-01', $year, $month), $now->getTimezone()))
            ->modify('last day of this month')
            ->format('j');

        return $this->moment(
            sprintf('%d-%02d-%02d', $year, $month, min($day, $daysInMonth)),
            $now->getTimezone(),
            false
        );
    }

    /**
     * 解析一个「日期 / 日期时间」片段；$endOfDay 决定缺省时刻取 00:00:00 还是 23:59:59
     */
    private function parseMoment(string $value, DateTimeZone $tz, bool $endOfDay): DateTimeImmutable
    {
        $value = trim($value);

        if (preg_match('/^\d{4}-\d{1,2}-\d{1,2}$/', $value) === 1) {
            return $this->moment($value, $tz, $endOfDay);
        }

        $moment = $this->moment($value, $tz, false);

        if (preg_match('/\d{1,2}:\d{1,2}/', $value) === 1) {
            return $moment;
        }

        return $endOfDay ? $moment->setTime(23, 59, 59) : $moment;
    }

    /**
     * 宽松构造时刻（接受 `Y-m-d` / `Y-m-d H:i` / `Y-m-d H:i:s`）
     *
     * ⚠️ 格式串**必须以 `!` 开头**：否则 `createFromFormat('Y-m-d', '2026-02-28')` 会把未指定的
     * 时/分/秒填成**当前时刻**（PHP 的既有语义），导致 `2026-02-28 16:15:18` 这种脏边界。
     * 这个坑在单测里暴露过（上游验收 1 也由此守住）。
     */
    private function moment(string $value, DateTimeZone $tz, bool $endOfDay): DateTimeImmutable
    {
        $value   = str_replace('T', ' ', trim($value));
        $withSec = substr_count($value, ':') >= 2;
        $pattern = $withSec
            ? '!Y-m-d H:i:s'
            : (str_contains($value, ':') ? '!Y-m-d H:i' : '!Y-m-d');

        $moment = DateTimeImmutable::createFromFormat($pattern, $value, $tz);

        if ($moment === false) {
            $moment = new DateTimeImmutable($value, $tz);
        }

        return $endOfDay && !str_contains($value, ':') ? $moment->setTime(23, 59, 59) : $moment;
    }

    private function baseNow(DateTimeZone $tz): DateTimeImmutable
    {
        if ($this->now === null || trim($this->now) === '') {
            return new DateTimeImmutable('now', $tz);
        }

        return new DateTimeImmutable($this->now, $tz);
    }

    private function resolveTimeZone(?string $timeZone): string
    {
        $candidate = $timeZone ?? $this->timeZone;

        if ($candidate === null || trim($candidate) === '') {
            $candidate = $this->config('app.default_timezone', 'Asia/Shanghai');
        }

        return (string) $candidate;
    }

    private function config(string $key, mixed $default): mixed
    {
        if (!function_exists('config')) {
            return $default;
        }

        try {
            return config($key, $default) ?? $default;
        } catch (\Throwable) {
            return $default;
        }
    }
}
