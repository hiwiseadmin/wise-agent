<?php
declare(strict_types=1);

namespace wise\agent\security;

use think\facade\Event;
use wise\support\context\ContextualLogger;
use wise\agent\contract\ToolInterface;
use wise\agent\event\CapabilityAuthorize;
use wise\agent\event\SkillAuthorize;
use wise\agent\event\ToolAuthorize;
use wise\agent\skill\contract\SkillInterface;

/**
 * AI 能力统一授权判定器
 *
 * Tool 与 Skill 共用同一套判定，避免「工具受控、技能裸奔」的口径分裂。
 *
 * 判定顺序：
 *   1. 校验开关关闭（security.tool_permission_check / skill_permission_check）→ 放行
 *   2. 能力无权限标识 → 放行（no_permission_required）
 *   3. 触发授权事件（ToolAuthorize / SkillAuthorize）
 *        - deny()  → 硬拒绝，不再走 RBAC（拒绝优先）
 *        - allow() → 放行
 *   4. 回落 wise-auth RBAC
 *        - 超级管理员（super_admin_ids / super_admin 角色）→ 直通
 *        - 命中 `*` / `ai.capability.*`（全部 AI 能力）→ 放行
 *        - 命中 `ai.access.{access}.*`（按类别，如全部只读能力）→ 放行 ← 分类骨架
 *        - 命中该能力自身的权限标识（或前缀通配）→ 放行          ← 细粒度下钻
 *   5. 未登录上下文
 *        - 控制台 / 队列（无 HTTP 请求上下文）且 trust_console_context=true → 视为系统上下文放行（记日志）
 *        - 否则拒绝（anonymous）
 *
 * 权限模型说明见 {@see CapabilityAccess}。
 */
class CapabilityAuthorizer
{
    /**
     * 能力元数据解析器
     *
     * 说明：本类**故意不做判定结果缓存**。
     * 授权事件（ToolAuthorize / SkillAuthorize）允许监听器按 `arguments`
     * 做细粒度判定（如按 skill_name 拒绝），若按「能力」缓存判定结果，
     * 同一能力不同参数的第二次调用会命中旧结论，形成越权漏洞。
     * 昂贵部分（用户权限集合、超管判定）已由 wise-auth 的 RbacManager 缓存。
     */
    protected AccessResolver $resolver;

    public function __construct(?AccessResolver $resolver = null)
    {
        $this->resolver = $resolver ?? new AccessResolver();
    }

    public function resolver(): AccessResolver
    {
        return $this->resolver;
    }

    /**
     * 解析工具能力描述（供审批策略等下游复用，避免重复推断）
     */
    public function describeTool(ToolInterface $tool, ?string $name = null): CapabilityDescriptor
    {
        return $this->resolver->resolveTool($tool, $name);
    }

    /**
     * 解析 Skill 能力描述
     */
    public function describeSkill(SkillInterface $skill): CapabilityDescriptor
    {
        return $this->resolver->resolveSkill($skill);
    }

    /**
     * 工具授权
     */
    public function authorizeTool(ToolInterface $tool, array $arguments = [], ?string $name = null): AuthDecision
    {
        if (!(bool) config('wise-agent.security.tool_permission_check', true)) {
            return AuthDecision::allow('disabled', '工具权限校验已被配置关闭（security.tool_permission_check=false）');
        }

        return $this->authorizeDescriptor($this->resolver->resolveTool($tool, $name), $arguments);
    }

    /**
     * Skill 授权
     *
     * Skill 是编排入口，历史上完全没有权限模型，属权限绕过通道，必须与工具同等受控。
     */
    public function authorizeSkill(SkillInterface $skill, array $input = []): AuthDecision
    {
        if (!(bool) config('wise-agent.security.skill_permission_check', true)) {
            return AuthDecision::allow('disabled', 'Skill 权限校验已被配置关闭（security.skill_permission_check=false）');
        }

        return $this->authorizeDescriptor($this->resolver->resolveSkill($skill), $input);
    }

    /**
     * 按能力描述符授权（Tool / Skill 通用主流程）
     */
    public function authorizeDescriptor(CapabilityDescriptor $descriptor, array $arguments = []): AuthDecision
    {
        if (!$descriptor->requiresPermission()) {
            return AuthDecision::allow(
                'no_permission_required',
                '能力未声明权限要求，直接放行',
                $descriptor->access,
                $descriptor->permission
            );
        }

        $userId   = $this->currentUserId();
        $userType = $this->currentUserType();

        return $this->decide($descriptor, $arguments, $userId, $userType);
    }

    /**
     * 实际判定流程
     */
    protected function decide(
        CapabilityDescriptor $descriptor,
        array $arguments,
        int $userId,
        string $userType
    ): AuthDecision {
        $event = $this->createEvent($descriptor, $arguments, $userId, $userType);

        try {
            Event::trigger($event);
        } catch (\Throwable $e) {
            ContextualLogger::channel('ai')->error(
                "[CapabilityAuthorizer] {$descriptor->kind} authorize listener error: {$e->getMessage()}",
                ['name' => $descriptor->name, 'permission' => $descriptor->permission]
            );
        }

        if ($event->denied) {
            return AuthDecision::deny(
                $event->decidedBy ?: 'event',
                $event->reason ?: "能力 '{$descriptor->name}' 被授权策略拒绝",
                $descriptor->access,
                $descriptor->permission
            );
        }

        if ($event->authorized) {
            return AuthDecision::allow(
                $event->decidedBy ?: 'event',
                '由授权事件放行',
                $descriptor->access,
                $descriptor->permission
            );
        }

        return $this->decideByRbac($descriptor, $userId, $userType);
    }

    /**
     * 构造授权事件
     */
    protected function createEvent(
        CapabilityDescriptor $descriptor,
        array $arguments,
        int $userId,
        string $userType
    ): CapabilityAuthorize {
        $event = $descriptor->isSkill()
            ? new SkillAuthorize($descriptor->name, $descriptor->permission)
            : new ToolAuthorize($descriptor->name, $descriptor->permission);

        $event->access    = $descriptor->access;
        $event->arguments = $arguments;
        $event->userId    = $userId;
        $event->userType  = $userType;

        return $event;
    }

    /**
     * RBAC 兜底判定
     */
    protected function decideByRbac(CapabilityDescriptor $descriptor, int $userId, string $userType): AuthDecision
    {
        if ($userId <= 0) {
            if ($this->isSystemContext() && (bool) config('wise-agent.security.trust_console_context', true)) {
                ContextualLogger::channel('ai')->info(
                    "[CapabilityAuthorizer] Allowed by system context (console/queue): "
                    . "{$descriptor->kind} '{$descriptor->name}' ({$descriptor->permission})"
                );

                return AuthDecision::allow(
                    'system_context',
                    '控制台 / 队列等系统上下文，无登录用户，按系统身份放行',
                    $descriptor->access,
                    $descriptor->permission
                );
            }

            return AuthDecision::deny(
                'anonymous',
                '当前会话未登录，无法判定 AI 能力权限'
                    . '（可通过授权事件监听器或 security.tool_authorize.allow 放行）',
                $descriptor->access,
                $descriptor->permission
            );
        }

        $managerClass = \wise\auth\service\RbacManager::class;

        if (!class_exists($managerClass)) {
            return AuthDecision::deny(
                'rbac_unavailable',
                'wise-auth 未安装，无法进行 RBAC 判定',
                $descriptor->access,
                $descriptor->permission
            );
        }

        try {
            $manager = app($managerClass);

            if ($manager->isSuperAdmin($userId, $userType)) {
                return AuthDecision::allow(
                    'super_admin',
                    '超级管理员自动放行',
                    $descriptor->access,
                    $descriptor->permission
                );
            }

            $granted = (array) $manager->getUserPermissions($userId, $userType);

            $decision = $this->evaluateGranted($descriptor, $granted);

            if (!$decision->allowed) {
                return AuthDecision::deny(
                    $decision->decidedBy,
                    $decision->reason . "（用户 #{$userId}）",
                    $decision->access,
                    $decision->permission
                );
            }

            return $decision;
        } catch (\Throwable $e) {
            ContextualLogger::channel('ai')->error(
                "[CapabilityAuthorizer] RBAC check error: {$e->getMessage()}",
                ['user_id' => $userId, 'user_type' => $userType, 'permission' => $descriptor->permission]
            );

            return AuthDecision::deny(
                'rbac_error',
                'RBAC 校验异常：' . $e->getMessage(),
                $descriptor->access,
                $descriptor->permission
            );
        }
    }

    /**
     * 按用户已获授权限集合判定能力是否放行
     *
     * 判定顺序（分类骨架 → 细粒度下钻 → 全局通配）：
     *   1. `*`                        全局通配
     *   2. `ai.capability.*`          全部 AI 能力
     *   3. `ai.access.{access}.*`     按访问类别（只读 / 写入 / 编排执行 / 管理）
     *   4. 能力自身权限标识（精确或前缀通配）
     *
     * @param array $granted 用户已获授权限标识集合
     */
    public function evaluateGranted(CapabilityDescriptor $descriptor, array $granted): AuthDecision
    {
        $accessWildcard = CapabilityAccess::wildcardIdent($descriptor->access);

        if (in_array('*', $granted, true)) {
            return AuthDecision::allow(
                'rbac',
                '命中全局通配权限 *',
                $descriptor->access,
                $descriptor->permission
            );
        }

        if (in_array(CapabilityAccess::ALL_IDENT, $granted, true)) {
            return AuthDecision::allow(
                'capability_wildcard',
                '命中「全部 AI 能力」通配 ' . CapabilityAccess::ALL_IDENT,
                $descriptor->access,
                $descriptor->permission
            );
        }

        if (in_array($accessWildcard, $granted, true)) {
            return AuthDecision::allow(
                'access_wildcard',
                '命中类别通配 ' . $accessWildcard . '（' . CapabilityAccess::label($descriptor->access) . '）',
                $descriptor->access,
                $descriptor->permission
            );
        }

        if ($this->matchPermission($descriptor->permission, $granted)) {
            return AuthDecision::allow(
                'rbac',
                '角色已授予权限 ' . $descriptor->permission,
                $descriptor->access,
                $descriptor->permission
            );
        }

        return AuthDecision::deny(
            'rbac',
            "RBAC 校验未通过：未被授予 {$descriptor->permission}，也未获得类别授权 {$accessWildcard}",
            $descriptor->access,
            $descriptor->permission
        );
    }

    /**
     * 权限匹配：精确匹配 + 前缀通配（`*` / `ai.skill.*` / `plugin:demo.auto.list.*`）
     *
     * 注意：类别通配（ai.access.read.*）与「全部 AI 能力」（ai.capability.*）
     * 是语义通配，在本方法之前已单独判定，不依赖字符串前缀。
     *
     * @param string $permission 目标权限标识
     * @param array  $granted    用户已获授权限标识集合
     */
    public function matchPermission(string $permission, array $granted): bool
    {
        foreach ($granted as $item) {
            if (!is_string($item) || $item === '') {
                continue;
            }

            if ($item === '*' || $item === $permission) {
                return true;
            }

            if (str_ends_with($item, '*') && str_starts_with($permission, rtrim($item, '*'))) {
                return true;
            }
        }

        return false;
    }

    /**
     * 当前用户 ID
     */
    public function currentUserId(): int
    {
        return (int) (function_exists('auth_id')
            ? (auth_id('admin') ?? auth_id('api') ?? 0)
            : (session('admin_id') ?? session('user_id') ?? 0));
    }

    /**
     * 当前用户类型
     */
    public function currentUserType(): string
    {
        return (string) (function_exists('auth_user')
            ? (auth_user('admin')?->user_type ?? auth_user('api')?->user_type ?? 'admin')
            : (session('user_type') ?? 'admin'));
    }

    /**
     * 是否系统上下文（控制台 / 队列，而非 HTTP 请求）
     */
    public function isSystemContext(): bool
    {
        try {
            if (class_exists(\think\facade\Request::class)) {
                $request = \think\facade\Request::instance();
                if ($request !== null && $request->pathinfo() !== '') {
                    return false;
                }
            }
        } catch (\Throwable) {
        }

        try {
            return app()->runningInConsole();
        } catch (\Throwable) {
            return false;
        }
    }

}
