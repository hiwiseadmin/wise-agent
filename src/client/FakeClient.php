<?php
declare(strict_types=1);

namespace wise\agent\client;

use wise\agent\client\Feature;
use wise\agent\contract\AiClientInterface;

/**
 * 本地测试用 Fake LLM 客户端
 *
 * 不发起任何真实网络请求，按调用次数脚本化返回：
 *   - 第 1 次 chat()：返回 1 个工具调用（tool_calls），模拟「模型决定调用工具」
 *   - 第 2 次及以后：返回纯文本，模拟「模型基于工具结果给出最终答案」
 *
 * 这样即可在本地（无 API key / 无联网 / 无 DB）跑通完整 agent→tool 循环。
 *
 * 用法：
 *   $agent->setClient(new FakeClient());
 *   或 AiClientFactory::register('fake', ['class' => FakeClient::class]);
 */
class FakeClient implements AiClientInterface
{
    private int $callCount = 0;

    /** 第 1 步要调用的工具名（默认 echo_tool） */
    public string $toolName = 'echo_tool';

    /** 第 1 步传给工具的参数 */
    public array $toolArgs = ['text' => 'Hello from FakeClient (no real LLM)'];

    public function chat(array $messages, array $tools = [], array $options = []): array
    {
        $this->callCount++;

        if ($this->callCount === 1) {
            return [
                'content'    => null,
                'tool_calls' => [
                    [
                        'id'       => 'call_fake_1',
                        'type'     => 'function',
                        'function' => [
                            'name'      => $this->toolName,
                            'arguments' => json_encode($this->toolArgs, JSON_UNESCAPED_UNICODE),
                        ],
                    ],
                ],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
            ];
        }

        return [
            'content'    => 'Done. The agent→tool loop completed after ' . $this->callCount . ' LLM calls.',
            'tool_calls' => [],
            'usage'      => ['prompt_tokens' => 12, 'completion_tokens' => 8, 'total_tokens' => 20],
        ];
    }

    public function chatStream(array $messages, array $tools = [], array $options = []): \Generator
    {
        $resp = $this->chat($messages, $tools, $options);
        if (!empty($resp['tool_calls'])) {
            yield ['type' => 'tool_call', 'calls' => $resp['tool_calls']];
        } else {
            yield ['type' => 'token', 'content' => $resp['content'] ?? ''];
        }
        yield ['type' => 'finish', 'reason' => 'stop', 'tool_calls' => $resp['tool_calls'] ?? [], 'usage' => $resp['usage'] ?? []];
    }

    public function getProviderName(): string
    {
        return 'fake';
    }

    public function getModelName(): string
    {
        return 'fake-1';
    }

    public function supports(string $feature): bool
    {
        return in_array($feature, [Feature::TOOLS, Feature::STREAMING, Feature::JSON_MODE, Feature::FUNCTION_CALLING], true);
    }

    public function listModels(): array
    {
        return ['fake-1'];
    }

    public function countTokens(string|array $input): int
    {
        return TokenEstimator::count($input);
    }

    public function estimateCost(int $promptTokens, int $completionTokens): float
    {
        return 0.0;
    }

    public function getCallCount(): int
    {
        return $this->callCount;
    }
}
