<?php
declare(strict_types=1);

namespace wise\agent\form;

use wise\agent\menu\MenuActionInvoker;
use wise\agent\menu\MenuActionMeta;
use wise\agent\menu\MenuActionPipeline;
use wise\agent\menu\MenuActionResult;

/**
 * A2 表单目标的**锚点权限**判定＼�
 *
 * 验收 12 要求「无该控制器锚点菜单权限的管理员调用 A2 目标 → **与 A1 相同的 403 行为**」。
 *
 * ## 为什么不是自己写一个 RBAC 判定
 *
 * A1/A3 的判定**唯一来源**是 `MenuActionPipeline` 里的 `RbacMiddleware`
 * （route + method → `auth_permissions`，见 `MenuActionPipeline` 类注释）。
 * 若 A2 自己拼一套「按控制器查权限点」，就会出现两个判定源，必然漂移。
 * 因此这里**复用同一条链**：用 `MenuActionInvoker::buildRequest()` 合成与人工完全同源的
 * 请求（route/method/UA 标记），把一个 **no-op handler** 交给 `MenuActionPipeline::run()` ——
 * 判定照常发生，但**不执行任何业务动作**（A2 是只读/纯计算，绝不能借判定之名真跑一次 `getList`）。
 *
 * 判定用的动作固定为**读动作 `getList`**：① 它是每个 CRUD 控制器最基础的动作，
 * ② A2 的四个工具全部是只读（`form_generate` 默认不写盘），因此「读得动列表」= 「看得见表单」。
 *
 * ## 三档降级（都记 trace，不静默）
 *
 * | 情形 | 处理 | 理由 |
 * |---|---|---|
 * | 无请求上下文（CLI / 离线 / `discover`） | 放行 | 与 A1「中间件类不存在 ⇒ 视为未安装」同属**非绕过**的降级 |
 * | `MenuActionMeta` 构造失败（控制器不在菜单/动作不合法） | 放行 | 无锚点可判，与 A1 的 `allow_unmapped_controllers` 语义一致 |
 * | 链上抛异常（依赖缺失/配置错） | 按 `menu_actions.pipeline_fail_closed`（默认 true）→ **拒绝** | 与 A1 完全相同的 fail-closed 语义 |
 *
 * 默认**开启**（`form_schema.check_anchor_permission=true`）：这样 A2 与 A1 的访问面天然一致
 * （AI 的访问面 ≤ 人工）。若要临时关闭（如离线批处理），把它置 false。
 */
class FormAnchorGuard
{
    /** 判定结果：被拦截（回传该失败结构） */
    public const DENIED = 'denied';

    /** @var array<string, mixed> */
    private array $options;

    /** @var array<int, array{step:string, message:string}> */
    private array $trace = [];

    /**
     * @param array<string, mixed> $options 覆盖项（默认读 `wise-agent.menu_actions.*` 与 `form_schema.*`）
     */
    public function __construct(array $options = [])
    {
        $this->options = $options;
    }

    /**
     * 判定「当前用户能否访问该控制器的锚点菜单」
     *
     * @return array<string, mixed>|null `null` = 允许；数组 = 拦截结果（与 A1 同核的失败结构）
     */
    public function check(string $controller, string $target = ''): ?array
    {
        if (!(bool) $this->opt('check_anchor_permission', true)) {
            $this->addTrace('anchor.skipped', 'check_anchor_permission=false');

            return null;
        }

        if (!$this->useContainer()) {
            $this->addTrace('anchor.skipped', '无请求上下文（非 HTTP 进程），不做锚点判定');

            return null;
        }

        try {
            $meta = MenuActionMeta::forAction($controller, 'getList');
        } catch (\Throwable $e) {
            $this->addTrace('anchor.skipped', '无法构造动作元数据：' . $e->getMessage());

            return null;
        }

        try {
            $invoker   = new MenuActionInvoker($this->options, $this->pipeline());
            $request   = $invoker->buildRequest($meta, []);
            $outcome   = $this->pipeline()->run($request, static fn(): array => ['anchor_check' => true]);
            $route     = $invoker->actionRouteOf($meta);
        } catch (\Throwable $e) {
            $failClosed = (bool) $this->opt('pipeline_fail_closed', true);
            $this->addTrace('anchor.error', $e->getMessage() . ($failClosed ? '（fail-closed：拒绝）' : '（fail-open：放行）'));

            return $failClosed ? $this->deny($meta, '异常：' . $e->getMessage(), 403, 'pipeline_error', $target) : null;
        }

        if ((bool) ($outcome['passed'] ?? false)) {
            $this->addTrace('anchor.passed', sprintf('route=%s 判定通过', $route));

            return null;
        }

        $blockedBy = (string) ($outcome['blocked_by'] ?? 'middleware');
        $response  = $outcome['response'] ?? null;
        $status    = $response instanceof \think\Response ? (int) $response->getCode() : 0;
        $code      = $status >= 400 ? $status : 403;

        $this->addTrace('anchor.denied', sprintf('route=%s blocked_by=%s', $route, $blockedBy));

        return $this->deny(
            $meta,
            sprintf('与人工访问 %s 的判定一致：被 %s 拒绝', $route, class_basename($blockedBy)),
            $code,
            $blockedBy,
            $target
        );
    }

    /**
     * @return array<int, array{step:string, message:string}>
     */
    public function trace(): array
    {
        return $this->trace;
    }

    /**
     * 拦截结果（与 A1 的 `MenuActionInvoker::translateBlocked()` 同核：`stage=guard` + 真实 HTTP 状态码）
     *
     * @return array<string, mixed>
     */
    protected function deny(MenuActionMeta $meta, string $message, int $code, string $blockedBy, string $target): array
    {
        return MenuActionResult::failure($message, $code, [
            'target'     => $target,
            'controller' => $meta->controller,
            'action'     => $meta->action,
            'blocked_by' => $blockedBy,
            'stage'      => MenuActionResult::STAGE_GUARD,
            'hint'       => '该表单目标绑定的菜单没有对当前用户开放，因此 AI 也拿不到它（A2 不新增权限概念，判定与人工一致）。',
        ]);
    }

    protected function pipeline(): MenuActionPipeline
    {
        return new MenuActionPipeline($this->options);
    }

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
            $value = config('wise-agent.form_schema.' . $key, null);
            if ($value !== null) {
                return $value;
            }

            return config('wise-agent.menu_actions.' . $key, $default) ?? $default;
        } catch (\Throwable) {
            return $default;
        }
    }
}
