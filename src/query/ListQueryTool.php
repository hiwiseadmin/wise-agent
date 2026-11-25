<?php
declare(strict_types=1);

namespace wise\agent\query;

use wise\agent\menu\MenuActionResult;
use wise\agent\query\executor\ListQueryExecutorInterface;
use wise\agent\query\executor\ListQueryExecutorResolver;
use wise\agent\query\exception\QueryParamException;
use wise\agent\security\CapabilityAccess;
use wise\agent\tool\BaseTool;

/**
 * 自然语言列表查询工具（A3-3，`lq_query`）
 *
 * A3 **不是**「让 AI 写查询」，而是「把自然语言编译成框架既有的查询参数」：
 * LLM 只产出结构化意图，字段白名单 / 操作符 / 日期 / 枚举与关联取值全部由确定性代码处理，
 * 最终执行**默认回到 A1**（权限、审计、结果契约与人工操作一致）。
 *
 * 因此本工具是**纯只读**的：
 * - `getAccess() = READ`、`getRiskLevel() = LOW`、`requireConfirmation() = false`；
 * - 配置里**不存在**写路径（唯一执行入口是目标的 `getList`）。
 *
 * ## 结果契约（与 A1/A4 同核，多一档语义）
 *
 * | code | 含义 | Agent 应做什么 |
 * |---|---|---|
 * | `0` | 成功 | 读取 `data`（`structured=true`）或 `text` |
 * | `1` | 失败（意图非法/未知字段/超上限） | 修正参数后重试 |
 *
 * 成功响应在 A1 的契约上追加 `target` / `params` / `plan`：`plan` 含解析后的时间区间与
 *
 * ## 可测试性
 * `catalog()` / `valueResolver()` / `timeResolver()` 都是 `protected` 接缝，单测覆写即可离线覆盖
 * 「意图 → 编译 → 响应」全过程；因此本类**刻意不加 `final`**（与 `MenuActionSchemaResolver` 同策略）。
 */
class ListQueryTool extends BaseTool
{
    public const NAME = 'lq_query';

    private ListQueryTargetResolver $targets;

    private ListQueryExecutorInterface $executor;

    /** @var array<string, mixed> */
    private array $options;

    /**
     * @param array<string, mixed> $options `list_query.*` 覆盖项
     */
    public function __construct(
        array $options = [],
        ?ListQueryTargetResolver $targets = null,
        ?ListQueryExecutorInterface $executor = null
    ) {
        $this->options = $options;
        $this->targets = $targets ?? new ListQueryTargetResolver(null, $options);
        $this->executor = $executor ?? new ListQueryExecutorResolver($options);
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public function getDescription(): string
    {
        $names = $this->targets->names();

        $lines = [
            '按自然语言查询业务列表（只读）。把「上月华东区的退货单，按金额倒序」这类需求编译成受约束的查询参数并执行。',
            '可用目标：' . ($names === [] ? '（当前没有可用目标）' : implode(', ', $names)) . '。',
            '目标的可筛选维度、枚举候选、关联字段请先用 lq_fields 查询，**不要编造字段名或取值**。',
            '时间条件请用 time_expr（如 上月/本季度/近7天），不要自己计算日期。',
            '若返回 code=2，说明取值有多个候选，应当让用户确认，不要猜也不要取第一个。',
            '参数上限：filters ≤ ' . $this->opt('max_filters', 12) . ' 条，limit ≤ ' . $this->opt('max_limit', 100) . '。',
        ];

        return implode(' ', $lines);
    }

    public function getParameters(): array
    {
        $names = $this->targets->names();

        return [
            'type'       => 'object',
            'properties' => [
                'target'  => array_filter([
                    'type'        => 'string',
                    'enum'        => $names === [] ? null : $names,
                    'description' => $names === []
                        ? '查询目标（列表标识）；当前没有可用目标'
                        : '查询目标：' . implode(' / ', $names),
                ]),
                'filters' => [
                    'type'        => 'array',
                    'description' => '筛选条件（AND 或 OR，见 logic）。字段名与取值必须来自该目标的目录（用 lq_fields 拉取），'
                        . '枚举字段请直接用中文候选（如 状态=已退款）。',
                    'items'       => [
                        'type'       => 'object',
                        'properties' => [
                            'field'      => ['type' => 'string', 'description' => '字段名（对外键名）'],
                            'op'         => [
                                'type'        => 'string',
                                'enum'        => QueryField::ALL_OPERATORS,
                                'description' => '操作符；不传按 eq 处理。时间区间用 between + time_expr',
                            ],
                            'value'      => [
                                'type'        => 'string',
                                'description' => '取值（字符串）。区间型（between）写 "小,大" 两个值；无值操作符（null/notnull）不用传',
                            ],
                            'time_expr'  => [
                                'type'        => 'string',
                                'enum'        => TimeRangeResolver::EXPRESSIONS,
                                'description' => '时间词（如 上月/近7天/本季度）；between 时优先于 value',
                            ],
                            'time_start' => ['type' => 'string', 'description' => '绝对起始日期 Y-m-d（无 time_expr 时使用）'],
                            'time_end'   => ['type' => 'string', 'description' => '绝对结束日期 Y-m-d（无 time_expr 时使用）'],
                        ],
                        'required'   => ['field'],
                    ],
                ],
                'logic'   => [
                    'type'        => 'string',
                    'enum'        => [QueryIntent::LOGIC_AND, QueryIntent::LOGIC_OR],
                    'description' => '多个 filters 之间的关系（默认 and）。or 为单层 OR，不支持嵌套',
                ],
                'orders'  => [
                    'type'        => 'array',
                    'description' => '排序（字段必须在可排序集合内）',
                    'items'       => [
                        'type'       => 'object',
                        'properties' => [
                            'field'     => ['type' => 'string'],
                            'direction' => ['type' => 'string', 'enum' => ['asc', 'desc']],
                        ],
                        'required'   => ['field'],
                    ],
                ],
                'fields'  => [
                    'type'        => 'array',
                    'items'       => ['type' => 'string'],
                    'description' => '字段投影（只回传这些列）',
                ],
                'page'    => ['type' => 'integer', 'description' => '页码（默认 1）'],
                'limit'   => ['type' => 'integer', 'description' => '每页条数（默认 20，上限 ' . $this->opt('max_limit', 100) . '）'],
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
     * 只读工具：不需要人工确认
     */
    public function requireConfirmation(): bool
    {
        return false;
    }

    /**
     * deny-only（与 A1-5 同口径）：默认不产生人工没有的权限面
     */
    public function getPermission(): string
    {
        if (!(bool) $this->opt('require_ai_permission', false)) {
            return '';
        }

        return 'list_query.query';
    }

    public function execute(array $arguments): string
    {
        $targetName = trim((string) ($arguments['target'] ?? ''));

        if ($targetName === '') {
            return $this->encode($this->invalid('缺少 target 参数', '-', [
                'available_targets' => $this->targets->names(),
            ]));
        }

        $target = $this->targets->get($targetName);
        if ($target === null) {
            return $this->encode($this->invalid(sprintf('未知目标 %s', $targetName), $targetName, [
                'available_targets' => $this->targets->names(),
            ]));
        }

        $args = $arguments;
        unset($args['target']);

        try {
            $intent = QueryIntent::fromToolArgs($args, $this->options);
        } catch (QueryParamException $e) {
            return $this->encode($this->fromParamException($e, $targetName));
        }

        if (!$target->supportsFilters() && ($intent->filters !== [] || $intent->orders !== [])) {
            $reason = $target->filterSupport === ListQueryTarget::FILTER_UNKNOWN
                ? '该 getList 被控制器重写，无法确认它是否会消费筛选参数（可能被静默忽略）'
                : '该 getList 未使用查询构建器，筛选/排序一定会被忽略';

            return $this->encode(MenuActionResult::failure(
                sprintf('目标 %s 不支持 filters/orders：%s', $targetName, $reason),
                QueryParamException::CODE_INVALID,
                [
                    'target'         => $targetName,
                    'filter_support' => $target->filterSupport,
                    'hint'           => sprintf(
                        '请显式声明 list_query.targets.%s.supports_filters=true（确认该 getList 消费参数），或改为 mode=model_search 走独立执行路径。',
                        $targetName
                    ),
                    'stage'          => MenuActionResult::STAGE_ARGUMENTS,
                ]
            ));
        }

        try {
            $catalog = $this->catalog($target);
            $params  = $intent->compile($catalog, $this->valueResolver(), $this->timeResolver());
        } catch (QueryParamException $e) {
            return $this->encode($this->fromParamException($e, $targetName));
        } catch (\Throwable $e) {
            return $this->encode(MenuActionResult::fromException($e, [
                'target' => $targetName,
                'stage'  => MenuActionResult::STAGE_EXECUTION,
            ]));
        }

        try {
            $result = $this->executor->execute($target, $params);
        } catch (\Throwable $e) {
            return $this->encode(MenuActionResult::fromException($e, [
                'target' => $targetName,
                'stage'  => MenuActionResult::STAGE_EXECUTION,
            ]));
        }

        $result['target']       = $targetName;
        $result['mode']         = $target->mode;
        $result['exec_path']    = $this->execPathOf($target);
        $result['filter_support'] = $target->filterSupport;
        $result['params']       = $params;
        $result['plan']         = $intent->plan();

        return $this->encode($result);
    }

    /**
     * 本次查询实际走哪条执行路径（`menu_action` = 委托 A1 / `standalone` = A3 自己取数）
     *
     * 回显给模型与审计：同一个查询走两条路径的语义可能不同（附录三 现状事实 8），
     * 让「走哪条」变成可观测事实，而不是隐性漂移。
     */
    protected function execPathOf(ListQueryTarget $target): string
    {
        if ($this->executor instanceof ListQueryExecutorResolver) {
            return $this->executor->pathOf($target);
        }

        return $target->usesBuilderMode()
            ? ListQueryExecutorResolver::PATH_MENU_ACTION
            : ListQueryExecutorResolver::PATH_STANDALONE;
    }

    /**
     * 目标的字段目录（进程内复用 `MenuActionSchemaResolver` 的缓存）
     */
    public function catalog(ListQueryTarget $target): QueryFieldCatalog
    {
        return QueryFieldCatalog::forController(
            $target->controller,
            $this->options,
            null,
            $target->aliases,
            $target->synonyms === [] ? (array) $this->opt('synonyms', []) : $target->synonyms
        );
    }


    /**
     * `QueryParamException` → 观察契约（`code:1` 失败 / `code:2` 需澄清）
     *
     * 响应契约），人类可读细节放 `detail`，不污染 `msg`。
     */
    private function fromParamException(QueryParamException $e, string $target): array
    {
        $payload = $e->toResult();

        return MenuActionResult::failure((string) $payload['msg'], (int) $payload['code'], [
            'target' => $target,
            'data'   => $payload['data'],
            'hint'   => $payload['hint'],
            'detail' => $e->getMessage(),
            'stage'  => MenuActionResult::STAGE_ARGUMENTS,
        ]);
    }

    /**
     * 工具自身层面的非法调用（未知目标 / 缺参）
     *
     * @param array<string, mixed> $extra
     */
    private function invalid(string $message, string $target, array $extra = []): array
    {
        return MenuActionResult::failure($message, QueryParamException::CODE_INVALID, array_merge([
            'target' => $target,
            'hint'   => '请用 list_query 工具描述里的目标名（详见 lq_fields）。',
            'stage'  => MenuActionResult::STAGE_ARGUMENTS,
        ], $extra));
    }

    /**
     * 取值接地器（`protected` 接缝：单测可覆写以离线覆盖三级接地与歧义）
     */
    protected function valueResolver(): QueryValueResolver
    {
        return new QueryValueResolver($this->options);
    }

    protected function timeResolver(): TimeRangeResolver
    {
        $zone = $this->opt('time_zone', null);

        return new TimeRangeResolver(null, is_string($zone) && $zone !== '' ? $zone : null);
    }

    /**
     * 工具响应编码
     *
     * ⚠️ `BaseTool` 不提供编码方法（A1 的 `MenuActionTool` 也是自带私有实现），
     * 这里与之保持同构：统一 JSON、中文不转义、编码失败也要给合法 JSON。
     */
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
