<?php
declare(strict_types=1);

namespace wise\agent\client;

use think\facade\Config;
use wise\agent\contract\AiClientInterface;
use wise\agent\exception\ProviderException;

/**
 * AI 客户端工厂
 *
 * 基于配置创建 LLM Provider 客户端。
 * 类似于框架中的 StorageFactory 模式。
 *
 * 注意：实例以静态方式缓存。如果 Provider 配置
 * 在运行时发生更改，请调用 clearCache() 强制重新创建。
 * 这是一个设计权衡：缓存在请求范围的应用程序中
 * 可以提高性能，但代价是不能自动反映
 * 运行时的配置更改。
 */
class AiClientFactory
{
    protected static array $instances = [];
    protected static array $customProviders = [];

    /**
     * 创建一个客户端实例
     *
     * 实例按 Provider 名称缓存。如果 Provider 配置
     * 在运行时发生更改，请调用 clearCache() 重置。
     *
     * @param string|null $provider Provider 名称（null = 使用默认值）
     * @return AiClientInterface
     * @throws ProviderException
     */
    public static function create(?string $provider = null): AiClientInterface
    {
        $provider = $provider ?: Config::get('wise-agent.default', 'openai');

        // 返回缓存实例（设计原理见类文档）
        $cacheKey = $provider;
        if (isset(static::$instances[$cacheKey])) {
            return static::$instances[$cacheKey];
        }

        // 首先检查自定义 Provider
        if (isset(static::$customProviders[$provider])) {
            $config = static::$customProviders[$provider];
        } else {
            $providers = Config::get('wise-agent.providers', []);
            if (!isset($providers[$provider])) {
                throw new ProviderException("Unknown AI provider: {$provider}");
            }
            $config = $providers[$provider];
        }

        $class = $config['class'] ?? null;
        if (!$class || !class_exists($class)) {
            throw new ProviderException("Provider class not found: {$class}");
        }

        $instance = new $class($config);

        if (!($instance instanceof AiClientInterface)) {
            throw new ProviderException("Provider class must implement AiClientInterface");
        }

        static::$instances[$cacheKey] = $instance;
        return $instance;
    }

    /**
     * 注册自定义 Provider
     *
     * 插件可以调用此方法来添加自定义 LLM Provider。
     *
     * @param string $name   Provider 名称
     * @param array  $config Provider 配置
     */
    public static function register(string $name, array $config): void
    {
        static::$customProviders[$name] = $config;
        unset(static::$instances[$name]);
    }

    /**
     * 清除所有缓存的客户端实例
     *
     * 在运行时配置更改后调用，以强制
     * 使用更新后的设置创建新实例。
     */
    public static function clearCache(): void
    {
        static::$instances = [];
    }

    /**
     * 获取所有已注册的 Provider 名称
     */
    public static function getProviders(): array
    {
        $configured = array_keys(Config::get('wise-agent.providers', []));
        $custom = array_keys(static::$customProviders);
        return array_unique(array_merge($configured, $custom));
    }
}
