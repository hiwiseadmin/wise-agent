<?php
declare(strict_types=1);

namespace wise\agent\Contract;

/**
 * AI Client interface
 *
 * Defines the contract for LLM provider clients.
 * New providers can be added by implementing this interface.
 */
interface AiClientInterface
{
    /**
     * Send a chat completion request (non-streaming)
     *
     * @param array $messages Message list [['role' => 'user', 'content' => '...']]
     * @param array $tools    Available tool definitions (Function Calling format)
     * @param array $options  Extra parameters (temperature, max_tokens, etc.)
     * @return array ['content' => '...', 'tool_calls' => [...], 'usage' => [...]]
     */
    public function chat(array $messages, array $tools = [], array $options = []): array;

    /**
     * Send a streaming chat completion request
     *
     * Yields events one by one as the LLM generates tokens.
     * Event types:
     *   - ['type' => 'token', 'content' => '...']         — single text token
     *   - ['type' => 'tool_call', 'call' => [...]]         — tool call detected (partial)
     *   - ['type' => 'finish', 'reason' => '...']          — stream finished
     *   - ['type' => 'error', 'message' => '...']          — error encountered
     *
     * @param array $messages Message list
     * @param array $tools    Available tool definitions
     * @param array $options  Extra parameters (temperature, max_tokens, etc.)
     * @return \Generator  Yields event arrays
     */
    public function chatStream(array $messages, array $tools = [], array $options = []): \Generator;

    /**
     * Get the provider name
     */
    public function getProviderName(): string;

    /**
     * Get the model name being used
     */
    public function getModelName(): string;

    /**
     * Check if the provider supports a feature
     */
    public function supports(string $feature): bool;
}
