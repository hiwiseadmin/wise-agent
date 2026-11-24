<?php
declare(strict_types=1);

namespace wise\agent\test;

use PHPUnit\Framework\TestCase;
use wise\agent\agent\AgentContext;
use wise\agent\context\KnowledgeContextProvider;
use wise\agent\contract\AiClientInterface;
use wise\agent\memory\session\Conversation;
use wise\agent\tool\ToolRegistry;

class KnowledgeContextProviderTest extends TestCase
{
    private function agentContext(string $task): AgentContext
    {
        $client = new class implements AiClientInterface {
            public function chat(array $messages, array $tools = [], array $options = []): array { return []; }
            public function chatStream(array $messages, array $tools = [], array $options = []): \Generator { yield []; }
            public function getProviderName(): string { return 'fake'; }
            public function getModelName(): string { return 'fake'; }
            public function supports(string $feature): bool { return false; }
            public function listModels(): array { return ['fake']; }
            public function countTokens(string|array $input): int { return 0; }
            public function estimateCost(int $promptTokens, int $completionTokens): float { return 0.0; }
        };

        $conversation = new class extends Conversation {
            public function __construct() {
                $this->sessionId = 's1';
            }
        };

        return new AgentContext('s1', $task, 'agent', $conversation, $client, ToolRegistry::instance());
    }

    public function testKnowledgeRetrievalInjection(): void
    {
        $provider = new KnowledgeContextProvider(
            ['enabled' => true],
            fn (string $query) => [
                ['content' => '库存表 stock 结构说明', 'score' => 0.9],
                ['content' => '退货流程文档', 'score' => 0.7],
            ],
            fn (string $query) => []
        );

        $messages = $provider->getContext($this->agentContext('查询库存表结构'));

        $this->assertCount(1, $messages, '只有知识库来源时注入一条');
        $this->assertSame('system', $messages[0]['role']);
        $this->assertStringStartsWith(KnowledgeContextProvider::PREFIX_KNOWLEDGE, $messages[0]['content']);
        $this->assertStringContainsString('库存表 stock 结构说明', $messages[0]['content']);
        $this->assertSame('knowledge', $provider->getSource());
    }

    public function testHistorySourceSeparated(): void
    {
        $provider = new KnowledgeContextProvider(
            ['enabled' => true],
            fn (string $query) => [],
            fn (string $query) => [
                ['task' => '上月华东区退货单查询', 'create_time' => '2026-10-16 10:00:00'],
            ]
        );

        $messages = $provider->getContext($this->agentContext('查退货单'));

        $this->assertCount(1, $messages);
        $this->assertStringStartsWith(KnowledgeContextProvider::PREFIX_HISTORY, $messages[0]['content'], '历史轨迹用独立前缀，与知识库区分');
    }

    public function testBothSourcesSeparated(): void
    {
        $provider = new KnowledgeContextProvider(
            ['enabled' => true],
            fn (string $query) => [['content' => '知识文档', 'score' => 0.8]],
            fn (string $query) => [['task' => '历史成功用例', 'create_time' => '2026-10-16']]
        );

        $messages = $provider->getContext($this->agentContext('查询'));

        $this->assertCount(2, $messages);
        $this->assertStringStartsWith(KnowledgeContextProvider::PREFIX_KNOWLEDGE, $messages[0]['content']);
        $this->assertStringStartsWith(KnowledgeContextProvider::PREFIX_HISTORY, $messages[1]['content']);
    }

    public function testMaxCharsTruncation(): void
    {
        $provider = new KnowledgeContextProvider(
            ['enabled' => true, 'max_chars' => 20],
            fn (string $query) => [['content' => '这是一段非常非常长的知识内容需要被截断到二十个字符以内', 'score' => 0.9]],
            fn (string $query) => []
        );

        $messages = $provider->getContext($this->agentContext('查询'));

        $this->assertLessThanOrEqual(
            mb_strlen(KnowledgeContextProvider::PREFIX_KNOWLEDGE) + 20 + 1,
            mb_strlen($messages[0]['content']),
            '总字符数 ≤ 前缀 + max_chars'
        );
    }

    public function testRetrievalFailureDegradesToEmpty(): void
    {
        $provider = new KnowledgeContextProvider(
            ['enabled' => true],
            function (string $query): array {
                throw new \RuntimeException('vector store down');
            },
            fn (string $query) => []
        );

        $messages = $provider->getContext($this->agentContext('查询'));

        $this->assertSame([], $messages, '检索异常 ⇒ 降级为空，不阻断上下文构建');
    }

    public function testDisabledReturnsEmpty(): void
    {
        $provider = new KnowledgeContextProvider(
            ['enabled' => false],
            fn (string $query) => [['content' => 'x', 'score' => 0.9]],
            fn (string $query) => []
        );

        $this->assertSame([], $provider->getContext($this->agentContext('查询')));
    }

    public function testEmptyQueryReturnsEmpty(): void
    {
        $provider = new KnowledgeContextProvider(
            ['enabled' => true],
            fn (string $query) => [['content' => 'x', 'score' => 0.9]],
            fn (string $query) => []
        );

        $this->assertSame([], $provider->getContext($this->agentContext('   ')));
    }

    public function testHistoryDisabledOnlyKnowledge(): void
    {
        $provider = new KnowledgeContextProvider(
            ['enabled' => true, 'history' => ['enabled' => false]],
            fn (string $query) => [['content' => '知识', 'score' => 0.9]],
            fn (string $query) => [['task' => '历史']]
        );

        $messages = $provider->getContext($this->agentContext('查询'));

        $this->assertCount(1, $messages, 'history.enabled=false 时不注入历史轨迹');
        $this->assertStringStartsWith(KnowledgeContextProvider::PREFIX_KNOWLEDGE, $messages[0]['content']);
    }
}
