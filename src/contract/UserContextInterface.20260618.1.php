<?php
declare(strict_types=1);

namespace wise\agent\Contract;

/**
 * User context interface
 *
 * Abstracts user identification for AI operations (session tracking,
 * memory storage, logging). Decouples the agent from hardcoded
 * session keys (admin_id, user_id, user_type).
 *
 * Implementations can read from session, JWT, API token, or any
 * custom auth mechanism.
 */
interface UserContextInterface
{
    /**
     * Get the current user ID
     *
     * @return int User ID, or 0 if not authenticated
     */
    public function getUserId(): int;

    /**
     * Get the current user type
     *
     * @return string User type identifier (e.g., 'admin', 'user', 'api')
     */
    public function getUserType(): string;
}
