<?php
declare(strict_types=1);

namespace wise\agent\test;

use PHPUnit\Framework\TestCase;
use wise\agent\feedback\ToolDegrader;
use wise\agent\tool\ToolRegistry;

class ToolDegraderTest extends TestCase
{
    private function fakeTool(string $name): \wise\agent\contract\ToolInterface
    {
        return new class($name) implements \wise\agent\contract\ToolInterface {
            public function __construct(private string $name) {}
            public function getName(): string { return $this->name; }
            public function getDescription(): string { return 'fake'; }
            public function getParameters(): array { return ['type' => 'object', 'properties' => []]; }
            public function execute(array $arguments): string { return '{}'; }
            public function getPermission(): string { return ''; }
            public function requireConfirmation(): bool { return false; }
            public function getAccess(): string { return 'write'; }
            public function getRiskLevel(): string { return 'medium'; }
        };
    }

    public function testComputeConsecutiveFailures(): void
    {
        $rows = [
            ['tool' => 'A', 'ok' => false],
            ['tool' => 'A', 'ok' => false],
            ['tool' => 'A', 'ok' => true],
            ['tool' => 'A', 'ok' => false],
            ['tool' => 'B', 'ok' => false],
        ];

        $result = ToolDegrader::computeConsecutiveFailures($rows);

        $this->assertSame(1, $result['A'], '成功后清零，末尾连续失败 1 次');
        $this->assertSame(1, $result['B']);
    }

    public function testDegradeRemovesFromRegistryAndRestoreRecovers(): void
    {
        $tool = $this->fakeTool('db_query');
        $registry = ToolRegistry::newInstance(['db_query' => $tool]);
        $degrader = new ToolDegrader(static fn () => null, $registry);

        $this->assertTrue($registry->has('db_query'));

        $this->assertTrue($degrader->degrade('db_query'));
        $this->assertFalse($registry->has('db_query'), '降级后从可见清单移除');
        $this->assertTrue($registry->isDegraded('db_query'));

        $this->assertTrue($degrader->restore('db_query'));
        $this->assertTrue($registry->has('db_query'), '恢复后重新可见');
        $this->assertFalse($registry->isDegraded('db_query'));
    }

    public function testDegradeAudits(): void
    {
        $registry = ToolRegistry::newInstance(['db_query' => $this->fakeTool('db_query')]);
        $audits   = [];
        $degrader = new ToolDegrader(function (string $name, string $action) use (&$audits) {
            $audits[] = [$name, $action];
        }, $registry);

        $degrader->degrade('db_query');
        $degrader->restore('db_query');

        $this->assertSame([['db_query', 'degrade'], ['db_query', 'restore']], $audits, '降级/恢复均写审计');
    }

    public function testDegradeNonexistentReturnsFalse(): void
    {
        $registry = ToolRegistry::newInstance();
        $degrader = new ToolDegrader(static fn () => null, $registry);

        $this->assertFalse($degrader->degrade('no_such_tool'));
    }

    public function testDegradeUnhealthy(): void
    {
        $registry = ToolRegistry::newInstance([
            'tool_a' => $this->fakeTool('tool_a'),
            'tool_b' => $this->fakeTool('tool_b'),
        ]);
        $degrader = new ToolDegrader(static fn () => null, $registry);

        $degraded = $degrader->degradeUnhealthy([
            'tool_a' => 3,
            'tool_b' => 1,
        ]);

        $this->assertSame(['tool_a'], $degraded);
        $this->assertFalse($registry->has('tool_a'));
        $this->assertTrue($registry->has('tool_b'), '未达阈值不降级');
    }
}
