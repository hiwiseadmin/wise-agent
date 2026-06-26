<?php
declare(strict_types=1);

namespace wise\agent\Memory\Session;

use wise\agent\Contract\UserContextInterface;

/**
 * Default session-based user context implementation
 *
 * Reads user identity from PHP session variables.
 * Provides the default implementation for UserContextInterface.
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
