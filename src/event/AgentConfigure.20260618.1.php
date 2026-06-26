<?php
declare(strict_types=1);

namespace wise\agent\Event;

use wise\agent\Agent\AgentContext;

/**
 * AgentConfigure - fired after AgentContext creation but before the Run Loop starts
 *
 * Plugins can use this hook to inspect/modify configuration,
 * register extra tools, inject context, or adjust options
 * before the agent begins execution.
 */
class AgentConfigure extends Event
{
    public string $sessionId;
    public string $agentType;
    public string $task;
    public AgentContext $agentContext;
    public array $context;

    public function __construct(
        string $sessionId,
        string $agentType,
        string $task,
        AgentContext $agentContext,
        array $context = []
    ) {
        parent::__construct();
        $this->sessionId    = $sessionId;
        $this->agentType    = $agentType;
        $this->task         = $task;
        $this->agentContext = $agentContext;
        $this->context      = $context;
    }

    public function getEventName(): string
    {
        return 'AgentConfigure';
    }
}
