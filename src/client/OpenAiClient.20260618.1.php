<?php
declare(strict_types=1);

namespace wise\agent\Client;

use wise\agent\Contract\AiClientInterface;
use wise\agent\Exception\ProviderException;

/**
 * OpenAI API client
 *
 * Supports OpenAI and OpenAI-compatible APIs (e.g., Azure, local LLM proxies).
 * Implements both standard chat() and streaming chatStream().
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

        // Add tools if provided
        if (!empty($tools)) {
            $body['tools'] = $this->formatTools($tools);
            $body['tool_choice'] = $options['tool_choice'] ?? 'auto';
        }

        $response = $this->http->post("{$this->baseUrl}/chat/completions", $body);

        return $this->parseResponse($response);
    }

    /**
     * Send a streaming chat completion request
     *
     * Calls the OpenAI chat/completions endpoint with stream: true
     * and yields parsed SSE events token by token.
     *
     * @param array $messages Message list
     * @param array $tools    Available tool definitions
     * @param array $options  Extra parameters (temperature, max_tokens, etc.)
     * @return \Generator  Yields event arrays
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

        // Add tools if provided
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
                // Emit finish event
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

                // Extract usage if present (from stream_options)
                if (isset($data['usage'])) {
                    $usage = $data['usage'];
                }

                foreach ($choices as $choice) {
                    $delta = $choice['delta'] ?? [];
                    $finishReason = $choice['finish_reason'] ?? $finishReason;

                    // Content token
                    if (isset($delta['content']) && $delta['content'] !== '') {
                        $tokenContent = $delta['content'];
                        $accumulatedContent .= $tokenContent;
                        yield [
                            'type'    => 'token',
                            'content' => $tokenContent,
                        ];
                    }

                    // Tool calls in delta
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

        // If we get here without explicit done/error, yield finish
        yield [
            'type'    => 'finish',
            'reason'  => $finishReason ?? 'stop',
            'content' => $accumulatedContent,
            'tool_calls' => array_values($accumulatedToolCalls),
            'usage'   => $usage,
        ];
    }

    /**
     * Format tools into OpenAI Function Calling schema
     *
     * Standard format uses ['function']['name'] pattern.
     * The ['type' => 'function', 'function' => [...] ] wrapper
     * comes from ToolRegistry::getToolSchemas().
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
     * Parse OpenAI API response
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

        // Parse tool calls if present
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
