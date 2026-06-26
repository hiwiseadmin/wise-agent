<?php
declare(strict_types=1);

namespace wise\agent\Contract;

/**
 * Conversation interface
 *
 * Defines the contract for conversation message management.
 * Implementations can provide different backends (database, in-memory, etc.).
 */
interface ConversationInterface
{
    /**
     * Get the session identifier
     */
    public function getSessionId(): string;

    /**
     * Get the system prompt
     */
    public function getSystemPrompt(): string;

    /**
     * Set the system prompt
     */
    public function setSystemPrompt(string $prompt): self;

    /**
     * Add a message to the conversation
     *
     * @param string      $role        Message role (user, assistant, system, tool)
     * @param string|null $content     Message content
     * @param array|null  $toolCalls   Tool calls made by assistant
     * @param string|null $toolCallId  Tool call ID (for tool results)
     * @param string|null $toolName    Tool name (for tool results)
     */
    public function addMessage(string $role, ?string $content, ?array $toolCalls = null, ?string $toolCallId = null, ?string $toolName = null): self;

    /**
     * Get all messages including system prompt
     *
     * @return array List of message arrays
     */
    public function getMessages(): array;

    /**
     * Get user-facing messages only (filtered)
     *
     * @return array List of user-role message arrays
     */
    public function getUserMessages(): array;

    /**
     * Get the total message count (excluding system prompt)
     */
    public function count(): int;

    /**
     * Clear all messages
     */
    public function clear(): self;
}
