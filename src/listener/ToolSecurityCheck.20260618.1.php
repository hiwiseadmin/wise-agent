<?php
declare(strict_types=1);

namespace wise\agent\Listener;

use think\facade\Event;
use wise\agent\Event\ToolAuthorize;
use wise\agent\Event\ToolExecute;

/**
 * Tool security check listener
 *
 * Validates tool execution permissions and safety constraints.
 * Subscribes to: ToolExecute (BEFORE phase)
 *
 * TODO: Integrate with application's RBAC system for full permission enforcement.
 * Currently only logs dangerous operations; actual blocking requires
 * application-level listeners that call the host app's auth service.
 */
class ToolSecurityCheck
{
    public function handle(ToolExecute $event): void
    {
        // Only check before execution
        if ($event->phase !== ToolExecute::PHASE_BEFORE) {
            return;
        }

        // Check permission if tool is registered
        $registry = \wise\agent\Tool\ToolRegistry::instance();
        $tool = $registry->get($event->toolName);

        if ($tool instanceof \wise\agent\Contract\ToolInterface) {
            $permission = $tool->getPermission();
            if (!empty($permission)) {
                // Build authorization event (DEFAULT DENY)
                $authEvent = new ToolAuthorize($event->toolName, $permission);

                // Populate current user context from session (best-effort)
                $authEvent->userId   = (int)(session('admin_id') ?? session('user_id') ?? 0);
                $authEvent->userType = (string)(session('user_type') ?? 'admin');

                // Fire event — external listeners decide allow/deny
                Event::trigger($authEvent);

                if (!$authEvent->authorized) {
                    $event->skip       = true;
                    $event->mockResult = json_encode([
                        'error'  => "Permission denied: {$permission}",
                        'tool'   => $event->toolName,
                        'reason' => $authEvent->reason ?: 'No authorization listener granted access',
                    ], JSON_UNESCAPED_UNICODE);

                    \think\facade\Log::channel('ai')->warning(
                        "[ToolSecurity] Blocked unauthorized tool: {$event->toolName} ({$permission})"
                    );
                    return;
                }
            }

            // Log dangerous operations
            if ($tool->requireConfirmation()) {
                \think\facade\Log::channel('ai')->warning(
                    "[ToolSecurity] Dangerous tool used: {$event->toolName}",
                    ['arguments' => array_keys($event->arguments)]
                );
            }
        }
    }
}
