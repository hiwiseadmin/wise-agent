<?php
declare(strict_types=1);

namespace wise\agent\query;

/**
 * 查询字段条目（A3-1）
 *
 * 「可筛选维度」的最小单元：一个对外键名 + 它的真实列、中文标签、类型、可用操作符、
 * 枚举候选、关联信息、排序/筛选能力。
 *
 * ## 为什么键名与列名要分开
 *
 * LLM 只能用 `key`（对外键名），编译期再映射回 `column`：
 *   - 别名/逻辑字段（配置 `aliases` 把 `amount` 指向 `total_amount`）不会污染真实列名；
 *   - `key` 是工具 Schema 里的 `enum` 约束项，改名（如 `region`）不需要动数据库。
 *
 * ## 操作符为什么按类型裁剪
 *
 * 白名单**取自 `RequestQueryBuilder::$operatorMap`**（`app/common/library/RequestQueryBuilder.php:145-174`）
 * 并**排除 `exists` / `notexists`** —— 它们在 `$allowedRawOperators`（L179-184）里被注释掉，
 * 实际不可用（附录三 现状事实 2）。裁剪到类型是为了让 LLM 在解码阶段就选不到无意义的组合
 * （如对 `text` 用 `gt`、对 `bool` 用 `like`），而不是等编译期再拒绝。
 *
 * ⚠️ 与 Builder 的两个已知行为相关（附录三 现状事实 3）：
 *   - `between` 只给 1 个值时 Builder 会**静默退化为 `>=`** → A3 编译期必须自己校验恰好 2 值；
 *   - `like` Builder 会自动补 `%` → A3 编译期**不要**再加 `%`（否则 `%%v%%`）。
 * 本类只声明「该类型允许哪些操作符」，上述校验由 A3-2 的编译层负责。
 */
final class QueryField implements \JsonSerializable
{
    public const TYPE_STRING   = 'string';
    public const TYPE_TEXT     = 'text';
    public const TYPE_INT      = 'int';
    public const TYPE_DECIMAL  = 'decimal';
    public const TYPE_BOOL     = 'bool';
    public const TYPE_DATE     = 'date';
    public const TYPE_DATETIME = 'datetime';
    public const TYPE_ENUM     = 'enum';
    public const TYPE_RELATION = 'relation';

    /** 全部操作符（= `$operatorMap` 去掉 exists/notexists） */
    public const ALL_OPERATORS = [
        'eq', 'ne', 'neq',
        'gt', 'lt', 'gte', 'ge', 'lte', 'le',
        'like', 'notlike',
        'in', 'notin',
        'between', 'notbetween',
        'null', 'notnull',
    ];

    /**
     * 类型 → 可用操作符子集
     *
     * `date` / `datetime` 刻意不含 `gt` / `lt`：时间维度的直觉语义是「区间 / 不早于 / 不晚于」，
     * 且后台页面筛选器也只提供这三档。
     *
     * @var array<string, string[]>
     */
    public const OPERATORS_BY_TYPE = [
        self::TYPE_STRING => ['eq', 'ne', 'neq', 'like', 'notlike', 'in', 'notin', 'null', 'notnull'],
        self::TYPE_TEXT   => ['like', 'notlike', 'null', 'notnull'],
        self::TYPE_INT    => ['eq', 'ne', 'neq', 'gt', 'lt', 'gte', 'ge', 'lte', 'le', 'between', 'notbetween', 'in', 'notin', 'null', 'notnull'],
        self::TYPE_DECIMAL => ['eq', 'ne', 'neq', 'gt', 'lt', 'gte', 'ge', 'lte', 'le', 'between', 'notbetween', 'in', 'notin', 'null', 'notnull'],
        self::TYPE_BOOL   => ['eq', 'ne', 'neq'],
        self::TYPE_DATE   => ['eq', 'gte', 'ge', 'lte', 'le', 'between', 'notbetween', 'null', 'notnull'],
        self::TYPE_DATETIME => ['eq', 'gte', 'ge', 'lte', 'le', 'between', 'notbetween', 'null', 'notnull'],
        self::TYPE_ENUM   => ['eq', 'ne', 'neq', 'in', 'notin', 'null', 'notnull'],
        self::TYPE_RELATION => ['eq', 'ne', 'neq', 'in', 'notin'],
    ];

    /** 需要区间值的操作符 */
    private const RANGE_OPERATORS = ['between', 'notbetween'];

    /** 无值操作符 */
    private const VALUELESS_OPERATORS = ['null', 'notnull'];

    /**
     * @param string   $key       对外键名（LLM 唯一可见的过滤键）
     * @param string   $column    真实列名
     * @param string   $label     中文标签
     * @param string   $type      归一化类型（见 TYPE_* 常量）
     * @param string[] $operators 可用操作符（默认按类型推导）
     * @param array<int, array{value:mixed, label:string}> $enum 枚举候选
     * @param array{table:string, label_column:string, name:string}|array{} $relation 关联接地信息
     * @param string[] $aliases   同义词/别名（配置 + 标签）
     * @param string   $source    来源标记（db|form|comment|relation|model_search|config）
     */
    public function __construct(
        public readonly string $key,
        public readonly string $column,
        public readonly string $label = '',
        public string $type = self::TYPE_STRING,
        public array $operators = [],
        public array $enum = [],
        public array $relation = [],
        public bool $sortable = false,
        public bool $filterable = true,
        public bool $searchable = false,
        public array $aliases = [],
        public string $source = 'db',
        public string $rawType = '',
    ) {
        if ($this->operators === []) {
            $this->operators = self::operatorsFor($this->type);
        }
    }

    /**
     * 该类型的默认操作符子集
     *
     * @return string[]
     */
    public static function operatorsFor(string $type): array
    {
        return self::OPERATORS_BY_TYPE[$type] ?? self::OPERATORS_BY_TYPE[self::TYPE_STRING];
    }

    /**
     * 是否为时间类型（决定是否走 {@see TimeRangeResolver} 与列类型适配）
     */
    public function isTime(): bool
    {
        return $this->type === self::TYPE_DATE || $this->type === self::TYPE_DATETIME;
    }

    public function isRelation(): bool
    {
        return $this->type === self::TYPE_RELATION;
    }

    public function hasEnum(): bool
    {
        return $this->enum !== [];
    }

    /**
     * 操作符是否被允许（白名单 + 类型裁剪双重校验）
     */
    public function supports(string $operator): bool
    {
        return in_array(strtolower($operator), $this->operators, true);
    }

    public function needsRange(string $operator): bool
    {
        return in_array(strtolower($operator), self::RANGE_OPERATORS, true);
    }

    public function needsNoValue(string $operator): bool
    {
        return in_array(strtolower($operator), self::VALUELESS_OPERATORS, true);
    }

    /**
     * 关键词是否命中该字段（键名 / 列名 / 标签 / 别名，大小写与空白不敏感）
     *
     * `aliases`，于是「金额大于 1000」能被解析到 `amount`。
     */
    public function matches(string $keyword): bool
    {
        $needle = $this->normalize($keyword);
        if ($needle === '') {
            return false;
        }

        foreach (array_merge([$this->key, $this->column, $this->label], $this->aliases) as $candidate) {
            if ($this->normalize((string) $candidate) === $needle) {
                return true;
            }
        }

        return false;
    }

    /**
     * 按枚举 label / value 精确解析候选值
     *
     * @return array{value:mixed, label:string}|null
     */
    public function resolveEnum(string $keyword): ?array
    {
        $needle = $this->normalize($keyword);
        if ($needle === '' || $this->enum === []) {
            return null;
        }

        foreach ($this->enum as $candidate) {
            foreach ([$candidate['label'] ?? '', (string) ($candidate['value'] ?? '')] as $text) {
                if ($this->normalize((string) $text) === $needle) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    /**
     * LLM 可读的一行描述
     *
     * @param int $maxEnumValues 枚举候选展示上限（超出只给个数，附录三 §8）
     */
    public function describe(int $maxEnumValues = 16): string
    {
        $parts = [];

        $label = $this->label !== '' && $this->label !== $this->key ? "（{$this->label}）" : '';
        $parts[] = $this->key . $label;

        $parts[] = '类型:' . $this->type;

        if ($this->hasEnum()) {
            $values = $this->enumValues();
            if (count($values) <= $maxEnumValues) {
                $parts[] = '可选值:' . implode('|', $values);
            } else {
                $parts[] = sprintf('可选值:%d 个（用 lq_fields 查看）', count($values));
            }
        }

        if ($this->isRelation() && !empty($this->relation['table'])) {
            $parts[] = '关联:' . $this->relation['table'];
        }

        if ($this->sortable) {
            $parts[] = '可排序';
        }

        return implode(' ', $parts);
    }

    /**
     * 枚举候选的展示值（`label(value)` 形式，值省略时只给 label）
     *
     * @return string[]
     */
    public function enumValues(): array
    {
        $out = [];
        foreach ($this->enum as $candidate) {
            $label = (string) ($candidate['label'] ?? '');
            $value = $candidate['value'] ?? null;

            if ($label === '') {
                $out[] = (string) $value;
                continue;
            }

            if ((string) $value === $label) {
                $out[] = $label;
                continue;
            }

            $out[] = $label . '(' . $value . ')';
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key'        => $this->key,
            'column'     => $this->column,
            'label'      => $this->label,
            'type'       => $this->type,
            'operators'  => array_values($this->operators),
            'enum'       => $this->enum,
            'relation'   => $this->relation,
            'sortable'   => $this->sortable,
            'filterable' => $this->filterable,
            'searchable' => $this->searchable,
            'aliases'    => array_values($this->aliases),
            'source'     => $this->source,
            'raw_type'   => $this->rawType,
        ];
    }

    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        return $this->toArray();
    }

    private function normalize(string $value): string
    {
        return mb_strtolower(trim($value), 'UTF-8');
    }
}
