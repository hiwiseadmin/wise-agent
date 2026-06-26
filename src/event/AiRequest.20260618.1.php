<?php
declare(strict_types=1);

namespace wise\agent\Event;

/**
 * AiRequest - fired before sending a request to the LLM
 *
 * Plugins can modify messages, tools, options, or skip the request entirely.
 */
class AiRequest extends Event
{
    public string $provider;
    public string $model;
    public array $messages;
    public array $tools;
    public array $options;
    public bool $skip = false;
    public mixed $mockResponse = null;

    public function __construct(string $provider, string $model, array $messages, array $tools = [], array $options = [])
    {
        parent::__construct();
        $this->provider = $provider;
        $this->model    = $model;
        $this->messages = $messages;
        $this->tools    = $tools;
        $this->options  = $options;
    }

    public function getEventName(): string
    {
        return 'AiRequest';
    }
}
