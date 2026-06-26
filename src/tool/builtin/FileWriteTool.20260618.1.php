<?php
declare(strict_types=1);

namespace wise\agent\Tool\builtin;

use wise\agent\Tool\BaseTool;
use wise\agent\Tool\PathHelper;

/**
 * File write tool
 *
 * Writes content to a file. Disabled by default for security.
 */
class FileWriteTool extends BaseTool
{
    use PathHelper;

    public function __construct(?string $basePath = null)
    {
        $this->basePath = $basePath ?? dirname(__DIR__, 5);
    }

    public function getName(): string
    {
        return 'file_write';
    }

    public function getDescription(): string
    {
        return 'Write content to a file. Creates the file if it does not exist, overwrites if it does.';
    }

    public function getParameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'path' => [
                    'type'        => 'string',
                    'description' => 'The relative or absolute path to the file to write',
                ],
                'content' => [
                    'type'        => 'string',
                    'description' => 'The content to write to the file',
                ],
            ],
            'required'   => ['path', 'content'],
        ];
    }

    public function getPermission(): string
    {
        return 'ai.tool.file_write';
    }

    public function requireConfirmation(): bool
    {
        return true;
    }

    public function execute(array $arguments): string
    {
        $path = $arguments['path'] ?? '';
        $content = $arguments['content'] ?? '';

        if (empty($path)) {
            return json_encode(['error' => 'Path is required']);
        }

        // Resolve the full path, supporting absolute and relative paths
        $fullPath = $this->resolvePathForWrite($path);
        if ($fullPath === null) {
            return json_encode(['error' => 'Access denied: path is outside allowed directory']);
        }

        $dir = dirname($fullPath);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true)) {
                return json_encode(['error' => "Failed to create directory: {$dir}"]);
            }
        }

        $bytes = file_put_contents($fullPath, $content);
        if ($bytes === false) {
            return json_encode(['error' => "Failed to write file: {$path}"]);
        }

        return json_encode([
            'success' => true,
            'path'    => $path,
            'bytes'   => $bytes,
        ]);
    }

    /**
     * Resolve path for write operations
     *
     * This extends the shared resolvePath to handle paths where
     * the target directory may not exist yet (common in write scenarios).
     */
    protected function resolvePathForWrite(string $path): ?string
    {
        $base = realpath($this->basePath);
        if ($base === false) {
            return null;
        }

        // If the path is absolute, validate it against basePath first
        if ($this->isAbsolutePath($path)) {
            $pathBase = realpath(dirname($path));
            if ($pathBase === false || !str_starts_with($pathBase, $base)) {
                return null;
            }
            return $path;
        }

        $fullPath = $base . DIRECTORY_SEPARATOR . ltrim($path, '/\\');
        $resolved = realpath(dirname($fullPath));

        // If directory doesn't exist, walk up to find existing parent
        if ($resolved === false) {
            $parent = dirname($fullPath);
            while (!file_exists($parent) && strlen($parent) > strlen($base)) {
                $parent = dirname($parent);
            }
            $resolved = realpath($parent);
        }

        if ($resolved === false) {
            return null;
        }

        if (!str_starts_with($resolved, $base)) {
            return null;
        }

        return $fullPath;
    }
}
