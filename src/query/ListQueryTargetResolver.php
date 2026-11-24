<?php
declare(strict_types=1);

namespace wise\agent\query;

use wise\agent\menu\MenuActionIntrospector;
use wise\agent\menu\MenuActionMeta;

/**
 * 查询目标解析器（A3-3）
 *
 * 两个来源，显式配置优先：
 *
 * 1. `list_query.targets`（手工声明：可指定 `mode` / `search_method` / `aliases`）；
 * 2. `list_query.auto_from_menu_actions=true` 时，从 **A1 的内省结果**自动产出
 *    （只取动作白名单里的 `getList` 元数据，目标名与 A1 的权限标识同构）。
 *
 * ## 为什么不在 A3 重新扫控制器
 *
 * A1-2 已经把「菜单 → 控制器 → 动作枚举 → 权限锚点」做完并缓存进
 * `runtime/wise-agent/menu_actions.json`。A3 直接消费，既避免两处口径漂移，
 * 也天然继承了 A1 的护栏（例如 `customized` 标记、动作白名单）。
 */
class ListQueryTargetResolver
{
    /** 「方法体是否消费查询参数」的判定关键词 */
    private const BUILDER_MARKERS = ['applyQueryToModel', 'buildQueryParams', 'RequestQueryBuilder', 'queryBuilder'];

    private MenuActionIntrospector $introspector;

    /** @var array<string, mixed> */
    private array $options;

    /** @var array<string, ListQueryTarget>|null 进程内缓存 */
    private static ?array $cache = null;

    /** @var array<int, array{step:string, message:string}> */
    private array $trace = [];

    /**
     * @param array<string, mixed> $options `list_query.*` 覆盖项
     */
    public function __construct(?MenuActionIntrospector $introspector = null, array $options = [])
    {
        $this->introspector = $introspector ?? new MenuActionIntrospector();
        $this->options      = $options;
    }

    /**
     * 清空进程内缓存（单测 / 长驻进程）
     */
    public static function resetCache(): void
    {
        self::$cache = null;
    }

    /**
     * 全部可用目标
     *
     * @param bool $force 强制重建（忽略进程内缓存）
     * @return array<string, ListQueryTarget>
     */
    public function resolve(bool $force = false): array
    {
        if (!$force && self::$cache !== null) {
            return self::$cache;
        }

        $targets = [];

        foreach ((array) $this->opt('targets', []) as $name => $data) {
            if (!is_array($data)) {
                continue;
            }

            $target = ListQueryTarget::fromArray((string) $name, $data);
            if ($target !== null) {
                $targets[$target->name] = $target;
            }
        }

        if ((bool) $this->opt('auto_from_menu_actions', true)) {
            foreach ($this->targetsFromIntrospection() as $name => $target) {
                if (!isset($targets[$name])) {
                    $targets[$name] = $target;
                }
            }
        }

        return self::$cache = $targets;
    }

    public function get(string $name): ?ListQueryTarget
    {
        return $this->resolve()[$name] ?? null;
    }

    /**
     * @return string[]
     */
    public function names(): array
    {
        return array_keys($this->resolve());
    }

    /**
     * 从 A1 的动作元数据产出目标（`getList` 且可暴露为工具的动作）
     *
     * ⚠️ **重写的 `getList` 会被检查是否真的消费查询参数**（{@see self::supportsFilters()}）：
     * 实测 `app\admin\controller\Menu::getList()` 是 `MenuModel::order(...)->select()` 全量返回，
     * **完全忽略查询参数**。若把它暴露成 A3 目标，AI 会拿到「未被筛选的全量数据」却以为筛过了
     * —— 这正是 A3 要消灭的静默偏差，因此默认跳过（`skip_filter_unsupported=true`）。
     *
     * @return array<string, ListQueryTarget>
     */
    protected function targetsFromIntrospection(): array
    {
        $targets = [];

        try {
            $metas = $this->introspector->resolve(false);
        } catch (\Throwable) {
            return [];
        }

        foreach ($metas as $meta) {
            if (!$meta instanceof MenuActionMeta) {
                continue;
            }

            if ($meta->action !== 'getList' || !$meta->isToolCandidate()) {
                continue;
            }

            $support = $this->detectFilterSupport($meta);

            if ($support === ListQueryTarget::FILTER_UNSUPPORTED && (bool) $this->opt('skip_filter_unsupported', true)) {
                $this->addTrace('target.skipped', sprintf(
                    '%s::%s 的 getList 未使用查询构建器（筛选/排序会被静默忽略）→ 不暴露为查询目标',
                    $meta->controller,
                    $meta->action
                ));
                continue;
            }

            if ($support !== ListQueryTarget::FILTER_SUPPORTED) {
                $this->addTrace('target.filterSupport', sprintf(
                    '%s::%s 的 getList 被重写，筛选能力判为 %s（如需筛选请显式声明 supports_filters 或改用 mode=model_search）',
                    $meta->controller,
                    $meta->action,
                    $support
                ));
            }

            $target = ListQueryTarget::fromMeta($meta, $support);

            if ($target->name !== '' && !isset($targets[$target->name])) {
                $targets[$target->name] = $target;
            }
        }

        return $targets;
    }

    /**
     * 判定 `getList` 的筛选能力（三态，见 `ListQueryTarget::FILTER_*`）
     *
     * - 标准 `CrudAction::getList`（`customized=false`）→ `supported`（一定走 `RequestQueryBuilder`）；
     * - 重写的方法体**完全没有**构建器引用 → `unsupported`：它不可能消费 `字段__op`；
     * - 重写的方法体引用了构建器 → 默认 `unknown`（静态无法确认是否真的用上），
     *   除非宿主把 `customized_getlist_policy` 设为 `trust` 才按 `supported` 处理。
     *
     * 为什么默认不信任启发式：实测 `LoginLog::getList()` 调了 `buildQueryParams()` 却把过滤代码
     * 全部注释、只用了分页 —— 任何文本启发式都会在这里判错，而判错的代价是**静默返回全量**。
     */
    protected function detectFilterSupport(MenuActionMeta $meta): string
    {
        if (!$meta->customized) {
            return ListQueryTarget::FILTER_SUPPORTED;
        }

        $usesBuilder = $this->bodyUsesBuilder($meta);
        $policy      = strtolower(trim((string) $this->opt('customized_getlist_policy', 'strict')));

        if ($policy === 'trust') {
            return $usesBuilder ? ListQueryTarget::FILTER_SUPPORTED : ListQueryTarget::FILTER_UNSUPPORTED;
        }

        return $usesBuilder ? ListQueryTarget::FILTER_UNKNOWN : ListQueryTarget::FILTER_UNSUPPORTED;
    }

    /**
     * 方法体是否引用查询构建器
     *
     * 只看**存在性**（`applyQueryToModel` / `buildQueryParams` / `RequestQueryBuilder` / `queryBuilder`）；
     * 无引用 ⇒ 一定不支持筛选，有引用 ⇒ 只能说明「可能支持」。
     */
    protected function bodyUsesBuilder(MenuActionMeta $meta): bool
    {
        if (!$meta->customized) {
            return true;
        }

        $body = $this->methodBody($meta);

        if ($body === null) {
            return true;
        }

        foreach (self::BUILDER_MARKERS as $marker) {
            if (str_contains($body, $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 读取动作方法的源码片段（`protected` 接缝：单测可注入固定方法体）
     *
     * 返回 `null` 表示无法读取（反射失败 / 文件不可读）。
     */
    protected function methodBody(MenuActionMeta $meta): ?string
    {
        try {
            $method = new \ReflectionMethod($meta->controller, $meta->action);
            $file   = $method->getFileName();

            if ($file === false || !is_readable($file)) {
                return null;
            }

            $lines = file($file);
            if ($lines === false) {
                return null;
            }

            return implode('', array_slice(
                $lines,
                $method->getStartLine() - 1,
                max(1, $method->getEndLine() - $method->getStartLine() + 1)
            ));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<int, array{step:string, message:string}>
     */
    public function trace(): array
    {
        return $this->trace;
    }

    private function addTrace(string $step, string $message): void
    {
        $this->trace[] = ['step' => $step, 'message' => $message];
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
