<?php
declare(strict_types=1);

namespace wise\agent\event;

/**
 * AiRequest - 向 LLM 发送请求前触发
 *
 * 插件可以修改消息、工具、选项，或完全跳过请求。
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
