<?php
declare(strict_types=1);

namespace wise\agent\menu;

use think\helper\Str;

/**
 * 控制器动作就地执行器（A1-3 / A1-5）
 *
 * ## 为什么能「就地复用」控制器
 *
 * `app\BaseController::__construct(App $app)` 只做三件事（`src/app/BaseController.php:111`）：
 * ```php
 * $this->app     = $app;
 * $this->request = $this->app->request;              // ← 全局单例 request
 * $this->queryBuilder = new RequestQueryBuilder($this->request);
 * $this->initialize();
 * ```
 * 而全部 CRUD 动作都只通过 `$this->request->param(...)` 取参、通过 `$this->getModel()` 取模型。
 * **结论：只要让控制器拿到一个「装着目标参数的 Request」，就能原样执行该动作，业务逻辑零重复实现。**
 *
 * ## 执行链路（A1-5 起：AI 走人工同一条中间件链）
 * ```
 * 1. 入参校验与归一（validateArguments）
 * 2. 合成 Request：复制真实请求的 server/header/cookie（同一会话同一 IP/UA）
 *    + 只替换 method（按动作映射）/ params / controller / layer / action / pathinfo（动作路由）
 * 3. app()->instance('request', $合成请求)              ← 换绑容器，而非只改控制器属性
 * 4. MenuActionPipeline::run()                          ← 人工同款中间件链（含 RbacMiddleware 权限判定）
 *      └─ 最内层：只读沙箱 ? 影响面预演 : 控制器动作调用
 * 5. MenuActionResult::normalize()                      ← 统一「观察」契约
 * 6. finally：app()->instance('request', $origin)        ← 严格还原
 * ```
 * 权限判定**不在本类**：由链上的 `RbacMiddleware` 用人工同款口径（
 * `route + method` → `auth_permissions` 行动作级权限点 → `checkAny/checkAll`）完成，
 * 从而保证「AI 的访问面 ≤ 人工」，不出现第二套判定。
 *
 * ## 两个必须遵守的细节
 *
 * 1. **每次调用必须新建 Request**：`Request::param()` 有 `mergeParam` 一次性合并语义
 *    （`Request.php:798-814`），复用实例会导致参数串味。
 * 2. **必须 `make($class, [], true)` 强制新实例**：`Container::make()` 会缓存构造结果
 *    （`think-container/src/Container.php:244-264`），复用实例会让控制器把 `$this->request`
 *    钉死在上一次的合成请求上（参数串味 + `initialize()` 不再执行 + `RequestQueryBuilder`
 *    沿用旧 `queryParams`）。ThinkPHP 自身的路由分发同样是 `make($class, [], true)`
 *    （`think/route/dispatch/Controller.php:132`）。
 *
 * ## 可测试性
 * `useContainer` / `makeController` / `instantiate`（pipeline）是 `protected` 接缝；
 * `validateArguments()` / `buildRequest()` / `actionRouteOf()` 完全不依赖容器，可离线单测。
 */
class MenuActionInvoker
{
    /** 读动作的 HTTP 方法（未在权限点方法表内的动作） */
    public const METHOD_READ = 'GET';

    /** 写动作的 HTTP 方法 */
    public const METHOD_WRITE = 'POST';

    /**
     * 查询语义的保留键（这些键直接透传给 `RequestQueryBuilder`）
     *
     * @var string[]
     */
    private const RESERVED_QUERY_KEYS = [
        'page', 'limit', 'pagesize', 'offset', 'order', 'sort',
        'field', 'fields', 'select', 'with', 'with_count', 'group', 'having',
    ];

    /** 通用 filters 数组允许的操作符（不含 exists —— 见附录三现状事实 2） */
    private const FILTER_OPERATORS = [
        'eq', 'ne', 'neq', 'gt', 'lt', 'gte', 'ge', 'lte', 'le',
        'like', 'notlike', 'in', 'notin', 'between', 'notbetween',
    ];

    /** 需要 `id` 或 `ids` 的动作 */
    private const ACTIONS_REQUIRING_ID = ['update', 'delete', 'restore', 'forceDelete', 'status', 'sort'];

    /** `CrudAction::sort()` 的排序字段白名单（与源码同源） */
    private const DEFAULT_SORT_FIELDS = ['sort', 'weigh', 'order_weight'];

    /** @var array<string, mixed> */
    private array $options;

    /** @var array<int, array{step:string, message:string}> */
    private array $trace = [];

    private ?MenuActionPipeline $pipeline;

    private ?MenuActionGuard $guard;

    private ?MenuActionPermissionMap $permissionMapResolver = null;

    /**
     * @param array<string, mixed> $options 覆盖配置（默认读 `wise-agent.menu_actions.*`）
     */
    public function __construct(
        array $options = [],
        ?MenuActionPipeline $pipeline = null,
        ?MenuActionGuard $guard = null,
        ?MenuActionPermissionMap $permissionMap = null
    ) {
        $this->options              = $options;
        $this->pipeline             = $pipeline;
        $this->guard                = $guard;
        $this->permissionMapResolver = $permissionMap;
    }

    /**
     * 执行一个控制器动作
     *
     * 任何失败都返回同核结构（不抛异常），保证 Agent 的 ReAct 循环稳定。
     *
     * @param MenuActionMeta       $meta
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function invoke(MenuActionMeta $meta, array $arguments): array
    {
        $this->trace = [];
        $startedAt   = microtime(true);

        $context = [
            'controller' => $meta->controller,
            'action'     => $meta->action,
            'menu_path'  => $meta->menuPath,
        ];

        if (MenuActionMeta::isHtmlAction($meta->action) || !$meta->isToolCandidate()) {
            $this->addTrace('action.rejected', $meta->action . ' 不是可执行动作');

            return MenuActionResult::failure(
                sprintf('动作 %s 不提供 AI 执行（HTML 动作或未知动作）', $meta->action),
                1,
                array_merge($this->failContext($context, $startedAt), ['stage' => MenuActionResult::STAGE_ACTION])
            );
        }

        try {
            $params = $this->validateArguments($meta, $arguments);
        } catch (\Throwable $e) {
            $this->addTrace('arguments.invalid', $e->getMessage());

            return MenuActionResult::failure(
                'Invalid arguments: ' . $e->getMessage(),
                1,
                array_merge($this->failContext($context, $startedAt), ['stage' => MenuActionResult::STAGE_ARGUMENTS])
            );
        }

        if (!$this->useContainer()) {
            return MenuActionResult::failure(
                'Container is not available; controller actions can only be executed inside a booted application.',
                1,
                array_merge($this->failContext($context, $startedAt), ['stage' => MenuActionResult::STAGE_EXECUTION])
            );
        }

        $app = app();

        try {
            $origin = $app->request ?? null;
        } catch (\Throwable) {
            $origin = null;
        }

        try {
            $request = $this->buildRequest($meta, $params, $origin instanceof \think\Request ? $origin : null);
            $app->instance('request', $request);

            $guard     = $this->guard();
            $sandboxed = $guard->isSandboxed($meta);

            $outcome = $this->pipeline()->run($request, function () use ($meta, $params, $guard, $sandboxed) {
                if ($sandboxed) {
                    return $guard->preview($meta, $params);
                }

                $controller = $this->makeController($meta->controller);
                if ($controller === null) {
                    throw new \RuntimeException('控制器不可实例化：' . $meta->controller);
                }

                return $controller->{$meta->action}();
            });

            if (!($outcome['passed'] ?? false)) {
                return $this->translateBlocked($outcome, $meta, $context, $startedAt, $sandboxed);
            }

            $this->addTrace('invoked', sprintf('%s::%s()', $meta->controller, $meta->action));

            return MenuActionResult::normalize($outcome['result'], $context + [
                'duration_ms'     => $this->elapsed($startedAt),
                'max_text_length' => (int) $this->opt('max_text_length', MenuActionResult::DEFAULT_MAX_TEXT),
            ]);
        } catch (\Throwable $e) {
            $this->addTrace('execution.exception', get_class($e) . ': ' . $e->getMessage());

            return MenuActionResult::fromException($e, $context + [
                'duration_ms' => $this->elapsed($startedAt),
                'stage'       => MenuActionResult::STAGE_EXECUTION,
            ]);
        } finally {
            if (($origin ?? null) instanceof \think\Request) {
                $app->instance('request', $origin);
                $this->addTrace('restored', '容器 request 已还原');
            } else {
                $this->addTrace('restored.skipped', '原始 request 不可用，未还原');
            }
        }
    }

    /**
     * 入参校验 + 归一为「扁平查询参数」＼�
     *
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     * @throws \InvalidArgumentException
     */
    public function validateArguments(MenuActionMeta $meta, array $arguments): array
    {
        $params = $this->parseFilters($arguments);

        $declared    = $this->declaredProperties($meta);
        $sortFields  = $this->sortFields();
        $genericMode = in_array('filters', $declared, true);

        foreach ($params as $key => $value) {
            $key = (string) $key;

            if (!$this->isWellFormedKey($key)) {
                throw new \InvalidArgumentException(sprintf(
                    "参数名 '%s' 非法（只允许 `字段` 或 `字段__操作符`，操作符限：%s）",
                    $key,
                    implode('/', self::FILTER_OPERATORS)
                ));
            }

            if (!$genericMode
                && $declared !== []
                && !in_array($key, $declared, true)
                && !in_array($key, self::RESERVED_QUERY_KEYS, true)
            ) {
                throw new \InvalidArgumentException(sprintf('参数 %s 未在该动作的 Schema 中声明', $key));
            }

            $this->assertValue($key, $value);
        }

        $action = $meta->action;

        if ($meta->fromAnnotation) {
            foreach ((array) ($meta->annotation['required'] ?? []) as $required) {
                if (!array_key_exists((string) $required, $params)) {
                    throw new \InvalidArgumentException(sprintf('参数 %s 必填', $required));
                }
            }
        } elseif (in_array($action, self::ACTIONS_REQUIRING_ID, true) && !$this->hasId($params)) {
            throw new \InvalidArgumentException('id 与 ids 至少提供一个');
        }

        if ($action === 'status' && !isset($params['status'])) {
            throw new \InvalidArgumentException('status 必须提供');
        }

        if ($action === 'sort') {
            if (!isset($params['sort'])) {
                throw new \InvalidArgumentException('sort 必须提供');
            }
            if (isset($params['sort_field']) && !in_array((string) $params['sort_field'], $sortFields, true)) {
                throw new \InvalidArgumentException(sprintf('sort_field 只允许：%s', implode('/', $sortFields)));
            }
        }

        return $this->normalizeValues($params, $meta);
    }

    /**
     * 构造合成 Request
     *
     * 关键点（保证与人工同源）：
     *   - **复制真实请求的 `server` / `header` / `cookie`**：审计日志里的 IP / User-Agent / 租户
     *     与人工一致；`RbacMiddleware` 的 `isAjax()`、`IpGuardMiddleware` 的 `ip()` 也据此工作。
     *   - **只替换** method（按 `MenuActionPermissionMap::methodOf()`，与权限点 method 同源）/
     *     get+post 参数 / controller / layer / action / pathinfo（**动作路由**，不是锚点路径）。
     *   - 参数**同时写入 get 与 post**：`param()` 按原始 HTTP 方法决定合并哪一侧。
     *   - 追加 `audit_user_agent_marker`：让审计日志可区分「AI 代做」，零 schema 变更。
     */
    public function buildRequest(MenuActionMeta $meta, array $params, ?\think\Request $origin = null): \think\Request
    {
        $request = $this->newRequest($origin);

        if ($origin instanceof \think\Request) {
            $server = $origin->server();
            if (is_array($server) && $server !== []) {
                $request->withServer($server);
            }

            $headers = $origin->header();
            if (is_array($headers) && $headers !== []) {
                $request->withHeader($headers);
            }

            $cookie = $origin->cookie();
            if (is_array($cookie) && $cookie !== []) {
                $request->withCookie($cookie);
            }
        }

        $marker  = trim((string) $this->opt('audit_user_agent_marker', 'WiseAgent/menu-action'));
        $headers = $request->header();
        $headers = is_array($headers) ? $headers : [];
        if ($marker !== '') {
            $headers['user-agent'] = trim(($headers['user-agent'] ?? '') . ' ' . $marker);
            $request->withHeader($headers);
        }

        $request->withGet($params)
            ->withPost($params)
            ->setMethod($this->methodOf($meta))
            ->setController($this->controllerName($meta))
            ->setAction($meta->action);

        $layer = $this->layerOf($meta);
        if ($layer !== '') {
            $request->setLayer($layer);
        }

        $route = $this->actionRouteOf($meta);
        if ($route !== '') {
            $request->setPathinfo($route);
        }

        return $request;
    }

    /**
     * 该动作人工访问时使用的 HTTP 方法
     *
     * 解析顺序：权限行 → 宿主桥接（config/menu_permission.php）→ 常量兜底。
     * 只依赖本地常量会让 `delete`（权限行 POST、前端 http.post）被判成 DELETE，
     * 从而在 `RbacMiddleware` 的 method 匹配上出现「AI 与人工不一致」。
     */
    public function methodOf(MenuActionMeta $meta): string
    {
        return $this->permissionMap()->resolveMethod($meta);
    }

    /**
     * 权限点解析器（动作 → route / method）
     */
    protected function permissionMap(): MenuActionPermissionMap
    {
        return $this->permissionMapResolver ??= new MenuActionPermissionMap($this->options);
    }

    /**
     * 创建与宿主同类的 Request 实例
     *
     * ⚠️ **必须与宿主的请求类一致**（通常是 `app\Request extends think\Request`）：
     * 容器里 `app\Request` 通常被别名到 `request` 服务，宿主可能注册了**参数类型为 `app\Request`
     * 的闭包绑定**（例如 `AppService` 的 `wise_login_security.user_resolver`）。若 A1 塞入一个
     * 裸 `think\Request`，容器就会把该实例注入闭包 → `TypeError`（实测：
     * `Argument #1 ($request) must be of type app\Request, think\Request given`）。
     *
     * 优先用框架自身的请求工厂 `__make(App)`（按 `$_SERVER` 填充 server/header），
     * 依次回落到「与 origin 同类」→「裸 think\Request」。
     */
    protected function newRequest(?\think\Request $origin = null): \think\Request
    {
        $class = $origin instanceof \think\Request ? get_class($origin) : \think\Request::class;

        try {
            if (method_exists($class, '__make') && function_exists('app') && is_object(app())) {
                $built = $class::__make(app());
                if ($built instanceof \think\Request) {
                    return $built;
                }
            }
        } catch (\Throwable) {
        }

        try {
            $built = new $class();
            if ($built instanceof \think\Request) {
                return $built;
            }
        } catch (\Throwable) {
        }

        return new \think\Request();
    }

    /**
     * 该动作人工访问时的路由
     *
     *     /**
     * 该动作人工访问时的路由
     *
     * 优先用**权限锚点 path 派生**（与后台按钮菜单的 path 写法、`auth_permissions.route`
     * 天然同构，插件同样成立）；无锚点（显式放开的未映射控制器）时按 FQCN 兜底。
     */
    public function actionRouteOf(MenuActionMeta $meta): string
    {
        if ($meta->menuPath !== '') {
            return MenuActionPermissionMap::routeOf($meta->menuPath, $meta->action);
        }

        $segments = explode('\\', $this->layerOf($meta));
        if (($segments[0] ?? '') === 'app') {
            array_shift($segments);
        }

        $base = '/' . implode('/', array_filter($segments, static fn($s): bool => $s !== ''));

        $base .= '/' . strtolower(Str::snake($this->controllerName($meta)));

        return $base . '/' . strtolower($meta->action);
    }

    /**
     * 上一次执行的轨迹
     *
     * @return array<int, array{step:string, message:string}>
     */
    public function trace(): array
    {
        return $this->trace;
    }


    /**
     * 容器是否可用
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

    /**
     * 实例化控制器（容器优先，失败回落直接实例化）
     *
     * ⚠️ 必须 `make($class, [], true)` 强制新实例，原因见类注释第 2 点。
     */
    protected function makeController(string $controllerClass): ?object
    {
        if (!class_exists($controllerClass)) {
            return null;
        }

        try {
            $instance = app()->make($controllerClass, [], true);
            if (is_object($instance)) {
                return $instance;
            }
        } catch (\Throwable $e) {
            $this->addTrace('controller.makeFailed', get_class($e) . ': ' . $e->getMessage());
        }

        try {
            return new $controllerClass();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * 人工同款中间件链执行器
     */
    protected function pipeline(): MenuActionPipeline
    {
        return $this->pipeline ??= new MenuActionPipeline($this->options);
    }

    /**
     * 沙箱 / 预演决策
     */
    protected function guard(): MenuActionGuard
    {
        return $this->guard ??= new MenuActionGuard($this->options);
    }


    /**
     * 把中间件的拦截响应翻译为同核失败结构
     *
     * **不改判定**：判定由中间件做出（拒绝就是拒绝），这里只把 `Response`（可能是
     * 302 重定向或 JSON）转成 Agent 可读的观察结构。
     *
     * @param array<string, mixed> $outcome
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function translateBlocked(array $outcome, MenuActionMeta $meta, array $context, float $startedAt, bool $sandboxed): array
    {
        $error = $outcome['error'] ?? null;
        if ($error instanceof \Throwable) {
            return MenuActionResult::fromException($error, $context + [
                'duration_ms' => $this->elapsed($startedAt),
                'stage'       => MenuActionResult::STAGE_EXECUTION,
            ]);
        }

        $blockedBy = (string) ($outcome['blocked_by'] ?? 'middleware');
        $response  = $outcome['response'] ?? null;
        $status    = $response instanceof \think\Response ? (int) $response->getCode() : 0;
        $code      = $status >= 400 ? $status : 403;

        $this->addTrace('pipeline.blocked', $blockedBy);

        return MenuActionResult::failure(
            sprintf(
                '与人工访问 %s 的判定一致：被 %s 拒绝（%s）',
                $this->actionRouteOf($meta),
                class_basename($blockedBy),
                $this->responseReason($response)
            ),
            $code,
            array_merge($context, [
                'duration_ms' => $this->elapsed($startedAt),
                'stage'       => MenuActionResult::STAGE_GUARD,
                'blocked_by'  => $blockedBy,
                'route'       => $this->actionRouteOf($meta),
                'sandboxed'   => $sandboxed,
            ])
        );
    }

    /**
     * 从拦截响应中提取人类可读原因
     */
    private function responseReason(mixed $response): string
    {
        if (!$response instanceof \think\Response) {
            return 'no response';
        }

        $status = (int) $response->getCode();

        if ($status >= 300 && $status < 400) {
            $location = (string) ($response->getHeader('location') ?? '');

            return sprintf('重定向 %d → %s', $status, $location !== '' ? $location : '(未配置)');
        }

        try {
            $data = $response->getData();
            if (is_array($data)) {
                return (string) ($data['msg'] ?? json_encode($data, JSON_UNESCAPED_UNICODE));
            }
            if (is_string($data) && $data !== '') {
                return $data;
            }
        } catch (\Throwable) {
        }

        return 'HTTP ' . $status;
    }


    /**
     * 展开通用 `filters`（`['status__eq=1','amount__gte=1000']`）为扁平参数
     *
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     * @throws \InvalidArgumentException
     */
    private function parseFilters(array $arguments): array
    {
        if (!array_key_exists('filters', $arguments)) {
            return $arguments;
        }

        $filters = $arguments['filters'];
        unset($arguments['filters']);

        if ($filters === null || $filters === '' || $filters === []) {
            return $arguments;
        }

        foreach ((array) $filters as $item) {
            if (!is_string($item) || !str_contains($item, '=')) {
                throw new \InvalidArgumentException('filters 的元素必须形如 `字段__操作符=值`');
            }

            [$key, $value] = explode('=', $item, 2);
            $key = trim($key);

            if ($key === '' || !$this->isWellFormedKey($key)) {
                throw new \InvalidArgumentException(sprintf("filters 中的字段 '%s' 非法", $key));
            }

            $arguments[$key] = $value;
        }

        return $arguments;
    }

    /**
     * 键形状：`字段` 或 `字段__操作符`
     *
     * ⚠️ 必须**先判 `__`**：普通字段名允许下划线，若先匹配「纯字段名」，
     * `name__exists` 这种「未知操作符」会被误当成字段名放过，最终被
     * `RequestQueryBuilder` 静默跳过。
     */
    private function isWellFormedKey(string $key): bool
    {
        if (str_contains($key, '__')) {
            if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)__([a-z]+)$/', $key, $m) !== 1) {
                return false;
            }

            return in_array($m[2], self::FILTER_OPERATORS, true);
        }

        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key) === 1;
    }

    /**
     * @throws \InvalidArgumentException
     */
    private function assertValue(string $key, mixed $value): void
    {
        if ($value === null || is_scalar($value)) {
            return;
        }

        if (!is_array($value)) {
            throw new \InvalidArgumentException(sprintf('参数 %s 的值类型不支持（仅支持标量或数组）', $key));
        }

        if ($key === 'ids') {
            $maxIds = (int) $this->opt('max_ids', 100);
            if (count($value) > $maxIds) {
                throw new \InvalidArgumentException(sprintf('ids 最多 %d 个', $maxIds));
            }
        } elseif (!str_ends_with($key, '__in') && !str_ends_with($key, '__notin')) {
            throw new \InvalidArgumentException(sprintf(
                '参数 %s 不支持数组值（仅 ids 与 `字段__in` / `字段__notin` 支持）',
                $key
            ));
        }

        foreach ($value as $item) {
            if (!is_scalar($item)) {
                throw new \InvalidArgumentException(sprintf('参数 %s 的数组元素必须是标量', $key));
            }
        }
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function normalizeValues(array $params, MenuActionMeta $meta): array
    {
        foreach (['id', 'status', 'sort', 'page', 'limit', 'offset'] as $key) {
            if (isset($params[$key]) && is_scalar($params[$key]) && is_numeric($params[$key])) {
                $params[$key] = (int) $params[$key];
            }
        }

        if (isset($params['ids']) && is_array($params['ids'])) {
            $params['ids'] = array_values(array_map(
                static fn($v): int => (int) $v,
                array_filter($params['ids'], static fn($v): bool => is_scalar($v) && is_numeric($v))
            ));
        }

        if ($meta->action === 'getList') {
            $min = 1;
            $max = max($min, (int) $this->opt('max_limit', 100));

            if (isset($params['limit'])) {
                $params['limit'] = max($min, min($max, (int) $params['limit']));
            }
            if (isset($params['page'])) {
                $params['page'] = max(1, (int) $params['page']);
            }
        }

        return $params;
    }

    /**
     * @return string[]
     */
    private function declaredProperties(MenuActionMeta $meta): array
    {
        if (!(bool) $this->opt('strict_parameters', true)) {
            return [];
        }

        $properties = $meta->parameters['properties'] ?? null;

        return is_array($properties) ? array_map('strval', array_keys($properties)) : [];
    }

    /**
     * @param array<string, mixed> $params
     */
    private function hasId(array $params): bool
    {
        if (isset($params['id']) && (int) $params['id'] > 0) {
            return true;
        }

        return isset($params['ids']) && is_array($params['ids']) && $params['ids'] !== [];
    }

    /**
     * @return string[]
     */
    private function sortFields(): array
    {
        $fields = array_values(array_filter(
            (array) $this->opt('sort_fields', self::DEFAULT_SORT_FIELDS),
            static fn($v): bool => is_string($v) && $v !== ''
        ));

        return $fields !== [] ? $fields : self::DEFAULT_SORT_FIELDS;
    }

    /**
     * 控制器段（`app\admin\controller\Market\Plugin` → `Market.Plugin`）
     */
    private function controllerName(MenuActionMeta $meta): string
    {
        $parts = explode('\\', ltrim($meta->controller, '\\'));
        $index = array_search('controller', $parts, true);

        $tail = $index === false
            ? [(string) end($parts)]
            : array_slice($parts, $index + 1);

        return implode('.', array_filter($tail, static fn($s): bool => $s !== ''));
    }

    /**
     * 控制器分层（`plugin\smartstock\controller\Product` → `plugin\smartstock`）
     *
     * 与 `AuditLog::resolveControllerClass()` / `OperationLog::resolveControllerClass()`
     * 的「layer 承载命名空间前缀」约定一致。
     */
    private function layerOf(MenuActionMeta $meta): string
    {
        $parts = explode('\\', ltrim($meta->controller, '\\'));
        $index = array_search('controller', $parts, true);

        if ($index === false || $index === 0) {
            return '';
        }

        return implode('\\', array_slice($parts, 0, $index));
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function failContext(array $context, float $startedAt): array
    {
        return $context + ['duration_ms' => $this->elapsed($startedAt)];
    }

    private function elapsed(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
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
