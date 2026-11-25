<?php
declare(strict_types=1);

namespace wise\agent\query\executor;

use think\Request;
use wise\agent\menu\MenuActionInvoker;
use wise\agent\menu\MenuActionMeta;
use wise\agent\menu\MenuActionPipeline;
use wise\agent\menu\MenuActionResult;
use wise\agent\query\ListQueryTarget;

/**
 * 用于 **A1 不可用**或 **`mode=model_search`（手写列表页）**的目标：A3 自己取数，
 * 但**权限与审计仍然复用 A1-5 的人工同款中间件链** —— 这一点是硬要求
 *
 * ## 为什么取数放在链**内**
 *
 * `MenuActionPipeline::run($request, $handler)` 的最内层就是「真实业务」。因此这里把
 * **取数本身**作为 handler 传进去，而不是先跑一遍「权限探针」再在链外取数：
 *
 * ```php
 * $outcome = $pipeline->run($request, fn (Request $r) => $this->fetch($target, $params, $r));
 * ```
 *
 * 这样 `IpGuard → Rbac → TwoFactor → XssFilter → AuditLog → OperationLog` 六层对
 * 「独立取数」同样生效：无权限者拿不到数据、审计日志里也有这次读取的记录。
 *
 * ## 两条取数方式＼�
 *
 * | mode | 实现 | 说明 |
 * |---|---|---|
 * | `model_search` | `(new Model)->search($params)` | 插件手写列表页的既有语义 |
 * | `builder`（兜底） | `RequestQueryBuilder::applyToModel()` + `paginate()` | **与 `CrudAction::getList` 完全同构**，返回结构也一致（`total/list/page/limit`） |
 *
 * 走模型而不是 `Db::name()`：软删除、自动时间戳等模型语义自动生效，与人工列表页口径一致。
 */
class StandaloneListQueryExecutor implements ListQueryExecutorInterface
{
    private MenuActionPipeline $pipeline;

    private MenuActionInvoker $invoker;

    /**
     * @param array<string, mixed> $options `list_query.*` 覆盖项
     */
    public function __construct(
        private array $options = [],
        ?MenuActionPipeline $pipeline = null,
        ?MenuActionInvoker $invoker = null
    ) {
        $this->pipeline = $pipeline ?? new MenuActionPipeline($options);
        $this->invoker  = $invoker ?? new MenuActionInvoker($options);
    }

    /**
     * {@inheritDoc}
     */
    public function execute(ListQueryTarget $target, array $params): array
    {
        $meta = MenuActionMeta::forAction($target->controller, $target->getListAction, [
            'menuPath' => $target->anchorPath,
            'menuName' => $target->title,
        ]);

        $request = $this->invoker->buildRequest($meta, $params, $this->originRequest());

        try {
            $outcome = $this->pipeline->run($request, fn (Request $req): array => $this->fetch($target, $params, $req));
        } catch (\Throwable $e) {
            return MenuActionResult::fromException($e, [
                'target'   => $target->name,
                'executor' => 'standalone',
            ]);
        }

        $error = $outcome['error'] ?? null;
        if ($error instanceof \Throwable) {
            return MenuActionResult::fromException($error, [
                'target'   => $target->name,
                'executor' => 'standalone',
            ]);
        }

        if (!($outcome['passed'] ?? false)) {
            $blocked = $outcome['blocked_by'] ?? null;

            return MenuActionResult::failure(
                sprintf(
                    '该查询与人工访问 %s 的判定一致：被 %s 拒绝（独立执行路径同样经过人工同款权限链）',
                    $this->invoker->actionRouteOf($meta),
                    $blocked ?? '权限链'
                ),
                403,
                [
                    'target'     => $target->name,
                    'executor'   => 'standalone',
                    'stage'      => MenuActionResult::STAGE_GUARD,
                    'blocked_by' => $blocked,
                    'menu_path'  => $meta->menuPath,
                ]
            );
        }

        $result = MenuActionResult::normalize($outcome['result'] ?? null);
        $result['executor'] = 'standalone';
        $result['mode']     = $target->mode;

        return $result;
    }

    /**
     * 链内取数（`protected` 接缝：单测可覆写以离线覆盖两种 mode）
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    protected function fetch(ListQueryTarget $target, array $params, Request $request): array
    {
        if ($target->mode === ListQueryTarget::MODE_MODEL_SEARCH) {
            return $this->fetchByModelSearch($target, $params);
        }

        return $this->fetchByBuilder($target, $params, $request);
    }

    /**
     * `builder` 兜底：与 `CrudAction::getList` 同构
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    protected function fetchByBuilder(ListQueryTarget $target, array $params, Request $request): array
    {
        $builderClass = $this->builderClass();

        if ($builderClass === null) {
            return [
                'code' => 1,
                'msg'  => '宿主缺少 app\\common\\library\\RequestQueryBuilder，独立执行路径不可用；请改用 mode=model_search 或接入 A1',
            ];
        }

        $model = $this->newModel($target);

        $builder = new $builderClass($request);
        $query   = $builder->applyToModel($model, ['withoutPage' => true]);

        $this->applyOwnerScope($query);

        $page  = max(1, (int) ($params['page'] ?? 1));
        $limit = max(1, (int) ($params['limit'] ?? 20));

        $data = $query->paginate(['list_rows' => $limit, 'page' => $page])->toArray();

        return [
            'code' => 0,
            'msg'  => '操作成功',
            'data' => [
                'total' => (int) ($data['total'] ?? 0),
                'list'  => $data['data'] ?? [],
                'page'  => (int) ($data['current_page'] ?? $page),
                'limit' => (int) ($data['per_page'] ?? $limit),
            ],
        ];
    }

    /**
     * `model_search`：调用插件模型自己的 `search($params)`
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    protected function fetchByModelSearch(ListQueryTarget $target, array $params): array
    {
        $model  = $this->newModel($target);
        $method = $target->searchMethod;

        if (!is_object($model) || !method_exists($model, $method)) {
            return [
                'code' => 1,
                'msg'  => sprintf('目标 %s 声明了 model_search，但模型上不存在方法 %s()', $target->name, $method),
            ];
        }

        $rows = $model->{$method}($params);

        if (is_array($rows) && (array_key_exists('list', $rows) || array_key_exists('total', $rows))) {
            return [
                'code' => 0,
                'msg'  => '操作成功',
                'data' => [
                    'total' => (int) ($rows['total'] ?? count((array) ($rows['list'] ?? []))),
                    'list'  => $rows['list'] ?? [],
                    'page'  => (int) ($rows['page'] ?? ($params['page'] ?? 1)),
                    'limit' => (int) ($rows['limit'] ?? ($params['limit'] ?? 20)),
                ],
            ];
        }

        if ($rows instanceof \Traversable) {
            $rows = iterator_to_array($rows);
        }

        $list = is_array($rows) ? array_values($rows) : [];

        return [
            'code' => 0,
            'msg'  => '操作成功',
            'data' => [
                'total' => count($list),
                'list'  => $list,
                'page'  => (int) ($params['page'] ?? 1),
                'limit' => (int) ($params['limit'] ?? 20),
            ],
        ];
    }

    /**
     * 行级 owner 隔离（`list_query.owner_scope`，默认关闭）
     *
     * 默认关闭 ⇒ **与人工一致**；开启则 AI 更严（只看到自己的数据），属于允许的缩小。
     * 超管不隔离（与 `AutoGeneratedTool` 的既有策略一致）。
     */
    protected function applyOwnerScope(object $query): void
    {
        $scope = $this->opt('owner_scope', []);

        if (!is_array($scope) || empty($scope['enabled'])) {
            return;
        }

        $field  = trim((string) ($scope['field'] ?? 'admin_id'));
        $userId = $this->currentUserId();

        if ($field === '' || $userId === null || $this->isSuperAdmin()) {
            return;
        }

        if (method_exists($query, 'where')) {
            $query->where($field, $userId);
        }
    }

    /**
     * 目标对应的模型实例（软删/时间戳等模型语义自动生效）
     */
    protected function newModel(ListQueryTarget $target): ?object
    {
        $modelClass = $this->resolveModelClass($target);

        if ($modelClass === null) {
            return null;
        }

        try {
            return new $modelClass();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * 反射控制器 `getModel()` 取模型类名
     */
    protected function resolveModelClass(ListQueryTarget $target): ?string
    {
        if (!class_exists($target->controller)) {
            return null;
        }

        try {
            $controller = app()->make($target->controller, [], true);
            if (!is_object($controller) || !method_exists($controller, 'getModel')) {
                return null;
            }

            $method = new \ReflectionMethod($controller, 'getModel');
            $method->setAccessible(true);
            $model  = $method->invoke($controller);

            return is_object($model) ? get_class($model) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * 宿主当前请求（提供同会话 / 同 IP / 同 UA，供权限链与审计使用）
     */
    protected function originRequest(): ?Request
    {
        if (!$this->useContainer()) {
            return null;
        }

        try {
            $request = app('request');

            return $request instanceof Request ? $request : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * 宿主查询构建器（懒加载 + 存在性判断，保持包与宿主解耦）
     */
    protected function builderClass(): ?string
    {
        $class = '\\app\\common\\library\\RequestQueryBuilder';

        return class_exists($class) ? $class : null;
    }

    protected function currentUserId(): ?int
    {
        if (!function_exists('auth_id')) {
            return null;
        }

        try {
            $id = auth_id('admin');

            return is_numeric($id) ? (int) $id : null;
        } catch (\Throwable) {
            return null;
        }
    }

    protected function isSuperAdmin(): bool
    {
        $userId = $this->currentUserId();

        if ($userId === null || !class_exists(\wise\auth\facade\Rbac::class)) {
            return false;
        }

        try {
            return (bool) \wise\auth\facade\Rbac::isSuperAdmin($userId, 'admin');
        } catch (\Throwable) {
            return false;
        }
    }

    protected function useContainer(): bool
    {
        return function_exists('app') && is_object(app());
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
