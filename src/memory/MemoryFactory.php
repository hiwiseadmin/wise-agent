<?php
declare(strict_types=1);

namespace wise\agent\memory;

use think\facade\Config;
use wise\agent\contract\MemoryInterface;
use wise\agent\exception\AiException;

/**
 * 记忆工厂
 *
 * 基于配置创建记忆存储实例。
 * 类似于框架中的 StorageFactory 模式。
 */
class MemoryFactory
{
    protected static array $instances = [];

    /**
     * 创建记忆存储实例
     *
     * @param string|null $store 存储名称（null 表示使用默认值）
     * @return MemoryInterface
     */
    public static function create(?string $store = null): MemoryInterface
    {
        $store = $store ?: Config::get('wise-agent.memory.default', 'database');

        if (isset(static::$instances[$store])) {
            return static::$instances[$store];
        }

        $stores = Config::get('wise-agent.memory.stores', []);
        if (!isset($stores[$store])) {
            throw new AiException("Unknown memory store: {$store}");
        }

        $config = $stores[$store];
        $class = $config['class'] ?? null;

        if (!$class || !class_exists($class)) {
            throw new AiException("Memory store class not found: {$class}");
        }

        $instance = new $class($config);

        if (!($instance instanceof MemoryInterface)) {
            throw new AiException("Memory store class must implement MemoryInterface");
        }

        static::$instances[$store] = $instance;
        return $instance;
    }

    /**
     * 清除缓存的实例
     */
    public static function clearCache(): void
    {
        static::$instances = [];
    }
}
