<?php
declare(strict_types=1);

namespace wise\agent\event;

/**
 * 工具授权事件
 *
 * 在工具具有权限要求时，执行前触发。默认拒绝，监听器需显式 allow()。
 * 语义、判定优先级与扩展方式详见基类 {@see CapabilityAuthorize}。
 *
 * @property string $toolName 工具名称，如 "file_read"
 */
class ToolAuthorize extends CapabilityAuthorize
{
    /** @var string 工具名称，如 "file_read" */
    public string $toolName;

    public function __construct(string $toolName, string $permission)
    {
        parent::__construct($permission);

        $this->toolName = $toolName;
    }

    public function getCapabilityName(): string
    {
        return $this->toolName;
    }

    public function getCapabilityKind(): string
    {
        return 'tool';
    }
}
