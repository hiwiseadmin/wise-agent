<?php
declare(strict_types=1);

namespace wise\agent\Memory;

use think\facade\Config;
use wise\agent\Contract\MemoryInterface;
use wise\agent\Exception\AiException;

/**
 * Memory factory
 *
 * Creates memory store instances based on configuration.
 * Analogous to StorageFactory pattern in the framework.
 */
class MemoryFactory
{
    protected static array $instances = [];

    /**
     * Create a memory store instance
     *
     * @param string|null $store Store name (null = use default)
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
     * Clear cached instances
     */
    public static function clearCache(): void
    {
        static::$instances = [];
    }
}
