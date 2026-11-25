<?php
declare(strict_types=1);

namespace wise\agent\menu;

use wise\agent\contract\RequestContext;
use wise\agent\contract\ToolInterface;
use wise\agent\contract\ToolProviderInterface;
use wise\agent\tool\ToolRegistry;

/**
 * 控制器动作工具提供者（A1-4 / A1-5）
 *
 * 复用插件 AI 接入的 `ToolProviderInterface` 契约：`getNamespace()` 返回 `menu`，
 * `getTools()` 批量产出「菜单可达动作 → AI 工具」。
 *
 * ## 注册期「人工可达性」过滤（默认关闭）
 *
 * 历史实现默认开启：`wise-auth.default_mode=whitelist` 下，匹配不到
 * `auth_permissions` 行动作级权限点的路由，非超管人工也会被 403，因此注册期直接
 * 不生成工具，避免模型拿到必然失败的入口。
 *
 * 但**超管在路由匹配之前就直通**（`RbacMiddleware::handle()`，`:97-100`），
 * 超管场景下没有权限点也能正常调用。因此默认值改为 `false`：
 *
 *   - 默认：只做动作白名单过滤，可达性交给**运行时**中间件链判定（唯一判定点）；
 *   - 需要「注册期就隐藏非超管不可达动作」时，显式打开
 *     `menu_actions.require_human_reachable=true`（此时无 DB / CLI 前置按 fail-closed 处理）。
 *
 * 过滤依据是 {@see MenuActionPermissionMap}（只判**权限点是否存在**，不判授权），
 * 授权判定唯一实现仍是中间件链里的 `RbacMiddleware`（见 {@see MenuActionPipeline}）。
 *
 * ⚠️ **注册命名空间说明**（与 A4 同一坑）：`ToolRegistry::execute()` 按**精确键**查找，
 * 而 `getSchemas()` 把 `ToolInterface::getName()` 作为 LLM function name 下发 ——
 * 二者必须一致才能回调成功。`register($tool, $name, 'menu')` 会产生 `menu.ma_xxx` 前缀键，
 * 与 LLM 拿到的 `ma_xxx` 不一致，因此实际注册走 builtin 键 + `ma_` 前缀隔离
 * （与 `wa_menu_tree`、A4 的 `cmd_` 同策略）。
 *
 * 门禁：`wise-agent.menu_actions.enabled`（默认 false）。
 */
final class MenuActionToolProvider implements ToolProviderInterface
{
    /** 提供者命名空间（逻辑分组） */
    public const NAMESPACE = 'menu';

    /** 工具名前缀（LLM 侧可见的隔离标识） */
    public const TOOL_PREFIX = 'ma_';

    private MenuActionIntrospector $introspector;
    private MenuActionGuard $guard;
    private MenuActionInvoker $invoker;
    private MenuActionPermissionMap $permissionMap;

    /** @var array<int, array{step:string, message:string}> */
    private array $trace = [];

    public function __construct(
        ?MenuActionIntrospector $introspector = null,
        ?MenuActionGuard $guard = null,
        ?MenuActionInvoker $invoker = null,
        ?MenuActionPermissionMap $permissionMap = null
    ) {
        $this->introspector  = $introspector ?? new MenuActionIntrospector();
        $this->guard         = $guard ?? new MenuActionGuard();
        $this->invoker       = $invoker ?? new MenuActionInvoker();
        $this->permissionMap = $permissionMap ?? new MenuActionPermissionMap();
    }

    public function getNamespace(): string
    {
        return self::NAMESPACE;
    }

    public function isEnabled(): bool
    {
        return (bool) $this->config('enabled', false);
    }

    public function getGuard(): MenuActionGuard
    {
        return $this->guard;
    }

    /**
     * 生成全部「静态可执行 且 人工可达」的动作工具
     *
     * @return ToolInterface[]
     */
    public function getTools(): array
    {
        if (!$this->isEnabled()) {
            return [];
        }

        $tools = [];
        $seen  = [];

        foreach ($this->introspector->resolve(false) as $meta) {
            $meta = $this->guard->annotate($meta);

            if (!$meta->enabled) {
                continue;
            }

            if (!$this->isHumanReachable($meta)) {
                continue;
            }

            $name = $meta->getToolName();
            if ($name === '' || isset($seen[$name])) {
                continue;
            }
            $seen[$name] = true;

            $tools[] = new MenuActionTool($meta, $this->guard, $this->invoker);
        }

        return $tools;
    }

    /**
     * 批量注册到 ToolRegistry（builtin 键，保证 LLM 可回调）
     *
     * @return int 实际注册的工具数量
     */
    public function registerTo(?ToolRegistry $registry = null): int
    {
        $registry = $registry ?? ToolRegistry::instance();
        $count    = 0;

        foreach ($this->getTools() as $tool) {
            if (!$tool instanceof ToolInterface) {
                continue;
            }

            $registry->unregister($tool->getName());
            $registry->register($tool, $tool->getName(), 'builtin');
            $count++;
        }

        return $count;
    }

    /**
     * 按名执行动作工具（ToolProviderInterface 契约入口）
     *
     * @param array<string, mixed> $params
     */
    public function execute(string $toolName, array $params, RequestContext $context): mixed
    {
        foreach ($this->getTools() as $tool) {
            if ($tool->getName() === $toolName || $tool->getMeta()->getToolName() === $toolName) {
                return $tool->execute($params);
            }
        }

        return json_encode([
            'code' => 1,
            'msg'  => "Menu action tool '{$toolName}' not found or not allowed",
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return array{label:string,description:string,version:string}
     */
    public function getMetadata(): array
    {
        return [
            'label'       => 'Backend Menu Actions',
            'description' => '把后台菜单可达的 CrudAction 动作内省为 AI 工具（与人工同一套鉴权链：菜单权限 + 2FA + IP 守卫 + 审计；写动作默认只读沙箱）',
            'version'     => '1.1',
        ];
    }

    /**
     * @return array<int, array{step:string, message:string}>
     */
    public function trace(): array
    {
        return $this->trace;
    }


    /**
     * 该动作是否「人工可达」（有行动作级权限点，或该路由被白名单放行）
     *
     * - 默认**不启用**（`require_human_reachable=false`）：超管在路由匹配前直通，
     *   可达性由运行时中间件链判定，注册期不做重复裁决；
     * - 开启后：无锚点不做存在性判断；权限数据不可用按 fail-closed 处理。
     */
    private function isHumanReachable(MenuActionMeta $meta): bool
    {
        if (!(bool) $this->config('require_human_reachable', false)) {
            return true;
        }

        if ($meta->menuPath === '') {
            $this->addTrace('reachable.skipped', $meta->getToolName() . '（无锚点，跳过存在性判断）');

            return true;
        }

        $reachable = $this->permissionMap->isHumanReachable($meta->menuPath, $meta->action);

        if ($reachable === true) {
            return true;
        }

        if ($reachable === null) {
            $this->addTrace('reachable.failClosed', $meta->getToolName() . '（权限数据不可用，不注册）');

            return false;
        }

        $this->addTrace(
            'reachable.noPermissionPoint',
            sprintf(
                '%s：%s 无行动作级权限点（人工也会被 default_mode=whitelist 拒绝）→ 不生成工具',
                $meta->getToolName(),
                MenuActionPermissionMap::routeOf($meta->menuPath, $meta->action)
            )
        );

        return false;
    }

    private function config(string $key, mixed $default): mixed
    {
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
