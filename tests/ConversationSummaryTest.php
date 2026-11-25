<?php
declare(strict_types=1);

namespace wise\agent\test;

use PHPUnit\Framework\TestCase;
use wise\agent\client\TokenEstimator;
use wise\agent\contract\AiClientInterface;
use wise\agent\memory\session\Conversation;

/**
 * B4-1：会话摘要压缩接线
 *
 * 改造前 `summarizeIfNeeded()` 无任何调用方，`addMessage()` 直接走
 * `trimIfNeeded()` 的 array_slice 硬截断 —— 长对话前半段被砍掉，模型"失忆"。
 * 这里断言：达到阈值时优先摘要，摘要不可用时才退化截断。
 */
class ConversationSummaryTest extends TestCase
{
    private function conversation(array $seed, ?AiClientInterface $client): Conversation
    {
        $conversation = new class extends Conversation {
            public function __construct()
            {
                $this->sessionId      = 'test-session';
                $this->maxMessages    = 50;
                $this->summaryEnabled = true;
                $this->table          = 'ai_messages';
            }

            public function seed(array $messages): void
            {
                $this->messages = $messages;
            }

            public function raw(): array
            {
                return $this->messages;
            }
        };

        $conversation->seed($seed);
        $conversation->setSummaryClient($client);

        return $conversation;
    }

    private function messages(int $count): array
    {
        $messages = [];

        for ($i = 0; $i < $count; $i++) {
            $messages[] = ['role' => $i % 2 === 0 ? 'user' : 'assistant', 'content' => 'm' . $i];
        }

        return $messages;
    }

    private function summaryClient(string $summary = '这是一段摘要'): AiClientInterface
    {
        return new class($summary) implements AiClientInterface {
            public function __construct(private string $summary)
            {
            }

            public function chat(array $messages, array $tools = [], array $options = []): array
            {
                return ['content' => $this->summary, 'tool_calls' => [], 'usage' => []];
            }

            public function chatStream(array $messages, array $tools = [], array $options = []): \Generator
            {
                yield ['type' => 'finish'];
            }

            public function getProviderName(): string
            {
                return 'test';
            }

            public function getModelName(): string
            {
                return 'test-1';
            }

            public function supports(string $feature): bool
            {
                return false;
            }

            public function listModels(): array
            {
                return ['test-1'];
            }

            public function countTokens(string|array $input): int
            {
                return TokenEstimator::count($input);
            }

            public function estimateCost(int $promptTokens, int $completionTokens): float
            {
                return 0.0;
            }
        };
    }

    public function testSummarizesWhenOverThreshold(): void
    {
        $client       = $this->summaryClient();
        $conversation = $this->conversation($this->messages(51), $client);

        $this->assertTrue($conversation->summarizeIfNeeded($client));

        $raw = $conversation->raw();

        $this->assertCount(22, $raw);
        $this->assertSame('system', $raw[0]['role']);
        $this->assertStringContainsString('[Conversation Summary]', $raw[0]['content']);
    }

    public function testDoesNotSummarizeUnderThreshold(): void
    {
        $client       = $this->summaryClient();
        $conversation = $this->conversation($this->messages(30), $client);

        $this->assertFalse($conversation->summarizeIfNeeded($client));
        $this->assertCount(30, $conversation->raw());
    }

    public function testFallsBackWhenNoClient(): void
    {
        $conversation = $this->conversation($this->messages(51), null);

        $this->assertFalse(
            $conversation->summarizeIfNeeded(),
            '未注入客户端时必须返回 false，由调用方退化为硬截断'
        );
        $this->assertCount(51, $conversation->raw(), '未摘要时消息不应被改动');
    }

    public function testSettingClientIsFluent(): void
    {
        $conversation = $this->conversation([], null);

        $this->assertSame($conversation, $conversation->setSummaryClient($this->summaryClient()));
    }
}
