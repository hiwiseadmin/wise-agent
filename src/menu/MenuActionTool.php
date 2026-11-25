<?php
declare(strict_types=1);

namespace wise\agent\menu;

/**
 * 单个控制器动作的 AI 工具（A1-4 / A1-5）
 *
 * ## 执行链（A1-5：权限判定完全交给人工同款中间件链）
 * ```
 * LLM function call
 *   → MenuActionInvoker::invoke()
 *       1. 入参校验（明确报错，而不是让模型读控制器里的中文提示）
 *       2. 合成 Request（复制真实请求 server/header/cookie；替换 method/params/controller/action/pathinfo）
 *       3. 换绑容器 request
 *       4. MenuActionPipeline（IpGuard → Rbac → TwoFactor → XssFilter → AuditLog → OperationLog）
 *            └─ 最内层：只读沙箱预演 或 控制器动作调用
 *       5. MenuActionResult 归一化
 *       6. finally 还原容器 request
 *   → 统一「观察」契约 JSON
 * ```
 * **本工具不做任何权限判定** —— 不写「允许」逻辑，只负责暴露身份与执行入口。
 *
 * ## 权限标识：默认空串（deny-only）
 *
 * `require_ai_permission=false`（默认）时 `getPermission()` 返回 `''`：
 * `ToolSecurityCheck` 的 `requiresPermission()` 为假 → 不产生 `menu.action.*` 权限面，
 * AI 的可做范围**完全等于**人工（由中间件链裁决）。开启该配置后才返回
 * `menu.action.*`，那是**叠加在人工判定之上**的加严层，只可能更严。
 *
 * 工具名与注册键：`ma_{ident}_{controller}_{action}`（如 `ma_host_menu_getlist`）。
 * 与 A4 同策略 —— `ToolRegistry::execute()` 按**注册键精确匹配**，而 `getSchemas()`
 * 用 `getName()` 下发给 LLM，因此注册必须用 builtin 键
 * （见 {@see MenuActionToolProvider::registerTo()}），靠 `ma_` 前缀完成逻辑隔离。
 */
final class MenuActionTool extends \wise\agent\tool\BaseTool
{
    /**
     * 各动作给 LLM 的语义说明
     *
     * @var array<string, string>
     */
    private const ACTION_HINTS = [
        'getList'     => '分页查询列表数据（筛选/排序/分页参数见 parameters）',
        'save'        => '新增一条记录',
        'update'      => '按 id 或 ids 更新记录的字段',
        'delete'      => '按 id 或 ids 删除记录（软删除，可恢复）',
        'restore'     => '按 id 或 ids 恢复已软删除的记录',
        'forceDelete' => '按 id 或 ids 物理删除记录（不可恢复，高风险）',
        'status'      => '按 id 或 ids 修改状态字段',
        'sort'        => '按 id 或 ids 修改排序值',
    ];

    public function __construct(
        private MenuActionMeta $meta,
        private MenuActionGuard $guard,
        private MenuActionInvoker $invoker,
    ) {
    }

    public function getName(): string
    {
        return $this->meta->getToolName();
    }

    public function getDescription(): string
    {
        $hint = $this->meta->fromAnnotation
            ? $this->meta->getLabel()
            : (self::ACTION_HINTS[$this->meta->action] ?? '控制器动作');

        $lines = [sprintf(
            '执行后台控制器动作 `%s::%s`（%s）。',
            $this->meta->controller,
            $this->meta->action,
            $hint
        )];

        if ($this->meta->fromAnnotation && $this->meta->getDescription() !== '') {
            $lines[] = $this->meta->getDescription();
        }

        if ($this->meta->menuPath !== '') {
            $lines[] = sprintf(
                '入口菜单：%s（%s）；人工访问路径 %s。',
                $this->meta->menuName !== '' ? $this->meta->menuName : '未命名',
                $this->meta->menuPath,
                MenuActionPermissionMap::routeOf($this->meta->menuPath, $this->meta->action)
            );
        }

        $lines[] = sprintf('风险：%s；访问类型：%s。', $this->meta->risk, $this->meta->access);

        if ($this->meta->customized && !$this->meta->fromAnnotation) {
            $lines[] = '注意：该动作被控制器重写，响应结构与可用参数可能与标准 CRUD 不同。';
        }

        if ($this->guard->isSandboxed($this->meta)) {
            $lines[] = '注意：当前处于只读沙箱，本动作只返回影响面预演（would_affect/samples），不会真正修改数据。';
        }

        if (in_array($this->meta->action, ['update', 'delete', 'restore', 'forceDelete', 'status', 'sort'], true)) {
            $lines[] = '必须提供 id 或 ids（二者至少一个）。';
        }

        $lines[] = '权限与人工操作完全一致（同会话同 IP、经同一套鉴权链）；无权限时返回与人工相同的拒绝结果。';
        $lines[] = '返回统一 JSON：{code,msg,structured,data,text,truncated}；structured=true 时读 data，否则读 text。';

        return implode(' ', $lines);
    }

    public function getParameters(): array
    {
        return $this->meta->parameters;
    }

    /**
     * AI 面权限标识（默认空串 = 不产生人工没有的权限面）
     */
    public function getPermission(): string
    {
        if (!$this->guard->requireAiPermission()) {
            return '';
        }

        return $this->meta->getPermission();
    }

    public function getAccess(): string
    {
        return $this->guard->accessOf($this->meta);
    }

    public function getRiskLevel(): string
    {
        return $this->guard->riskLevelOf($this->meta);
    }

    public function requireConfirmation(): bool
    {
        return $this->guard->requireConfirmation($this->meta);
    }

    public function getMeta(): MenuActionMeta
    {
        return $this->meta;
    }

    public function execute(array $arguments): string
    {
        try {
            return $this->encode($this->invoker->invoke($this->meta, $arguments));
        } catch (\Throwable $e) {
            return $this->encode(MenuActionResult::fromException($e, [
                'controller' => $this->meta->controller,
                'action'     => $this->meta->action,
                'menu_path'  => $this->meta->menuPath,
            ]));
        }
    }

    private function encode(array $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_UNICODE) ?: '{"code":1,"msg":"json encode failed"}';
    }
}
