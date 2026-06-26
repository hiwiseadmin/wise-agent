<?php
declare(strict_types=1);

namespace wise\agent\listener;

use think\facade\Event;
use wise\agent\event\ToolAuthorize;
use wise\agent\event\ToolExecute;

/**
 * 工具安全检查监听器
 *
 * 验证工具执行权限和安全约束。
 * 订阅：ToolExecute（BEFORE 阶段）
 *
 * TODO: 集成应用程序的 RBAC 系统以实现完整的权限执行。
 * 目前仅记录危险操作；实际拦截需要
 * 应用级监听器调用宿主应用的认证服务。
 */
class ToolSecurityCheck
{
    public function handle(ToolExecute $event): void
    {
        // 仅在执行前检查
        if ($event->phase !== ToolExecute::PHASE_BEFORE) {
            return;
        }

        // 如果工具已注册，则检查权限
        $registry = \wise\agent\tool\ToolRegistry::instance();
        $tool = $registry->get($event->toolName);

        if ($tool instanceof \wise\agent\contract\ToolInterface) {
            $permission = $tool->getPermission();
            if (!empty($permission)) {
                // 构建授权事件（默认拒绝）
                $authEvent = new ToolAuthorize($event->toolName, $permission);

                // 从 session 填充当前用户上下文（尽力而为）
                $authEvent->userId   = (int)(session('admin_id') ?? session('user_id') ?? 0);
                $authEvent->userType = (string)(session('user_type') ?? 'admin');

                // 触发事件 — 外部监听器决定允许/拒绝
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

            // 记录危险操作
            if ($tool->requireConfirmation()) {
                \think\facade\Log::channel('ai')->warning(
                    "[ToolSecurity] Dangerous tool used: {$event->toolName}",
                    ['arguments' => array_keys($event->arguments)]
                );
            }
        }
    }
}
