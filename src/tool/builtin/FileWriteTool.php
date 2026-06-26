<?php
declare(strict_types=1);

namespace wise\agent\tool\builtin;

use wise\agent\tool\BaseTool;
use wise\agent\tool\PathHelper;

/**
 * 文件写入工具
 *
 * 将内容写入文件。出于安全考虑，默认禁用。
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

        // 解析完整路径，支持绝对和相对路径
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
     * 为写入操作解析路径
     *
     * 扩展共享的 resolvePath 以处理目标目录
     * 尚不存在的情况（写入场景中常见）。
     */
    protected function resolvePathForWrite(string $path): ?string
    {
        $base = realpath($this->basePath);
        if ($base === false) {
            return null;
        }

        // 如果路径是绝对路径，先根据 basePath 验证
        if ($this->isAbsolutePath($path)) {
            $pathBase = realpath(dirname($path));
            if ($pathBase === false || !str_starts_with($pathBase, $base)) {
                return null;
            }
            return $path;
        }

        $fullPath = $base . DIRECTORY_SEPARATOR . ltrim($path, '/\\');
        $resolved = realpath(dirname($fullPath));

        // 如果目录不存在，向上查找以找到存在的父目录
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
