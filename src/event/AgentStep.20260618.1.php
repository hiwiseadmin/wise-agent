<?php
declare(strict_types=1);

namespace wise\agent\Event;

/**
 * AgentStep - fired after each step of the agent loop
 */
class AgentStep extends Event
{
    public string $sessionId;
    public int $step;
    public array $inputMessages;
    public ?array $response;
    public ?array $toolCalls;
    public ?array $toolResults;

    public function __construct(
        string $sessionId,
        int $step,
        array $inputMessages,
        ?array $response = null,
        ?array $toolCalls = null,
        ?array $toolResults = null
    ) {
        parent::__construct();
        $this->sessionId     = $sessionId;
        $this->step          = $step;
        $this->inputMessages = $inputMessages;
        $this->response      = $response;
        $this->toolCalls     = $toolCalls;
        $this->toolResults   = $toolResults;
    }

    public function getEventName(): string
    {
        return 'AgentStep';
    }
}
