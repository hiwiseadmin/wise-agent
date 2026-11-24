<?php
declare(strict_types=1);

namespace wise\agent\query;

use wise\agent\menu\MenuActionResult;
use wise\agent\query\executor\ListQueryExecutorResolver;
use wise\agent\query\exception\QueryParamException;
use wise\agent\security\CapabilityAccess;
use wise\agent\tool\BaseTool;

/**
 * 目录查询工具（A3-3，`lq_fields`）
 *
 * **不进常驻 Prompt**，需要时由 Agent 主动调用本工具取回。这样：
 * - 目标清单注入受 `max_prompt_chars` 严格约束（不会随插件数量线性膨胀）；
 * - 真正要下查询时，模型拿到的是一致的、完整的维度定义。
 *
 * 纯只读，且**不触碰任何业务表**（只读元数据）。
 *
 * 与 `ListQueryTool` 同策略：**不加 `final`**，`protected` 接缝供离线单测覆写。
 */
class ListQueryFieldsTool extends BaseTool
{
    public const NAME = 'lq_fields';

    private ListQueryTool $queryTool;

    private ListQueryTargetResolver $targets;

    /** @var array<string, mixed> */
    private array $options;

    /**
     * @param array<string, mixed> $options `list_query.*` 覆盖项
     */
    public function __construct(
        array $options = [],
        ?ListQueryTool $queryTool = null,
        ?ListQueryTargetResolver $targets = null
    ) {
        $this->options   = $options;
        $this->targets   = $targets ?? new ListQueryTargetResolver(null, $options);
        $this->queryTool = $queryTool ?? new ListQueryTool($options, $this->targets);
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public function getDescription(): string
    {
        return '查询某个目标的完整可筛选维度：字段名、类型、可用操作符、枚举候选值、关联字段、可排序字段。'
            . '在调用 lq_query 之前用它确认字段名与取值写法；结果按需拉取，不占常驻上下文。';
    }

    public function getParameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'target' => [
                    'type'        => 'string',
                    'description' => '目标名（与 lq_query 的 target 一致）',
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

    public function requireConfirmation(): bool
    {
        return false;
    }

    public function getPermission(): string
    {
        if (!(bool) $this->opt('require_ai_permission', false)) {
            return '';
        }

        return 'list_query.fields';
    }

    public function execute(array $arguments): string
    {
        $targetName = trim((string) ($arguments['target'] ?? ''));

        try {
            $target = $this->targets->get($targetName);

            if ($target === null) {
                return $this->encode(MenuActionResult::failure(
                    sprintf('未知目标 %s', $targetName),
                    QueryParamException::CODE_INVALID,
                    [
                        'target'            => $targetName,
                        'available_targets' => $this->targets->names(),
                        'stage'             => MenuActionResult::STAGE_ARGUMENTS,
                    ]
                ));
            }

            $catalog = $this->catalog($target);

            $fields = [];
            foreach ($catalog->all() as $key => $field) {
                $fields[$key] = [
                    'label'      => $field->label,
                    'type'       => $field->type,
                    'operators'  => $field->operators,
                    'enum'       => $field->enum,
                    'relation'   => $field->relation,
                    'sortable'   => $field->sortable,
                    'filterable' => $field->filterable,
                    'aliases'    => $field->aliases,
                    'source'     => $field->source,
                ];
            }

            return $this->encode(MenuActionResult::normalize([
                'code' => 0,
                'msg'  => 'ok',
                'data' => [
                    'target'              => $target->name,
                    'title'               => $target->title,
                    'table'               => $catalog->tableName(),
                    'fields'              => $fields,
                    'filterable'          => $catalog->filterableKeys(),
                    'sortable'            => $catalog->sortableKeys(),
                    'searchable'          => $catalog->searchableKeys(),
                    'relations'           => array_keys($catalog->relations()),
                    'exec_path'           => $this->execPathOf($target),
                    'filter_support'      => $target->filterSupport,
                    'filterable_now'      => $target->supportsFilters(),
                    'scope_note'          => $target->supportsFilters()
                        ? '只读目标（getList）；无任何写路径'
                        : '只读目标（getList）；该动作的筛选能力未确认/不支持，仅支持分页查询',
                ],
            ], ['target' => $target->name]));
        } catch (\Throwable $e) {
            return $this->encode(MenuActionResult::fromException($e, ['target' => $targetName]));
        }
    }

    /**
     * 目录（`protected` 接缝：单测覆写即可离线验证）
     */
    protected function catalog(ListQueryTarget $target): QueryFieldCatalog
    {
        return $this->queryTool->catalog($target);
    }

    /**
     * 该目标会走哪条执行路径（与 `lq_query` 的选择逻辑同源）
     */
    protected function execPathOf(ListQueryTarget $target): string
    {
        try {
            return (new ListQueryExecutorResolver($this->options))->pathOf($target);
        } catch (\Throwable) {
            return ListQueryExecutorResolver::PATH_MENU_ACTION;
        }
    }

    private function encode(array $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_UNICODE) ?: '{"code":1,"msg":"json encode failed"}';
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
}
