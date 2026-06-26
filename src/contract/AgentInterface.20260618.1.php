<?php
declare(strict_types=1);

namespace wise\agent\Contract;

use wise\agent\Tool\ToolRegistry;

/**
 * Agent interface (identification + execution)
 *
 * Defines the core contract for agent identification and execution.
 * Configuration/builder methods have been extracted to
 * ConfigurableAgentInterface (see Interface Segregation Principle).
 *
 * Plugins can create custom agent types by implementing this interface.
 * For builder-style configuration, also implement ConfigurableAgentInterface.
 */
interface AgentInterface
{
    /**
     * Get the session ID for this agent run
     */
    public function getSessionId(): string;

    /**
     * Get the agent type identifier
     */
    public function getType(): string;

    /**
     * Get the system prompt for this agent
     */
    public function getSystemPrompt(): string;

    /**
     * Get client provider name
     */
    public function getProviderName(): string;

    /**
     * Get available tools
     */
    public function getTools(): ToolRegistry;

    /**
     * Run the agent with the given task
     */
    public function run(string $task, array $context = []): string;

    /**
     * Dispatch agent asynchronously via queue
     */
    public function dispatchAsync(string $task, array $options = []): string;
}
