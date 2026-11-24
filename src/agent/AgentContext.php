<?php
declare(strict_types=1);

namespace wise\agent\agent;

use wise\agent\contract\AiClientInterface;
use wise\agent\contract\ConversationInterface;
use wise\agent\memory\session\Conversation;
use wise\agent\tool\ToolRegistry;

/**
 * Agent 执行上下文
 *
 * 保存单次 Agent 运行的全部状态。
 * 在 Agent 生命周期和事件链中传递。
 */
class AgentContext
{
    protected string $sessionId;
    protected string $task;
    protected string $agentType;
    protected Conversation $conversation;
    protected AiClientInterface $client;
    protected ToolRegistry $toolRegistry;
    protected array $options;
    protected array $usage = ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0];
    protected int $stepCount = 0;
    protected float $startTime;
    protected array $metadata = [];

    public function __construct(
        string $sessionId,
        string $task,
        string $agentType,
        Conversation $conversation,
        AiClientInterface $client,
        ToolRegistry $toolRegistry,
        array $options = []
    ) {
        $this->sessionId    = $sessionId;
        $this->task         = $task;
        $this->agentType    = $agentType;
        $this->conversation = $conversation;
        $this->client       = $client;
        $this->toolRegistry = $toolRegistry;
        $this->options      = $options;
        $this->startTime    = microtime(true);
    }

    public function getSessionId(): string
    {
        return $this->sessionId;
    }

    public function getTask(): string
    {
        return $this->task;
    }

    public function getAgentType(): string
    {
        return $this->agentType;
    }

    public function getConversation(): Conversation
    {
        return $this->conversation;
    }

    public function getClient(): AiClientInterface
    {
        return $this->client;
    }

    public function getToolRegistry(): ToolRegistry
    {
        return $this->toolRegistry;
    }

    public function getOptions(): array
    {
        return $this->options;
    }

    public function getOption(string $key, mixed $default = null): mixed
    {
        return $this->options[$key] ?? $default;
    }

    public function addUsage(array $usage): void
    {
        foreach (['prompt_tokens', 'completion_tokens', 'total_tokens'] as $key) {
            $this->usage[$key] += $usage[$key] ?? 0;
        }
    }

    public function getUsage(): array
    {
        return $this->usage;
    }

    public function getTotalTokens(): int
    {
        return $this->usage['total_tokens'];
    }

    public function incrementStep(): void
    {
        $this->stepCount++;
    }

    public function getStepCount(): int
    {
        return $this->stepCount;
    }

    public function getDuration(): float
    {
        return microtime(true) - $this->startTime;
    }

    /**
     * 获取最大步数 — 使用 AgentManager 通过 options 传入的值。
     * 仅当 options 中未提供时才回退到配置。
     */
    public function getMaxSteps(): int
    {
        return (int) ($this->options['max_steps'] ?? config('wise-agent.agent.max_steps', 10));
    }

    public function isMaxStepsReached(): bool
    {
        return $this->stepCount >= $this->getMaxSteps();
    }

    public function setMetadata(string $key, mixed $value): void
    {
        $this->metadata[$key] = $value;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }
}
