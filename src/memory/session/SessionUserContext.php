<?php
declare(strict_types=1);

namespace wise\agent\memory\session;

use wise\agent\contract\UserContextInterface;

/**
 * 默认的基于 AuthManager 的用户上下文实现
 *
 * 优先通过 AuthManager 获取用户身份，回退到 session 为兼容旧版。
 * 提供 UserContextInterface 的默认实现。
 */
class SessionUserContext implements UserContextInterface
{
    public function getUserId(): int
    {
        $id = function_exists('auth_id') ? (auth_id('admin') ?? auth_id('api')) : null;
        if ($id !== null) {
            return (int) $id;
        }
        return (int) (session('admin_id') ?? session('user_id') ?? 0);
    }

    public function getUserType(): string
    {
        if (function_exists('auth_user')) {
            $user = auth_user('admin') ?? auth_user('api');
            if ($user !== null && isset($user->user_type)) {
                return (string) $user->user_type;
            }
        }
        return (string) (session('user_type') ?? 'admin');
    }
}
