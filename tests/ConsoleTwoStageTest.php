<?php
declare(strict_types=1);

namespace wise\agent\test;

use PHPUnit\Framework\TestCase;
use wise\agent\console\ConsoleCommandGuard;
use wise\agent\console\ConsoleCommandMeta;
use wise\agent\console\ConsoleCommandRunTool;
use wise\agent\console\ConsoleCommandSearchTool;
use wise\agent\tool\BaseTool;
use wise\agent\tool\ToolRegistry;

/**
 * 两段式控制台工具面（console.two_stage）
 *
 * 目的：把「N 条命令 = N 个 function」压成 cmd_search + cmd_run 两个小工具，
 * 每步省下约 2.8k token；同时**不放大权限面**：
 *   - 具体命令仍在注册表（hidden），`ai.cmd.{name}` 权限/审批照旧生效；
 *   - 参数仍走 ConsoleCommandTool 的白名单映射与危险字符净化。
 */
class ConsoleTwoStageTest extends TestCase
{
    protected function setUp(): void
    {
        $ref = new \ReflectionClass(ToolRegistry::class);
        $prop = $ref->getProperty('instance');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }

    private function meta(
        string $name,
        string $description = '',
        array $arguments = [],
        array $options = [],
        string $risk = ConsoleCommandMeta::RISK_WRITE
    ): ConsoleCommandMeta {
        return new ConsoleCommandMeta(
            name: $name,
            description: $description,
            risk: $risk,
            arguments: $arguments,
            options: $options,
            allowed: true,
        );
    }

    public function testHiddenToolIsNotExposedButStillResolvable(): void
    {
        $registry = ToolRegistry::instance();
        $tool = new class extends BaseTool {
            public function getName(): string { return 'cmd_demo'; }
            public function getDescription(): string { return 'demo'; }
            public function getParameters(): array { return ['type' => 'object', 'properties' => ['x' => ['type' => 'string']]]; }
            public function execute(array $arguments): string { return 'ok'; }
        };

        $registry->register($tool);
        $registry->hide('cmd_demo');

        $names = array_map(
            static fn(array $schema): string => (string) $schema['function']['name'],
            $registry->getToolSchemas()
        );

        $this->assertNotContains('cmd_demo', $names, '隐藏工具不能下发给模型');
        $this->assertNotNull($registry->get('cmd_demo'), '隐藏工具仍要被安全层/执行层查到');
        $this->assertTrue($registry->isHidden('cmd_demo'));

        $registry->show('cmd_demo');
        $this->assertFalse($registry->isHidden('cmd_demo'));
    }

    public function testSearchReturnsCompactCatalogOnly(): void
    {
        $tool = new ConsoleCommandSearchTool([
            $this->meta('migrate:status', '查看数据库迁移状态', [], [['name' => 'format', 'value_required' => true, 'desc' => '输出格式']]),
            $this->meta('cache:clear', '清除缓存'),
            $this->meta('migrate:run', '执行数据库迁移'),
        ]);

        $payload = json_decode($tool->execute(['keyword' => 'migrate']), true);

        $this->assertSame(2, $payload['count']);
        $names = array_column($payload['commands'], 'name');
        $this->assertContains('migrate:status', $names);
        $this->assertNotContains('cache:clear', $names);

        $first = $payload['commands'][0];
        $this->assertArrayHasKey('params', $first);
        $this->assertArrayNotHasKey('parameters', $first);
        $this->assertArrayNotHasKey('schema', $first);
    }

    public function testSearchOrdersReadFirst(): void
    {
        $tool = new ConsoleCommandSearchTool([
            $this->meta('db:wipe', '危险操作', risk: ConsoleCommandMeta::RISK_ADMIN),
            $this->meta('db:status', '只读状态', risk: ConsoleCommandMeta::RISK_READ),
        ]);

        $payload = json_decode($tool->execute(['keyword' => 'db']), true);

        $this->assertSame('db:status', $payload['commands'][0]['name']);
    }

    public function testRunRejectsCommandOutsideAllowlist(): void
    {
        $guard = new ConsoleCommandGuard(['help'], [], false);
        $tool  = new ConsoleCommandRunTool($guard, ['help' => $this->meta('help')]);

        $payload = json_decode($tool->execute(['command' => 'migrate:run']), true);

        $this->assertSame(1, $payload['code']);
        $this->assertStringContainsString('白名单', (string) $payload['msg']);
    }

    public function testRunExecutesAllowedCommandThroughWhitelistedArguments(): void
    {
        $guard = new ConsoleCommandGuard(['help'], [], false);
        $meta  = $this->meta('help', 'Displays help for a command', [
            ['name' => 'command_name', 'required' => false, 'array' => false, 'default' => null, 'desc' => '命令名'],
        ]);

        $captured = null;
        $executor = static function (string $cmdline, array $options) use (&$captured): array {
            $captured = $cmdline;
            return [
                'success'        => true,
                'exit_code'      => 0,
                'error_code'     => 0,
                'output'         => 'ok',
                'execution_time' => 0.01,
            ];
        };

        $tool    = new ConsoleCommandRunTool($guard, ['help' => $meta], $executor);
        $payload = json_decode($tool->execute([
            'command'   => 'help',
            'arguments' => ['command_name' => 'migrate:run'],
        ]), true);

        $this->assertSame(0, $payload['code'], (string) ($payload['msg'] ?? ''));
        $this->assertSame('ok', $payload['msg']);
        $this->assertIsString($captured);
        $this->assertStringContainsString('think', $captured);
        $this->assertStringContainsString('help', $captured);
        $this->assertStringContainsString('migrate:run', $captured);
    }

    public function testRunRejectsUnknownArguments(): void
    {
        $guard = new ConsoleCommandGuard(['help'], [], false);
        $tool  = new ConsoleCommandRunTool($guard, ['help' => $this->meta('help')], static fn(): array => []);

        $payload = json_decode($tool->execute([
            'command'   => 'help',
            'arguments' => ['evil' => 'rm -rf /'],
        ]), true);

        $this->assertSame(1, $payload['code']);
    }
}
