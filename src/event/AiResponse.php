<?php
declare(strict_types=1);

namespace wise\agent\event;

/**
 * AiResponse - 收到 LLM 响应后触发
 *
 * 插件可以在 Agent 处理响应之前修改它。
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
