<?php
declare(strict_types=1);

namespace wise\agent\test;

use PHPUnit\Framework\TestCase;
use wise\agent\exception\AiException;
use wise\agent\exception\ProviderException;
use wise\agent\exception\ToolException;
use wise\support\exception\WiseException;

/**
 * Exception tests
 */
class ExceptionTest extends TestCase
{
    public function testAiException(): void
    {
        $e = new AiException('AI error', 500, null, ['key' => 'value']);
        $this->assertEquals('AI error', $e->getMessage());
        $this->assertEquals(80000, $e->getErrorCode());
        $this->assertEquals(500, $e->getStatusCode());
        $this->assertEquals(500, $e->getHttpCode());
    }

    public function testProviderException(): void
    {
        $e = new ProviderException('API key missing', 401);
        $this->assertEquals(80001, $e->getErrorCode());
        $this->assertEquals(401, $e->getHttpCode());
    }

    public function testToolException(): void
    {
        $e = new ToolException('Tool failed', 500);
        $this->assertEquals(80002, $e->getErrorCode());
    }

    public function testExceptionInheritance(): void
    {
        $e = new ProviderException('test');
        $this->assertInstanceOf(AiException::class, $e);
        $this->assertInstanceOf(WiseException::class, $e);
        $this->assertInstanceOf(\RuntimeException::class, $e);
    }
}
