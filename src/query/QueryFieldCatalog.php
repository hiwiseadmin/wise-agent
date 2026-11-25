<?php
declare(strict_types=1);

namespace wise\agent\query;

use wise\agent\menu\MenuActionMeta;
use wise\agent\menu\MenuActionSchemaResolver;
use wise\agent\schema\attribute\AiSearchable;
use wise\agent\schema\dto\RelationInfo;
use wise\agent\schema\dto\TableInfo;

/**
 * 查询字段目录（A3-1）
 *
 * 在既有 `TableInfo` / `FieldInfo` / A1-1 三级回退链之上做**叠加**（不修改它们），
 *
 * ## 逐级叠加的来源（后面的覆盖前面的 label / 别名）
 *
 * | # | 来源 | 提供 |
 * |---|---|---|
 * | ① | A1-1 `MenuActionSchemaResolver::fields()`（`defineForm()` → `crudSchema()` → DB） | 键名、真实列、中文标签、类型、枚举、可排序、可筛选 |
 * | ② | 注释枚举 | A1-1 已解析进 `enum` / `enum_labels`（`1=启用 0=禁用`），此处直接消费 |
 * | ③ | `RelationInfo`（`belongsTo` / `hasOne`） | 关联字段（「华东区 → region_id」的接地依据） |
 * | ④ | `AiSearchable` 类属性 | 高优先搜索字段标记 |
 * | ⑤ | 模型 `search()` 方法体启发式 | 插件实际在用的筛选维度（手写列表页的经验信号） |
 * | ⑥ | 配置 `synonyms` / `aliases` | 同义词（`金额`→`amount`）与对外键名映射（`amount`→`total_amount`） |
 *
 * ## 与附录三的一处**有意偏离**
 *
 * 实现在这里只把 **`belongsTo` / `hasOne`** 变成**可筛选**字段，`hasMany` / `belongsToMany`
 * 只登记为**元数据**（{@see self::relations()}），不生成筛选字段。
 *
 * 原因（源码核验）：`RequestQueryBuilder` 没有任何 `exists` / `whereHas` 能力
 * —— `$allowedRawOperators` 把 `exists/not exists` 注释掉了（`RequestQueryBuilder.php:183`），
 * `with` 只做预载不参与 WHERE（附录三 现状事实 5）。若把 `hasMany` 也暴露成筛选字段，
 * 编译层只能拒绝或静默跳过 —— 两者都违背「不留静默跳过」的铁律。
 *
 * ## 可测试性
 * {@see self::build()} 是**纯函数**（全部输入显式传入），离线可完整单测；
 * {@see self::forController()} 只负责「收集输入」，容器/DB 访问集中在 `protected` 接缝
 * （`makeResolver` / `resolveModelClass` / `resolveSearchableFields` / `resolveModelSearchKeys` /
 * `resolveLabelColumn`），因此本类**刻意不加 `final`**（与 `MenuActionSchemaResolver` 同策略）。
 */
class QueryFieldCatalog implements \JsonSerializable
{
    /** A1-1 归一化类型 → QueryField 类型 */
    private const TYPE_MAP = [
        'integer' => QueryField::TYPE_INT,
        'number'  => QueryField::TYPE_DECIMAL,
        'boolean' => QueryField::TYPE_BOOL,
        'date'    => QueryField::TYPE_DATE,
        'datetime' => QueryField::TYPE_DATETIME,
        'enum'    => QueryField::TYPE_ENUM,
        'text'    => QueryField::TYPE_TEXT,
        'string'  => QueryField::TYPE_STRING,
        'upload'  => QueryField::TYPE_STRING,
    ];

    /** 关联表「展示列」的候选优先级（用于「华东区」这类关键词接地） */
    private const LABEL_COLUMN_CANDIDATES = ['name', 'title', 'username', 'nickname', 'label', 'code'];

    /** 可生成筛选字段的关系类型 */
    private const FILTERABLE_RELATIONS = ['belongsTo', 'hasOne'];

    /** @var array<string, QueryField> 对外键名 → 字段 */
    private array $fields = [];

    /** @var array<string, RelationInfo> 非筛选型关系（hasMany 等）仅作元数据 */
    private array $relations = [];

    /** @var array<int, array{step:string, message:string}> */
    private array $trace = [];

    private string $target = '';

    private string $table = '';

    private int $maxEnumValues;

    private int $maxFilterFields;

    /**
     * @param array<string, mixed> $options `list_query.*` 覆盖项
     */
    public function __construct(array $options = [])
    {
        $this->maxEnumValues   = max(1, (int) $this->opt($options, 'max_enum_values', 16));
        $this->maxFilterFields = max(1, (int) $this->opt($options, 'max_filter_fields', 20));
    }

    /**
     * 从控制器收集输入并装配目录（运行期入口）
     *
     * @param string               $controllerClass 控制器 FQCN
     * @param array<string, mixed> $options         `list_query.*` 覆盖项
     * @param TableInfo|null       $tableInfo       已有的表结构（插件侧来自 `SchemaIntrospector`）
     * @param array<string, string[]> $aliases      对外键名 → 真实列名（target 级配置）
     * @param array<string, string[]> $synonyms     键/列 → 同义词表（全局 + target 合并）
     */
    public static function forController(
        string $controllerClass,
        array $options = [],
        ?TableInfo $tableInfo = null,
        array $aliases = [],
        array $synonyms = []
    ): self {
        $catalog = new self($options);

        $resolver = $catalog->makeResolver($options);
        $fields   = $resolver->fields($controllerClass);

        $relations = $tableInfo instanceof TableInfo ? $tableInfo->relations : [];

        $modelClass = $catalog->resolveModelClass($controllerClass);

        return $catalog->build(
            target: $catalog->targetNameOf($controllerClass),
            sourceFields: $fields,
            relations: $relations,
            searchableFields: $catalog->resolveSearchableFields($modelClass),
            modelSearchKeys: $modelClass === null ? [] : $catalog->resolveModelSearchKeys($modelClass),
            aliases: $aliases,
            synonyms: $synonyms,
            table: $tableInfo instanceof TableInfo ? $tableInfo->name : $catalog->resolveTableName($modelClass),
        );
    }

    /**
     * 装配目录（纯函数，离线可测）
     *
     * @param array<string, array<string, mixed>> $sourceFields A1-1 `fields()` 的输出
     * @param RelationInfo[]                      $relations    表关系
     * @param string[]                            $searchableFields `AiSearchable` 标记的字段
     * @param string[]                            $modelSearchKeys  模型 `search()` 方法体里出现的筛选键
     * @param array<string, string[]>             $aliases      对外键名 → 真实列名
     * @param array<string, string[]>             $synonyms     键/列 → 同义词
     */
    public function build(
        string $target,
        array $sourceFields,
        array $relations = [],
        array $searchableFields = [],
        array $modelSearchKeys = [],
        array $aliases = [],
        array $synonyms = [],
        string $table = ''
    ): self {
        $this->target    = $target;
        $this->table     = $table;
        $this->fields    = [];
        $this->relations = [];
        $this->trace     = [];

        foreach ($sourceFields as $name => $def) {
            if (!is_array($def)) {
                continue;
            }

            $key   = (string) $name;
            $type  = self::TYPE_MAP[(string) ($def['type'] ?? 'string')] ?? QueryField::TYPE_STRING;
            $enum  = $this->enumCandidates($def['enum'] ?? [], $def['enum_labels'] ?? [], $type);

            if ($enum !== []) {
                $type = QueryField::TYPE_ENUM;
            }

            $sortable = !empty($def['sortable'])
                && !in_array($type, [QueryField::TYPE_TEXT, QueryField::TYPE_RELATION], true);

            $this->fields[$key] = new QueryField(
                key: $key,
                column: $key,
                label: (string) ($def['label'] ?? ''),
                type: $type,
                enum: $enum,
                sortable: $sortable,
                filterable: !empty($def['filterable']),
                source: (string) ($def['source'] ?? 'db'),
                rawType: (string) ($def['raw_type'] ?? ''),
            );
        }

        $this->addTrace('catalog.base', sprintf('基线字段 %d 个', count($this->fields)));

        $this->applyRelations($relations);

        foreach ($searchableFields as $field) {
            if (isset($this->fields[$field])) {
                $this->fields[$field]->searchable = true;
            }
        }
        if ($searchableFields !== []) {
            $this->addTrace('catalog.searchable', 'AiSearchable 标记 ' . count($searchableFields) . ' 个字段');
        }

        $hinted = 0;
        foreach ($modelSearchKeys as $field) {
            $field = (string) $field;
            if (!isset($this->fields[$field])) {
                continue;
            }
            $this->fields[$field]->filterable = true;
            $this->fields[$field]->source     = 'model_search';
            $hinted++;
        }
        if ($hinted > 0) {
            $this->addTrace('catalog.modelSearch', "模型 search() 提示筛选维度 {$hinted} 个");
        }

        $this->applyKeyAliases($aliases);

        $this->applySynonyms($synonyms);

        $this->addTrace('catalog.done', sprintf('目标 %s：字段 %d 个（可筛选 %d）', $target, count($this->fields), count($this->filterableKeys())));

        return $this;
    }


    /**
     * @return array<string, QueryField>
     */
    public function all(): array
    {
        return $this->fields;
    }

    public function get(string $key): ?QueryField
    {
        return $this->fields[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return isset($this->fields[$key]);
    }

    /**
     * @return string[]
     */
    public function keys(): array
    {
        return array_keys($this->fields);
    }

    /**
     * @return string[]
     */
    public function filterableKeys(): array
    {
        $keys = [];
        foreach ($this->fields as $key => $field) {
            if ($field->filterable) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * @return string[]
     */
    public function sortableKeys(): array
    {
        $keys = [];
        foreach ($this->fields as $key => $field) {
            if ($field->sortable) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * 高优先搜索字段；未显式标记时退化为所有字符串/文本字段
     *
     * @return string[]
     */
    public function searchableKeys(): array
    {
        $marked = [];
        foreach ($this->fields as $key => $field) {
            if ($field->searchable) {
                $marked[] = $key;
            }
        }

        if ($marked !== []) {
            return $marked;
        }

        $fallback = [];
        foreach ($this->fields as $key => $field) {
            if (in_array($field->type, [QueryField::TYPE_STRING, QueryField::TYPE_TEXT], true)) {
                $fallback[] = $key;
            }
        }

        return $fallback;
    }

    /**
     * 枚举候选
     *
     * @return array<int, array{value:mixed, label:string}>
     */
    public function enumOf(string $key): array
    {
        return $this->fields[$key]->enum ?? [];
    }

    /**
     * 非筛选型关系（hasMany / belongsToMany 等）——仅作元数据，**不生成筛选字段**
     *
     * @return array<string, RelationInfo>
     */
    public function relations(): array
    {
        return $this->relations;
    }

    public function target(): string
    {
        return $this->target;
    }

    public function tableName(): string
    {
        return $this->table;
    }

    /**
     * 同义词/别名解析：优先精确命中别名，其次「关键词包含」模糊命中
     *
     * ⚠️ 模糊命中**返回全部候选**（由调用方决定是否歧义），本方法不替调用方选第一个。
     *
     * @return QueryField[]
     */
    public function find(string $keyword): array
    {
        $keyword = trim($keyword);
        if ($keyword === '') {
            return [];
        }

        $exact = $this->resolveAlias($keyword);
        if ($exact !== null) {
            return [$exact];
        }

        $needle = mb_strtolower($keyword, 'UTF-8');
        $hits   = [];

        foreach ($this->fields as $field) {
            foreach (array_merge([$field->key, $field->label], $field->aliases) as $candidate) {
                $candidate = mb_strtolower(trim((string) $candidate), 'UTF-8');
                if ($candidate !== '' && ($candidate === $needle || str_contains($candidate, $needle))) {
                    $hits[] = $field;
                    break;
                }
            }
        }

        return $hits;
    }

    /**
     * 精确别名/同义词解析
     */
    public function resolveAlias(string $alias): ?QueryField
    {
        foreach ($this->fields as $field) {
            if ($field->matches($alias)) {
                return $field;
            }
        }

        return null;
    }

    public function maxEnumValues(): int
    {
        return $this->maxEnumValues;
    }


    /**
     * 注入 Prompt 的「目标 + 精简维度」文本（受字段数与字符数双重截断）
     *
     * 不注入完整枚举（受 {@see self::maxEnumValues()} 限制），完整目录由 `lq_fields` 按需拉取。
     */
    public function toPrompt(int $maxChars = 4000, ?int $maxFields = null): string
    {
        $maxFields = $maxFields ?? $this->maxFilterFields;

        $filterable = [];
        foreach ($this->fields as $key => $field) {
            if ($field->filterable) {
                $filterable[$key] = $field;
            }
        }

        if ($filterable === []) {
            return '';
        }

        $lines = [];
        $count = 0;

        foreach ($filterable as $key => $field) {
            if ($count >= $maxFields) {
                $lines[] = sprintf('    …其余 %d 个维度用 lq_fields 查看', count($filterable) - $maxFields);
                break;
            }

            $lines[] = '    ' . $field->describe($this->maxEnumValues);
            $count++;
        }

        $sortable = $this->sortableKeys();
        if ($sortable !== []) {
            $lines[] = '    可排序: ' . implode(', ', $sortable);
        }

        $header = $this->target . ($this->table !== '' ? "（表 {$this->table}）" : '');

        $text = $header . PHP_EOL . implode(PHP_EOL, $lines);

        if ($maxChars > 0 && mb_strlen($text, 'UTF-8') > $maxChars) {
            $this->addTrace('prompt.truncated', sprintf('精简维度 %d 字符超预算 %d，仅保留目标名', mb_strlen($text, 'UTF-8'), $maxChars));

            return $header . PHP_EOL . '    （维度清单超 Prompt 预算，请用 lq_fields 按需查看）';
        }

        return $text;
    }

    /**
     * @return array{target:string, table:string, fields:array<string, mixed>, relations:array<string, mixed>}
     */
    public function toArray(): array
    {
        $fields = [];
        foreach ($this->fields as $key => $field) {
            $fields[$key] = $field->toArray();
        }

        $relations = [];
        foreach ($this->relations as $name => $relation) {
            $relations[$name] = $relation->toArray();
        }

        return [
            'target'    => $this->target,
            'table'     => $this->table,
            'fields'    => $fields,
            'relations' => $relations,
        ];
    }

    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        return $this->toArray();
    }

    /**
     * @return array<int, array{step:string, message:string}>
     */
    public function trace(): array
    {
        return $this->trace;
    }


    /**
     * @param RelationInfo[] $relations
     */
    private function applyRelations(array $relations): void
    {
        foreach ($relations as $relation) {
            if (!$relation instanceof RelationInfo) {
                continue;
            }

            if (!in_array($relation->type, self::FILTERABLE_RELATIONS, true)) {
                $this->relations[$relation->name] = $relation;
                continue;
            }

            $key = $relation->foreignKey !== '' ? $relation->foreignKey : $relation->name;

            $existing = $this->fields[$key] ?? null;

            $this->fields[$key] = new QueryField(
                key: $key,
                column: $relation->foreignKey !== '' ? $relation->foreignKey : $relation->localKey,
                label: $existing?->label ?? $relation->name,
                type: QueryField::TYPE_RELATION,
                enum: [],
                relation: [
                    'name'         => $relation->name,
                    'table'        => $relation->targetTable,
                    'label_column' => $this->resolveLabelColumn($relation->targetTable),
                    'target_class' => $relation->targetClass,
                ],
                sortable: $relation->foreignKey !== '',
                filterable: true,
                searchable: $existing?->searchable ?? false,
                aliases: array_values(array_unique(array_merge(
                    $existing?->aliases ?? [],
                    [$relation->name]
                ))),
                source: 'relation',
                rawType: $existing?->rawType ?? '',
            );
        }

        if ($this->relations !== []) {
            $this->addTrace('catalog.relations', sprintf(
                '登记非筛选型关系 %d 个：%s',
                count($this->relations),
                implode(',', array_keys($this->relations))
            ));
        }
    }

    /**
     * 对外键名映射：`['amount' => 'total_amount']` 表示「对外暴露 amount，指向真实列 total_amount」
     *
     * @param array<string, mixed> $aliases
     */
    private function applyKeyAliases(array $aliases): void
    {
        foreach ($aliases as $publicKey => $column) {
            $publicKey = trim((string) $publicKey);
            $column    = trim((string) $column);

            if ($publicKey === '' || $column === '' || $publicKey === $column) {
                continue;
            }

            $source = $this->fields[$column] ?? $this->fields[$publicKey] ?? null;
            if (!$source instanceof QueryField) {
                $this->addTrace('alias.miss', "aliases.{$publicKey} → {$column} 未命中已声明字段，已忽略");
                continue;
            }

            unset($this->fields[$source->key]);

            $this->fields[$publicKey] = new QueryField(
                key: $publicKey,
                column: $column,
                label: $source->label,
                type: $source->type,
                operators: $source->operators,
                enum: $source->enum,
                relation: $source->relation,
                sortable: $source->sortable,
                filterable: $source->filterable,
                searchable: $source->searchable,
                aliases: array_values(array_unique(array_merge($source->aliases, [$source->key]))),
                source: $source->source,
                rawType: $source->rawType,
            );

            $this->addTrace('alias.applied', "aliases: {$publicKey} → {$column}");
        }
    }

    /**
     * 同义词：键或列命中即并入该字段的 `aliases`
     *
     * @param array<string, mixed> $synonyms
     */
    private function applySynonyms(array $synonyms): void
    {
        $applied = 0;

        foreach ($synonyms as $key => $words) {
            $key   = trim((string) $key);
            $words = is_array($words) ? $words : [$words];

            $field = $this->fields[$key] ?? null;

            if (!$field instanceof QueryField) {
                foreach ($this->fields as $candidate) {
                    if ($candidate->column === $key) {
                        $field = $candidate;
                        break;
                    }
                }
            }

            if (!$field instanceof QueryField) {
                $this->addTrace('synonym.miss', "synonyms.{$key} 未命中字段，已忽略");
                continue;
            }

            $merged = [];
            foreach (array_merge($field->aliases, $words) as $word) {
                $word = trim((string) $word);
                if ($word !== '') {
                    $merged[$word] = true;
                }
            }

            $field->aliases = array_keys($merged);
            $applied++;
        }

        if ($applied > 0) {
            $this->addTrace('synonym.applied', "同义词并入 {$applied} 个字段");
        }
    }

    /**
     * A1-1 的「字符串枚举 + 标签」→ `[{value,label}]`
     *
     * 值类型：全部为数字串时收敛为 int（与 `RequestQueryBuilder::parseValue()` 的类型推断一致），
     * 否则保留字符串。标签与值相同时省略标签，避免描述里出现 `已退款(已退款)`。
     *
     * @param mixed $values
     * @param mixed $labels
     * @return array<int, array{value:mixed, label:string}>
     */
    private function enumCandidates(mixed $values, mixed $labels, string $type): array
    {
        if (!is_array($values) || $values === []) {
            return [];
        }

        $values = array_values($values);
        $labels = is_array($labels) ? array_values($labels) : [];

        $allNumeric = true;
        foreach ($values as $value) {
            if (!is_scalar($value) || !is_numeric((string) $value) || (string) $value !== (string) (int) $value) {
                $allNumeric = false;
                break;
            }
        }

        $candidates = [];
        foreach ($values as $index => $value) {
            $cast  = $allNumeric ? (int) $value : (string) $value;
            $label = isset($labels[$index]) ? (string) $labels[$index] : (string) $value;

            $candidates[] = [
                'value' => $cast,
                'label' => $label === (string) $cast ? '' : $label,
            ];
        }

        return $candidates;
    }


    /**
     * A1-1 的字段解析器（可覆写以离线注入字段目录）
     *
     * @param array<string, mixed> $options
     */
    protected function makeResolver(array $options): MenuActionSchemaResolver
    {
        return new MenuActionSchemaResolver($options);
    }

    /**
     * 反射控制器的 `getModel()` 取模型类名
     */
    protected function resolveModelClass(string $controllerClass): ?string
    {
        if (!class_exists($controllerClass) || !$this->useContainer()) {
            return null;
        }

        try {
            $instance = app()->make($controllerClass, [], true);
            if (!is_object($instance) || !method_exists($instance, 'getModel')) {
                return null;
            }

            $method = new \ReflectionMethod($instance, 'getModel');
            $method->setAccessible(true);
            $model  = $method->invoke($instance);

            return is_object($model) ? get_class($model) : null;
        } catch (\Throwable $e) {
            $this->addTrace('model.failed', $e->getMessage());

            return null;
        }
    }

    /**
     * 模型表名（**不含前缀**，与 `TableInfo::name` 口径一致）
     *
     * ⚠️ 不能用 `method_exists($model, 'getTable')` 判断：`getTable()` 在 `think\Model` 上
     * **并不真实存在**，它是经 `Model::__call()` 转发到 Query 对象的（所以直接调用能成功、
     * `method_exists` 却是 false）。踩过：用 `method_exists` 守卫导致表名恒为空字符串。
     */
    protected function resolveTableName(?string $modelClass): string
    {
        if ($modelClass === null || !class_exists($modelClass)) {
            return '';
        }

        try {
            $model = new $modelClass();
            if (!is_object($model) || !is_callable([$model, 'getTable'])) {
                return '';
            }

            $table  = (string) $model->getTable();
            $prefix = $this->dbPrefix();

            if ($prefix !== '' && str_starts_with($table, $prefix)) {
                $table = substr($table, strlen($prefix));
            }

            return $table;
        } catch (\Throwable $e) {
            $this->addTrace('table.failed', $e->getMessage());

            return '';
        }
    }

    /**
     * 当前连接的 DB 表前缀
     *
     * TP8 的 `Db::getConfig()` 返回的是**整个 database 配置**（`default` / `connections` / …），
     * 因此 `getConfig('prefix')` 取不到值 —— 前缀在 `connections.{default}.prefix`。
     * 必须正确剥离：`Db::name()` 会自动补前缀，若传入带前缀的表名会拼成 `ws_ws_menus`。
     */
    protected function dbPrefix(): string
    {
        if (!$this->useDb()) {
            return '';
        }

        try {
            $config = (array) \think\facade\Db::getConfig();

            if (isset($config['prefix']) && is_string($config['prefix']) && $config['prefix'] !== '') {
                return $config['prefix'];
            }

            $default = (string) ($config['default'] ?? 'mysql');

            return (string) ($config['connections'][$default]['prefix'] ?? '');
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * 读取模型类上的 `AiSearchable` 标记
     *
     * @return string[]
     */
    protected function resolveSearchableFields(?string $modelClass): array
    {
        if ($modelClass === null || !class_exists($modelClass) || !class_exists(AiSearchable::class)) {
            return [];
        }

        try {
            $marked = [];
            foreach ((new \ReflectionClass($modelClass))->getAttributes(AiSearchable::class) as $attribute) {
                $instance = $attribute->newInstance();
                foreach ($instance->fields as $field) {
                    $marked[] = (string) $field;
                }
            }

            return array_values(array_unique($marked));
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * 启发式提取模型 `search()` 方法体里出现的筛选键
     *
     *   - `$params['status']` / `$params["status"]`
     *   - `->where('status', ...)` / `->whereIn('catid', ...)`
     *
     * 只在 `search()` 方法体内取，避免把整模型的 `where` 都算进来。
     *
     * @return string[]
     */
    protected function resolveModelSearchKeys(string $modelClass): array
    {
        if (!class_exists($modelClass)) {
            return [];
        }

        try {
            $reflection = new \ReflectionClass($modelClass);
            if (!$reflection->hasMethod('search')) {
                return [];
            }

            $method = $reflection->getMethod('search');
            $file   = $method->getFileName();
            if ($file === false) {
                return [];
            }

            $lines = file($file);
            if ($lines === false) {
                return [];
            }

            $body = implode('', array_slice(
                $lines,
                $method->getStartLine() - 1,
                max(1, $method->getEndLine() - $method->getStartLine() + 1)
            ));

            $keys = [];

            if (preg_match_all('/\$params\s*\[\s*[\'"]([A-Za-z_][A-Za-z0-9_]*)[\'"]\s*\]/', $body, $m) > 0) {
                $keys = array_merge($keys, $m[1]);
            }

            if (preg_match_all('/->where(?:In|NotIn|Like|Between)?\s*\(\s*[\'"]([A-Za-z_][A-Za-z0-9_]*)[\'"]/', $body, $m) > 0) {
                $keys = array_merge($keys, $m[1]);
            }

            return array_values(array_unique($keys));
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * 关联表展示列（`华东区` → `region.name`）
     *
     * 优先在候选列里挑**真实存在**的（需要 DB）；离线时返回首个候选。
     */
    protected function resolveLabelColumn(string $table): string
    {
        if ($table === '' || !$this->useDb()) {
            return self::LABEL_COLUMN_CANDIDATES[0];
        }

        try {
            $fields = \think\facade\Db::name($table)->getFields();
            if (is_array($fields)) {
                foreach (self::LABEL_COLUMN_CANDIDATES as $candidate) {
                    if (array_key_exists($candidate, $fields)) {
                        return $candidate;
                    }
                }
            }
        } catch (\Throwable) {
        }

        return self::LABEL_COLUMN_CANDIDATES[0];
    }

    /**
     * 目标名：`plugin\smartstock\controller\Product` → `smartstock.product`；
     * `app\admin\controller\Menu` → `host.menu`；`...\LoginLog` → `host.login_log`
     *
     * 与 A1 的命名约定**必须一致**（`MenuActionMeta::getControllerName()` 用 `Str::snake()`，
     * 权限标识形如 `menu.action.host.login_log.getlist`），否则 A3-4 无法按名找到 A1 的动作。
     */
    protected function targetNameOf(string $controllerClass): string
    {
        return MenuActionMeta::targetNameOf($controllerClass);
    }

    protected function useContainer(): bool
    {
        if (!function_exists('app')) {
            return false;
        }

        try {
            return is_object(app());
        } catch (\Throwable) {
            return false;
        }
    }

    protected function useDb(): bool
    {
        return $this->useContainer() && class_exists(\think\facade\Db::class);
    }

    private function opt(array $options, string $key, mixed $default): mixed
    {
        if (array_key_exists($key, $options)) {
            return $options[$key];
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

    private function addTrace(string $step, string $message): void
    {
        $this->trace[] = ['step' => $step, 'message' => $message];
    }
}
