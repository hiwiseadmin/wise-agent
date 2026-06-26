<?php
declare(strict_types=1);

namespace wise\agent\Event;

/**
 * Tool authorization event
 *
 * Fired before tool execution when the tool has a permission requirement.
 * Default: denied (authorized = false). Listeners must explicitly allow().
 *
 * If no listener responds, the tool is blocked by default (secure-by-default).
 *
 * @property string $toolName   Tool name, e.g. "file_read"
 * @property string $permission Permission identifier, e.g. "ai.tool.file_read"
 * @property bool   $authorized Whether the tool execution is authorized (DEFAULT DENY)
 * @property ?int   $userId     Current user ID from session
 * @property string $userType   Current user type from session
 * @property string $reason     Reason string if authorization was denied
 */
class ToolAuthorize
{
    /** @var string Tool name, e.g. "file_read" */
    public string $toolName;

    /** @var string Permission identifier, e.g. "ai.tool.file_read" */
    public string $permission;

    /** @var bool Whether authorized — DEFAULT DENY (secure-by-default) */
    public bool $authorized = false;

    /** @var ?int Current user ID populated from session */
    public ?int $userId = null;

    /** @var string Current user type populated from session */
    public string $userType = 'admin';

    /** @var string Reason for denial */
    public string $reason = '';

    /**
     * @param string $toolName   Tool name
     * @param string $permission Permission identifier
     */
    public function __construct(string $toolName, string $permission)
    {
        $this->toolName   = $toolName;
        $this->permission = $permission;
    }

    /**
     * Mark the tool execution as authorized
     */
    public function allow(): void
    {
        $this->authorized = true;
    }

    /**
     * Deny the tool execution with an optional reason
     *
     * @param string $reason Human-readable reason for denial
     */
    public function deny(string $reason = ''): void
    {
        $this->authorized = false;
        $this->reason     = $reason;
    }
}
