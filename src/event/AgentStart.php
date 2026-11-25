<?php
declare(strict_types=1);

namespace wise\agent\event;

/**
 * AgentStart - Agent 开始执行时触发
 */
class AgentStart extends Event
{
    public string $sessionId;
    public string $agentType;
    public string $task;
    public array $context;

    public function __construct(string $sessionId, string $agentType, string $task, array $context = [])
    {
        parent::__construct();
        $this->sessionId = $sessionId;
        $this->agentType = $agentType;
        $this->task      = $task;
        $this->context   = $context;
    }

    public function getEventName(): string
    {
        return 'AgentStart';
    }
}
