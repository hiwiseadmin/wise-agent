<?php
declare(strict_types=1);

namespace wise\agent\Event;

/**
 * Base event class
 *
 * All AI lifecycle events extend this class to provide
 * a common timestamp and identity contract.
 *
 * @property-read float $timestamp Event creation time in microtime
 */
abstract class Event
{
    /** @var float Microtime when the event was created */
    public float $timestamp;

    public function __construct()
    {
        $this->timestamp = microtime(true);
    }

    /**
     * Get the event name for listener routing
     */
    abstract public function getEventName(): string;
}
