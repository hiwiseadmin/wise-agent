<?php
declare(strict_types=1);

namespace wise\agent\query;

/**
 * 查询目标（A3-3）
 *
 * 一个「可被自然语言查询的列表」= 一个控制器的 `getList` 动作。目标名与 A1 的命名约定
 * **严格一致**（`{ident}.{snake(controller)}`，如 `host.permission`、`smartstock.product`），
 * 这样 A1 的权限标识 `menu.action.host.permission.getlist` 与 A3 的目标能一一对应
 * （验收 12：A3 不得绕开 A1 的护栏）。
 *
 * ## `mode` 的三种取值（附录三 现状事实 8）
 *
 * 仓库里存在**两套并行的列表查询栈**：框架侧 `RequestQueryBuilder`（`字段__操作符`）与
 * 插件侧模型 `search($params)`。同一个「列表」走不同栈结果可能不同，所以必须显式声明：
 *
 * | mode | 含义 | 谁执行 |
 * |---|---|---|
 * | `builder` | 标准 `CrudAction` → `RequestQueryBuilder` | A1 的 `getList`（默认路径） |
 * | `model_search` | 手写列表页 → `(new Model)->search($params)` | A3-4 的独立执行路径 |
 * | `auto` | 由 A1 内省结论推断（`customized` 标记） | 见 {@see self::fromMeta()} |
 */
final class ListQueryTarget implements \JsonSerializable
{
    public const MODE_BUILDER      = 'builder';
    public const MODE_MODEL_SEARCH = 'model_search';
    public const MODE_AUTO         = 'auto';

    /**
     * 该 `getList` 是否真的把查询参数用到了查询上（三态）
     *
     * | 值 | 判据 | A3 的行为 |
     * |---|---|---|
     * | `supported` | 标准 `CrudAction::getList`（`customized=false`） | 允许 filters/orders |
     * | `unsupported` | 重写的方法体**完全没有**引用查询构建器 ⇒ 它不可能消费 `字段__op` | 拒绝 filters/orders（明确报错，绝不静默返回全量） |
     * | `unknown` | 重写的方法体**引用了**构建器，但无法静态判定是否真的用于本次查询 | 默认拒绝 filters/orders 并提示显式声明或改 `mode=model_search` |
     *
     * 第三态是**实测倒逼出来**的：`app\admin\controller\LoginLog::getList()` 调用了
     * `$this->buildQueryParams()`（会被「方法体含构建器」的启发式判为支持），但随后**自建**
     * `LoginLogModel::with('admin')` 查询，并把 `username/status/date_range` 的过滤代码整段注释掉
     * ——实际只用了分页。任何基于文本的启发式都会在这里判错，所以默认策略是：
     * **重写过的 `getList` 一律不默认可筛选**（`customized_getlist_policy=strict`），
     * 由宿主显式声明 `supports_filters`，或把目标改成 `mode=model_search` 走独立执行。
     */
    public const FILTER_SUPPORTED   = 'supported';
    public const FILTER_UNSUPPORTED = 'unsupported';
    public const FILTER_UNKNOWN     = 'unknown';

    /**
     * @param string              $name         目标名（`host.permission`）
     * @param string              $controller   控制器 FQCN
     * @param string              $mode         见 MODE_* 常量
     * @param string              $searchMethod `model_search` 模式下的模型方法名
     * @param string              $anchorPath   权限锚点菜单 path（A1 执行时的锚点）
     * @param string              $title        中文标题（菜单名）
     * @param array<string,mixed> $aliases      对外键名 → 真实列名
     * @param string[]            $synonyms     同义词（覆盖全局表）
     * @param string              $getListAction getList 动作名（允许定制，默认 `getList`）
     * @param array{name:string, label_column:string}|array{} $relationHints  已知关联表信息
     * @param string              $filterSupport 见 FILTER_* 常量
     */
    public function __construct(
        public readonly string $name,
        public readonly string $controller,
        public readonly string $mode = self::MODE_BUILDER,
        public readonly string $searchMethod = 'search',
        public readonly string $anchorPath = '',
        public readonly string $title = '',
        public readonly array $aliases = [],
        public readonly array $synonyms = [],
        public readonly string $getListAction = 'getList',
        public readonly array $relationHints = [],
        public readonly string $filterSupport = self::FILTER_SUPPORTED,
    ) {
    }

    /**
     * 从配置数组构造
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(string $name, array $data): ?self
    {
        $controller = trim((string) ($data['controller'] ?? ''));
        if ($name === '' || $controller === '') {
            return null;
        }

        $mode = strtolower(trim((string) ($data['mode'] ?? self::MODE_AUTO)));
        if (!in_array($mode, [self::MODE_BUILDER, self::MODE_MODEL_SEARCH, self::MODE_AUTO], true)) {
            $mode = self::MODE_AUTO;
        }

        return new self(
            name: $name,
            controller: $controller,
            mode: $mode,
            searchMethod: trim((string) ($data['search_method'] ?? 'search')) ?: 'search',
            anchorPath: trim((string) ($data['menu_path'] ?? $data['anchor_path'] ?? '')),
            title: trim((string) ($data['title'] ?? '')),
            aliases: is_array($data['aliases'] ?? null) ? $data['aliases'] : [],
            synonyms: is_array($data['synonyms'] ?? null) ? $data['synonyms'] : [],
            getListAction: trim((string) ($data['get_list_action'] ?? 'getList')) ?: 'getList',
            filterSupport: self::normalizeFilterSupport($data),
        );
    }

    /**
     * 解析显式声明的筛选能力
     *
     * 支持两种写法：三态字符串 `filter_support: supported|unsupported|unknown`，
     * 或布尔 `supports_filters: true|false`。**手工声明的目标默认 supported**
     * （宿主对自己的控制器负责；未声明即表示它走标准 CrudAction）。
     *
     * @param array<string, mixed> $data
     */
    private static function normalizeFilterSupport(array $data): string
    {
        $candidate = strtolower(trim((string) ($data['filter_support'] ?? '')));

        if (in_array($candidate, [self::FILTER_SUPPORTED, self::FILTER_UNSUPPORTED, self::FILTER_UNKNOWN], true)) {
            return $candidate;
        }

        if (array_key_exists('supports_filters', $data)) {
            return $data['supports_filters'] ? self::FILTER_SUPPORTED : self::FILTER_UNSUPPORTED;
        }

        return self::FILTER_SUPPORTED;
    }

    /**
     * 由 A1 的动作元数据自动产出（`auto_from_menu_actions` 路径）
     *
     * 目标名用 `{ident}.{controller_snake}` —— 与 {@see QueryFieldCatalog::targetNameOf()}
     * 以及 `MenuActionMeta::getPermission()` 的口径一致。
     */
    /**
     * @param string $filterSupport 由 {@see ListQueryTargetResolver} 判定（见 FILTER_* 常量）
     */
    public static function fromMeta(\wise\agent\menu\MenuActionMeta $meta, string $filterSupport = self::FILTER_SUPPORTED): self
    {
        return new self(
            name: $meta->getIdent() . '.' . $meta->getControllerName(),
            controller: $meta->controller,
            mode: self::MODE_AUTO,
            anchorPath: $meta->menuPath,
            title: $meta->menuName,
            getListAction: $meta->action,
            filterSupport: $filterSupport,
        );
    }

    /**
     * 是否可以安全地下发 filters / orders
     *
     * **只有 `supported` 才为真**：`unknown` 与 `unsupported` 都必须拒绝，否则会出现
     * 「AI 以为筛过了、实际拿到全量」的静默偏差（正是 A3 要消灭的行为）。
     */
    public function supportsFilters(): bool
    {
        return $this->filterSupport === self::FILTER_SUPPORTED;
    }

    /**
     * 筛选能力是否已确定（`unknown` = 需要宿主显式声明）
     */
    public function filterSupportKnown(): bool
    {
        return $this->filterSupport !== self::FILTER_UNKNOWN;
    }

    /**
     * 是否走 A1 的 `getList`（`builder` 语义）
     *
     * `auto` 由 A1 的执行面决定（A1 始终回调控制器的 `getList`），因此 `auto` 等价于 `builder`；
     * 只有显式声明 `model_search` 时才会走 A3-4 的独立执行路径。
     */
    public function usesBuilderMode(): bool
    {
        return $this->mode !== self::MODE_MODEL_SEARCH;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name'            => $this->name,
            'controller'      => $this->controller,
            'mode'            => $this->mode,
            'search_method'   => $this->searchMethod,
            'menu_path'       => $this->anchorPath,
            'title'           => $this->title,
            'aliases'         => $this->aliases,
            'get_list_action' => $this->getListAction,
            'filter_support'  => $this->filterSupport,
        ];
    }

    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        return $this->toArray();
    }
}
