<?php
declare(strict_types=1);

namespace wise\agent\Tool;

/**
 * PathHelper trait
 *
 * Shared path resolution logic for file-based tools.
 * Ensures consistent behavior between FileReadTool and FileWriteTool.
 *
 * Supports absolute paths (Unix, Windows, UNC), and restricts
 * resolution to within the configured base path for security.
 */
trait PathHelper
{
    protected string $basePath;

    /**
     * Resolve a path safely within the base path
     *
     * Supports:
     *   - Unix absolute: /home/user/file.txt
     *   - Windows absolute: C:\path\to\file.txt
     *   - UNC paths: \\server\share\file.txt
     *   - Relative paths (resolved against basePath)
     *
     * @return string|null Resolved absolute path, or null if outside basePath
     */
    protected function resolvePath(string $path): ?string
    {
        $isAbsolute = $this->isAbsolutePath($path);

        if ($isAbsolute) {
            $resolved = realpath($path);
        } else {
            $resolved = realpath($this->basePath . DIRECTORY_SEPARATOR . $path);
        }

        if ($resolved === false) {
            // For write operations, the directory may not exist yet
            $fullPath = $this->basePath . DIRECTORY_SEPARATOR . ltrim($path, '/\\');
            $parent = dirname($fullPath);
            while (!file_exists($parent) && strlen($parent) > strlen($this->basePath)) {
                $parent = dirname($parent);
            }
            $resolved = realpath($parent);
        }

        if ($resolved === false) {
            return null;
        }

        // Ensure path is within basePath
        $baseResolved = realpath($this->basePath);
        if ($baseResolved === false || !str_starts_with($resolved, $baseResolved)) {
            return null;
        }

        return $resolved;
    }

    /**
     * Check if a path is absolute (Unix, Windows, or UNC)
     */
    protected function isAbsolutePath(string $path): bool
    {
        // Unix absolute: /home/user/file.txt
        if (str_starts_with($path, '/')) {
            return true;
        }

        // Windows absolute: C:\path\to\file.txt
        if (preg_match('/^[A-Z]:\\\\/i', $path)) {
            return true;
        }

        // UNC paths: \\server\share\file.txt
        if (str_starts_with($path, '\\\\') || str_starts_with($path, '//')) {
            return true;
        }

        return false;
    }
}
