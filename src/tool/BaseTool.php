<?php
declare(strict_types=1);

namespace wise\agent\tool;

use wise\agent\contract\ToolInterface;

/**
 * 工具基类
 *
 * 提供合理的默认值。继承此类以创建自定义工具。
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
     * 通过类名约定获取工具名称
     *
     * 去掉类基名中的 'Tool' 后缀，并将
     * 驼峰命名转换为蛇形命名。使用 preg_replace 仅匹配后缀，
     * 以避免破坏如 'ToolBoxTool' 这样的类名。
     */
    public function getName(): string
    {
        $class = basename(str_replace('\\', '/', static::class));
        $name = preg_replace('/Tool$/', '', $class);
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $name));
    }
}
