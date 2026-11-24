<?php
declare(strict_types=1);

namespace wise\agent\tool;

use wise\agent\contract\ToolInterface;
use wise\agent\security\CapabilityAccess;

/**
 * 工具基类
 *
 * 提供合理的默认值。继承此类以创建自定义工具。
 *
 * 权限分类（访问类型）：
 *   默认 `execute`（保守：需显式授权，勾「全部编排执行」或细粒度权限才可用）。
 *   请在子类按实际语义覆写 getAccess() / getRiskLevel()：
 *     - 只查询、无副作用          → CapabilityAccess::READ  + RISK_LOW
 *     - 新增 / 修改 / 删除数据     → CapabilityAccess::WRITE + RISK_MEDIUM
 *     - 不可逆 / 触碰资金或外部系统 → CapabilityAccess::WRITE + RISK_HIGH
 *     - 系统级 / 运维类            → CapabilityAccess::ADMIN + RISK_HIGH
 *   分类决定了权限树归属与「类别通配」授权（如「全部只读能力」），
 *   并参与审批策略判定（详见 wise\agent\security\ApprovalPolicy）。
 */
abstract class BaseTool implements ToolInterface
{
    /**
     * @inheritDoc
     */
    public function getPermission(): string
    {
        return '';
    }

    /**
     * @inheritDoc
     */
    public function requireConfirmation(): bool
    {
        return false;
    }

    /**
     * @inheritDoc
     *
     * 默认保守归为「编排执行」：未分类的工具不会被「只读 / 写入」类别通配放行。
     */
    public function getAccess(): string
    {
        return CapabilityAccess::EXECUTE;
    }

    /**
     * @inheritDoc
     */
    public function getRiskLevel(): string
    {
        return CapabilityAccess::RISK_MEDIUM;
    }

    /**
     * 通过类名约定获取工具名称
     *
     * 去掉类基名中的 'Tool' 后缀，并将
     * 驼峰命名转换为蛇形命名。使用 preg_replace 仅匹配后缀，
     * 以避免破坏如 'ToolBoxTool' 这样的类名。
     */
    public function getName(): string
    {
        $class = basename(str_replace('\\', '/', static::class));
        $name = preg_replace('/Tool$/', '', $class);
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $name));
    }
}
