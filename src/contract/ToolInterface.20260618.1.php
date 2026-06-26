<?php
declare(strict_types=1);

namespace wise\agent\Contract;

/**
 * Tool interface
 *
 * Defines the contract for agent tools (Function Calling).
 * Plugins implement this interface to add custom tools.
 */
interface ToolInterface
{
    /**
     * Get the tool name (unique identifier)
     */
    public function getName(): string;

    /**
     * Get the tool description (shown to LLM)
     */
    public function getDescription(): string;

    /**
     * Get the parameter schema (JSON Schema format, shown to LLM)
     */
    public function getParameters(): array;

    /**
     * Execute the tool
     *
     * @param array $arguments Arguments from LLM
     * @return string Result text
     */
    public function execute(array $arguments): string;

    /**
     * Get permission identifier (empty string = no restriction)
     */
    public function getPermission(): string;

    /**
     * Whether the tool requires confirmation before execution
     */
    public function requireConfirmation(): bool;
}
