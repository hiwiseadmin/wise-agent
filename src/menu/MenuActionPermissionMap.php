<?php
declare(strict_types=1);

namespace wise\agent\menu;

use think\facade\Db;

/**
 * 动作级权限点目录（A1-5）
 *
 * ## 为什么需要它
 *
 * 「AI 代替人工」要求 AI 的访问面**不大于**人工。人工在后台点某个按钮时，浏览器请求的是
 * `/{app}/{controller}/{action}`，由 `RbacMiddleware` 用 `RbacManager::matchRouteToPermissions()`
 * 匹配到 `auth_permissions` 里 `type='route'` 的行动作级权限点；而 `wise-auth.default_mode`
 * 默认 `whitelist` —— **匹配不到权限点的路由，人工自己也会被 403**。
 *
 * 因此「路由是否存在权限点」直接等价于「人工是否可能访问该动作」。本类提供这一判断：
 *   - 注册期用它**过滤掉人工本就不可达的动作**（不生成必然 403 的工具）；
 *   - 对拍验收用它解释「为什么某个动作 AI 不能执行」（人工同样不能）。
 *
 * ## 路由与方法的推导口径（与人工完全一致）
 *
 * - **路由**：动作路由 = 权限锚点 path 去掉动作段后的**基路径** + `/{action小写}`。
 *   - 锚点 `/admin/menu/index` → 基路径 `/admin/menu` → `getList` → `/admin/menu/getlist`
 *   - 锚点 `/plugin/smartstock/product` → 基路径 `/plugin/smartstock/product` → `/plugin/smartstock/product/delete`
 *   这与后台「按钮菜单」写 path 的习惯（`/admin/menu/delete`）以及
 *   `auth_permissions.route`（存菜单 path 原样，见 `MenuPermissionBridge::transform()`）天然同构，
 *   因此**不需要**从 FQCN 反推路由，插件路径同样成立。
 * - **方法**：与 `MenuPermissionBridge::inferMethod()` 的 `path_suffix` 策略同源
 *   （`config/menu_permission.php`）：`save/update/edit/create/store` → POST，`delete/remove` → DELETE，其余 GET。
 *   ⚠️ 必须一致，否则 `methodMatch()` 会让匹配恒失败 —— 实测 `delete` 的权限点 method 是 DELETE，
 *   若按 POST 匹配则 `/admin/menu/delete` 永远匹配不到权限点（人工/AI 都会 403）。
 *
 * > **只做「存在性」判断，不做「是否已授权」判断** —— 授权判定唯一实现是
 * > `RbacMiddleware`（见 {@see MenuActionPipeline}），避免出现两套判定导致权限漂移。
 */
class MenuActionPermissionMap
{
    /**
     * 动作 → 人工访问时的 HTTP 方法（与 `config/menu_permission.php` 的
     * `method.path_suffix.pattern` 严格一致）
     *
     * @var array<string, string>
     */
    public const METHOD_BY_ACTION = [
        'save'   => 'POST',
        'update' => 'POST',
        'edit'   => 'POST',
        'create' => 'POST',
        'store'  => 'POST',
        'delete' => 'DELETE',
        'remove' => 'DELETE',
    ];

    /** 未命中 `METHOD_BY_ACTION` 时的默认方法 */
    public const DEFAULT_METHOD = 'GET';

    /** @var array<string, mixed> */
    private array $options;

    /** @var array<string, array{ident:string, method:string}>|null route → 权限点 */
    private static ?array $routeMap = null;

    /** @var array<string, string> 单请求内的动作 → HTTP 方法缓存 */
    private array $resolvedMethods = [];

    public function __construct(array $options = [])
    {
        $this->options = $options;
    }

    /**
     * 清空进程内缓存（长驻进程 / 测试用）
     */
    public static function resetCache(): void
    {
        self::$routeMap = null;
    }


    /**
     * 动作 → 人工访问时使用的 HTTP 方法（**兜底常量**）
     *
     * 注意：这不是事实源。事实源是 `auth_permissions.method`（RbacMiddleware 就拿它比对），
     * 其次是宿主 `MenuPermissionBridge::inferMethod()`（尊重 `config/menu_permission.php`
     * 的四种 strategy）。运行时请用 {@see self::resolveMethod()}。
     */
    public static function methodOf(string $action): string
    {
        return self::METHOD_BY_ACTION[$action] ?? self::DEFAULT_METHOD;
    }

    /**
     * 解析某个动作「人工访问时使用的 HTTP 方法」
     *
     * 顺序（前三步任一命中即返回，避免 AI 与人工的判定来源分裂）：
     *   1. `auth_permissions.method`（权限行；`A,B` 列表时按动作语义挑一个具体值；
     *      `*` / `ANY` / `ALL` 视为「不限方法」，**继续走第 2 步**，而不是直接落到常量）
     *   2. `MenuPermissionBridge::inferMethod(route)`（完整支持 path_suffix / always_* / callback）
     *   3. {@see self::methodOf()} 常量兜底（离线 / 无容器 / 权限行缺失）
     *
     * 合成请求的 method 会进入 `RbacMiddleware::matchRouteToPermissions()`，
     * 因此必须与权限行一致，否则 AI 与人工会出现「一边通、一边 403」。
     */
    public function resolveMethod(MenuActionMeta $meta): string
    {
        $cacheKey = $meta->controller . '::' . $meta->action . '|' . $meta->menuPath;

        if (isset($this->resolvedMethods[$cacheKey])) {
            return $this->resolvedMethods[$cacheKey];
        }

        if ($meta->menuPath !== '') {
            $row = $this->describe($meta->menuPath, $meta->action);

            if ($row !== null) {
                $concrete = $this->concreteMethod($row['method'], $meta->action);

                if ($concrete !== null) {
                    return $this->resolvedMethods[$cacheKey] = $concrete;
                }

            }
        }

        if ($meta->menuPath !== '' && $this->useContainer()
            && class_exists(\app\admin\service\MenuPermissionBridge::class)) {
            try {
                $bridge = app()->make(\app\admin\service\MenuPermissionBridge::class);
                $route  = self::routeOf($meta->menuPath, $meta->action);

                if (is_object($bridge) && method_exists($bridge, 'inferMethod')) {
                    $concrete = $this->concreteMethod(
                        (string) $bridge->inferMethod($route),
                        $meta->action
                    );

                    if ($concrete !== null) {
                        return $this->resolvedMethods[$cacheKey] = $concrete;
                    }
                }
            } catch (\Throwable) {
            }
        }

        return $this->resolvedMethods[$cacheKey] = self::methodOf($meta->action);
    }

    /**
     * 把权限行/配置给出的 method 归一为「可实际发送」的具体方法
     *
     * @return string|null null = 声明为不限方法（`*` / `ANY` / `ALL` / 空），交由下一级推导
     */
    private function concreteMethod(string $declared, string $action): ?string
    {
        $declared = strtoupper(trim($declared));

        if ($declared === '' || $declared === '*' || $declared === 'ANY' || $declared === 'ALL') {
            return null;
        }

        $allowed = array_values(array_filter(array_map(
            static fn(string $item): string => strtoupper(trim($item)),
            explode(',', $declared)
        )));

        if ($allowed === []) {
            return null;
        }

        $preferred = self::methodOf($action);

        return in_array($preferred, $allowed, true) ? $preferred : $allowed[0];
    }

    /**
     * 容器是否可用（离线单测 / CLI 前置）
     */
    private function useContainer(): bool
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

    /**
     * 权限锚点 path → 该控制器的基路径（去掉动作段）
     *
     * ```
     * /admin/menu/index            → /admin/menu
     * /admin/menu                  → /admin/menu
     * /plugin/x/product            → /plugin/x/product
     * /plugin/x/product/index      → /plugin/x/product
     * /admin/plugin/configIndex/name/smartstock → /admin/plugin
     * ```
     */
    public static function basePathOf(string $anchorPath): string
    {
        $segments = array_values(array_filter(explode('/', trim($anchorPath)), static fn($s) => $s !== ''));
        if ($segments === []) {
            return '';
        }

        $parsed = MenuActionIntrospector::parsePath($anchorPath);

        $take = ($parsed !== null && $parsed['source'] === MenuActionMeta::SOURCE_HOST) ? 2 : 3;

        return '/' . implode('/', array_slice($segments, 0, $take));
    }

    /**
     * 动作路由（= 人工点该按钮时浏览器请求的路径）
     */
    public static function routeOf(string $anchorPath, string $action): string
    {
        return self::basePathOf($anchorPath) . '/' . strtolower($action);
    }


    /**
     * 该动作路由是否存在行动作级权限点
     *
     * @return bool|null true 存在 / false 不存在（人工也会被 whitelist 拒绝）/ null 权限数据不可用
     */
    public function hasPermissionPoint(string $route): ?bool
    {
        $map = $this->loadRouteMap();
        if ($map === null) {
            return null;
        }

        return isset($map[$this->normalizeRoute($route)]);
    }

    /**
     * 该路由是否被 `whitelist_routes` 放行（放行 → 不需要权限点，人工与 AI 都可访问）
     */
    public function isBypassedRoute(string $route): bool
    {
        $patterns = $this->whitelistRoutes();
        if ($patterns === []) {
            return false;
        }

        $route = trim($route, '/');

        foreach ($patterns as $pattern) {
            if (!is_string($pattern) || $pattern === '') {
                continue;
            }

            $pattern = trim($pattern, '/');
            if ($pattern === $route) {
                return true;
            }

            if (str_contains($pattern, '*')
                && preg_match('#^' . str_replace('\*', '.*', preg_quote($pattern, '#')) . '$#', $route) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * 该动作是否可能被人工访问（= 有权限点，或被白名单路由放行）
     *
     * @return bool|null null = 权限数据不可用（调用方应保守处理）
     */
    public function isHumanReachable(string $anchorPath, string $action): ?bool
    {
        $route = self::routeOf($anchorPath, $action);

        if ($this->isBypassedRoute($route)) {
            return true;
        }

        return $this->hasPermissionPoint($route);
    }

    /**
     * 动作 → 权限点行（route / ident / method）
     *
     * @return array{route:string, ident:string, method:string, action:string}|null
     */
    public function describe(string $anchorPath, string $action): ?array
    {
        $route = self::routeOf($anchorPath, $action);
        $map   = $this->loadRouteMap();
        $row   = $map[$this->normalizeRoute($route)] ?? null;

        if ($row === null) {
            return null;
        }

        return [
            'route'  => $route,
            'ident'  => $row['ident'],
            'method' => $row['method'],
            'action' => $action,
        ];
    }

    /**
     * 全量 route → 权限点映射（进程内缓存）
     *
     * @return array<string, array{ident:string, method:string}>|null
     */
    public function loadRouteMap(): ?array
    {
        if (self::$routeMap !== null) {
            return self::$routeMap;
        }

        if (!$this->useDb()) {
            return null;
        }

        try {
            $rows = Db::name('auth_permissions')
                ->where('type', 'route')
                ->where('status', 1)
                ->column('ident,method,route', 'route');
        } catch (\Throwable) {
            return null;
        }

        $map = [];
        foreach ($rows as $route => $item) {
            if (!is_array($item) || !isset($item['ident'])) {
                continue;
            }
            $map[$this->normalizeRoute((string) $route)] = [
                'ident'  => (string) $item['ident'],
                'method' => (string) ($item['method'] ?? '*'),
            ];
        }

        return self::$routeMap = $map;
    }


    /**
     * 与前导斜杠无关（`RbacManager::normalizeRoute()` 同口径）
     */
    private function normalizeRoute(string $route): string
    {
        return ltrim(trim($route), '/');
    }

    /**
     * @return array<int, mixed>
     */
    private function whitelistRoutes(): array
    {
        if (array_key_exists('whitelist_routes', $this->options)) {
            $routes = $this->options['whitelist_routes'];

            return is_array($routes) ? array_values($routes) : [];
        }

        if (!function_exists('config')) {
            return [];
        }

        try {
            $routes = config('wise-auth.whitelist_routes', []);

            return is_array($routes) ? array_values($routes) : [];
        } catch (\Throwable) {
            return [];
        }
    }

    protected function useDb(): bool
    {
        if (!function_exists('app') || !class_exists(Db::class)) {
            return false;
        }

        try {
            return is_object(app());
        } catch (\Throwable) {
            return false;
        }
    }
}
