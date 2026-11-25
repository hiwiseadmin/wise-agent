<?php
declare(strict_types=1);

namespace wise\agent\query;

use wise\agent\query\exception\QueryParamException;

/**
 * 查询意图（A3-2 中间表示 + 编译）
 *
 * ```
 * LLM 结构化意图（Function Calling 参数即约束解码）
 *   ▼  QueryIntent
 * 编译期（确定性代码，全程白名单）：字段解析 → 时间词 → 值接地 → 操作符校验
 *   ▼
 * query params（与 RequestQueryBuilder 同构的一维数组）
 * ```
 *
 * ## 编译期的两条铁律
 *
 * 1. **快速失败，绝不静默跳过**：未知字段 / 非法操作符 / 形状错误一律抛
 *    {@see QueryParamException}。`RequestQueryBuilder` 对未知字段与非法排序只记 debug 日志
 *    然后跳过（附录三 现状事实 4）—— 那会让 AI 以为「筛过了」。
 * 2. **产出的是真实列名**：`QueryField::key` 只用于 LLM 侧与校验，写进参数字典的必须是
 *    `QueryField::column`，否则配置里的别名（`amount` → `total_amount`）会拼出
 *    `amount >= 1000` 这种指向不存在列的 SQL。
 *
 * | 场景 | 处理 |
 * |---|---|
 * | `op=eq` | 产出**裸键** `{column}=值`（Builder 无 `__` 即按等值处理，L436-439） |
 * | `op=between` 只给 1 值 | **拒绝**（Builder 会静默退化为 `>=`，L799-807） |
 * | `op=like` | **不补 `%`**（Builder 自动补，L810-816；重复补会变 `%%v%%`） |
 * | `op=null` / `notnull` | 产出 `{column}__null=1` —— 值必须非空，否则被 L419 静默跳过 |
 * | `logic=or` | `_or[i]['{column}__{op}']=值`（第二层键是「列名__操作符」，见 L461-485） |
 * | `group/having/with_count/exists` | **恒不产出** |
 */
final class QueryIntent implements \JsonSerializable
{
    public const LOGIC_AND = 'and';
    public const LOGIC_OR  = 'or';

    /** `fromToolArgs` 认识的顶层键（其余键被忽略并记入 plan，不报错） */
    private const KNOWN_KEYS = ['filters', 'logic', 'orders', 'fields', 'page', 'limit'];

    /** @var QueryFilter[] */
    public array $filters = [];

    public string $logic = self::LOGIC_AND;

    /** @var QueryOrder[] */
    public array $orders = [];

    /** @var string[] */
    public array $fields = [];

    public int $page = 1;

    public int $limit = 20;

    /** 请求的 limit（夹取前，用于 plan 回显） */
    private ?int $requestedLimit = null;

    /** @var array<string, mixed> */
    private array $params = [];

    /** @var array<string, mixed> */
    private array $plan = [];

    /** @var string[] */
    private array $notes = [];

    /** @var array<string, mixed> */
    private array $options;

    /**
     * @param array<string, mixed> $options `list_query.*` 覆盖项
     */
    public function __construct(array $options = [])
    {
        $this->options = $options;
    }

    /**
     * 从工具参数构造（**强校验**，非法即抛 `QueryParamException`）
     *
     * @param array<string, mixed> $args
     * @param array<string, mixed> $options
     * @throws QueryParamException
     */
    public static function fromToolArgs(array $args, array $options = []): self
    {
        $intent = new self($options);

        foreach (array_keys($args) as $key) {
            if (!in_array((string) $key, self::KNOWN_KEYS, true)) {
                $intent->notes[] = sprintf('忽略未知参数 %s', $key);
            }
        }

        $logic = strtolower(trim((string) ($args['logic'] ?? self::LOGIC_AND)));
        if ($logic === '') {
            $logic = self::LOGIC_AND;
        }
        if (!in_array($logic, [self::LOGIC_AND, self::LOGIC_OR], true)) {
            throw new QueryParamException(sprintf('logic 只允许 and/or（收到 %s）', $logic));
        }
        $intent->logic = $logic;

        $rawFilters = $args['filters'] ?? [];
        if ($rawFilters !== null && $rawFilters !== [] && !is_array($rawFilters)) {
            throw new QueryParamException('filters 必须是数组');
        }

        $rawFilters = is_array($rawFilters) ? array_values($rawFilters) : [];
        $maxFilters = $intent->optInt('max_filters', 12);

        if (count($rawFilters) > $maxFilters) {
            throw new QueryParamException(sprintf(
                'filters 数量 %d 超过上限 %d；请拆分为多次查询或减少条件',
                count($rawFilters),
                $maxFilters
            ));
        }

        foreach ($rawFilters as $index => $filter) {
            if (!is_array($filter)) {
                throw new QueryParamException(sprintf('filters[%d] 必须是对象', $index));
            }

            $intent->filters[] = QueryFilter::fromArray($filter);
        }

        $rawOrders = $args['orders'] ?? [];
        if ($rawOrders !== null && $rawOrders !== [] && !is_array($rawOrders)) {
            throw new QueryParamException('orders 必须是数组');
        }

        foreach (is_array($rawOrders) ? array_values($rawOrders) : [] as $index => $order) {
            if (!is_array($order)) {
                throw new QueryParamException(sprintf('orders[%d] 必须是对象', $index));
            }

            $intent->orders[] = QueryOrder::fromArray($order);
        }

        $rawFields = $args['fields'] ?? [];
        if ($rawFields !== null && $rawFields !== '' && !is_array($rawFields)) {
            throw new QueryParamException('fields 必须是数组');
        }

        foreach (is_array($rawFields) ? array_values($rawFields) : [] as $field) {
            if (!is_scalar($field) || trim((string) $field) === '') {
                throw new QueryParamException('fields 的元素必须是非空字符串');
            }
            $intent->fields[] = trim((string) $field);
        }

        $maxFields = $intent->optInt('max_fields', 20);
        if (count($intent->fields) > $maxFields) {
            throw new QueryParamException(sprintf('fields 数量超过上限 %d', $maxFields));
        }

        $page = $args['page'] ?? 1;
        if (!is_scalar($page) || !is_numeric((string) $page)) {
            throw new QueryParamException('page 必须是数字');
        }
        $intent->page = max(1, (int) $page);

        $limit = $args['limit'] ?? null;
        if ($limit !== null) {
            if (!is_scalar($limit) || !is_numeric((string) $limit)) {
                throw new QueryParamException('limit 必须是数字');
            }
            $intent->requestedLimit = (int) $limit;
            $intent->limit          = max(1, (int) $limit);
        } else {
            $intent->limit = max(1, $intent->optInt('default_limit', 20));
        }

        return $intent;
    }

    /**
     * 编译为可交给 `RequestQueryBuilder` 的一维参数数组
     *
     * @return array<string, mixed>
     * @throws QueryParamException
     * @throws exception\AmbiguousValueException
     */
    public function compile(
        QueryFieldCatalog $catalog,
        QueryValueResolver $values,
        TimeRangeResolver $time
    ): array {
        $values->reset();

        $this->plan  = [];
        $this->params = [];

        $compiled = [];

        foreach ($this->filters as $index => $filter) {
            $entry = $this->compileFilter($filter, $index, $catalog, $values, $time);

            if ($this->logic === self::LOGIC_OR) {
                $compiled[] = [$entry['param_key'] => $entry['param_value']];
            } else {
                if (array_key_exists($entry['param_key'], $this->params)) {
                    throw new QueryParamException(sprintf(
                        '同一字段与操作符被重复筛选（%s）；请在意图里合并为一个条件',
                        $entry['param_key']
                    ));
                }
                $this->params[$entry['param_key']] = $entry['param_value'];
            }
        }

        if ($this->logic === self::LOGIC_OR && $compiled !== []) {
            $this->params['_or'] = $compiled;
        }

        $this->compileOrders($catalog);
        $this->compileFields($catalog);
        $this->compilePaging();

        $this->plan['logic']        = $this->logic;
        $this->plan['notes']        = $this->notes;
        $this->plan['grounding']    = $values->trace();
        $this->plan['params']       = $this->params;

        return $this->params;
    }

    /**
     * 编译轨迹（审计 / 前端展示 / 调试；含解析后的时间区间与接地结果）
     *
     * @return array<string, mixed>
     */
    public function plan(): array
    {
        return $this->plan;
    }

    /**
     * 编译产物（`compile()` 后有效）
     *
     * @return array<string, mixed>
     */
    public function params(): array
    {
        return $this->params;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'filters' => array_map(static fn(QueryFilter $f): array => $f->toArray(), $this->filters),
            'logic'   => $this->logic,
            'orders'  => array_map(static fn(QueryOrder $o): array => $o->toArray(), $this->orders),
            'fields'  => $this->fields,
            'page'    => $this->page,
            'limit'   => $this->limit,
        ];
    }

    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        return $this->toArray();
    }


    /**
     * @return array{param_key:string, param_value:mixed}
     * @throws QueryParamException
     */
    private function compileFilter(
        QueryFilter $filter,
        int $index,
        QueryFieldCatalog $catalog,
        QueryValueResolver $values,
        TimeRangeResolver $time
    ): array {
        $field = $this->resolveField($catalog, $filter->field, $index);

        if (!$field->filterable) {
            throw new QueryParamException(sprintf('字段 %s 不可用于筛选', $filter->field));
        }

        $op = $filter->op;

        if (!$field->supports($op)) {
            throw new QueryParamException(sprintf(
                '字段 %s 不支持操作符 %s（可用：%s）',
                $filter->field,
                $op,
                implode('/', $field->operators)
            ));
        }

        $column = $field->column;

        if ($field->needsNoValue($op)) {
            $entry = [
                'param_key'   => $column . '__' . $op,
                'param_value' => 1,
                'plan'        => [
                    'field'    => $field->key,
                    'column'   => $column,
                    'grounded' => 'none',
                    'value'    => null,
                ],
            ];
        } elseif ($field->needsRange($op)) {
            $entry = $this->compileRange($filter, $field, $column, $values, $time);
        } elseif ($filter->isListOperator()) {
            $entry = $this->compileList($filter, $field, $column, $values);
        } else {
            $entry = $this->compileSingle($filter, $field, $column, $values);
        }

        $this->plan['filters'][$index] = array_merge([
            'input_field' => $filter->field,
            'op'          => $op,
        ], $entry['plan']);

        return ['param_key' => $entry['param_key'], 'param_value' => $entry['param_value']];
    }

    /**
     * 单值条件（`eq` 产出裸键，其余带 `__op` 后缀）
     *
     * @return array{param_key:string, param_value:mixed, plan:array<string, mixed>}
     */
    private function compileSingle(
        QueryFilter $filter,
        QueryField $field,
        string $column,
        QueryValueResolver $values
    ): array {
        if (is_array($filter->value)) {
            throw new QueryParamException(sprintf(
                '字段 %s 操作符 %s 只接受单个值',
                $filter->field,
                $filter->op
            ));
        }

        $hit = $values->resolve($field, $filter->value);

        return [
            'param_key'   => $filter->op === 'eq' ? $column : $column . '__' . $filter->op,
            'param_value' => $hit['value'],
            'plan'        => [
                'field'    => $field->key,
                'column'   => $column,
                'grounded' => $hit['grounded'],
                'matched'  => $hit['matched'],
                'value'    => $hit['value'],
            ],
        ];
    }

    /**
     * `in` / `notin`：每个元素独立接地
     *
     * @return array{param_key:string, param_value:string, plan:array<string, mixed>}
     */
    private function compileList(
        QueryFilter $filter,
        QueryField $field,
        string $column,
        QueryValueResolver $values
    ): array {
        if (!is_array($filter->value)) {
            throw new QueryParamException(sprintf('字段 %s 操作符 %s 需要数组值', $filter->field, $filter->op));
        }

        $hit = $values->resolveList($field, $filter->value);

        return [
            'param_key'   => $column . '__' . $filter->op,
            'param_value' => implode(',', array_map(static fn($v): string => (string) $v, $hit['values'])),
            'plan'        => [
                'field'    => $field->key,
                'column'   => $column,
                'grounded' => $hit['grounded'],
                'values'   => $hit['values'],
            ],
        ];
    }

    /**
     * `between` / `notbetween`：时间字段走 {@see TimeRangeResolver}，其余必须恰好 2 值
     *
     * @return array{param_key:string, param_value:mixed, plan:array<string, mixed>}
     */
    private function compileRange(
        QueryFilter $filter,
        QueryField $field,
        string $column,
        QueryValueResolver $values,
        TimeRangeResolver $time
    ): array {
        if ($field->isTime() || $filter->hasTimeInput()) {
            if (!$field->isTime()) {
                throw new QueryParamException(sprintf(
                    '字段 %s 不是时间字段，但给了时间词/日期（请改为具体值）',
                    $filter->field
                ));
            }

            $range = $time->resolveRange($filter->timeExpr, $filter->timeStart, $filter->timeEnd);

            if ($range === null) {
                throw new QueryParamException(sprintf(
                    '字段 %s 使用 %s 时必须给出 time_expr 或 time_start/time_end',
                    $filter->field,
                    $filter->op
                ));
            }

            return [
                'param_key'   => $column . '__' . $filter->op,
                'param_value' => $time->format($range, $field->rawType),
                'plan'        => [
                    'field'      => $field->key,
                    'column'     => $column,
                    'grounded'   => 'time',
                    'time'       => [
                        'expr'  => $range['expr'],
                        'start' => $range['start_time'],
                        'end'   => $range['end_time'],
                        'days'  => $range['days'],
                        'label' => $range['label'],
                    ],
                    'value'      => $time->format($range, $field->rawType),
                ],
            ];
        }

        $rawValues = $filter->value;

        if (is_string($rawValues) && str_contains($rawValues, ',')) {
            $rawValues = array_map('trim', explode(',', $rawValues));
        }

        if (!is_array($rawValues)) {
            throw new QueryParamException(sprintf('字段 %s 操作符 %s 需要 2 个值（数组或 "小,大"）', $filter->field, $filter->op));
        }

        $values2 = array_values($rawValues);
        if (count($values2) !== 2) {
            throw new QueryParamException(sprintf(
                '字段 %s 操作符 %s 必须恰好 2 个值（收到 %d 个）；单值会被 Builder 静默退化为 >=',
                $filter->field,
                $filter->op,
                count($values2)
            ));
        }

        $hit = $values->resolveList($field, $values2);

        return [
            'param_key'   => $column . '__' . $filter->op,
            'param_value' => implode(',', array_map(static fn($v): string => (string) $v, $hit['values'])),
            'plan'        => [
                'field'    => $field->key,
                'column'   => $column,
                'grounded' => $hit['grounded'],
                'values'   => $hit['values'],
            ],
        ];
    }


    private function compileOrders(QueryFieldCatalog $catalog): void
    {
        if ($this->orders === []) {
            $this->plan['orders'] = [];

            return;
        }

        $parts = [];
        foreach ($this->orders as $index => $order) {
            $field = $this->resolveField($catalog, $order->field, $index, 'orders');

            if (!$field->sortable) {
                throw new QueryParamException(sprintf('字段 %s 不支持排序', $order->field));
            }

            $parts[] = $field->column . ' ' . $order->direction;

            $this->plan['orders'][] = [
                'input_field' => $order->field,
                'column'      => $field->column,
                'direction'   => $order->direction,
            ];
        }

        $this->params['order'] = implode(',', $parts);
    }

    private function compileFields(QueryFieldCatalog $catalog): void
    {
        if ($this->fields === []) {
            $this->plan['fields'] = [];

            return;
        }

        $columns = [];
        foreach ($this->fields as $index => $name) {
            $field = $this->resolveField($catalog, $name, $index, 'fields');
            $columns[] = $field->column;
        }

        $this->params['field']  = implode(',', $columns);
        $this->plan['fields']   = $columns;
    }

    private function compilePaging(): void
    {
        $configured = $this->optInt('max_limit', 100);
        $pageMax    = $this->paginateMaxRows();
        $max        = max(1, min($configured, $pageMax));

        $applied = max(1, min($this->limit, $max));

        $this->params['page']  = $this->page;
        $this->params['limit'] = $applied;

        $this->plan['limit'] = [
            'requested' => $this->requestedLimit,
            'applied'   => $applied,
            'max'       => $max,
        ];
        $this->plan['page'] = $this->page;
    }

    /**
     * `paginate.max_list_rows`（框架侧上限，默认 1000）
     */
    private function paginateMaxRows(): int
    {
        if (!function_exists('config')) {
            return 1000;
        }

        try {
            return max(1, (int) config('paginate.max_list_rows', 1000));
        } catch (\Throwable) {
            return 1000;
        }
    }


    /**
     * 键 → 字段（先精确、再同义词）；未命中即拒绝（验收 6：绝不留静默跳过）
     *
     * @throws QueryParamException
     */
    private function resolveField(QueryFieldCatalog $catalog, string $name, int $index, string $source = 'filters'): QueryField
    {
        $key   = trim($name);
        $field = $catalog->get($key) ?? $catalog->resolveAlias($key);

        if (!$field instanceof QueryField) {
            throw new QueryParamException(sprintf(
                '未知字段 %s[%d].field = %s；可用字段：%s',
                $source,
                $index,
                $key,
                implode('/', array_slice($catalog->filterableKeys(), 0, 12))
            ));
        }

        return $field;
    }

    private function optInt(string $key, int $default): int
    {
        if (array_key_exists($key, $this->options)) {
            return (int) $this->options[$key];
        }

        if (!function_exists('config')) {
            return $default;
        }

        try {
            return (int) (config('wise-agent.list_query.' . $key, $default) ?? $default);
        } catch (\Throwable) {
            return $default;
        }
    }
}
