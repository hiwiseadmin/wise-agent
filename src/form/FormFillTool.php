<?php
declare(strict_types=1);

namespace wise\agent\form;

use wise\agent\menu\MenuActionResult;
use wise\agent\query\exception\AmbiguousValueException;
use wise\agent\query\exception\QueryParamException;
use wise\agent\query\QueryField;
use wise\agent\query\QueryFieldCatalog;
use wise\agent\query\QueryValueResolver;
use wise\agent\security\CapabilityAccess;
use wise\agent\tool\BaseTool;

/**
 * `form_fill` —— 表单智能填充（A2-3）
 *
 * 把「人/AI 说出来的词」变成「可提交的字段值映射」。**纯计算**：不写库、不改表单声明、
 * 不调用任何控制器动作（落库唯一入口是 A1 的 `save`/`update`）。
 *
 * ## 取值来源（复用 A3 的接地器，不重复造）
 *
 * | 级别 | 命中条件 | 解析方式 | 产出 |
 * |---|---|---|---|
 * | ① 枚举 | 字段有 `options` | 中文 label → value（精确 / 包含；歧义 → `code:2`） | `values` |
 * | ② 关联 | 目录（`QueryFieldCatalog`）判定该字段是关联外键 | 复用 A3 `QueryValueResolver`（label 模糊查 + 缓存 + 歧义澄清） | `values` |
 * | ③ 指令 | `instruction` 里**逐字出现**某个枚举候选 label | 确定性命中（≥2 命中 → `code:2`） | `values` |
 * | ④ 默认值 | 字段 `default` 非空且未被指令覆盖 | 直接用（`reasons` 标注「表单默认值」） | `values` |
 * | ⑤ 其余 | 以上都不命中 | **不产出**，进 `unfilled[]` 并说明原因 | 空 |
 *
 * ⚠️ 级别 ③（语义推断）在本类**刻意不调用 LLM**：`AiFillable` 的 prompt 约束写在工具参数
 * 的 description 里，由**外层 Agent**产出取值后作为 `values` 传入。这样本工具保持**确定性**
 *
 * ## 三条硬规则
 *
 * 1. **可写闸门先于接地**：命中不可写字段（`admin_id` / `password` / `*_hash` / 白名单外 …）
 *    时**不产出取值**，只进 `rejected[]` 并给出原因 —— 绝不因为「用户说了」就填一个越权字段
 *    （这是提示注入最容易打穿的点）。
 * 2. **歧义必须澄清**：≥2 个候选 → `code:2` + 候选 + 该字段**不产出取值**（不猜）。
 * 3. **不编造**：未命中的字段一律进 `unfilled[]`，附「指令里没有给出取值」。
 *
 * 可测试性：`introspector()` / `valueResolver()` / `relationOf()` / `catalogOf()` 均为
 * `protected` 接缝，故本类**不加 `final`**。
 */
class FormFillTool extends BaseTool
{
    use FormAnchorAware;

    public const NAME = 'form_fill';

    /** 不可写原因：字段不在 `only_fields` 范围内 */
    public const SKIP_OUT_OF_SCOPE = 'out_of_scope';

    /** 不可写原因：目录里没有这个字段 */
    public const SKIP_UNKNOWN_FIELD = 'unknown_field';

    /** 未填原因：指令里没有给出该字段的取值 */
    public const UNFILLED_NOT_INSTRUCTED = 'not_instructed';

    /** 未填原因：超出 `fill.max_fields` 上限 */
    public const UNFILLED_OVER_LIMIT = 'over_limit';

    /** @var array<string, mixed> */
    private array $options;

    private FormSchemaIntrospector $introspector;

    /** @var array<string, QueryFieldCatalog|null> 控制器 → 目录（进程内缓存） */
    private array $catalogCache = [];

    /**
     * @param array<string, mixed> $options `form_schema.*` 覆盖项
     */
    public function __construct(
        array $options = [],
        ?FormSchemaIntrospector $introspector = null
    ) {
        $this->options      = $options;
        $this->introspector = $introspector ?? new FormSchemaIntrospector($options);
    }

    public function getDescription(): string
    {
        return '按自然语言/草稿生成表单字段值（只读、不落库；提交请用 A1 的 save/update 工具）。'
            . '枚举字段直接用中文候选（如 状态=已退款），关联字段用人类说法（如 区域=华东区），'
            . '本工具会转成真实值并给出每个值的依据。'
            . '字段名与可选值请先用 form_fields 获取。'
            . '返回里 rejected[] 是**不可由 AI 填写**的字段（敏感/归属/白名单外），这是设计如此，不要重试；'
            . 'unfilled[] 是没能从指令里确定的字段，请向用户确认而不是编造。'
            . '若返回 code=2，说明取值有多个候选，应当让用户确认，不要猜也不要取第一个。'
            . '参数上限：单次填充 ≤ ' . $this->maxFields() . ' 个字段。';
    }

    /**
     * 第 3 层：把枚举候选写进 description（不硬编码进 Schema）
     *
     * @return array<string, mixed>
     */
    public function getParameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'target'      => [
                    'type'        => 'string',
                    'description' => '表单目标，与列表查询目标同一命名，如 host.permission / smartstock.product',
                ],
                'instruction' => [
                    'type'        => 'string',
                    'description' => '自然语言需求（如「新建一个企业客户，名称华东测试，类型企业」）。'
                        . '仅用于确定性命中枚举候选与说明取值依据；不会用它编造字段名。',
                ],
                'values'      => [
                    'type'        => 'object',
                    'description' => '已经确定取值的字段（字段名 → 原始取值）。'
                        . '枚举可传中文候选，关联字段可传人类说法（如 {"region_id":"华东区"}），本工具负责接地与类型转换。'
                        . '语义类字段（没有候选值的）请在此给出你推断的取值。',
                ],
                'draft'       => [
                    'type'        => 'object',
                    'description' => '已有草稿（字段名 → 已确定的取值），用于增量填充；本工具不会覆盖它。',
                ],
                'only_fields' => [
                    'type'        => 'array',
                    'items'       => ['type' => 'string'],
                    'description' => '只填充这些字段（留空表示全部可填字段）。',
                ],
            ],
            'required'   => ['target'],
        ];
    }

    public function getAccess(): string
    {
        return CapabilityAccess::READ;
    }

    public function getRiskLevel(): string
    {
        return CapabilityAccess::RISK_LOW;
    }

    /**
     * 纯计算、不落库；配置置 `fill.require_confirmation=true` 时改为要求确认（仅产出草稿）
     */
    public function requireConfirmation(): bool
    {
        return (bool) $this->fillOpt('require_confirmation', false);
    }

    public function getPermission(): string
    {
        if (!(bool) $this->opt('require_ai_permission', false)) {
            return '';
        }

        return 'form.fill';
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function execute(array $arguments): string
    {
        $targetName  = trim((string) ($arguments['target'] ?? ''));
        $instruction = (string) ($arguments['instruction'] ?? '');
        $rawValues   = $arguments['values'] ?? [];
        $draft       = $arguments['draft'] ?? [];
        $onlyFields  = $arguments['only_fields'] ?? [];

        if ($targetName === '') {
            return $this->encode(MenuActionResult::failure('缺少 target：请先用 form_fields 获取可用的表单目标', QueryParamException::CODE_INVALID, [
                'available_targets' => $this->introspector()->availableTargets(),
                'stage'             => MenuActionResult::STAGE_ARGUMENTS,
            ]));
        }

        foreach (['values' => $rawValues, 'draft' => $draft, 'only_fields' => $onlyFields] as $name => $value) {
            if (!is_array($value)) {
                return $this->encode(MenuActionResult::failure(
                    sprintf('%s 必须是对象/数组', $name),
                    QueryParamException::CODE_INVALID,
                    ['target' => $targetName, 'stage' => MenuActionResult::STAGE_ARGUMENTS]
                ));
            }
        }

        try {
            $controller = $this->introspector()->resolveTarget($targetName);

            if ($controller === null) {
                return $this->encode(MenuActionResult::failure(
                    sprintf('未知表单目标 %s', $targetName),
                    QueryParamException::CODE_INVALID,
                    [
                        'target'            => $targetName,
                        'available_targets' => $this->introspector()->availableTargets(),
                        'stage'             => MenuActionResult::STAGE_ARGUMENTS,
                    ]
                ));
            }

            if (($blocked = $this->anchorBlocked($controller, $targetName)) !== null) {
                return $this->encode($blocked);
            }

            $schema = $this->introspector()->forController($controller);

            if ($schema->isEmpty()) {
                return $this->encode(MenuActionResult::failure(
                    sprintf('目标 %s 没有可用的表单声明，无法填充', $targetName),
                    QueryParamException::CODE_INVALID,
                    [
                        'target'        => $targetName,
                        'schema_source' => $schema->source,
                        'notes'         => $schema->notes(),
                        'hint'          => '该控制器未声明 defineForm，且没有可用的 CRUD Schema / 表结构。',
                        'stage'         => MenuActionResult::STAGE_ARGUMENTS,
                    ]
                ));
            }

            $resolver = $this->valueResolver();

            $plan = $this->plan(
                $schema,
                $instruction,
                (array) $rawValues,
                (array) $draft,
                array_values((array) $onlyFields),
                $resolver
            );

            $data = [
                'target'        => $targetName,
                'readonly'      => true,
                'schema_source' => $schema->source,
                'values'        => $plan['values'],
                'reasons'       => $plan['reasons'],
                'unfilled'      => $plan['unfilled'],
                'rejected'      => $plan['rejected'],
                'ambiguous'     => $plan['ambiguous'],
                'plan'          => ['trace' => $plan['trace'], 'lookups' => $resolver->lookups()],
                'notes'         => array_merge($schema->notes(), $plan['notes']),
            ];

            if ($plan['ambiguous'] !== []) {
                return $this->encode(MenuActionResult::failure('ambiguous', $this->ambiguousCode(), [
                    'structured' => true,
                    'data'       => $data,
                    'text'       => '',
                    'target'     => $targetName,
                    'hint'       => '有字段取值存在多个候选，请让用户确认；不要猜，也不要自行取第一个。',
                    'stage'      => MenuActionResult::STAGE_ARGUMENTS,
                ]));
            }

            return $this->encode(MenuActionResult::normalize([
                'code' => 0,
                'msg'  => sprintf(
                    '已生成 %d 个字段取值（不可写 %d 个，未能确定 %d 个）',
                    count($plan['values']),
                    count($plan['rejected']),
                    count($plan['unfilled'])
                ),
                'data' => $data,
            ]));
        } catch (\Throwable $e) {
            return $this->encode(MenuActionResult::fromException($e, [
                'target' => $targetName,
                'stage'  => MenuActionResult::STAGE_EXECUTION,
            ]));
        }
    }


    /**
     * @param array<string, mixed> $rawValues
     * @param array<string, mixed> $draft
     * @param string[]             $onlyFields
     * @param QueryValueResolver   $resolver   本次调用共用的接地器
     * @return array{values:array<string,mixed>, reasons:array<string,string>, unfilled:array<string,string>, rejected:array<string,string>, ambiguous:array<int,array<string,mixed>>, trace:array<int,array{step:string,message:string}>, notes:array<int,array{code:string,message:string}>}
     */
    protected function plan(
        FormSchema $schema,
        string $instruction,
        array $rawValues,
        array $draft,
        array $onlyFields,
        QueryValueResolver $resolver
    ): array {
        $values    = [];
        $reasons   = [];
        $unfilled  = [];
        $rejected  = [];
        $ambiguous = [];
        $notes     = [];
        $trace     = [];

        $scope = $onlyFields === [] ? null : $onlyFields;

        foreach ($draft as $key => $value) {
            $key   = (string) $key;
            $field = $schema->get($key);

            if ($field === null) {
                $rejected[$key] = self::SKIP_UNKNOWN_FIELD;
                continue;
            }

            if (!$field->isFillable()) {
                $rejected[$key] = $field->denyReason !== '' ? $field->denyReason : 'not_writable';
                continue;
            }

            $values[$key]  = $value;
            $reasons[$key] = '来自已有草稿';
        }

        if ($draft !== []) {
            $trace[] = ['step' => 'fill.draft', 'message' => sprintf('草稿提供 %d 个字段', count($draft))];
        }

        foreach ($rawValues as $key => $raw) {
            $key   = (string) $key;
            $field = $schema->get($key);

            if ($field === null) {
                $rejected[$key] = self::SKIP_UNKNOWN_FIELD;
                $trace[]        = ['step' => 'fill.reject', 'message' => sprintf('%s：目录里没有该字段', $key)];
                continue;
            }

            if ($scope !== null && !in_array($key, $scope, true)) {
                $rejected[$key] = self::SKIP_OUT_OF_SCOPE;
                continue;
            }

            if (!$field->isFillable()) {
                $reason         = $field->denyReason !== '' ? $field->denyReason : 'not_writable';
                $rejected[$key] = $reason;
                $trace[]        = ['step' => 'fill.reject', 'message' => sprintf('%s：不可写（%s），不产出取值', $key, $reason)];
                continue;
            }

            try {
                $hit = $this->ground($schema, $field, $raw, $resolver);

                $values[$key]  = $hit['value'];
                $reasons[$key] = $hit['reason'];
                $trace[]       = ['step' => 'fill.grounded', 'message' => sprintf('%s：%s', $key, $hit['reason'])];
            } catch (AmbiguousValueException $e) {
                $ambiguous[] = [
                    'field'      => $key,
                    'keyword'    => $e->keyword(),
                    'candidates' => $e->candidates(),
                    'source'     => $e->source(),
                ];
                $trace[] = ['step' => 'fill.ambiguous', 'message' => sprintf('%s：「%s」有 %d 个候选', $key, $e->keyword(), count($e->candidates()))];
            } catch (QueryParamException $e) {
                $unfilled[$key] = 'unresolvable: ' . $e->getMessage();
                $trace[]        = ['step' => 'fill.unresolved', 'message' => sprintf('%s：%s', $key, $e->getMessage())];
            }
        }

        if ($instruction !== '') {
            foreach ($schema->readableFields() as $key => $field) {
                if (isset($values[$key]) || isset($unfilled[$key]) || isset($rejected[$key])) {
                    continue;
                }

                if (!$field->isFillable() || $field->options === []) {
                    continue;
                }

                if ($scope !== null && !in_array($key, $scope, true)) {
                    continue;
                }

                $hits = $this->optionsMentionedIn($field, $instruction);

                if (count($hits) > 1) {
                    $ambiguous[] = [
                        'field'      => $key,
                        'keyword'    => $instruction,
                        'candidates' => $hits,
                        'source'     => QueryValueResolver::GROUNDED_ENUM,
                    ];
                    continue;
                }

                if (count($hits) === 1) {
                    $label = (string) ($hits[0]['label'] ?? '');

                    $values[$key]  = $hits[0]['value'];
                    $reasons[$key] = '指令中命中枚举候选「' . ($label !== '' ? $label : (string) $hits[0]['value']) . '」';
                }
            }
        }

        foreach ($schema->all() as $key => $field) {
            if (isset($values[$key]) || isset($rejected[$key]) || isset($unfilled[$key])) {
                continue;
            }

            if (!$field->isFillable() || $field->default === null || $field->default === '') {
                continue;
            }

            if ($scope !== null && !in_array($key, $scope, true)) {
                continue;
            }

            $values[$key]  = $field->default;
            $reasons[$key] = '表单默认值';
        }

        $limit = $this->maxFields();
        if (count($values) > $limit) {
            $keep = array_slice(array_keys($values), 0, $limit);
            foreach (array_keys($values) as $key) {
                if (in_array($key, $keep, true)) {
                    continue;
                }

                $unfilled[$key] = self::UNFILLED_OVER_LIMIT;
                unset($values[$key], $reasons[$key]);
            }

            $notes[] = [
                'code'    => 'fill.truncated',
                'message' => sprintf('填充字段数超过上限 %d，超出部分已移入 unfilled（fill.max_fields）。', $limit),
            ];
        }

        foreach ($schema->fillableKeys() as $key) {
            if (isset($values[$key]) || isset($unfilled[$key]) || isset($rejected[$key])) {
                continue;
            }

            if ($scope !== null && !in_array($key, $scope, true)) {
                continue;
            }

            $unfilled[$key] = self::UNFILLED_NOT_INSTRUCTED;
        }

        if ($ambiguous !== []) {
            $notes[] = [
                'code'    => 'fill.ambiguous',
                'message' => '存在取值歧义的字段：' . implode(', ', array_column($ambiguous, 'field'))
                    . '；已按「不猜」处理（未产出取值），请让用户确认。',
            ];
        }

        if ($rejected !== []) {
            $notes[] = [
                'code'    => 'fill.rejected',
                'message' => '以下字段不可由 AI 填写（本工具不会为其生成值，请人工处理）：'
                    . implode(', ', array_map(
                        static fn(string $k, string $reason): string => $k . '(' . $reason . ')',
                        array_keys($rejected),
                        array_values($rejected)
                    )),
            ];
        }

        return [
            'values'    => $values,
            'reasons'   => $reasons,
            'unfilled'  => $unfilled,
            'rejected'  => $rejected,
            'ambiguous' => $ambiguous,
            'trace'     => $trace,
            'notes'     => $notes,
        ];
    }


    /**
     * 接地单个字段的取值
     *
     * @return array{value:mixed, reason:string}
     * @throws AmbiguousValueException
     * @throws QueryParamException
     */
    protected function ground(FormSchema $schema, FormFieldSchema $field, mixed $raw, QueryValueResolver $resolver): array
    {
        $queryField = $this->queryFieldOf($schema, $field);

        if (is_array($raw)) {
            if (!$field->multiple) {
                throw new QueryParamException(sprintf('字段 %s 不是多选，取值必须是标量', $field->key));
            }

            $hit = $resolver->resolveList($queryField, array_values($raw));

            return [
                'value'  => array_map(fn(mixed $v): mixed => $this->normalizeForForm($field, $v), $hit['values']),
                'reason' => '多选接地：' . implode('|', array_map('strval', $hit['values'])),
            ];
        }

        $hit = $resolver->resolve($queryField, $raw);

        return [
            'value'  => $this->normalizeForForm($field, $hit['value']),
            'reason' => $this->groundReasonOf($schema, $field, $hit),
        ];
    }

    /**
     * 取值依据（回传给 AI：回答「你为什么填这个值」）
     *
     * @param array{value:mixed, grounded:string, matched:string} $hit
     */
    protected function groundReasonOf(FormSchema $schema, FormFieldSchema $field, array $hit): string
    {
        if ($hit['grounded'] === QueryValueResolver::GROUNDED_ENUM) {
            return '枚举接地：命中候选「' . $hit['matched'] . '」';
        }

        if ($hit['grounded'] === QueryValueResolver::GROUNDED_RELATION) {
            $relation = $this->relationOf($schema->controller, $field->key);
            $table    = (string) ($relation['table'] ?? '');

            return '关联接地：' . ($table !== '' ? $table : '关联表') . ' 中匹配「' . $hit['matched'] . '」';
        }

        return '按字段类型直接取值（' . $field->type . '）';
    }

    /**
     * 表单字段 → A3 的 `QueryField`（唯一对接点）
     *
     * 关联信息来自 A3 的字段目录（`QueryFieldCatalog`）：表单本身没有「关联」概念
     * ＼�，所以只有目录判定为关联外键时才有 ② 级接地。
     */
    protected function queryFieldOf(FormSchema $schema, FormFieldSchema $field): QueryField
    {
        $relation = $this->relationOf($schema->controller, $field->key);

        if ($relation !== null && ($relation['table'] ?? '') !== '') {
            return new QueryField(
                key: $field->key,
                column: $field->key,
                label: $field->label,
                type: QueryField::TYPE_RELATION,
                relation: $relation
            );
        }

        return new QueryField(
            key: $field->key,
            column: $field->key,
            label: $field->label,
            type: $this->queryTypeOf($field),
            enum: $field->options
        );
    }

    /**
     * 表单类型 → 查询类型（只影响「字面量如何转换」，不影响枚举/关联接地）
     */
    protected function queryTypeOf(FormFieldSchema $field): string
    {
        return match ($field->type) {
            'number'   => QueryField::TYPE_DECIMAL,
            'switch'   => QueryField::TYPE_BOOL,
            'date'     => QueryField::TYPE_DATE,
            'datetime' => QueryField::TYPE_DATETIME,
            default    => $field->options !== [] ? QueryField::TYPE_ENUM : QueryField::TYPE_STRING,
        };
    }

    /**
     * 表单语义下的取值归一：`number` 的数字串还原成 int / float（其余保持接地结果）
     *
     * 为什么单独做：`QueryValueResolver` 对 `decimal` 刻意保留字符串（避免精度损失，
     * 那是 SQL 参数场景的正确选择）；但表单草稿的消费者是 A1 的 `save`/`update`，
     * 一个 `"9"` 比 `9` 更容易在后续「AI 回读自己产出的草稿」时产生困惑。
     */
    protected function normalizeForForm(FormFieldSchema $field, mixed $value): mixed
    {
        if ($field->type !== 'number' || !is_string($value) || !is_numeric($value)) {
            return $value;
        }

        return (string) (int) $value === $value ? (int) $value : (float) $value;
    }

    /**
     * 指令中逐字出现的**唯一**枚举候选（`label` 优先，其次 `value`）
     *
     * 只做包含匹配、不做模糊/编辑距离 —— 宁可 `unfilled`，也不猜。
     *
     * @return array<int, array{value:mixed, label:string}>
     */
    protected function optionsMentionedIn(FormFieldSchema $field, string $instruction): array
    {
        $hits = [];

        foreach ($field->options as $option) {
            if (!is_array($option)) {
                continue;
            }

            $label = trim((string) ($option['label'] ?? ''));
            $value = $option['value'] ?? null;

            $needle = ($label !== '' && $label !== (string) $value)
                ? $label
                : (is_bool($value) ? '' : (string) $value);

            if ($needle !== '' && mb_stripos($instruction, $needle, 0, 'UTF-8') !== false) {
                $hits[] = ['value' => $value, 'label' => $label !== '' ? $label : (string) $value];
            }
        }

        return $hits;
    }


    /**
     * 接地器（`protected` 接缝：单测覆写即可离线验证 ② 级关联接地与歧义）
     *
     * `max_lookups` / `value_cache_ttl` 取自 `form_schema.fill.*`（与 A3 同义）；
     * 显式传入覆盖项，避免误读 `list_query.*` 的其它键。
     */
    protected function valueResolver(): QueryValueResolver
    {
        return new QueryValueResolver([
            'max_lookups'     => max(0, (int) $this->fillOpt('max_lookups', 3)),
            'value_cache_ttl' => max(1, (int) $this->fillOpt('value_cache_ttl', 600)),
        ]);
    }

    protected function introspector(): FormSchemaIntrospector
    {
        return $this->introspector;
    }

    /**
     * 该字段的关联信息（来自 A3 目录；无关联 / 目录不可用返回 null）
     *
     * @return array{table:string, label_column:string, name:string}|null
     */
    protected function relationOf(string $controller, string $fieldKey): ?array
    {
        $catalog = $this->catalogOf($controller);

        if ($catalog === null) {
            return null;
        }

        try {
            $field = $catalog->get($fieldKey);
        } catch (\Throwable) {
            return null;
        }

        if ($field === null || !$field->isRelation() || ($field->relation['table'] ?? '') === '') {
            return null;
        }

        $relation = $field->relation;

        return $relation;
    }

    /**
     * A3 的字段目录（`protected` 接缝：单测可覆写以离线提供关联信息）
     */
    protected function catalogOf(string $controller): ?QueryFieldCatalog
    {
        if (array_key_exists($controller, $this->catalogCache)) {
            return $this->catalogCache[$controller];
        }

        try {
            return $this->catalogCache[$controller] = QueryFieldCatalog::forController($controller, $this->options);
        } catch (\Throwable) {
            return $this->catalogCache[$controller] = null;
        }
    }


    private function maxFields(): int
    {
        return max(1, (int) $this->fillOpt('max_fields', 20));
    }

    private function ambiguousCode(): int
    {
        return (int) $this->fillOpt('ambiguous_as_code', QueryParamException::CODE_AMBIGUOUS);
    }

    private function fillOpt(string $key, mixed $default): mixed
    {
        $fill = $this->opt('fill', []);

        if (is_array($fill) && array_key_exists($key, $fill)) {
            return $fill[$key];
        }

        return $default;
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
            return config('wise-agent.form_schema.' . $key, $default) ?? $default;
        } catch (\Throwable) {
            return $default;
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function encode(array $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_UNICODE) ?: '{"code":1,"msg":"json encode failed"}';
    }
}
