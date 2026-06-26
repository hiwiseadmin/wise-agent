<?php
declare(strict_types=1);

namespace wise\agent\Client;

use think\facade\Config;
use wise\agent\Contract\AiClientInterface;
use wise\agent\Exception\ProviderException;

/**
 * AI Client factory
 *
 * Creates LLM provider clients based on configuration.
 * Analogous to StorageFactory pattern in the framework.
 *
 * NOTE: Instances are cached statically. If provider configuration
 * changes at runtime, call clearCache() to force recreation.
 * This is a design trade-off: caching improves performance in
 * request-scoped applications, at the cost of not reflecting
 * runtime config changes automatically.
 */
class AiClientFactory
{
    protected static array $instances = [];
    protected static array $customProviders = [];

    /**
     * Create a client instance
     *
     * Instances are cached by provider name. Call clearCache()
     * to reset if provider configuration changes at runtime.
     *
     * @param string|null $provider Provider name (null = use default)
     * @return AiClientInterface
     * @throws ProviderException
     */
    public static function create(?string $provider = null): AiClientInterface
    {
        $provider = $provider ?: Config::get('wise-agent.default', 'openai');

        // Return cached instance (see class doc for design rationale)
        $cacheKey = $provider;
        if (isset(static::$instances[$cacheKey])) {
            return static::$instances[$cacheKey];
        }

        // Check custom providers first
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
     * Register a custom provider
     *
     * Plugins can call this to add custom LLM providers.
     *
     * @param string $name   Provider name
     * @param array  $config Provider config
     */
    public static function register(string $name, array $config): void
    {
        static::$customProviders[$name] = $config;
        unset(static::$instances[$name]);
    }

    /**
     * Clear all cached client instances
     *
     * Call this after runtime configuration changes to force
     * new instances with updated settings.
     */
    public static function clearCache(): void
    {
        static::$instances = [];
    }

    /**
     * Get all registered provider names
     */
    public static function getProviders(): array
    {
        $configured = array_keys(Config::get('wise-agent.providers', []));
        $custom = array_keys(static::$customProviders);
        return array_unique(array_merge($configured, $custom));
    }
}
