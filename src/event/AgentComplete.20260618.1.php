<?php
declare(strict_types=1);

namespace wise\agent\Event;

/**
 * AgentComplete - fired when an agent finishes successfully
 */
class AgentComplete extends Event
{
    public string $sessionId;
    public string $result;
    public int $totalSteps;
    public array $usage;
    public float $duration;

    public function __construct(string $sessionId, string $result, int $totalSteps, array $usage = [], float $duration = 0)
    {
        parent::__construct();
        $this->sessionId  = $sessionId;
        $this->result     = $result;
        $this->totalSteps = $totalSteps;
        $this->usage      = $usage;
        $this->duration   = $duration;
    }

    public function getEventName(): string
    {
        return 'AgentComplete';
    }
}
