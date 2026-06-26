<?php
declare(strict_types=1);

namespace wise\agent\Tool;

use wise\agent\Contract\ToolInterface;

/**
 * Base tool class
 *
 * Provides sensible defaults. Extend this class to create custom tools.
 */
abstract class BaseTool implements ToolInterface
{
    /**
     * @inheritDoc
     */
    public function getPermission(): string
    {
        return '';
    }

    /**
     * @inheritDoc
     */
    public function requireConfirmation(): bool
    {
        return false;
    }

    /**
     * Get tool name from class name convention
     *
     * Strips the 'Tool' suffix from the class basename and converts
     * CamelCase to snake_case. Uses preg_replace for suffix-only matching
     * to avoid mangling class names like 'ToolBoxTool'.
     */
    public function getName(): string
    {
        $class = basename(str_replace('\\', '/', static::class));
        $name = preg_replace('/Tool$/', '', $class);
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $name));
    }
}
