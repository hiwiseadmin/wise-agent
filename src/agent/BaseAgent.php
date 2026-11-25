<?php
declare(strict_types=1);

namespace wise\agent\agent;

use think\facade\Config;
use wise\agent\client\AiClientFactory;
use wise\agent\contract\AgentInterface;
use wise\agent\contract\AiClientInterface;
use wise\agent\contract\ConfigurableAgentInterface;
use wise\agent\contract\ToolInterface;
use wise\agent\memory\session\Conversation;
use wise\agent\memory\session\SessionManager;
use wise\agent\tool\ToolRegistry;
use wise\support\traits\Conditionable;
use wise\support\traits\Tappable;

/**
 * Agent 基类
 *
 * 提供默认的 Agent 行为。继承此类可创建具有
 * 自定义系统提示词、工具集或行为的 Agent。
 */
abstract class BaseAgent implements AgentInterface, ConfigurableAgentInterface
{
    use Conditionable;
    use Tappable;
    protected string $sessionId;
    protected string $agentType;
    protected string $providerName;
    protected ?AiClientInterface $client = null;
    protected ?ToolRegistry $toolRegistry = null;
    protected ?Conversation $conversation = null;
    protected array $toolNames = [];
    protected bool $memoryEnabled = true;
    protected array $options = [];

    public function __construct(?string $sessionId = null, array $options = [])
    {
        $this->sessionId    = $sessionId ?? $this->generateSessionId();
        $this->agentType    = $options['agent_type'] ?? $this->getType();
        $this->providerName = $options['provider'] ?? Config::get('wise-agent.default', 'openai');
        $this->memoryEnabled = $options['enable_memory'] ?? Config::get('wise-agent.agent.enable_memory', true);
        $this->options = array_merge([
            'max_steps'           => Config::get('wise-agent.agent.max_steps', 10),
            'max_tokens_per_step' => Config::get('wise-agent.agent.max_tokens_per_step', 4096),
            'temperature'         => Config::get('wise-agent.agent.temperature', 0.7),
        ], $options);
    }

    public function getSessionId(): string
    {
        return $this->sessionId;
    }

    public function getType(): string
    {
        return $this->agentType;
    }

    public function getProviderName(): string
    {
        return $this->providerName;
    }

    public function setProviderName(string $provider): self
    {
        $this->providerName = $provider;
        $this->client = null;
        return $this;
    }

    public function getSystemPrompt(): string
    {
        return Config::get('wise-agent.agent.system_prompt', 'You are the WiseAdmin AI assistant.');
    }

    public function setSystemPrompt(string $prompt): self
    {
        if ($this->conversation) {
            $this->conversation->setSystemPrompt($prompt);
        }
        return $this;
    }

    public function getTools(): ToolRegistry
    {
        if ($this->toolRegistry === null) {
            $this->toolRegistry = ToolRegistry::instance();
        }
        return $this->toolRegistry;
    }

    /**
     * 设置自定义工具注册表（用于 Skill 执行时的工具隔离）
     */
    public function setToolRegistry(ToolRegistry $toolRegistry): self
    {
        $this->toolRegistry = $toolRegistry;
        return $this;
    }

    public function addTool(callable|string|array $tool, string $name): self
    {
        if (is_string($tool)) {
            if (class_exists($tool)) {
                $instance = new $tool();
                if ($instance instanceof ToolInterface) {
                    $this->getTools()->register($instance, $name);
                    return $this;
                }
            }
            throw new \InvalidArgumentException(
                "Tool class '{$tool}' does not exist or does not implement ToolInterface"
            );
        }

        $this->getTools()->register($tool, $name);
        return $this;
    }

    public function withTools(array $toolNames): self
    {
        $this->toolNames = $toolNames;

        $configuredTools = Config::get('wise-agent.tools', []);
        foreach ($toolNames as $name) {
            if (isset($configuredTools[$name])) {
                $toolConfig = $configuredTools[$name];
                if (!empty($toolConfig['enabled']) || !isset($toolConfig['enabled'])) {
                    $class = $toolConfig['class'] ?? null;
                    if ($class && class_exists($class)) {
                        $this->addTool($class, $name);
                    }
                }
            }
        }

        return $this;
    }

    public function withMemory(bool $enabled): self
    {
        $this->memoryEnabled = $enabled;
        return $this;
    }

    /**
     * 获取 AI 客户端
     */
    public function getClient(): AiClientInterface
    {
        if ($this->client === null) {
            $this->client = AiClientFactory::create($this->providerName);
        }
        return $this->client;
    }

    /**
     * 设置自定义 AI 客户端
     */
    public function setClient(AiClientInterface $client): self
    {
        $this->client = $client;
        return $this;
    }

    /**
     * 尝试获取客户端，失败返回 null（B4-1）
     *
     * 摘要属于增强能力：provider 未配置 / API Key 缺失时，
     * 不应让「创建会话」这一步直接抛异常，否则聊天入口整体不可用。
     */
    protected function safeClient(): ?AiClientInterface
    {
        try {
            return $this->getClient();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * 获取或创建会话
     */
    public function getConversation(): Conversation
    {
        if ($this->conversation === null) {
            $this->conversation = new Conversation($this->sessionId, [
                'system_prompt'  => $this->getSystemPrompt(),
                'summary_client' => $this->safeClient(),
            ]);
        }
        return $this->conversation;
    }

    /**
     * 设置自定义会话
     */
    public function setConversation(Conversation $conversation): self
    {
        $this->conversation = $conversation;
        return $this;
    }

    /**
     * 运行 Agent（委托给 AgentManager）
     */
    public function run(string $task, array $context = []): string
    {
        $manager = app()->make(AgentManager::class);
        return $manager->run($this, $task, $context);
    }

    /**
     * 通过队列异步调度 Agent
     */
    public function dispatchAsync(string $task, array $options = []): string
    {
        $manager = app()->make(AgentManager::class);
        return $manager->dispatchAsync($this, $task, $options);
    }

    protected function generateSessionId(): string
    {
        return 'agent_' . bin2hex(random_bytes(16));
    }
}
