<?php
declare(strict_types=1);

namespace wise\agent\client;

use wise\agent\contract\AiClientInterface;
use wise\agent\exception\ProviderException;
use wise\agent\support\ToolSchemaNormalizer;

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
            'timeout'         => $config['timeout'] ?? 60,
            'max_retry'       => $config['max_retry'] ?? 2,
            'ssl_verify'      => $config['ssl_verify'] ?? true,
            'ca_bundle'       => $config['ca_bundle'] ?? '',
            'breaker_service' => $this->getProviderName(),
            'breaker_config'  => $this->circuitConfig(),
            'headers'         => [
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

    /**
     * 内置模型清单（可用 config 的 `models` 覆盖）
     *
     * @var string[]
     */
    protected const DEFAULT_MODELS = [
        'gpt-4o',
        'gpt-4o-mini',
        'gpt-4-turbo',
        'gpt-3.5-turbo',
    ];

    /**
     * 内置价目表（美元 / 每 1M token）
     *
     * 数值随厂商调价会过期，因此仅作**默认兜底**；
     * 生产环境请在 `wise-agent.providers.{name}.pricing` 显式配置。
     *
     * @var array<string, array{input:float, output:float}>
     */
    protected const DEFAULT_PRICING = [
        'gpt-4o'        => ['input' => 2.50, 'output' => 10.00],
        'gpt-4o-mini'   => ['input' => 0.15, 'output' => 0.60],
        'gpt-4-turbo'   => ['input' => 10.00, 'output' => 30.00],
        'gpt-3.5-turbo' => ['input' => 0.50, 'output' => 1.50],
    ];

    public function listModels(): array
    {
        $models = $this->options['models'] ?? null;

        if (is_array($models) && $models !== []) {
            return array_values(array_map('strval', $models));
        }

        return static::DEFAULT_MODELS;
    }

    public function countTokens(string|array $input): int
    {
        return TokenEstimator::count($input);
    }

    public function estimateCost(int $promptTokens, int $completionTokens): float
    {
        return TokenEstimator::cost($this->pricing(), $this->model, $promptTokens, $completionTokens);
    }

    /**
     * 熔断配置（容器不可用时降级为空配置）
     *
     * 构造期直接调 `Config` 门面会让本类无法在 CLI / 单测等无容器环境下实例化
     * （ClassNotFoundException: config）。熔断只是增强项，缺失时应降级而非阻断。
     *
     * @return array<string, mixed>
     */
    protected function circuitConfig(): array
    {
        try {
            return (array) \think\facade\Config::get('wise-agent.circuit', []);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * 当前生效的价目表（配置优先，内置兜底）
     *
     * @return array<string, array{input:float, output:float}>
     */
    protected function pricing(): array
    {
        $configured = $this->options['pricing'] ?? null;

        if (is_array($configured) && $configured !== []) {
            return $configured;
        }

        return static::DEFAULT_PRICING;
    }

    public function chat(array $messages, array $tools = [], array $options = []): array
    {
        $body = [
            'model'       => $options['model'] ?? $this->model,
            'messages'    => $messages,
            'temperature' => $options['temperature'] ?? 0.7,
            'max_tokens'  => $options['max_tokens'] ?? 4096,
        ];

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
                yield [
                    'type'    => 'finish',
                    'reason'  => $finishReason ?? 'stop',
                    'content' => $accumulatedContent,
                    'tool_calls' => array_values($accumulatedToolCalls),
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

                if (isset($data['usage'])) {
                    $usage = $data['usage'];
                }

                foreach ($choices as $choice) {
                    $delta = $choice['delta'] ?? [];
                    $finishReason = $choice['finish_reason'] ?? $finishReason;

                    if (isset($delta['content']) && $delta['content'] !== '') {
                        $tokenContent = $delta['content'];
                        $accumulatedContent .= $tokenContent;
                        yield [
                            'type'    => 'token',
                            'content' => $tokenContent,
                        ];
                    }

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
                    'parameters'  => ToolSchemaNormalizer::parameters(
                        $tool['function']['parameters'] ?? $tool['parameters'] ?? []
                    ),
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
