<?php
declare(strict_types=1);

namespace wise\agent\test;

use PHPUnit\Framework\TestCase;
use wise\agent\exception\AiException;
use wise\agent\exception\ProviderException;
use wise\agent\exception\ToolException;

/**
 * Exception tests
 */
class ExceptionTest extends TestCase
{
    public function testAiException(): void
    {
        $e = new AiException('AI error', 500, null, ['key' => 'value']);
        $this->assertEquals('AI error', $e->getMessage());
        $this->assertEquals('AI_ERROR', $e->getErrorCode());
        $this->assertEquals(500, $e->getStatusCode());

        $array = $e->toArray();
        $this->assertEquals('AI_ERROR', $array['error']);
        $this->assertEquals('AI error', $array['message']);
    }

    public function testProviderException(): void
    {
        $e = new ProviderException('API key missing', 401);
        $this->assertEquals('AI_PROVIDER_ERROR', $e->getErrorCode());
        $this->assertEquals(401, $e->getCode());
    }

    public function testToolException(): void
    {
        $e = new ToolException('Tool failed', 500);
        $this->assertEquals('AI_TOOL_ERROR', $e->getErrorCode());
    }

    public function testExceptionInheritance(): void
    {
        $e = new ProviderException('test');
        $this->assertInstanceOf(AiException::class, $e);
        $this->assertInstanceOf(\RuntimeException::class, $e);
    }
}
