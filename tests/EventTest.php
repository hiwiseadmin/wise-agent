<?php
declare(strict_types=1);

namespace wise\agent\test;

use PHPUnit\Framework\TestCase;
use wise\agent\event\AgentStart;
use wise\agent\event\AgentStep;
use wise\agent\event\AgentComplete;
use wise\agent\event\AgentError;
use wise\agent\event\AiRequest;
use wise\agent\event\AiResponse;
use wise\agent\event\ToolExecute;

/**
 * Event system tests
 */
class EventTest extends TestCase
{
    public function testAgentStartEvent(): void
    {
        $event = new AgentStart('session_123', 'simple', 'Test task', ['key' => 'value']);
        $this->assertEquals('session_123', $event->sessionId);
        $this->assertEquals('simple', $event->agentType);
        $this->assertEquals('Test task', $event->task);
        $this->assertEquals(['key' => 'value'], $event->context);
    }

    public function testAgentStepEvent(): void
    {
        $event = new AgentStep('session_123', 3, [], ['content' => 'hello'], [], []);
        $this->assertEquals(3, $event->step);
        $this->assertEquals(['content' => 'hello'], $event->response);
    }

    public function testAgentCompleteEvent(): void
    {
        $event = new AgentComplete('session_123', 'Done', 5, ['total_tokens' => 100], 2.5);
        $this->assertEquals('Done', $event->result);
        $this->assertEquals(5, $event->totalSteps);
        $this->assertEquals(100, $event->usage['total_tokens']);
        $this->assertEquals(2.5, $event->duration);
    }

    public function testAgentErrorEvent(): void
    {
        $exception = new \RuntimeException('Test error');
        $event = new AgentError('session_123', $exception, 3, ['task' => 'test']);
        $this->assertSame($exception, $event->exception);
        $this->assertEquals(3, $event->step);
    }

    public function testAiRequestEventSkip(): void
    {
        $event = new AiRequest('openai', 'gpt-4', [], [], []);
        $this->assertFalse($event->skip);

        $event->skip = true;
        $event->mockResponse = 'mocked';
        $this->assertTrue($event->skip);
        $this->assertEquals('mocked', $event->mockResponse);
    }

    public function testAiResponseEvent(): void
    {
        $response = ['content' => 'Hello', 'usage' => ['total_tokens' => 10]];
        $event = new AiResponse('openai', 'gpt-4', $response);
        $this->assertEquals('Hello', $event->response['content']);
    }

    public function testToolExecuteEvent(): void
    {
        $event = new ToolExecute(ToolExecute::PHASE_BEFORE, 'file_read', ['path' => 'test.txt']);
        $this->assertEquals(ToolExecute::PHASE_BEFORE, $event->phase);
        $this->assertEquals('test.txt', $event->arguments['path']);
    }

    public function testToolExecuteSkip(): void
    {
        $event = new ToolExecute(ToolExecute::PHASE_BEFORE, 'dangerous_tool', []);
        $event->skip = true;
        $event->mockResult = 'blocked';
        $this->assertTrue($event->skip);
    }

    public function testToolExecuteAfter(): void
    {
        $event = new ToolExecute(ToolExecute::PHASE_AFTER, 'file_read', ['path' => 'test.txt'], 'file content');
        $this->assertEquals(ToolExecute::PHASE_AFTER, $event->phase);
        $this->assertEquals('file content', $event->result);
    }
}
