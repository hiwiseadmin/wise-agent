<?php
declare(strict_types=1);

namespace wise\agent\event;

/**
 * 能力授权事件基类（Tool / Skill 共用）
 *
 * 在能力（工具 / 技能）具有权限要求时，执行前触发。
 * 默认：拒绝（authorized = false）。监听器必须显式调用 allow()。
 *
 * 判定优先级（由 `CapabilityAuthorizer` 实际执行）：
 *   1. deny()  —— 硬拒绝，优先级最高，且不会再走 RBAC 兜底
 *   2. allow() —— 显式放行
 *   3. 均未命中 —— 回落 wise-auth RBAC（超管直通 + 类别通配）
 *
 * 该事件是开放的扩展点：任意包 / 插件都可以注册自己的监听器实现
 * 不同的授权策略（黑名单、按参数细粒度判定、外部鉴权服务等）。
 *
 * @property string $permission 权限标识，如 "ai.tool.file_read" / "ai.skill.content.translate"
 * @property string $access     访问类型：read | write | execute | admin
 * @property bool   $authorized 是否已授权（默认拒绝）
 * @property bool   $denied     是否被显式拒绝（拒绝优先于允许）
 * @property string $decidedBy  判定来源，便于日志排查
 * @property array  $arguments  调用参数（供监听器做细粒度判定、按参数拒绝）
 * @property ?int   $userId     当前用户 ID
 * @property string $userType   当前用户类型
 * @property string $reason     拒绝原因
 */
abstract class CapabilityAuthorize
{
    /** @var string 权限标识 */
    public string $permission;

    /** @var string 访问类型：read | write | execute | admin */
    public string $access = '';

    /** @var bool 是否已授权 — 默认拒绝（默认安全原则） */
    public bool $authorized = false;

    /** @var bool 是否被显式拒绝 — 拒绝优先于允许，且会跳过 RBAC 兜底 */
    public bool $denied = false;

    /** @var string 判定来源（super_admin / rbac / access_wildcard / allow_list / deny_list / event ...） */
    public string $decidedBy = '';

    /** @var array 调用参数（供监听器做细粒度判定） */
    public array $arguments = [];

    /** @var ?int 当前用户 ID */
    public ?int $userId = null;

    /** @var string 当前用户类型 */
    public string $userType = 'admin';

    /** @var string 拒绝原因 */
    public string $reason = '';

    /** @var float 事件创建时间 */
    public float $timestamp;

    public function __construct(string $permission)
    {
        $this->permission = $permission;
        $this->timestamp  = microtime(true);
    }

    /**
     * 能力名称（工具名 / 技能名）
     */
    abstract public function getCapabilityName(): string;

    /**
     * 能力类型：tool | skill
     */
    abstract public function getCapabilityKind(): string;

    /**
     * 将能力执行标记为已授权
     *
     * 若此前已被 deny() 拒绝，则本调用不会覆盖拒绝结果（拒绝优先）。
     *
     * @param string $decidedBy 判定来源标识（便于日志排查）
     */
    public function allow(string $decidedBy = 'event'): void
    {
        if ($this->denied) {
            return;
        }

        $this->authorized = true;
        $this->decidedBy  = $decidedBy;
    }

    /**
     * 拒绝能力执行，可附带原因
     *
     * 拒绝优先级最高：一旦调用，判定层不会再执行 RBAC 兜底。
     *
     * @param string $reason    人类可读的拒绝原因
     * @param string $decidedBy 判定来源标识（便于日志排查）
     */
    public function deny(string $reason = '', string $decidedBy = 'event'): void
    {
        $this->denied     = true;
        $this->authorized = false;
        $this->reason     = $reason;
        $this->decidedBy  = $decidedBy;
    }

    /**
     * 是否已有明确的授权结论（允许或拒绝）
     */
    public function isDecided(): bool
    {
        return $this->authorized || $this->denied;
    }
}
