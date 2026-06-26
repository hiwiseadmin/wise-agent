<?php
declare(strict_types=1);

namespace wise\agent\memory\session;

use wise\agent\contract\UserContextInterface;

/**
 * 默认的基于 session 的用户上下文实现
 *
 * 从 PHP session 变量中读取用户身份。
 * 提供 UserContextInterface 的默认实现。
 */
class SessionUserContext implements UserContextInterface
{
    public function getUserId(): int
    {
        return (int) (session('admin_id') ?? session('user_id') ?? 0);
    }

    public function getUserType(): string
    {
        return (string) (session('user_type') ?? 'admin');
    }
}
