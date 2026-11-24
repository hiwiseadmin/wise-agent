<?php
declare(strict_types=1);

namespace wise\agent\event;

/**
 * AgentError - Agent 遇到错误时触发
 */
class AgentError extends Event
{
    public string $sessionId;
    public \Throwable $exception;
    public int $step;
    public array $context;

    public function __construct(string $sessionId, \Throwable $exception, int $step = 0, array $context = [])
    {
        parent::__construct();
        $this->sessionId = $sessionId;
        $this->exception = $exception;
        $this->step      = $step;
        $this->context   = $context;
    }

    public function getEventName(): string
    {
        return 'AgentError';
    }
}
