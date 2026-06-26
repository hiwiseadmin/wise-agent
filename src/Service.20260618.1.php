<?php
declare(strict_types=1);

namespace wise\agent;

use think\App;
use wise\agent\Agent\AgentManager;
use wise\agent\command\Publish;
use wise\agent\Contract\MemoryInterface;
use wise\agent\Contract\ToolInterface;
use wise\agent\Contract\UserContextInterface;
use wise\agent\Listener\AiLogger;
use wise\agent\Listener\ToolSecurityCheck;
use wise\agent\Memory\DatabaseMemory;
use wise\agent\Memory\MemoryFactory;
use wise\agent\Memory\Session\SessionManager;
use wise\agent\Memory\Session\SessionUserContext;
use wise\agent\Tool\ToolRegistry;

/**
 * Wise-Agent service provider
 *
 * Registered via composer.json extra.think.services for automatic discovery.
 *
 * Responsibilities:
 *  1. Merge configuration
 *  2. Bind interfaces to implementations
 *  3. Register console commands
 *  4. Register event listeners
 *  5. Initialize builtin tools
 */
class Service extends \think\Service
{
    use trait\ServiceTrait;

    public function register(): void
    {
        // Merge package config into global config namespace
        $this->mergeConfig('wise-agent');

        // Bind core services
        $this->app->bind(AgentManager::class, AgentManager::class);
        $this->app->bind(SessionManager::class, SessionManager::class);
        $this->app->bind(ToolRegistry::class, ToolRegistry::class);

        // Bind MemoryInterface — use factory pattern instead of singleton
        // so that configuration changes take effect without container reset.
        $this->app->bind(MemoryInterface::class, function (App $app) {
            MemoryFactory::clearCache();
            return MemoryFactory::create();
        });

        // Bind UserContextInterface — decouples from hardcoded session() calls
        $this->app->bind(UserContextInterface::class, SessionUserContext::class);

        // Bind DatabaseMemory
        $this->app->bind(DatabaseMemory::class, DatabaseMemory::class);

        // Register event listeners
        $this->registerEventListeners();

        // Initialize builtin tools
        $this->initializeBuiltinTools();

        // Load helper functions (already auto-loaded via composer autoload.files)
    }

    public function boot(): void
    {
        // Register console commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                Publish::class,
            ]);
        }
    }

    /**
     * Register event listeners for AI lifecycle events
     */
    protected function registerEventListeners(): void
    {
        $event = $this->app->event;

        // Logger
        $logger = new AiLogger();
        $event->listen('AgentStart', [$logger, 'handle']);
        $event->listen('AgentConfigure', [$logger, 'handle']);
        $event->listen('AgentStep', [$logger, 'handle']);
        $event->listen('AgentComplete', [$logger, 'handle']);
        $event->listen('AgentError', [$logger, 'handle']);
        $event->listen('AiRequest', [$logger, 'handle']);
        $event->listen('AiResponse', [$logger, 'handle']);

        // Security
        $security = new ToolSecurityCheck();
        $event->listen('ToolExecute', [$security, 'handle']);
    }

    /**
     * Initialize builtin tools from configuration
     */
    protected function initializeBuiltinTools(): void
    {
        $configuredTools = $this->app->config->get('wise-agent.tools', []);
        $registry = ToolRegistry::instance();

        foreach ($configuredTools as $name => $config) {
            if (empty($config['enabled'])) {
                continue;
            }

            $class = $config['class'] ?? null;
            if (!$class || !class_exists($class)) {
                continue;
            }

            $instance = $this->app->make($class);
            if ($instance instanceof ToolInterface) {
                $registry->register($instance);
            }
        }
    }
}
