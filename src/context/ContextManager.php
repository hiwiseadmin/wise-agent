<?php
declare(strict_types=1);

namespace wise\agent\context;

use wise\support\context\ContextualLogger;
use wise\agent\agent\AgentContext;
use wise\agent\context\contract\ContextProviderInterface;

/**
 * 上下文管理器
 *
 * 收集所有已注册的 ContextProvider，在 Agent 配置阶段将上下文
 * 消息注入到 conversation 的 system prompt 之前。
 *
 * 插件在 BootServiceProvider 中注册自己的 ContextProvider：
 *   \wise\agent\context\ContextManager::instance()->register(new MyProvider());
 */
class ContextManager
{
    protected static ?ContextManager $instance = null;

    /** @var ContextProviderInterface[] */
    protected array $providers = [];

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function reset(): void
    {
        self::$instance = null;
    }

    /**
     * 注册上下文注入器
     */
    public function register(ContextProviderInterface $provider): void
    {
        $this->providers[] = $provider;

        usort($this->providers, function (ContextProviderInterface $a, ContextProviderInterface $b) {
            return $b->getPriority() - $a->getPriority();
        });
    }

    /**
     * 获取所有注册的 Provider
     *
     * @return ContextProviderInterface[]
     */
    public function getProviders(): array
    {
        return $this->providers;
    }

    /**
     * 获取所有注册的 Provider（与 getProviders 一致，统一接口命名）
     *
     * @return ContextProviderInterface[]
     */
    public function all(): array
    {
        return $this->providers;
    }

    /**
     * 构建上下文消息数组
     *
     * AgentManager 在 AgentConfigure 事件中调用此方法，
     * 将返回的消息注入到 agentContext 中。
     *
     * @param AgentContext $agentContext
     * @return array 消息数组 [['role' => 'system', 'content' => '...'], ...]
     */
    public function buildContext(AgentContext $agentContext): array
    {
        $messages = [];

        foreach ($this->providers as $provider) {
            try {
                $contextMessages = $provider->getContext($agentContext);
                if (!empty($contextMessages)) {
                    foreach ($contextMessages as &$msg) {
                        $msg['_source'] = $provider->getSource();
                    }
                    unset($msg);
                    $messages = array_merge($messages, $contextMessages);
                }
            } catch (\Throwable $e) {
                ContextualLogger::warning("[WiseAgent] Context provider '{$provider->getSource()}' failed: {$e->getMessage()}");
            }
        }

        return $messages;
    }

    /**
     * 清除所有 Provider
     */
    public function clear(): void
    {
        $this->providers = [];
    }

    /**
     * Provider 数量
     */
    public function count(): int
    {
        return count($this->providers);
    }
}
