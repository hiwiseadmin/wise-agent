<?php
declare(strict_types=1);

namespace wise\agent\Event;

/**
 * ToolExecute - fired before and after tool execution
 *
 * Plugins can intercept tools for security checks, logging, or result modification.
 *
 * Phase constants:
 *   BEFORE = 'before' - modification allowed, skip supported
 *   AFTER  = 'after'  - result modification allowed
 */
class ToolExecute extends Event
{
    public const PHASE_BEFORE = 'before';
    public const PHASE_AFTER  = 'after';

    public string $phase;
    public string $toolName;
    public array $arguments;
    public mixed $result;
    public bool $skip = false;
    public mixed $mockResult = null;

    public function __construct(string $phase, string $toolName, array $arguments = [], mixed $result = null)
    {
        parent::__construct();
        $this->phase     = $phase;
        $this->toolName  = $toolName;
        $this->arguments = $arguments;
        $this->result    = $result;
    }

    public function getEventName(): string
    {
        return 'ToolExecute';
    }
}
