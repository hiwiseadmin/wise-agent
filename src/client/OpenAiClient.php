<?php
declare(strict_types=1);

namespace wise\agent\client;

use wise\agent\contract\AiClientInterface;
use wise\agent\exception\ProviderException;

/**
 * OpenAI API 客户端
 *
 * 支持 OpenAI 和 OpenAI 兼容的 API（如 Azure、本地 LLM 代理）。
 * 实现标准 chat() 和流式 chatStream() 两种模式。
 */
class OpenAiClient implements AiClientInterface
{
    protected HttpClient $http;
    protected string $apiKey;
    protected string $baseUrl;
    protected string $model;
    protected array $options;

    public function __construct(array $config = [])
    {
        $this->apiKey  = $config['api_key'] ?? '';
        $this->baseUrl = rtrim($config['base_url'] ?? 'https://api.openai.com/v1', '/');
        $this->model   = $config['model'] ?? 'gpt-4o';
        $this->options = $config;

        if (empty($this->apiKey)) {
            throw new ProviderException('OpenAI API key is required');
        }

        $this->http = new HttpClient([
            'timeout'   => $config['timeout'] ?? 60,
            'max_retry' => $config['max_retry'] ?? 2,
            'headers'   => [
                'Authorization' => "Bearer {$this->apiKey}",
            ],
        ]);
    }

    public function getProviderName(): string
    {
        return 'openai';
    }

    public function getModelName(): string
    {
        return $this->model;
    }

    public function supports(string $feature): bool
    {
        return in_array($feature, [
            Feature::TOOLS,
            Feature::JSON_MODE,
            Feature::STREAMING,
            Feature::FUNCTION_CALLING,
        ], true);
    }

    public function chat(array $messages, array $tools = [], array $options = []): array
    {
        $body = [
            'model'       => $options['model'] ?? $this->model,
            'messages'    => $messages,
            'temperature' => $options['temperature'] ?? 0.7,
            'max_tokens'  => $options['max_tokens'] ?? 4096,
        ];

        // 如果提供了工具，则添加
        if (!empty($tools)) {
            $body['tools'] = $this->formatTools($tools);
            $body['tool_choice'] = $options['tool_choice'] ?? 'auto';
        }

        $response = $this->http->post("{$this->baseUrl}/chat/completions", $body);

        return $this->parseResponse($response);
    }

    /**
     * 发送流式聊天补全请求
     *
     * 调用 OpenAI chat/completions 端点并设置 stream: true，
     * 逐 token 解析并 yield SSE 事件。
     *
     * @param array $messages 消息列表
     * @param array $tools    可用的工具定义
     * @param array $options  额外参数（temperature、max_tokens 等）
     * @return \Generator  生成事件数组
     */
    public function chatStream(array $messages, array $tools = [], array $options = []): \Generator
    {
        $body = [
            'model'       => $options['model'] ?? $this->model,
            'messages'    => $messages,
            'temperature' => $options['temperature'] ?? 0.7,
            'max_tokens'  => $options['max_tokens'] ?? 4096,
            'stream'      => true,
            'stream_options' => ['include_usage' => true],
        ];

        // 如果提供了工具，则添加
        if (!empty($tools)) {
            $body['tools'] = $this->formatTools($tools);
            $body['tool_choice'] = $options['tool_choice'] ?? 'auto';
        }

        $accumulatedContent = '';
        $accumulatedToolCalls = [];
        $finishReason = null;
        $usage = null;

        $generator = $this->http->streamPost("{$this->baseUrl}/chat/completions", $body);

        foreach ($generator as $event) {
            if ($event['type'] === 'error') {
                yield $event;
                return;
            }

            if ($event['type'] === 'done') {
                // 发出 finish 事件
                yield [
                    'type'    => 'finish',
                    'reason'  => $finishReason ?? 'stop',
                    'content' => $accumulatedContent,
                    'tool_calls' => $accumulatedToolCalls,
                    'usage'   => $usage,
                ];
                return;
            }

            if ($event['type'] === 'data') {
                $data = json_decode($event['content'], true);
                if (!is_array($data)) {
                    continue;
                }

                $choices = $data['choices'] ?? [];

                // 提取 usage（来自 stream_options）
                if (isset($data['usage'])) {
                    $usage = $data['usage'];
                }

                foreach ($choices as $choice) {
                    $delta = $choice['delta'] ?? [];
                    $finishReason = $choice['finish_reason'] ?? $finishReason;

                    // 内容 token
                    if (isset($delta['content']) && $delta['content'] !== '') {
                        $tokenContent = $delta['content'];
                        $accumulatedContent .= $tokenContent;
                        yield [
                            'type'    => 'token',
                            'content' => $tokenContent,
                        ];
                    }

                    // delta 中的工具调用
                    if (isset($delta['tool_calls'])) {
                        foreach ($delta['tool_calls'] as $tc) {
                            $index = $tc['index'] ?? 0;

                            if (!isset($accumulatedToolCalls[$index])) {
                                $accumulatedToolCalls[$index] = [
                                    'id'   => $tc['id'] ?? '',
                                    'type' => 'function',
                                    'function' => [
                                        'name'      => '',
                                        'arguments' => '',
                                    ],
                                ];
                            }

                            if (isset($tc['id'])) {
                                $accumulatedToolCalls[$index]['id'] = $tc['id'];
                            }
                            if (isset($tc['function']['name'])) {
                                $accumulatedToolCalls[$index]['function']['name'] .= $tc['function']['name'];
                            }
                            if (isset($tc['function']['arguments'])) {
                                $accumulatedToolCalls[$index]['function']['arguments'] .= $tc['function']['arguments'];
                            }
                        }
                    }
                }
            }
        }

        // 如果到达此处而没有显式的 done/error，则发出 finish
        yield [
            'type'    => 'finish',
            'reason'  => $finishReason ?? 'stop',
            'content' => $accumulatedContent,
            'tool_calls' => array_values($accumulatedToolCalls),
            'usage'   => $usage,
        ];
    }

    /**
     * 将工具格式化为 OpenAI Function Calling schema
     *
     * 标准格式使用 ['function']['name'] 模式。
     * ['type' => 'function', 'function' => [...] ] 包装
     * 来自 ToolRegistry::getToolSchemas()。
     */
    protected function formatTools(array $tools): array
    {
        $formatted = [];
        foreach ($tools as $tool) {
            $formatted[] = [
                'type'     => 'function',
                'function' => [
                    'name'        => $tool['function']['name'] ?? $tool['name'] ?? 'unknown',
                    'description' => $tool['function']['description'] ?? $tool['description'] ?? '',
                    'parameters'  => $tool['function']['parameters'] ?? $tool['parameters'] ?? ['type' => 'object', 'properties' => []],
                ],
            ];
        }
        return $formatted;
    }

    /**
     * 解析 OpenAI API 响应
     */
    protected function parseResponse(array $response): array
    {
        $choice = $response['choices'][0] ?? [];
        $message = $choice['message'] ?? [];

        $result = [
            'content'    => $message['content'] ?? '',
            'tool_calls' => [],
            'usage'      => $response['usage'] ?? [],
            'finish_reason' => $choice['finish_reason'] ?? 'stop',
        ];

        // 如果存在工具调用，则解析
        if (!empty($message['tool_calls'])) {
            foreach ($message['tool_calls'] as $call) {
                $result['tool_calls'][] = [
                    'id'   => $call['id'] ?? '',
                    'type' => 'function',
                    'function' => [
                        'name'      => $call['function']['name'] ?? '',
                        'arguments' => $call['function']['arguments'] ?? '{}',
                    ],
                ];
            }
        }

        return $result;
    }
}
