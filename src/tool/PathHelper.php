<?php
declare(strict_types=1);

namespace wise\agent\tool;

/**
 * PathHelper trait
 *
 * 基于文件的工具共享的路径解析逻辑。
 * 确保 FileReadTool 和 FileWriteTool 之间的行为一致。
 *
 * 支持绝对路径（Unix、Windows、UNC），并出于安全考虑
 * 将解析限制在配置的基础路径范围内。
 */
trait PathHelper
{
    protected string $basePath;

    /**
     * 在基础路径内安全解析路径
     *
     * 支持：
     *   - Unix 绝对路径：/home/user/file.txt
     *   - Windows 绝对路径：C:\path\to\file.txt
     *   - UNC 路径：\\server\share\file.txt
     *   - 相对路径（根据 basePath 解析）
     *
     * @return string|null 解析后的绝对路径，超出 basePath 范围则返回 null
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
            // 对于写入操作，目录可能尚不存在
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

        // 确保路径在 basePath 内
        $baseResolved = realpath($this->basePath);
        if ($baseResolved === false || !str_starts_with($resolved, $baseResolved)) {
            return null;
        }

        return $resolved;
    }

    /**
     * 检查路径是否为绝对路径（Unix、Windows 或 UNC）
     */
    protected function isAbsolutePath(string $path): bool
    {
        // Unix 绝对路径：/home/user/file.txt
        if (str_starts_with($path, '/')) {
            return true;
        }

        // Windows 绝对路径：C:\path\to\file.txt
        if (preg_match('/^[A-Z]:\\\\/i', $path)) {
            return true;
        }

        // UNC 路径：\\server\share\file.txt
        if (str_starts_with($path, '\\\\') || str_starts_with($path, '//')) {
            return true;
        }

        return false;
    }
}
