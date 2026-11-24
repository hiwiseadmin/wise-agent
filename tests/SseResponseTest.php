<?php
declare(strict_types=1);

namespace wise\agent\test;

use PHPUnit\Framework\TestCase;
use wise\agent\http\SseResponse;

/**
 * SSE 响应对象测试
 *
 * 关注三件事：
 *   1. 响应头固定为 text/event-stream 且关闭缓冲；
 *   2. 事件帧格式与前端解析约定一致（`event: xxx` + `data: {json}`）；
 *   3. 生成器惰性执行——构造时不得消费，否则流式会退化成一次性输出。
 */
class SseResponseTest extends TestCase
{
    public function testHeadersAreSseReady(): void
    {
        $response = new SseResponse([]);

        $this->assertSame('text/event-stream; charset=utf-8', $response->getHeader('Content-Type'));
        $this->assertSame('no-cache', $response->getHeader('Cache-Control'));
        $this->assertSame('keep-alive', $response->getHeader('Connection'));
        $this->assertSame('no', $response->getHeader('X-Accel-Buffering'));
        $this->assertSame(200, $response->getCode());
    }

    public function testExtraHeadersAreMerged(): void
    {
        $response = new SseResponse([], 200, ['Access-Control-Allow-Origin' => 'https://example.com']);

        $this->assertSame('https://example.com', $response->getHeader('Access-Control-Allow-Origin'));
        $this->assertSame('text/event-stream; charset=utf-8', $response->getHeader('Content-Type'));
    }

    public function testGetContentIsEmptyBecauseStreaming(): void
    {
        $response = new SseResponse([['type' => 'done']]);

        $this->assertSame('', $response->getContent());
    }

    public function testSendEmitsSseFrames(): void
    {
        $response = new SseResponse([
            ['type' => 'token', 'content' => '你'],
            ['type' => 'done', 'success' => true],
        ], 200, [], false);

        ob_start();
        $response->send();
        $output = (string) ob_get_clean();

        $this->assertStringContainsString("event: token\n", $output);
        $this->assertStringContainsString('"content":"你"', $output);
        $this->assertStringContainsString("event: done\n", $output);
        $this->assertStringContainsString('"success":true', $output);
        $this->assertSame(2, substr_count($output, "\n\n"));
    }

    public function testEventNameIsSanitized(): void
    {
        $response = new SseResponse([['type' => "bad\nname", 'content' => 'x']], 200, [], false);

        ob_start();
        $response->send();
        $output = (string) ob_get_clean();

        $this->assertStringContainsString("event: badname\n", $output);
        $this->assertStringNotContainsString("event: bad\nname", $output);
    }

    public function testEmptyEventNameFallsBackToMessage(): void
    {
        $response = new SseResponse([['type' => '', 'content' => 'x']], 200, [], false);

        ob_start();
        $response->send();
        $output = (string) ob_get_clean();

        $this->assertStringContainsString("event: message\n", $output);
    }

    public function testGeneratorIsNotConsumedOnConstruction(): void
    {
        $started = false;

        $events = (static function () use (&$started): \Generator {
            $started = true;
            yield ['type' => 'done'];
        })();

        new SseResponse($events);

        $this->assertFalse($started, '构造响应时不应消费事件生成器');
    }
}
