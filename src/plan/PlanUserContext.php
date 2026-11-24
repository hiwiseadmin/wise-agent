<?php
declare(strict_types=1);

namespace wise\agent\plan;

use wise\agent\security\CapabilityAuthorizer;

/**
 * 计划工具的「当前用户」来源（B1-4）
 *
 * 两个用途，都必须拿到**真实身份**：
 *
 * 1. **计划级 fail-closed**：系统上下文（`user_id<=0`）下含写步骤的计划要拒绝执行
 *    （`ApprovalManager::gate()` 在该上下文会跳过审批）；
 * 2. **续跑的身份一致性**：审批单匹配包含申请人（`ApprovalManager` 匹配维度），
 *    换个人续跑会「批了也消费不到」。
 *
 * 身份有两级来源（后者兜底前者）：
 *
 * - **工具提供者注入**：`ToolProviderInterface::execute()` 拿到的 `RequestContext`
 *   （HTTP 请求链路上最可靠）——通过 {@see self::withUser()} 注入；
 * - **`CapabilityAuthorizer::currentUserId()`**：与工具审批链同源
 *   （`ToolSecurityCheck` 也是这么取当前用户的），因此直接调用工具时不会取错人。
 *
 * 取不到时返回 `0`（= 系统上下文）——**保守而非放行**：写计划会被 fail-closed 拒掉。
 */
trait PlanUserContext
{
    private ?int $planUserId = null;

    private ?string $planUserType = null;

    /**
     * 注入当前用户（工具提供者从 `RequestContext` 取；返回 `$this` 便于链式调用）
     */
    public function withUser(?int $userId, ?string $userType = null): static
    {
        if ($userId !== null) {
            $this->planUserId = $userId;
        }

        if ($userType !== null) {
            $this->planUserType = $userType;
        }

        return $this;
    }

    protected function currentUserId(): int
    {
        if ($this->planUserId !== null) {
            return $this->planUserId;
        }

        try {
            return (new CapabilityAuthorizer())->currentUserId();
        } catch (\Throwable) {
            return 0;
        }
    }

    protected function currentUserType(): string
    {
        if ($this->planUserType !== null) {
            return $this->planUserType;
        }

        try {
            return (new CapabilityAuthorizer())->currentUserType();
        } catch (\Throwable) {
            return 'admin';
        }
    }
}
