<?php
declare(strict_types=1);

namespace wise\agent\Agent;

use think\facade\Config;
use wise\agent\Client\AiClientFactory;
use wise\agent\Contract\AgentInterface;
use wise\agent\Contract\AiClientInterface;
use wise\agent\Contract\ConfigurableAgentInterface;
use wise\agent\Contract\ToolInterface;
use wise\agent\Memory\Session\Conversation;
use wise\agent\Memory\Session\SessionManager;
use wise\agent\Tool\ToolRegistry;

/**
 * Base agent class
 *
 * Provides default agent behavior. Extend this class to create custom agents
 * with specialized system prompts, tool sets, or behavior.
 */
abstract class BaseAgent implements AgentInterface, ConfigurableAgentInterface
{
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
        $this->client = null; // Reset client to force recreation
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
            // Invalid string tool: class doesn't exist or doesn't implement ToolInterface
            throw new \InvalidArgumentException(
                "Tool class '{$tool}' does not exist or does not implement ToolInterface"
            );
        }

        // Valid callable or array
        $this->getTools()->register($tool, $name);
        return $this;
    }

    public function withTools(array $toolNames): self
    {
        $this->toolNames = $toolNames;

        // Register builtin tools from config
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
     * Get the AI client
     */
    public function getClient(): AiClientInterface
    {
        if ($this->client === null) {
            $this->client = AiClientFactory::create($this->providerName);
        }
        return $this->client;
    }

    /**
     * Set a custom AI client
     */
    public function setClient(AiClientInterface $client): self
    {
        $this->client = $client;
        return $this;
    }

    /**
     * Get or create the conversation
     */
    public function getConversation(): Conversation
    {
        if ($this->conversation === null) {
            $this->conversation = new Conversation($this->sessionId, [
                'system_prompt' => $this->getSystemPrompt(),
            ]);
        }
        return $this->conversation;
    }

    /**
     * Set a custom conversation
     */
    public function setConversation(Conversation $conversation): self
    {
        $this->conversation = $conversation;
        return $this;
    }

    /**
     * Run the agent (delegated to AgentManager)
     */
    public function run(string $task, array $context = []): string
    {
        $manager = app()->make(AgentManager::class);
        return $manager->run($this, $task, $context);
    }

    /**
     * Dispatch agent asynchronously via queue
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
