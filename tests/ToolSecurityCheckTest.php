<?php
declare(strict_types=1);

namespace wise\agent\test;

use PHPUnit\Framework\TestCase;
use wise\agent\event\ToolExecute;
use wise\agent\listener\ToolSecurityCheck;
use wise\agent\tool\ToolRegistry;

require_once __DIR__ . '/stubs/config_stub.php';

/**
 * 工具安全检查的 fail-closed 契约
 *
 * 重点：resolveTool() 找不到工具时必须拒绝，不能直接 return 让调用继续。
 */
class ToolSecurityCheckTest extends TestCase
{
    protected function setUp(): void
    {
        $ref  = new \ReflectionClass(ToolRegistry::class);
        $prop = $ref->getProperty('instance');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }

    public function testUnknownToolIsBlockedFailClosed(): void
    {
        $event = new ToolExecute(ToolExecute::PHASE_BEFORE, 'definitely_missing_tool', []);

        (new ToolSecurityCheck())->handle($event);

        $this->assertTrue($event->skip);
        $this->assertStringContainsString('tool_not_resolved', (string) $event->mockResult);
        $this->assertStringContainsString('definitely_missing_tool', (string) $event->mockResult);
    }

    public function testAfterPhaseIsIgnored(): void
    {
        $event = new ToolExecute(ToolExecute::PHASE_AFTER, 'definitely_missing_tool', [], 'done');

        (new ToolSecurityCheck())->handle($event);

        $this->assertFalse($event->skip);
        $this->assertSame('done', $event->result);
    }
}
