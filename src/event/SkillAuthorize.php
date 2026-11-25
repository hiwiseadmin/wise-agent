<?php
declare(strict_types=1);

namespace wise\agent\event;

/**
 * Skill 授权事件
 *
 * 在 Skill 具有权限要求时，执行前触发（由 `SkillManager::execute()` 触发）。
 * 默认拒绝，监听器需显式 allow()。语义与扩展方式详见基类 {@see CapabilityAuthorize}。
 *
 * 为什么需要它：
 *   历史上 Skill 完全没有权限模型，而唯一的编排入口工具 `orchestrate_step`
 *   又不要求确认，导致「只要能用 orchestrate_step，就能执行任意 Skill」——
 *   这是一条完整的权限绕过通道。本事件与 Skill 权限标识一起堵住该缺口。
 *
 * @property string $skillName Skill 名称，如 "content.translate"
 */
class SkillAuthorize extends CapabilityAuthorize
{
    /** @var string Skill 名称，如 "content.translate" */
    public string $skillName;

    public function __construct(string $skillName, string $permission)
    {
        parent::__construct($permission);

        $this->skillName = $skillName;
    }

    public function getCapabilityName(): string
    {
        return $this->skillName;
    }

    public function getCapabilityKind(): string
    {
        return 'skill';
    }
}
