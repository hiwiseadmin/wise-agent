<?php
declare(strict_types=1);

namespace wise\agent\security;

/**
 * AI 能力授权判定结果
 *
 * 由 `CapabilityAuthorizer` 产出，统一描述「是否放行 / 由什么策略决定 / 原因」，
 * 便于日志、审计与前端展示（AI 可据此向用户解释为什么被拒绝）。
 */
final class AuthDecision
{
    /**
     * @param bool   $allowed   是否放行
     * @param string $decidedBy 判定来源：no_permission_required | deny_list | allow_list | event
     *                          | super_admin | access_wildcard | capability_wildcard | rbac
     *                          | system_context | anonymous | rbac_unavailable | rbac_error
     * @param string $reason    人类可读说明
     * @param string $access    访问类型（read/write/execute/admin）
     * @param string $permission 判定的权限标识
     */
    public function __construct(
        public readonly bool $allowed,
        public readonly string $decidedBy = '',
        public readonly string $reason = '',
        public readonly string $access = '',
        public readonly string $permission = ''
    ) {
    }

    public static function allow(
        string $decidedBy,
        string $reason = '',
        string $access = '',
        string $permission = ''
    ): self {
        return new self(true, $decidedBy, $reason, $access, $permission);
    }

    public static function deny(
        string $decidedBy,
        string $reason = '',
        string $access = '',
        string $permission = ''
    ): self {
        return new self(false, $decidedBy, $reason, $access, $permission);
    }

    /**
     * 转为数组（用于日志与 API 输出）
     */
    public function toArray(): array
    {
        return [
            'allowed'    => $this->allowed,
            'decided_by' => $this->decidedBy,
            'reason'     => $this->reason,
            'access'     => $this->access,
            'permission' => $this->permission,
        ];
    }
}
