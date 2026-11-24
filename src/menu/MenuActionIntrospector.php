<?php
declare(strict_types=1);

namespace wise\agent\menu;

use think\helper\Str;
use wise\agent\attribute\AgentActionReader;

/**
 * 「菜单 → 控制器动作」内省器（A1-2）
 *
 * ## 核心设计决策：以「控制器」为单位内省，菜单只做权限锚点
 *
 * `menus` 表每条记录只有**一个** url（`path`），既没有 `getList/save/delete` 的独立记录，
 * 也无法表达「这个控制器有哪些动作」。若以菜单为单位生成工具，一个列表页菜单只能映射到
 * 一个动作，而它恰恰是 HTML 的 `index`，等于什么也做不了＼�。
 *
 * 因此本内省器的流程是：
 * ```
 * 1. 读 menus（status=1 且未软删、path 非空）
 * 2. path → 控制器 FQCN            （parsePath，纯函数，可离线单测）
 * 3. 控制器准入：use CrudAction **或** 声明了 #[AgentAction]（否则跳过）
 * 4. 动作来源 = **控制器的 public 面**（标准动作）+ `#[AgentAction]`（非标准动作）
 * 5. 选一个「权限锚点菜单」          （非参数化 > type=menu > 段数少 > sort > id）
 * 6. 锚点 path → 权限标识 ident      （复用 MenuPermissionBridge，离线时回落到同源算法）
 * 7. 逐个动作生成 MenuActionMeta，并回填参数 Schema
 * ```
 *
 * > **AI 与人工看同一个入口**：trait 只提供 `_xxx()` 实现，控制器必须自己声明
 * > `public xxx()`；没有 public 方法的动作，人工路由不存在，AI 也不会生成工具。
 *
 * ## ⚠️ `customized` 判定不能用 `getDeclaringClass()`
 *
 * PHP 的 trait 方法在被 `use` 后会「看起来」声明在使用方类中：
 * ```
 * (new ReflectionMethod(Menu::class, 'getList'))->getDeclaringClass()->getName()
 *   → 'app\admin\controller\Menu'      // 不是 CrudAction！
 * ```
 * 因此判定方式是：**反射 trait 自身**取标准动作名与定义位置（file + startLine），
 * 再与控制器上的方法来比对 —— 位置相同即「继承自 CrudAction」，不同即「控制器重写」。
 * 重写仍然是一个合法动作（如 `app\admin\controller\Menu::getList` 返回全量列表），
 * 只是会把 `customized=true` 记进元数据，供 A1-4 在工具描述里提示模型「响应结构可能非标准分页」。
 *
 * ## 缓存
 *
 * `runtime/wise-agent/menu_actions.json`（结构与 A4 的 `console_commands.json` 同构）；
 * 失效来源：TTL、`cache_invalidated.flag`（插件装/卸时由 `ProviderCache::invalidate()` 写入）、
 * `php think wise:agent:discover --force`。
 *
 * ## 可测试性
 *
 * 与 DB / 容器交互的动作（`loadMenuRows` / `identOf` / `useContainer`）都是 `protected` 接缝，
 * 单测覆写即可离线覆盖全部分支；因此本类**刻意不加 `final`**（与 A1-1 的 resolver 一致）。
 */
class MenuActionIntrospector
{
    /** 宿主后台应用名（`/admin/**` → `app\admin\controller\*`） */
    public const ADMIN_APP = 'admin';

    /** 插件路由前缀（`/plugin/{ident}/{controller}[/{action}]`，与 `MenuPathNormalizer::PLUGIN_PREFIX` 同源） */
    public const PLUGIN_PREFIX = 'plugin';

    /** 标准动作所在的 trait（判定「是不是 CRUD 控制器」） */
    public const CRUD_ACTION_TRAIT = 'app\\common\\controller\\traits\\CrudAction';

    /** 缓存文件名（相对 `runtime/wise-agent/`） */
    private const CACHE_FILE = 'menu_actions.json';

    /**
     * 缓存结构版本
     *
     * 动作枚举来源从「反射 trait public 方法」改为「控制器 public 面 + 注解」后，
     * 旧缓存会让工具列表静默变空。版本不符一律重建。
     */
    private const CACHE_VERSION = 2;

    /** 缓存失效标记（与 A4 / ProviderCache 共用） */
    private const INVALIDATION_FLAG = 'cache_invalidated.flag';

    /** 权限标识生成来源：复用宿主桥接服务 */
    public const IDENT_BRIDGE = 'bridge';

    /** 权限标识生成来源：本包同源回落算法 */
    public const IDENT_FALLBACK = 'fallback';

    /** 进程内缓存 */
    private static ?array $memoryCache = null;

    /** @var array<string, mixed> */
    private array $options;

    private ?MenuActionSchemaResolver $resolver;

    private AgentActionReader $agentActionReader;

    /**
     * 注解读取结果缓存（类 → method => 元数据）
     *
     * `introspect()` 会按菜单行反复询问同一个控制器，缓存避免逐行反射整类。
     *
     * @var array<string, array<string, array<string, mixed>>>
     */
    private array $agentActionCache = [];

    /** @var array<int, array{step:string, message:string}> */
    private array $trace = [];

    private string $identSource = self::IDENT_FALLBACK;

    /**
     * @param array<string, mixed> $options  覆盖配置（默认读 `wise-agent.menu_actions.*`）
     * @param MenuActionSchemaResolver|null $resolver 参数 Schema 推导器（null 则惰性新建）
     * @param AgentActionReader|null $agentActionReader 注解读取器（null 则新建）
     */
    public function __construct(
        array $options = [],
        ?MenuActionSchemaResolver $resolver = null,
        ?AgentActionReader $agentActionReader = null
    ) {
        $this->options           = $options;
        $this->resolver          = $resolver;
        $this->agentActionReader = $agentActionReader ?? new AgentActionReader();
    }

    /**
     * 清空进程内缓存（长驻进程 / 测试用）
     */
    public static function resetMemoryCache(): void
    {
        self::$memoryCache = null;
    }


    /**
     * 解析全部「菜单可达的 CrudAction 动作」
     *
     * @param bool $force 强制跳过缓存
     * @return array<string, MenuActionMeta> 键为 `{controller}::{action}`
     */
    public function resolve(bool $force = false): array
    {
        if (!$force && self::$memoryCache !== null) {
            return self::$memoryCache;
        }

        if (!$force) {
            $cached = $this->readCache();
            if ($cached !== null) {
                $this->addTrace('cache.hit', sprintf('命中缓存，动作 %d 个', count($cached)));

                return self::$memoryCache = $cached;
            }
        }

        $metas = $this->introspect();
        $this->writeCache($metas);

        return self::$memoryCache = $metas;
    }

    /**
     * 强制重建缓存
     *
     * @return array<string, MenuActionMeta>
     */
    public function refresh(): array
    {
        self::$memoryCache = null;
        $this->clearCache();

        return $this->resolve(true);
    }

    /**
     * 真实内省（不读缓存，写缓存由 `resolve()` 负责）
     *
     * @param bool $withParameters 是否顺带推导参数 Schema（需要 DB / 容器时）
     * @return array<string, MenuActionMeta>
     */
    public function introspect(bool $withParameters = true): array
    {
        $this->trace = [];

        $rows = $this->loadMenuRows();
        $this->addTrace('menus', sprintf('读取菜单 %d 条', count($rows)));

        $grouped = [];

        foreach ($rows as $row) {
            $path = trim((string) ($row['path'] ?? ''));
            if ($path === '') {
                continue;
            }

            $parsed = static::parsePath($path, $this->defaultAction());
            if ($parsed === null) {
                $this->addTrace('path.skipped', '无法解析：' . $path);
                continue;
            }

            if (!$this->isAgentCapableController($parsed['controller'])) {
                $this->addTrace('controller.skipped', sprintf(
                    '%s（未使用 CrudAction、无 #[AgentAction]，或类不存在）',
                    $parsed['controller']
                ));
                continue;
            }

            if (!$this->inScope($parsed['controller'], $parsed['ident'])) {
                $this->addTrace('controller.outOfScope', $parsed['controller']);
                continue;
            }

            $grouped[$parsed['controller']]['rows'][] = $row + ['_path' => $path, '_parsed' => $parsed];
        }

        foreach ($this->extraControllers() as $controller) {
            if (isset($grouped[$controller])) {
                continue;
            }
            if (!$this->isAgentCapableController($controller)) {
                $this->addTrace('controller.skipped', $controller . '（手工声明但既非 CRUD 控制器、也无 #[AgentAction]）');
                continue;
            }
            if (!$this->opt('allow_unmapped_controllers', false)) {
                $this->addTrace('controller.unmapped', $controller . '（无菜单锚点且未开启 allow_unmapped_controllers）');
                continue;
            }
            $grouped[$controller]['rows'] = [];
        }

        $metas = [];

        foreach ($grouped as $controller => $group) {
            $anchor  = $this->pickAnchor($group['rows']);
            $anchorPath = (string) ($anchor['_path'] ?? '');
            $ident      = $anchorPath !== '' ? $this->identOf($anchorPath) : '';

            if ($anchorPath === '') {
                $this->identSource = self::IDENT_FALLBACK;
            }

            $actions = $this->controllerActions($controller);
            if ($actions === []) {
                $this->addTrace('controller.noActions', $controller);
                continue;
            }

            $anchorName = (string) ($anchor['name'] ?? '');
            $anchorType = (string) ($anchor['type'] ?? '');

            foreach ($actions as $action => $info) {
                $meta = MenuActionMeta::forAction($controller, $action, [
                    'menuPath'  => $anchorPath,
                    'menuName'  => $anchorName,
                    'menuIdent' => $ident,
                    'customized' => $info['customized'],
                    'annotation' => $info['annotation'] ?? [],
                ]);

                if ($withParameters) {
                    $this->schemaResolver()->resolveFor($meta);
                }

                $metas[$controller . '::' . $action] = $meta;
            }

            $this->addTrace('controller', sprintf(
                '%s ← 锚点 %s（%s/%s），动作 %d 个',
                $controller,
                $anchorPath !== '' ? $anchorPath : '(无)',
                $ident !== '' ? $ident : '-',
                $anchorType !== '' ? $anchorType : '-',
                count($actions)
            ));
        }

        ksort($metas);

        $this->addTrace('done', sprintf('动作合计 %d 个，权限标识来源 %s', count($metas), $this->identSource));

        return $metas;
    }

    /**
     * 单个控制器的动作清单（离线可用，不访问 DB）
     *
     * @return array<string, array{customized: bool}> action => 元信息
     */
    public function controllerActions(string $controllerClass): array
    {
        $controllerClass = ltrim($controllerClass, '\\');

        if (!class_exists($controllerClass)) {
            return [];
        }

        $rc      = new \ReflectionClass($controllerClass);
        $actions = [];

        if ($this->isCrudController($controllerClass)) {
            $traitMethods = $this->crudActionMethods();

            foreach (MenuActionMeta::allActions() as $action) {
                if (!$rc->hasMethod($action)) {
                    continue;
                }

                $method = $rc->getMethod($action);
                if (!$method->isPublic() || $method->isStatic() || $method->isAbstract()) {
                    continue;
                }

                $actions[$action] = [
                    'customized' => !isset($traitMethods[$action])
                        || [$method->getFileName(), $method->getStartLine()] !== $traitMethods[$action],
                    'annotation' => [],
                ];
            }
        }

        $annotations = $this->agentActionsOf($controllerClass);

        foreach ($annotations as $action => $annotation) {
            if (isset($actions[$action])) {
                $actions[$action]['annotation'] = $annotation;
                continue;
            }

            $actions[$action] = [
                'customized' => true,
                'annotation' => $annotation,
            ];
        }

        foreach ($this->extraActions() as $ref) {
            [$class, $action] = $ref;
            if ($class !== $controllerClass || !$rc->hasMethod($action)) {
                continue;
            }

            $method = $rc->getMethod($action);
            if (!$method->isPublic() || $method->isStatic() || $method->isAbstract()) {
                continue;
            }

            $actions[$action] = [
                'customized' => true,
                'annotation' => $actions[$action]['annotation'] ?? [],
            ];
        }

        return $actions;
    }

    /**
     * 上一次内省的轨迹（审计 / 调试 / 单测断言）
     *
     * @return array<int, array{step:string, message:string}>
     */
    public function trace(): array
    {
        return $this->trace;
    }

    /**
     * 权限标识生成来源：`bridge`（宿主桥接）| `fallback`（同源回落）
     */
    public function identSource(): string
    {
        return $this->identSource;
    }


    /**
     * 菜单 `path` → 控制器 + 动作（纯函数，可离线单测）
     *
     * 支持两种形态（与 `MenuPathNormalizer` 的段位约定一致）：
     * ```
     * /admin/{controller}/{action}[/key/value...]        → app\admin\controller\{Controller}
     * /plugin/{ident}/{controller}[/{action}][/k/v...]   → plugin\{ident}\controller\{Controller}
     * ```
     * 控制器段支持点分分层（`market.plugin` → `Market\Plugin`，与
     * `think\route\dispatch\Controller` 的 `layer + Str::studly` 解析一致）。
     *
     * @return array{source:string, ident:string, controller:string, controller_path:string, action:string, extras:array<string,string>, parameterized:bool}|null
     */
    public static function parsePath(string $path, string $defaultAction = 'index'): ?array
    {
        $segments = array_values(array_filter(
            explode('/', trim($path)),
            static fn(string $segment): bool => $segment !== ''
        ));

        if ($segments === []) {
            return null;
        }

        $head = strtolower($segments[0]);

        if ($head === self::PLUGIN_PREFIX) {
            if (count($segments) < 3) {
                return null;
            }

            $ident = $segments[1];
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $ident) !== 1) {
                return null;
            }

            $controllerPath = $segments[2];
            $action         = $segments[3] ?? $defaultAction;
            $extras         = array_slice($segments, 4);
            $controller     = 'plugin\\' . $ident . '\\controller\\' . self::controllerClassSuffix($controllerPath);
            $source         = MenuActionMeta::SOURCE_PLUGIN_PREFIX . $ident;
            $identKey       = $ident;
        } elseif ($head === self::ADMIN_APP) {
            if (count($segments) < 2) {
                return null;
            }

            $controllerPath = $segments[1];
            $action         = $segments[2] ?? $defaultAction;
            $extras         = array_slice($segments, 3);
            $controller     = 'app\\' . self::ADMIN_APP . '\\controller\\' . self::controllerClassSuffix($controllerPath);
            $source         = MenuActionMeta::SOURCE_HOST;
            $identKey       = MenuActionMeta::HOST_IDENT;
        } else {
            return null;
        }

        if ($controllerPath === '' || preg_match('/^[A-Za-z0-9_.]+$/', $controllerPath) !== 1) {
            return null;
        }
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $action) !== 1) {
            return null;
        }

        [$extraMap, $parameterized] = self::parseExtras($extras);

        return [
            'source'          => $source,
            'ident'           => $identKey,
            'controller'      => $controller,
            'controller_path' => $controllerPath,
            'action'          => $action,
            'extras'          => $extraMap,
            'parameterized'   => $parameterized,
        ];
    }

    /**
     * 控制器段 → 类名段（点分层 → 反斜杠分层，逐段 `Str::studly`）
     */
    private static function controllerClassSuffix(string $controllerPath): string
    {
        $parts = array_filter(explode('.', $controllerPath), static fn(string $s): bool => $s !== '');

        return implode('\\', array_map(static fn(string $s): string => Str::studly($s), $parts));
    }

    /**
     * 尾部段 → `key => value` 映射（`name/smartstock` → `['name' => 'smartstock']`）
     *
     * @param string[] $extras
     * @return array{0: array<string, string>, 1: bool} [映射, 是否参数化]
     */
    private static function parseExtras(array $extras): array
    {
        if ($extras === []) {
            return [[], false];
        }

        $map = [];
        $count = count($extras);

        for ($i = 0; $i < $count; $i += 2) {
            $key           = (string) $extras[$i];
            $map[$key]     = isset($extras[$i + 1]) ? (string) $extras[$i + 1] : '';
        }

        return [$map, true];
    }


    /**
     * `CrudAction` trait 的 public 动作 → 定义位置
     *
     * @return array<string, array{0: string|false, 1: int}>
     */
    private function crudActionMethods(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $cache = [];

        try {
            $rc = new \ReflectionClass(self::CRUD_ACTION_TRAIT);

            $visibility = \ReflectionMethod::IS_PUBLIC | \ReflectionMethod::IS_PROTECTED;

            foreach ($rc->getMethods($visibility) as $method) {
                if ($method->isStatic() || $method->isAbstract() || str_starts_with($method->getName(), '__')) {
                    continue;
                }

                $name   = $method->getName();
                $action = str_starts_with($name, '_') ? substr($name, 1) : $name;

                if (!in_array($action, MenuActionMeta::allActions(), true)) {
                    continue;
                }

                $cache[$action] = [$method->getFileName(), (int) $method->getStartLine()];
            }
        } catch (\Throwable $e) {
            $this->addTrace('crudAction.failed', $e->getMessage());
        }

        return $cache;
    }

    /**
     * 是否 CRUD 控制器（use 了 `CrudAction`）
     */
    public function isCrudController(string $controllerClass): bool
    {
        $controllerClass = ltrim($controllerClass, '\\');

        if (!class_exists($controllerClass)) {
            return false;
        }

        $traits = class_uses_recursive($controllerClass);

        return isset($traits[self::CRUD_ACTION_TRAIT]);
    }

    /**
     * 是否可被内省的控制器
     *
     * 两种来源：
     *   - CRUD 控制器（use CrudAction）：标准动作来自控制器 public 面；
     *   - 任意控制器：只要方法上声明了 `#[AgentAction]`。
     */
    public function isAgentCapableController(string $controllerClass): bool
    {
        if ($this->isCrudController($controllerClass)) {
            return true;
        }

        return $this->hasAgentActions($controllerClass);
    }

    /**
     * 控制器是否声明了任意 `#[AgentAction]`
     */
    public function hasAgentActions(string $controllerClass): bool
    {
        return $this->agentActionsOf(ltrim($controllerClass, '\\')) !== [];
    }

    /**
     * 读取并缓存控制器上的 `#[AgentAction]`
     *
     * @return array<string, array<string, mixed>>
     */
    private function agentActionsOf(string $controllerClass): array
    {
        if (isset($this->agentActionCache[$controllerClass])) {
            return $this->agentActionCache[$controllerClass];
        }

        if (!class_exists($controllerClass)) {
            return $this->agentActionCache[$controllerClass] = [];
        }

        try {
            $annotations = $this->agentActionReader->readAll(new \ReflectionClass($controllerClass));
        } catch (\Throwable $e) {
            $this->addTrace('annotation.reflectFailed', $controllerClass . '：' . $e->getMessage());

            return $this->agentActionCache[$controllerClass] = [];
        }

        foreach ($this->agentActionReader->errors() as $error) {
            $this->addTrace(
                'annotation.invalid',
                sprintf('%s::%s %s', $controllerClass, $error['method'], $error['message'])
            );
        }

        return $this->agentActionCache[$controllerClass] = $annotations;
    }


    /**
     * 选举权限锚点菜单
     *
     * 优先级：非参数化 > `type=menu` > 段数少 > `sort` 升序 > `id` 升序。
     *
     * @param array<int, array> $rows
     * @return array<string, mixed> 空数组表示无菜单（手工声明场景）
     */
    protected function pickAnchor(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $scored = [];
        foreach ($rows as $index => $row) {
            $path   = (string) ($row['_path'] ?? '');
            $parsed = $row['_parsed'] ?? [];
            $score  = 0;

            if (!empty($parsed['parameterized'])) {
                $score += 1000;
            }
            if ((string) ($row['type'] ?? '') !== 'menu') {
                $score += 100;
            }
            $score += count(array_filter(explode('/', trim($path)), static fn(string $s): bool => $s !== ''));

            $scored[] = [
                'score' => $score,
                'sort'  => (int) ($row['sort'] ?? 0),
                'id'    => (int) ($row['id'] ?? 0),
                'index' => $index,
                'row'   => $row,
            ];
        }

        usort($scored, static function (array $a, array $b): int {
            return [$a['score'], $a['sort'], $a['id'], $a['index']]
                <=> [$b['score'], $b['sort'], $b['id'], $b['index']];
        });

        return (array) $scored[0]['row'];
    }

    /**
     * path → 权限标识
     *
     * 优先复用宿主 `MenuPermissionBridge::generateIdent()`（尊重宿主的 `ident` 策略配置）；
     * 容器不可用（CLI 前置、单测）时回落到与 `path_to_dot` **同源**的算法，
     * 并把实际来源记进 `identSource()`，避免「静默用了另一套算法」。
     */
    protected function identOf(string $path): string
    {
        if ($this->useContainer() && class_exists(\app\admin\service\MenuPermissionBridge::class)) {
            try {
                $bridge = app()->make(\app\admin\service\MenuPermissionBridge::class);
                if (is_object($bridge) && method_exists($bridge, 'generateIdent')) {
                    $this->identSource = self::IDENT_BRIDGE;

                    return (string) $bridge->generateIdent($path);
                }
            } catch (\Throwable $e) {
                $this->addTrace('ident.fallback', '桥接不可用：' . $e->getMessage());
            }
        }

        $this->identSource = self::IDENT_FALLBACK;

        return self::fallbackIdent($path);
    }

    /**
     * `path_to_dot` 策略的同源实现（无容器时的兜底）
     *
     * 与 `app\admin\service\MenuPermissionBridge::generateIdent()` 默认分支一致：
     * `strtolower(str_replace('/', '.', Str::snake(trim($path, '/'))))`
     */
    public static function fallbackIdent(string $path): string
    {
        return strtolower(str_replace('/', '.', Str::snake(trim($path, '/'))));
    }


    /**
     * 控制器白 / 黑名单（前缀通配）
     */
    protected function inScope(string $controllerClass, string $ident): bool
    {
        $deny = (array) $this->opt('deny_controllers', []);
        if ($this->matchesAny($controllerClass, $ident, $deny)) {
            return false;
        }

        $allow = (array) $this->opt('allow_controllers', []);
        if ($allow === []) {
            return true;
        }

        return $this->matchesAny($controllerClass, $ident, $allow);
    }

    /**
     * @param array<int, mixed> $patterns
     */
    private function matchesAny(string $controllerClass, string $ident, array $patterns): bool
    {
        $candidates = [
            $controllerClass,
            ltrim($controllerClass, '\\'),
            $ident . '.' . basename(str_replace('\\', '/', $controllerClass)),
            $ident . ':' . basename(str_replace('\\', '/', $controllerClass)),
        ];

        foreach ($patterns as $pattern) {
            if (!is_string($pattern) || $pattern === '') {
                continue;
            }

            foreach ($candidates as $candidate) {
                if ($candidate === $pattern || $pattern === '*') {
                    return true;
                }

                if (str_contains($pattern, '*')) {
                    $regex = '#^' . str_replace('\*', '.*', preg_quote($pattern, '#')) . '$#i';
                    if (preg_match($regex, $candidate) === 1) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * @return string[]
     */
    private function extraControllers(): array
    {
        $list = [];
        foreach ((array) $this->opt('extra_controllers', []) as $item) {
            if (is_string($item) && $item !== '') {
                $list[] = ltrim($item, '\\');
            }
        }

        return $list;
    }

    /**
     * @return array<int, array{0: string, 1: string}>
     */
    private function extraActions(): array
    {
        $list = [];
        foreach ((array) $this->opt('extra_actions', []) as $item) {
            if (!is_string($item) || !str_contains($item, '::')) {
                continue;
            }
            [$class, $action] = explode('::', $item, 2);
            $class = ltrim(trim($class), '\\');
            $action = trim($action);
            if ($class === '' || $action === '') {
                continue;
            }
            $list[] = [$class, $action];
        }

        return $list;
    }


    /**
     * 读取菜单行（`status=1`、未软删、`path` 非空）
     *
     * @return array<int, array<string, mixed>>
     */
    protected function loadMenuRows(): array
    {
        if (!$this->useContainer() || !class_exists(\think\facade\Db::class)) {
            return [];
        }

        try {
            $rows = \think\facade\Db::name('menus')
                ->where('status', 1)
                ->whereNull('delete_time')
                ->where('path', '<>', '')
                ->order('sort', 'asc')
                ->order('id', 'asc')
                ->select()
                ->toArray();
        } catch (\Throwable $e) {
            $this->addTrace('menus.failed', $e->getMessage());

            return [];
        }

        return array_values(array_filter($rows, static fn($row): bool => is_array($row)));
    }

    /**
     * 容器是否可用（单测环境无 app）
     */
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

    private function schemaResolver(): MenuActionSchemaResolver
    {
        return $this->resolver ??= new MenuActionSchemaResolver();
    }

    private function defaultAction(): string
    {
        $configured = $this->opt('default_action', null);
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        if (function_exists('config')) {
            try {
                $value = config('route.default_action', null);
                if (is_string($value) && $value !== '') {
                    return $value;
                }
            } catch (\Throwable) {
            }
        }

        return 'index';
    }


    /**
     * 序列化（供缓存 / dump / 调试）
     *
     * @param array<string, MenuActionMeta> $metas
     * @return array<int, array<string, mixed>>
     */
    public static function toArrayList(array $metas): array
    {
        $list = [];
        foreach ($metas as $meta) {
            $list[] = $meta->toArray();
        }

        return $list;
    }

    /**
     * @param array<int, mixed> $list
     * @return array<string, MenuActionMeta>
     */
    public static function fromArrayList(array $list): array
    {
        $metas = [];
        foreach ($list as $item) {
            if (!is_array($item)) {
                continue;
            }

            $meta = MenuActionMeta::fromArray($item);
            if ($meta->controller === '' || $meta->action === '') {
                continue;
            }

            $metas[$meta->controller . '::' . $meta->action] = $meta;
        }

        ksort($metas);

        return $metas;
    }

    /**
     * 清空磁盘缓存
     */
    public function clearCache(): void
    {
        self::$memoryCache = null;

        $file = $this->cacheFile();
        if ($file !== null && is_file($file)) {
            @unlink($file);
        }
    }

    /**
     * @return array<string, MenuActionMeta>|null
     */
    private function readCache(): ?array
    {
        $file = $this->cacheFile();
        if ($file === null || !is_file($file)) {
            return null;
        }

        if (is_file(dirname($file) . DIRECTORY_SEPARATOR . self::INVALIDATION_FLAG)) {
            $this->addTrace('cache.invalidated', '失效标记存在');

            return null;
        }

        $raw = @file_get_contents($file);
        if ($raw === false || $raw === '') {
            return null;
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return null;
        }

        if ((int) ($data['version'] ?? 0) !== self::CACHE_VERSION) {
            $this->addTrace('cache.versionMismatch', '缓存结构版本不符，已重建');

            return null;
        }

        $ttl          = (int) $this->opt('cache_ttl', 1800);
        $discoveredAt = (int) ($data['discovered_at'] ?? 0);

        if ($ttl > 0 && time() - $discoveredAt > $ttl) {
            return null;
        }

        $actions = $data['actions'] ?? null;
        if (!is_array($actions)) {
            return null;
        }

        return self::fromArrayList($actions);
    }

    /**
     * @param array<string, MenuActionMeta> $metas
     */
    private function writeCache(array $metas): void
    {
        $file = $this->cacheFile();
        if ($file === null) {
            return;
        }

        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return;
        }

        $payload = json_encode([
            'version'       => self::CACHE_VERSION,
            'discovered_at' => time(),
            'ident_source'  => $this->identSource,
            'actions'       => self::toArrayList($metas),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($payload === false) {
            return;
        }

        @file_put_contents($file, $payload, LOCK_EX);
    }

    private function cacheFile(): ?string
    {
        $runtime = $this->runtimePath();
        if ($runtime === null) {
            return null;
        }

        return $runtime . 'wise-agent' . DIRECTORY_SEPARATOR . self::CACHE_FILE;
    }

    private function runtimePath(): ?string
    {
        if (!$this->useContainer()) {
            return null;
        }

        try {
            $app = app();
            return method_exists($app, 'getRuntimePath') ? $app->getRuntimePath() : null;
        } catch (\Throwable) {
            return null;
        }
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
            return config('wise-agent.menu_actions.' . $key, $default) ?? $default;
        } catch (\Throwable) {
            return $default;
        }
    }

    private function addTrace(string $step, string $message): void
    {
        $this->trace[] = ['step' => $step, 'message' => $message];
    }
}
