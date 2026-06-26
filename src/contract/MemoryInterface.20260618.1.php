<?php
declare(strict_types=1);

namespace wise\agent\Contract;

/**
 * Memory interface
 *
 * Defines the contract for AI memory storage backends.
 * Supports both short-term (session-scoped) and long-term (persisted) memory.
 */
interface MemoryInterface
{
    /**
     * Store a memory entry
     *
     * @param string $sessionId Session identifier (empty string for global memory)
     * @param string $key       Memory key
     * @param mixed  $value     Memory value
     * @param array  $tags      Tags for categorization and retrieval
     */
    public function store(string $sessionId, string $key, mixed $value, array $tags = []): void;

    /**
     * Retrieve a memory entry
     *
     * @param string $sessionId Session identifier
     * @param string $key       Memory key
     * @param mixed  $default   Default value if not found
     * @return mixed
     */
    public function retrieve(string $sessionId, string $key, mixed $default = null): mixed;

    /**
     * Search memory entries by tags
     *
     * @param string $sessionId Session identifier
     * @param array  $tags      Tags to search for
     * @return array
     */
    public function searchByTags(string $sessionId, array $tags): array;

    /**
     * Get all memory entries for a session
     *
     * @param string $sessionId Session identifier
     * @return array
     */
    public function getAll(string $sessionId): array;

    /**
     * Forget (delete) memory entries
     *
     * @param string      $sessionId Session identifier
     * @param string|null $key       Specific key to forget, or null to clear all
     */
    public function forget(string $sessionId, ?string $key = null): void;
}
