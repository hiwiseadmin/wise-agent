<?php
declare(strict_types=1);

namespace wise\agent\Contract;

use wise\agent\Tool\ToolRegistry;

/**
 * Configurable agent interface (Builder pattern)
 *
 * Extracted from AgentInterface to comply with Interface Segregation Principle.
 * Provides builder-style methods for configuring an agent before execution.
 * Consumer classes that only need to run an agent don't need these methods.
 */
interface ConfigurableAgentInterface
{
    /**
     * Set the system prompt
     */
    public function setSystemPrompt(string $prompt): self;

    /**
     * Get available tools
     */
    public function getTools(): ToolRegistry;

    /**
     * Add a tool to this agent
     */
    public function addTool(callable|string|array $tool, string $name): self;

    /**
     * Set tools for this agent
     */
    public function withTools(array $toolNames): self;

    /**
     * Set memory enabled/disabled
     */
    public function withMemory(bool $enabled): self;

    /**
     * Set client provider name
     */
    public function setProviderName(string $provider): self;
}
