<?php
declare(strict_types=1);

namespace wise\agent\Event;

/**
 * AiResponse - fired after receiving a response from the LLM
 *
 * Plugins can modify the response before it's processed by the agent.
 */
class AiResponse extends Event
{
    public string $provider;
    public string $model;
    public array $response;

    public function __construct(string $provider, string $model, array $response)
    {
        parent::__construct();
        $this->provider = $provider;
        $this->model    = $model;
        $this->response = $response;
    }

    public function getEventName(): string
    {
        return 'AiResponse';
    }
}
