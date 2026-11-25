<?php
declare(strict_types=1);

namespace wise\agent\menu;

/**
 * 「AI ≡ 人工」对拍校验器（A1-5 收尾 / G2）
 *
 * ## 为什么必须有它
 *
 * A1-5 把权限判定完全交给人工同款中间件链（`RbacMiddleware`），A1 侧只保留
 * 「按动作级权限点过滤注册范围」这一层。**一旦这两层口径产生分叉，就会出现权限漂移**
 * （方向可能是放大，也可能是意外缩小），而菜单 / 权限点随时可能被运营改动。
 *
 * 因此把「对拍」固化为可执行的校验：以 `RbacManager::matchRouteToPermissions()` 为
 * **独立参照**（人工访问时的真实判定入口），逐动作比对 A1 自己的可达性结论。
 *
 * ## 漂移类型
 *
 * | 类型 | 含义 | 严重性 |
 * |---|---|---|
 * | `pipeline.missing_permission_check` | 中间件链里没有 `RbacMiddleware` → 等于没有权限判定 | **致命（放大）** |
 * | `amplification` | 注册了「人工不可达」的动作 → AI 能做人工做不了的事 | **致命（放大）** |
 * | `ai_permission_face` | deny-only 模式下仍暴露 `menu.action.*` → 出现人工没有的权限面 | **严重** |
 * | `inconsistent` | A1 可达性结论与权限引擎不一致 | 严重 |
 * | `unregistered` | 人工可达但没注册成工具（AI 能力缩小） | 提示（≤人工，允许） |
 *
 * ## 接入点
 * `php think wise:agent:discover --force` 的 `refreshMenuActions()` 收尾调用 ——
 * 菜单/权限点变更后自动跑一遍，发现漂移立即告警。
 */
class MenuActionParityChecker
{
    public const DRIFT_PIPELINE = 'pipeline.missing_permission_check';
    public const DRIFT_AMPLIFICATION = 'amplification';
    public const DRIFT_AI_FACE = 'ai_permission_face';
    public const DRIFT_INCONSISTENT = 'inconsistent';
    public const DRIFT_UNREGISTERED = 'unregistered';

    /** @var array<string, mixed> */
    private array $options;

    private MenuActionPermissionMap $permissionMap;
    private MenuActionInvoker $invoker;

    public function __construct(
        array $options = [],
        ?MenuActionPermissionMap $permissionMap = null,
        ?MenuActionInvoker $invoker = null
    ) {
        $this->options       = $options;
        $this->permissionMap = $permissionMap ?? new MenuActionPermissionMap();
        $this->invoker       = $invoker ?? new MenuActionInvoker();
    }

    /**
     * 执行对拍
     *
     * @param array<string, MenuActionMeta> $metas 内省出的动作（默认自行内省）
     * @param array<int, object>            $tools 已生成的动作工具（默认自行生成）
     * @return array{checked:int, reachable:int, tools:int, drifts:array<int, array{type:string, action:string, detail:string}>, ok:bool}
     */
    public function check(?array $metas = null, ?array $tools = null): array
    {
        $metas = $metas ?? $this->introspect();
        $tools = $tools ?? $this->toolsFrom($metas);

        $drifts = [];

        $chain = $this->pipelineChain();
        if ($chain !== [] && !in_array('wise\\auth\\middleware\\RbacMiddleware', $chain, true)) {
            $drifts[] = $this->drift(
                self::DRIFT_PIPELINE,
                '-',
                '中间件链缺少 RbacMiddleware：AI 的动作调用将没有权限判定'
            );
        }

        $checked   = 0;
        $reachable = 0;
        $toolNames = [];
        foreach ($tools as $tool) {
            if (is_object($tool) && method_exists($tool, 'getName')) {
                $toolNames[$tool->getName()] = true;
            }
        }

        foreach ($metas as $meta) {
            if (!$meta instanceof MenuActionMeta) {
                continue;
            }

            $checked++;

            if (!$this->invoker->actionRouteOf($meta) || !$meta->isToolCandidate()) {
                continue;
            }

            $a1Reachable = $meta->menuPath === ''
                ? null
                : $this->permissionMap->isHumanReachable($meta->menuPath, $meta->action);

            $engineReachable = $this->engineReachable($meta);

            if ($a1Reachable !== null && $engineReachable !== null && $a1Reachable !== $engineReachable) {
                $drifts[] = $this->drift(
                    self::DRIFT_INCONSISTENT,
                    $meta->getToolName(),
                    sprintf(
                        'A1 判断可达=%s，权限引擎判断可达=%s（route=%s method=%s）',
                        var_export($a1Reachable, true),
                        var_export($engineReachable, true),
                        $this->invoker->actionRouteOf($meta),
                        $this->invoker->methodOf($meta)
                    )
                );
            }

            if ($a1Reachable === true) {
                $reachable++;
            }

            $registered = isset($toolNames[$meta->getToolName()]);
            if ($registered && $engineReachable === false) {
                $drifts[] = $this->drift(
                    self::DRIFT_AMPLIFICATION,
                    $meta->getToolName(),
                    sprintf('%s 无行动作级权限点，人工会被 whitelist 拒绝，但已注册为工具', $this->invoker->actionRouteOf($meta))
                );
            }

            if (!$registered && $engineReachable === true) {
                $drifts[] = $this->drift(
                    self::DRIFT_UNREGISTERED,
                    $meta->getToolName(),
                    '人工可达但未注册为工具（AI 能力缩小，符合「≤人工」）'
                );
            }
        }

        if (!(bool) $this->opt('require_ai_permission', false)) {
            foreach ($tools as $tool) {
                if (!is_object($tool) || !method_exists($tool, 'getPermission')) {
                    continue;
                }

                $permission = (string) $tool->getPermission();
                if ($permission !== '') {
                    $drifts[] = $this->drift(
                        self::DRIFT_AI_FACE,
                        method_exists($tool, 'getName') ? (string) $tool->getName() : '-',
                        sprintf('deny-only 模式下仍暴露权限标识 %s（形成人工没有的权限面）', $permission)
                    );
                }
            }
        }

        return [
            'checked'   => $checked,
            'reachable' => $reachable,
            'tools'     => count($toolNames),
            'drifts'    => $drifts,
            'ok'        => $this->hasFatal($drifts) === false,
        ];
    }

    /**
     * 汇总文本（供命令输出）
     *
     * @param array<string, mixed> $report
     */
    public function summary(array $report, int $maxDetail = 5): string
    {
        $drifts = (array) ($report['drifts'] ?? []);
        $fatal = $this->hasFatal($drifts);

        $head = sprintf(
            '对拍 %d 个动作 / 可达 %d / 工具 %d → %s',
            (int) ($report['checked'] ?? 0),
            (int) ($report['reachable'] ?? 0),
            (int) ($report['tools'] ?? 0),
            $drifts === [] ? '一致（AI ≤ 人工）' : ($fatal ? '发现致命漂移' : '存在提示项')
        );

        if ($drifts === []) {
            return $head;
        }

        $lines = [$head];
        foreach (array_slice($drifts, 0, $maxDetail) as $drift) {
            $lines[] = sprintf('    · [%s] %s：%s', $drift['type'], $drift['action'], $drift['detail']);
        }
        if (count($drifts) > $maxDetail) {
            $lines[] = sprintf('    · …其余 %d 项', count($drifts) - $maxDetail);
        }

        return implode(PHP_EOL, $lines);
    }

    /**
     * @param array<int, array{type:string, action:string, detail:string}> $drifts
     */
    public function hasFatal(array $drifts): bool
    {
        foreach ($drifts as $drift) {
            if (in_array($drift['type'] ?? '', [self::DRIFT_PIPELINE, self::DRIFT_AMPLIFICATION, self::DRIFT_AI_FACE], true)) {
                return true;
            }
        }

        return false;
    }


    /**
     * 权限引擎判断：该动作路由是否存在权限点（或被白名单旁路）
     *
     * 独立参照 = `RbacManager::matchRouteToPermissions()`（人工访问时的真实入口）。
     *
     * @return bool|null null = 权限引擎不可用（不参与对拍）
     */
    protected function engineReachable(MenuActionMeta $meta): ?bool
    {
        $route = $this->invoker->actionRouteOf($meta);
        if ($route === '') {
            return null;
        }

        if ($this->permissionMap->isBypassedRoute($route)) {
            return true;
        }

        $idents = $this->routeIdents($route, $this->invoker->methodOf($meta));

        return $idents === null ? null : $idents !== [];
    }

    /**
     * 路由 → 权限标识（`RbacManager::matchRouteToPermissions()`）
     *
     * @return string[]|null null = wise-auth 未安装 / 不可用
     */
    protected function routeIdents(string $route, string $method): ?array
    {
        if (!class_exists('wise\\auth\\service\\RbacManager') || !function_exists('app')) {
            return null;
        }

        try {
            $manager = app('wise\\auth\\service\\RbacManager');
            if (!is_object($manager) || !method_exists($manager, 'matchRouteToPermissions')) {
                return null;
            }

            return array_values(array_filter((array) $manager->matchRouteToPermissions($route, $method), 'is_string'));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, MenuActionMeta>
     */
    protected function introspect(): array
    {
        try {
            return (new MenuActionIntrospector())->resolve(false);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param array<string, MenuActionMeta> $metas
     * @return array<int, object>
     */
    protected function toolsFrom(array $metas): array
    {
        try {
            return (new MenuActionToolProvider())->getTools();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return string[]
     */
    protected function pipelineChain(): array
    {
        try {
            return (new MenuActionPipeline())->chain();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return array{type:string, action:string, detail:string}
     */
    private function drift(string $type, string $action, string $detail): array
    {
        return ['type' => $type, 'action' => $action, 'detail' => $detail];
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
}
