<?php
declare(strict_types=1);

namespace wise\agent\context\builtin;

use think\facade\Session;
use wise\agent\agent\AgentContext;
use wise\agent\context\contract\ContextProviderInterface;
use wise\support\facade\Auth;

/**
 * 当前用户上下文注入器
 *
 * 向 AI 注入当前登录用户信息。
 * 优先级较高（50），确保 AI 知道当前操作者身份。
 */
class UserContextProvider implements ContextProviderInterface
{
    public function getPriority(): int
    {
        return 50;
    }

    public function getContext(AgentContext $agentContext): array
    {
        $userId = class_exists(Auth::class) ? (Auth::id('admin') ?? Auth::id('api')) : null;
        $userType = 'admin';

        if ($userId === null) {
            $userId = Session::get('admin_id') ?: Session::get('user_id');
            $userType = Session::get('user_type', 'admin');
        } else {
            $user = Auth::user('admin') ?? Auth::user('api');
            $userType = $user->user_type ?? 'admin';
        }

        if (empty($userId)) {
            return [];
        }

        $info = [
            '用户ID'   => $userId,
            '用户类型' => $userType,
        ];

        $username = null;
        if ($user = Auth::user('admin')) {
            $username = $user->username ?? $user->nickname ?? null;
        }
        if (!$username) {
            $username = Session::get('admin_username') ?: Session::get('username');
        }
        if ($username) {
            $info['用户名'] = $username;
        }

        $lines = [];
        foreach ($info as $key => $value) {
            $lines[] = "  - {$key}: {$value}";
        }

        return [[
            'role'    => 'system',
            'content' => "【当前用户信息】\n" . implode("\n", $lines),
        ]];
    }

    public function getSource(): string
    {
        return 'builtin:user';
    }
}
