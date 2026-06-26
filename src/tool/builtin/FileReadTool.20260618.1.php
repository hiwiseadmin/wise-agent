<?php
declare(strict_types=1);

namespace wise\agent\Tool\builtin;

use wise\agent\Tool\BaseTool;
use wise\agent\Tool\PathHelper;

/**
 * File read tool
 *
 * Reads file content from the application.
 * Disabled by default for security.
 */
class FileReadTool extends BaseTool
{
    use PathHelper;

    public function __construct(?string $basePath = null)
    {
        $this->basePath = $basePath ?? dirname(__DIR__, 5);
    }

    public function getName(): string
    {
        return 'file_read';
    }

    public function getDescription(): string
    {
        return 'Read the content of a file. Returns the file contents as text.';
    }

    public function getParameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'path' => [
                    'type'        => 'string',
                    'description' => 'The relative or absolute path to the file to read',
                ],
            ],
            'required'   => ['path'],
        ];
    }

    public function getPermission(): string
    {
        return 'ai.tool.file_read';
    }

    public function requireConfirmation(): bool
    {
        return true;
    }

    public function execute(array $arguments): string
    {
        $path = $arguments['path'] ?? '';
        if (empty($path)) {
            return json_encode(['error' => 'Path is required']);
        }

        $fullPath = $this->resolvePath($path);
        if ($fullPath === null) {
            return json_encode(['error' => 'Access denied: path is outside allowed directory']);
        }

        if (!file_exists($fullPath)) {
            return json_encode(['error' => "File not found: {$path}"]);
        }

        if (!is_readable($fullPath)) {
            return json_encode(['error' => "File not readable: {$path}"]);
        }

        if (is_dir($fullPath)) {
            return json_encode(['error' => "Path is a directory: {$path}"]);
        }

        $content = file_get_contents($fullPath);
        if ($content === false) {
            return json_encode(['error' => "Failed to read file: {$path}"]);
        }

        // Limit to 100KB
        if (strlen($content) > 102400) {
            $content = substr($content, 0, 102400) . "\n\n... (truncated)";
        }

        return $content;
    }
}
