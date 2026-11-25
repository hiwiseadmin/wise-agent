<?php
declare(strict_types=1);

namespace wise\agent\query;

use wise\agent\query\exception\AmbiguousValueException;
use wise\agent\query\exception\QueryParamException;

/**
 * 把「人说出来的词」变成「数据库里的值」。三级顺序，逐级下降，**永不猜**：
 *
 * | 级别 | 命中条件 | 解析方式 | 成本 |
 * |---|---|---|---|
 * | ① 枚举 | 字段有 `enum` 候选 | `label` 精确匹配 → 包含匹配 → `value` | 0 查询 |
 * | ② 关联 | 字段为 `relation` 类型 | 对关联表 `label_column` 做 `like '%词%'`（`limit 5`，带缓存） | 1 次查询 |
 * | ③ 自由值 | 其余 | 类型转换后原样作为值 | 0 |
 *
 * ## 三条硬规则
 *
 * 1. **歧义必须澄清**：命中 ≥2 个候选 → 抛 {@see AmbiguousValueException}（`code:2`），
 * 2. **不猜**：关联接地 0 命中时**不**回退成「第一个候选」，而是按自由值处理并记入 trace
 *    （结果为空比结果错好；且 trace 可回放「AI 是怎么理解这句话的」）。
 * 3. **不静默降级**：关联接地次数超过 `max_lookups` → 明确报错（而不是悄悄少查一次）。
 *
 * ## 与 Builder 静默行为的对抗
 *
 * `RequestQueryBuilder::buildWhere()` 会**跳过值为空的参数**（`RequestQueryBuilder.php:419`），
 * 因此空字符串取值在这里就要拦掉 —— 否则 AI 以为筛了、SQL 里其实没有这个条件
 * （附录三 现状事实 4 的同类陷阱）。
 */
class QueryValueResolver
{
    /** 接地级别 */
    public const GROUNDED_ENUM     = 'enum';
    public const GROUNDED_RELATION = 'relation';
    public const GROUNDED_LITERAL  = 'literal';

    /** 关联接地的候选查询上限（超过即判定为歧义，而不是截断后当唯一命中） */
    private const RELATION_CANDIDATE_LIMIT = 6;

    /** @var array<string, mixed> */
    private array $options;

    /** @var array<int, array{step:string, message:string}> */
    private array $trace = [];

    private int $lookups = 0;

    /**
     * @param array<string, mixed> $options `list_query.*` 覆盖项
     */
    public function __construct(array $options = [])
    {
        $this->options = $options;
    }

    /**
     * 接地单个取值
     *
     * @return array{value:mixed, grounded:string, matched:string}
     * @throws QueryParamException          空值 / 关联查询超限
     * @throws AmbiguousValueException      命中多个候选
     */
    public function resolve(QueryField $field, mixed $raw): array
    {
        if (!is_scalar($raw)) {
            throw new QueryParamException(sprintf('字段 %s 的取值必须是标量', $field->key));
        }

        $keyword = trim((string) $raw);

        if ($keyword === '') {
            throw new QueryParamException(sprintf(
                '字段 %s 的取值为空；空条件会被 RequestQueryBuilder 静默忽略，请给出具体值',
                $field->key
            ));
        }

        $enum = $this->resolveByEnum($field, $keyword);
        if ($enum !== null) {
            return $enum;
        }

        $relation = $this->resolveByRelation($field, $keyword);
        if ($relation !== null) {
            return $relation;
        }

        return [
            'value'    => $this->castValue($field, $keyword),
            'grounded' => self::GROUNDED_LITERAL,
            'matched'  => $keyword,
        ];
    }

    /**
     * 批量接地（`op=in|notin`：每个元素独立接地）
     *
     * @param array<int, mixed> $raws
     * @return array{values:array<int, mixed>, grounded:array<int, string>}
     * @throws QueryParamException
     * @throws AmbiguousValueException
     */
    public function resolveList(QueryField $field, array $raws): array
    {
        if ($raws === []) {
            throw new QueryParamException(sprintf('字段 %s 的 __in/__notin 取值不能为空数组', $field->key));
        }

        $values   = [];
        $grounded = [];

        foreach ($raws as $raw) {
            $hit        = $this->resolve($field, $raw);
            $values[]   = $hit['value'];
            $grounded[] = $hit['grounded'];
        }

        return ['values' => $values, 'grounded' => $grounded];
    }

    /**
     * 本次编译内已发生的关联接地次数
     */
    public function lookups(): int
    {
        return $this->lookups;
    }

    /**
     * @return array<int, array{step:string, message:string}>
     */
    public function trace(): array
    {
        return $this->trace;
    }

    /**
     * 重置本次编译的计数与轨迹（每个 intent 一次）
     */
    public function reset(): void
    {
        $this->trace   = [];
        $this->lookups = 0;
    }


    /**
     * @return array{value:mixed, grounded:string, matched:string}|null
     * @throws AmbiguousValueException
     */
    private function resolveByEnum(QueryField $field, string $keyword): ?array
    {
        if (!$field->hasEnum()) {
            return null;
        }

        $exact = $field->resolveEnum($keyword);
        if ($exact !== null) {
            $this->addTrace('value.enum', sprintf('%s：「%s」精确命中 %s', $field->key, $keyword, $this->stringify($exact['value'])));

            return [
                'value'    => $exact['value'],
                'grounded' => self::GROUNDED_ENUM,
                'matched'  => (string) ($exact['label'] !== '' ? $exact['label'] : $exact['value']),
            ];
        }

        $needle     = mb_strtolower($keyword, 'UTF-8');
        $candidates = [];

        foreach ($field->enum as $candidate) {
            $label = mb_strtolower((string) ($candidate['label'] ?? ''), 'UTF-8');
            $value = mb_strtolower((string) ($candidate['value'] ?? ''), 'UTF-8');

            if ($label !== '' && str_contains($label, $needle)) {
                $candidates[] = $candidate;
                continue;
            }

            if ($value !== '' && str_contains($value, $needle)) {
                $candidates[] = $candidate;
            }
        }

        if (count($candidates) > 1) {
            throw new AmbiguousValueException($field->key, $keyword, $candidates, self::GROUNDED_ENUM);
        }

        if (count($candidates) === 1) {
            $this->addTrace('value.enum', sprintf('%s：「%s」包含命中 %s', $field->key, $keyword, $this->stringify($candidates[0]['value'])));

            return [
                'value'    => $candidates[0]['value'],
                'grounded' => self::GROUNDED_ENUM,
                'matched'  => (string) ($candidates[0]['label'] !== '' ? $candidates[0]['label'] : $candidates[0]['value']),
            ];
        }

        $this->addTrace('value.enumMiss', sprintf('%s：「%s」未命中枚举候选', $field->key, $keyword));

        return null;
    }


    /**
     * @return array{value:mixed, grounded:string, matched:string}|null
     * @throws QueryParamException
     * @throws AmbiguousValueException
     */
    private function resolveByRelation(QueryField $field, string $keyword): ?array
    {
        if (!$field->isRelation()) {
            return null;
        }

        if (is_numeric($keyword) && (string) (int) $keyword === $keyword) {
            $this->addTrace('value.relationId', sprintf('%s：数值 %s 直接作为外键', $field->key, $keyword));

            return [
                'value'    => (int) $keyword,
                'grounded' => self::GROUNDED_RELATION,
                'matched'  => $keyword,
            ];
        }

        $table       = (string) ($field->relation['table'] ?? '');
        $labelColumn = (string) ($field->relation['label_column'] ?? '');

        if ($table === '' || $labelColumn === '') {
            $this->addTrace('value.relationSkip', sprintf('%s：缺少关联表信息，按自由值处理', $field->key));

            return null;
        }

        $this->guardLookups($field);

        $candidates = $this->loadRelationCandidates($table, $labelColumn, $keyword);

        if (count($candidates) > 1) {
            throw new AmbiguousValueException($field->key, $keyword, $candidates, self::GROUNDED_RELATION);
        }

        if (count($candidates) === 1) {
            $this->addTrace('value.relation', sprintf(
                '%s：「%s」→ %s.%s = %s',
                $field->key,
                $keyword,
                $table,
                $labelColumn,
                $this->stringify($candidates[0]['value'])
            ));

            return [
                'value'    => $candidates[0]['value'],
                'grounded' => self::GROUNDED_RELATION,
                'matched'  => (string) $candidates[0]['label'],
            ];
        }

        $this->addTrace('value.relationMiss', sprintf(
            '%s：%s 中未找到「%s」，按自由值处理（不猜候选）',
            $field->key,
            $table,
            $keyword
        ));

        return null;
    }

    /**
     * 关联候选（带缓存 + LIKE 通配符转义）
     *
     * @return array<int, array{value:mixed, label:string}>
     */
    private function loadRelationCandidates(string $table, string $labelColumn, string $keyword): array
    {
        $cacheKey = sprintf('wsa_lq_%s_%s_%s', $table, $labelColumn, md5($keyword));

        $cached = $this->cacheGet($cacheKey);
        if (is_array($cached)) {
            $this->addTrace('value.cache', $cacheKey);

            return $cached;
        }

        $candidates = $this->relationCandidates($table, $labelColumn, $keyword, self::RELATION_CANDIDATE_LIMIT);

        $this->cacheSet($cacheKey, $candidates);

        return $candidates;
    }

    /**
     * 关联表候选查询（`protected` 接缝：单测覆写即可离线覆盖 ② 级）
     *
     * @return array<int, array{value:mixed, label:string}>
     */
    protected function relationCandidates(string $table, string $labelColumn, string $keyword, int $limit): array
    {
        if (!$this->useDb()) {
            return [];
        }

        try {
            $rows = \think\facade\Db::name($table)
                ->where($labelColumn, 'like', '%' . $this->escapeLike($keyword) . '%')
                ->limit($limit)
                ->field($this->primaryKey() . ',' . $labelColumn)
                ->select()
                ->toArray();
        } catch (\Throwable $e) {
            $this->addTrace('value.relationError', $e->getMessage());

            return [];
        }

        $candidates = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $candidates[] = [
                'value' => $row[$this->primaryKey()] ?? null,
                'label' => (string) ($row[$labelColumn] ?? ''),
            ];
        }

        return $candidates;
    }

    /**
     * LIKE 通配符转义：`%` / `_` / `\` 都当字面量（否则关键词里的 `%` 会变成 wildcard）
     */
    protected function escapeLike(string $keyword): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $keyword);
    }

    protected function primaryKey(): string
    {
        return (string) $this->opt('relation_primary_key', 'id');
    }


    /**
     * @throws QueryParamException
     */
    private function castValue(QueryField $field, string $keyword): mixed
    {
        switch ($field->type) {
            case QueryField::TYPE_INT:
                if (!is_numeric($keyword)) {
                    throw new QueryParamException(sprintf('字段 %s 需要数字，收到「%s」', $field->key, $keyword));
                }

                return (int) $keyword;

            case QueryField::TYPE_DECIMAL:
                if (!is_numeric($keyword)) {
                    throw new QueryParamException(sprintf('字段 %s 需要数字，收到「%s」', $field->key, $keyword));
                }

                return $keyword;

            case QueryField::TYPE_BOOL:
                $normalized = mb_strtolower($keyword, 'UTF-8');

                return match (true) {
                    in_array($normalized, ['1', 'true', 'yes', 'on', '是', '启用'], true) => 1,
                    in_array($normalized, ['0', 'false', 'no', 'off', '否', '禁用'], true) => 0,
                    default => throw new QueryParamException(sprintf(
                        '字段 %s 是布尔值，只接受 1/0、true/false、是/否',
                        $field->key
                    )),
                };

            default:
                return $keyword;
        }
    }


    /**
     * @throws QueryParamException
     */
    private function guardLookups(QueryField $field): void
    {
        $max = max(0, (int) $this->opt('max_lookups', 3));

        if ($this->lookups >= $max) {
            throw new QueryParamException(sprintf(
                '关联接地次数已达上限 %d（list_query.max_lookups）；请减少关联字段的筛选条件（当前字段 %s）',
                $max,
                $field->key
            ));
        }

        $this->lookups++;
    }


    protected function cacheGet(string $key): mixed
    {
        if (!$this->useCache()) {
            return null;
        }

        try {
            return \think\facade\Cache::get($key);
        } catch (\Throwable) {
            return null;
        }
    }

    protected function cacheSet(string $key, mixed $value): void
    {
        if (!$this->useCache()) {
            return;
        }

        try {
            \think\facade\Cache::set($key, $value, max(1, (int) $this->opt('value_cache_ttl', 600)));
        } catch (\Throwable) {
        }
    }

    protected function useDb(): bool
    {
        return function_exists('app') && class_exists(\think\facade\Db::class);
    }

    protected function useCache(): bool
    {
        return function_exists('app') && class_exists(\think\facade\Cache::class);
    }

    private function opt(string $key, mixed $default): mixed
    {
        if (array_key_exists($key, $this->options)) {
            return $this->options[$key];
        }

        if (!function_exists('config')) {
            return $default;
        }

        try {
            return config('wise-agent.list_query.' . $key, $default) ?? $default;
        } catch (\Throwable) {
            return $default;
        }
    }

    private function stringify(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return is_scalar($value) ? (string) $value : gettype($value);
    }

    private function addTrace(string $step, string $message): void
    {
        $this->trace[] = ['step' => $step, 'message' => $message];
    }
}
