<?php
declare(strict_types=1);

namespace wise\agent\menu;

/**
 * 控制器动作护栏（A1-4 / A1-5 瘦身版）
 *
 * ## A1-5 起了什么变化
 *
 * 原实现自带一套「第A层菜单权限直通」（`Rbac::getUserPermissions()` + `MenuPermissionBridge::generateIdent()`
 * 比对 ident，并忽略 HTTP 方法、无权限点时默认放行）。这与人工的真实判定口径不一致，会产生
 * **权限放大**（有列表页权限 = 能执行该控制器全部动作）与**权限漂移**（两套判定实现）。
 *
 * A1-5 起，**权限判定整体移交给 `MenuActionPipeline`**（把人工同款中间件链套在动作调用外面，
 * 由链上的 `RbacMiddleware` 用 `route + method` 匹配 `auth_permissions` 行动作级权限点）。
 * 本类因此只保留「非授权类」职责：
 *
 * | 职责 | 说明 |
 * |---|---|
 * | `accessOf` / `riskLevelOf` | 供 `BaseTool::getAccess()/getRiskLevel()` 消费 |
 * | `requireConfirmation` | 供 `ApprovalPolicy` 消费（第C层：确认/审批，**只加严**） |
 * | `isSandboxed` | 第D层只读沙箱：写动作降级为影响面预演 |
 * | `preview` | 只读预演实现（**在中间件链内执行**，因此无权限者连预演也拿不到） |
 * | `annotate` | 回填「静态可执行」结论（动作白名单） |
 *
 * > **口径铁律**：本类不得再实现任何「允许」逻辑。人类能做的，AI 才能做；
 * > 人类的判定唯一来源是中间件链（`RbacMiddleware`），本类只能在其之上**加严**
 * > （沙箱 / 确认 / 审批），永不放松。
 *
 * ## 可测试性
 * 与数据库交互的动作（`probeRows` / `modelOf` / `useContainer`）是 `protected` 接缝。
 */
class MenuActionGuard
{
    /** 权限不足的返回码＼� */
    public const CODE_FORBIDDEN = 403;

    /** @var array<string, mixed> */
    private array $options;

    /** @var array<int, array{step:string, message:string}> */
    private array $trace = [];

    public function __construct(array $options = [])
    {
        $this->options = $options;
    }


    public function accessOf(MenuActionMeta $meta): string
    {
        return $meta->access;
    }

    public function riskLevelOf(MenuActionMeta $meta): string
    {
        return $meta->risk;
    }

    /**
     * 是否需要人工确认（A1-1 的动作语义表已给出结论，此处仅透传并保留扩展点）
     */
    public function requireConfirmation(MenuActionMeta $meta): bool
    {
        return $meta->requireConfirmation();
    }

    /**
     * 是否启用「AI 面加严权限」（`require_ai_permission`）
     *
     * 默认 **false**：AI 不产生任何人工没有的权限面 —— 授权判定唯一来源是中间件链里的
     * `RbacMiddleware`（与人工同一口径）。开启后工具才会暴露 `menu.action.*` 权限标识，
     * 那是在人工判定**之上**再叠一层 RBAC，只可能更严，不可能放松。
     */
    public function requireAiPermission(): bool
    {
        return (bool) $this->opt('require_ai_permission', false);
    }

    /**
     * 回填治理结论到元数据（供注册侧过滤）
     *
     * `enabled` 语义：**静态可执行**（动作白名单通过）。用户级授权是运行期判定，
     * 不能写进元数据（否则会被缓存成全局结论）。
     */
    public function annotate(MenuActionMeta $meta): MenuActionMeta
    {
        $meta->enabled = $meta->isToolCandidate();

        return $meta;
    }


    /**
     * 是否处于只读沙箱（写类动作降级为预演）
     */
    public function isSandboxed(MenuActionMeta $meta): bool
    {
        if ($meta->isReadOnly()) {
            return false;
        }

        return (bool) $this->opt('read_only', true);
    }

    /**
     * 影响面预演（dry-run）：只读，不产生任何写入
     *
     * ⚠️ 调用方必须把它放在 `MenuActionPipeline` 内执行 —— 预演会读取目标记录摘要，
     * 若绕过权限链，无权限者就能通过 dry-run 窥探数据。
     *
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function preview(MenuActionMeta $meta, array $arguments): array
    {
        $ids     = $this->targetIds($arguments);
        $context = [
            'controller' => $meta->controller,
            'action'     => $meta->action,
            'menu_path'  => $meta->menuPath,
        ];

        if ($ids === []) {
            $data = [
                'would_create' => true,
                'fields'       => $this->writeFields($arguments),
            ];
        } else {
            $probe = $this->probeRows($meta, $ids, max(1, (int) $this->opt('preview_rows', 5)));
            $data  = [
                'would_affect' => (int) ($probe['total'] ?? 0),
                'target_ids'   => $ids,
                'samples'      => $probe['samples'] ?? [],
            ];

            if (isset($probe['error'])) {
                $data['probe_error'] = $probe['error'];
            }
        }

        return MenuActionResult::normalize([
            'code' => 0,
            'msg'  => 'dry-run',
            'data' => $data + [
                'blocked'   => true,
                'action'    => $meta->action,
                'menu_path' => $meta->menuPath,
                'note'      => '只读沙箱已开启（menu_actions.read_only=true）：写动作已降级为影响面预演，未做任何修改。',
            ],
        ], $context);
    }


    /**
     * 从入参提取目标主键
     *
     * @param array<string, mixed> $arguments
     * @return int[]
     */
    private function targetIds(array $arguments): array
    {
        $ids = [];

        if (isset($arguments['id']) && is_numeric($arguments['id']) && (int) $arguments['id'] > 0) {
            $ids[] = (int) $arguments['id'];
        }

        if (isset($arguments['ids']) && is_array($arguments['ids'])) {
            foreach ($arguments['ids'] as $id) {
                if (is_numeric($id) && (int) $id > 0) {
                    $ids[] = (int) $id;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * 写动作将要写入的字段（剔除主键）
     *
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function writeFields(array $arguments): array
    {
        unset($arguments['id'], $arguments['ids'], $arguments['filters']);

        return $arguments;
    }

    /**
     * 只读探测目标记录（**只 select**，不产生写入）
     *
     * @param int[] $ids
     * @return array{total:int, samples:array<int, mixed>, error?:string}
     */
    protected function probeRows(MenuActionMeta $meta, array $ids, int $limit): array
    {
        $model = $this->modelOf($meta);
        if ($model === null) {
            return ['total' => 0, 'samples' => []];
        }

        try {
            $pk = (string) $model->getPk();

            $total   = (int) $this->modelOf($meta)?->whereIn($pk, $ids)->count();
            $samples = $this->modelOf($meta)?->whereIn($pk, $ids)->limit($limit)->select()->toArray() ?? [];

            return ['total' => $total, 'samples' => array_values($samples)];
        } catch (\Throwable $e) {
            $this->addTrace('probe.failed', $e->getMessage());

            return ['total' => 0, 'samples' => [], 'error' => $e->getMessage()];
        }
    }

    /**
     * 反射 `getModel()` 取模型实例（失败返回 null）
     */
    private function modelOf(MenuActionMeta $meta): ?\think\Model
    {
        if (!$this->useContainer() || !class_exists($meta->controller)) {
            return null;
        }

        try {
            $instance = app()->make($meta->controller, [], true);
            if (!is_object($instance) || !method_exists($instance, 'getModel')) {
                return null;
            }

            $method = new \ReflectionMethod($instance, 'getModel');
            $method->setAccessible(true);
            $model = $method->invoke($instance);

            return $model instanceof \think\Model ? $model : null;
        } catch (\Throwable $e) {
            $this->addTrace('model.failed', $e->getMessage());

            return null;
        }
    }

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
     * @return array<int, array{step:string, message:string}>
     */
    public function trace(): array
    {
        return $this->trace;
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
