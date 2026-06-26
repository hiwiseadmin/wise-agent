<?php
declare(strict_types=1);

namespace wise\agent\test;

use PHPUnit\Framework\TestCase;
use wise\agent\tool\ToolRegistry;
use wise\agent\tool\BaseTool;

/**
 * Tool system tests
 */
class ToolRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        // Reset singleton and clear tools array for testing
        $ref = new \ReflectionClass(ToolRegistry::class);

        $instanceProp = $ref->getProperty('instance');
        $instanceProp->setAccessible(true);
        $instanceProp->setValue(null, null);

        $toolsProp = $ref->getProperty('tools');
        $toolsProp->setAccessible(true);

        // Clear tools on any stale instance before resetting singleton
        $stale = $instanceProp->getValue(null);
        if ($stale !== null) {
            $toolsProp->setValue($stale, []);
        }
    }

    public function testRegisterAndGet(): void
    {
        $tool = new class extends BaseTool {
            public function getName(): string { return 'test_tool'; }
            public function getDescription(): string { return 'A test tool'; }
            public function getParameters(): array {
                return ['type' => 'object', 'properties' => []];
            }
            public function execute(array $arguments): string {
                return json_encode(['success' => true]);
            }
        };

        $registry = ToolRegistry::instance();
        $registry->register($tool);

        $this->assertTrue($registry->has('test_tool'));
        $this->assertSame($tool, $registry->get('test_tool'));

        $result = $registry->execute('test_tool', []);
        $this->assertStringContainsString('"success":true', $result);
    }

    public function testGetToolSchemas(): void
    {
        $tool = new class extends BaseTool {
            public function getName(): string { return 'schema_tool'; }
            public function getDescription(): string { return 'Schema test'; }
            public function getParameters(): array {
                return [
                    'type' => 'object',
                    'properties' => ['name' => ['type' => 'string']],
                    'required' => ['name'],
                ];
            }
            public function execute(array $arguments): string { return ''; }
        };

        $registry = ToolRegistry::instance();
        $registry->register($tool);

        $schemas = $registry->getToolSchemas();
        $this->assertCount(1, $schemas);
        $this->assertEquals('function', $schemas[0]['type']);
        $this->assertEquals('schema_tool', $schemas[0]['function']['name']);
    }

    public function testUnregister(): void
    {
        $tool = new class extends BaseTool {
            public function getName(): string { return 'removable_tool'; }
            public function getDescription(): string { return 'Temp'; }
            public function getParameters(): array { return ['type' => 'object', 'properties' => []]; }
            public function execute(array $arguments): string { return ''; }
        };

        $registry = ToolRegistry::instance();
        $registry->register($tool);
        $this->assertTrue($registry->has('removable_tool'));

        $registry->unregister('removable_tool');
        $this->assertFalse($registry->has('removable_tool'));
    }

    public function testExecuteNonexistentTool(): void
    {
        $result = ToolRegistry::instance()->execute('nonexistent', []);
        $this->assertStringContainsString('not found', $result);
    }
}
