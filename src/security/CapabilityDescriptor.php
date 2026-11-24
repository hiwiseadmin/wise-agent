<?php
declare(strict_types=1);

namespace wise\agent\security;

/**
 * AI 能力描述符（Tool / Skill 归一化元数据）
 *
 * 由 `AccessResolver` 产出，是「能力元数据」的统一表达：
 * 鉴权（CapabilityAuthorizer）与权限注册（ToolPermissionRegistrar）共用同一份描述，
 * 避免两处各自推断导致口径分裂。
 */
final class CapabilityDescriptor
{
    public const KIND_TOOL  = 'tool';
    public const KIND_SKILL = 'skill';

    /**
     * @param string $kind            能力类型：tool | skill
     * @param string $name            能力名称（工具名 / 技能名）
     * @param string $permission      权限标识（空字符串表示不需要权限）
     * @param string $access          访问类型：read | write | execute | admin
     * @param string $risk            风险等级：low | medium | high
     * @param string $description     能力描述
     * @param string $plugin          归属插件标识
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $name,
        public readonly string $permission,
        public readonly string $access,
        public readonly string $risk,
        public readonly string $description = '',
        public readonly string $plugin = ''
    ) {
    }

    public function isTool(): bool
    {
        return $this->kind === self::KIND_TOOL;
    }

    public function isSkill(): bool
    {
        return $this->kind === self::KIND_SKILL;
    }

    /**
     * 是否需要权限校验（无权限标识 = 免校验）
     */
    public function requiresPermission(): bool
    {
        return $this->permission !== '';
    }
}
