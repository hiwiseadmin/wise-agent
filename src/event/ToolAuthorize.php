<?php
declare(strict_types=1);

namespace wise\agent\event;

/**
 * 工具授权事件
 *
 * 在工具具有权限要求时，执行前触发。
 * 默认：拒绝（authorized = false）。监听器必须显式调用 allow()。
 *
 * 如果没有监听器响应，工具默认被阻止（默认安全原则）。
 *
 * @property string $toolName   工具名称，如 "file_read"
 * @property string $permission 权限标识，如 "ai.tool.file_read"
 * @property bool   $authorized 工具执行是否已授权（默认拒绝）
 * @property ?int   $userId     从 session 获取的当前用户 ID
 * @property string $userType   从 session 获取的当前用户类型
 * @property string $reason     授权被拒绝时的原因字符串
 */
class ToolAuthorize
{
    /** @var string 工具名称，如 "file_read" */
    public string $toolName;

    /** @var string 权限标识，如 "ai.tool.file_read" */
    public string $permission;

    /** @var bool 是否已授权 — 默认拒绝（默认安全原则） */
    public bool $authorized = false;

    /** @var ?int 从 session 获取的当前用户 ID */
    public ?int $userId = null;

    /** @var string 从 session 获取的当前用户类型 */
    public string $userType = 'admin';

    /** @var string 拒绝原因 */
    public string $reason = '';

    /**
     * @param string $toolName   工具名称
     * @param string $permission 权限标识
     */
    public function __construct(string $toolName, string $permission)
    {
        $this->toolName   = $toolName;
        $this->permission = $permission;
    }

    /**
     * 将工具执行标记为已授权
     */
    public function allow(): void
    {
        $this->authorized = true;
    }

    /**
     * 拒绝工具执行，可附带原因
     *
     * @param string $reason 人类可读的拒绝原因
     */
    public function deny(string $reason = ''): void
    {
        $this->authorized = false;
        $this->reason     = $reason;
    }
}
