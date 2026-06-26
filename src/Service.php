<?php
declare(strict_types=1);

namespace wise\agent;

use think\App;
use wise\agent\agent\AgentManager;
use wise\agent\command\Publish;
use wise\agent\contract\MemoryInterface;
use wise\agent\contract\ToolInterface;
use wise\agent\contract\UserContextInterface;
use wise\agent\listener\AiLogger;
use wise\agent\listener\ToolSecurityCheck;
use wise\agent\memory\DatabaseMemory;
use wise\agent\memory\MemoryFactory;
use wise\agent\memory\session\SessionManager;
use wise\agent\memory\session\SessionUserContext;
use wise\agent\tool\ToolRegistry;

/**
 * Wise-Agent 服务提供者
 *
 * 通过 composer.json extra.think.services 注册以实现自动发现。
 *
 * 职责：
 *  1. 合并配置
 *  2. 绑定接口到实现
 *  3. 注册控制台命令
 *  4. 注册事件监听器
 *  5. 初始化内置工具
 */
class Service extends \think\Service
{
    use trait\ServiceTrait;

    public function register(): void
    {
        // 将包配置合并到全局配置命名空间
        $this->mergeConfig('wise-agent');

        // 绑定核心服务
        $this->app->bind(AgentManager::class, AgentManager::class);
        $this->app->bind(SessionManager::class, SessionManager::class);
        $this->app->bind(ToolRegistry::class, ToolRegistry::class);

        // 绑定 MemoryInterface — 使用工厂模式而非单例，
        // 以便配置变更在不重置容器的情况下生效。
        $this->app->bind(MemoryInterface::class, function (App $app) {
            MemoryFactory::clearCache();
            return MemoryFactory::create();
        });

        // 绑定 UserContextInterface — 与硬编码的 session() 调用解耦
        $this->app->bind(UserContextInterface::class, SessionUserContext::class);

        // 绑定 DatabaseMemory
        $this->app->bind(DatabaseMemory::class, DatabaseMemory::class);

        // 注册事件监听器
        $this->registerEventListeners();

        // 初始化内置工具
        $this->initializeBuiltinTools();

        // 加载辅助函数（已通过 composer autoload.files 自动加载）
    }

    public function boot(): void
    {
        // 注册控制台命令
        if ($this->app->runningInConsole()) {
            $this->commands([
                Publish::class,
            ]);
        }
    }

    /**
     * 注册 AI 生命周期事件的事件监听器
     */
    protected function registerEventListeners(): void
    {
        $event = $this->app->event;

        // 日志记录
        $logger = new AiLogger();
        $event->listen('AgentStart', [$logger, 'handle']);
        $event->listen('AgentConfigure', [$logger, 'handle']);
        $event->listen('AgentStep', [$logger, 'handle']);
        $event->listen('AgentComplete', [$logger, 'handle']);
        $event->listen('AgentError', [$logger, 'handle']);
        $event->listen('AiRequest', [$logger, 'handle']);
        $event->listen('AiResponse', [$logger, 'handle']);

        // 安全检查
        $security = new ToolSecurityCheck();
        $event->listen('ToolExecute', [$security, 'handle']);
    }

    /**
     * 从配置初始化内置工具
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
