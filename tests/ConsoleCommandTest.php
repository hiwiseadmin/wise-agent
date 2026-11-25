<?php
declare(strict_types=1);

namespace wise\agent\test;

use PHPUnit\Framework\TestCase;
use wise\agent\console\ConsoleCommandGuard;
use wise\agent\console\ConsoleCommandMeta;
use wise\agent\console\ConsoleCommandTool;
use wise\agent\console\ConsoleCommandToolProvider;
use wise\agent\console\ConsoleResult;
use wise\agent\security\AccessResolver;
use wise\agent\security\CapabilityAccess;
use wise\agent\security\CapabilityAuthorizer;
use wise\support\tools\CommandTool;

/**
 * A4 框架命令内省执行 —— 纯逻辑回归测试
 *
 * 覆盖＼�：
 *   1  元数据 → JSON Schema / 权限标识 / 工具名
 *   2  白名单（allow / deny / 插件声明）
 *   3  权限判定（CapabilityAuthorizer::evaluateGranted）
 *   4  风险分级 + requireConfirmation
 *   5  命令拼接的注入拦截
 *   7  结果结构化（--json / 文本归一化 / 截断）
 *   8  异常安全（归一化永远返回结构化 JSON）
 *
 * 说明：不依赖容器 / 数据库 / 真实子进程（超时与真实执行由集成验收脚本覆盖）。
 */
class ConsoleCommandTest extends TestCase
{
    private function guard(): ConsoleCommandGuard
    {
        return new ConsoleCommandGuard(
            allow: ['wise:agent:*', 'wise:health:*', 'cache:*', 'migrate:status', 'list', 'help', 'match3game:*'],
            deny: ['migrate:run', 'migrate:rollback', 'install:*', 'plugin:disable:*'],
            writeRequiresConfirmation: true,
        );
    }

    private function meta(string $name, array $arguments = [], array $options = [], bool $json = false, string $source = 'package:wise-agent'): ConsoleCommandMeta
    {
        return new ConsoleCommandMeta(
            name: $name,
            description: 'test command',
            source: $source,
            supportsJson: $json,
            arguments: $arguments,
            options: $options,
        );
    }


    public function testJsonSchemaFromArgumentsAndOptions(): void
    {
        $meta = $this->meta('wise:agent:demo', [
            ['name' => 'table', 'required' => true, 'array' => false, 'default' => null, 'desc' => '目标表'],
            ['name' => 'ids', 'required' => false, 'array' => true, 'default' => [], 'desc' => 'ID 列表'],
        ], [
            ['name' => 'force', 'shortcut' => 'f', 'value_required' => false, 'value_optional' => false, 'array' => false, 'default' => null, 'desc' => '强制'],
            ['name' => 'limit', 'shortcut' => '', 'value_required' => true, 'value_optional' => false, 'array' => false, 'default' => '10', 'desc' => '条数'],
            ['name' => 'json', 'shortcut' => '', 'value_required' => false, 'value_optional' => false, 'array' => false, 'default' => null, 'desc' => 'JSON 输出'],
        ]);

        $schema = $meta->toJsonSchema();

        $this->assertSame('object', $schema['type']);
        $this->assertSame(['table'], $schema['required']);
        $this->assertSame('string', $schema['properties']['table']['type']);
        $this->assertSame('array', $schema['properties']['ids']['type']);
        $this->assertSame('boolean', $schema['properties']['force']['type']);
        $this->assertSame('string', $schema['properties']['limit']['type']);
        $this->assertSame('10', $schema['properties']['limit']['default']);
        $this->assertArrayNotHasKey('json', $schema['properties']);
    }

    public function testPermissionAndToolName(): void
    {
        $framework = $this->meta('wise:agent:discover');
        $this->assertSame('ai.cmd.wise:agent:discover', $framework->getPermission());
        $this->assertSame('cmd_wise_agent_discover', $framework->getToolName());

        $plugin = $this->meta('match3game:daily', [], [], false, 'plugin:match3game');
        $this->assertSame('plugin:match3game.cmd.match3game:daily', $plugin->getPermission());
        $this->assertTrue($plugin->isPluginCommand());
    }

    public function testMetaRoundTrip(): void
    {
        $meta = $this->meta('wise:health:check', [], [
            ['name' => 'json', 'shortcut' => '', 'value_required' => false, 'value_optional' => false, 'array' => false, 'default' => null, 'desc' => 'JSON'],
        ], true, 'package:wise-health-check');

        $restored = ConsoleCommandMeta::fromArray($meta->toArray());

        $this->assertSame($meta->name, $restored->name);
        $this->assertSame($meta->source, $restored->source);
        $this->assertTrue($restored->supportsJson);
        $this->assertSame($meta->getPermission(), $restored->getPermission());
    }

    public function testHelpSurvivesRoundTrip(): void
    {
        $meta = new ConsoleCommandMeta(name: 'wise:demo:x', description: 'desc', help: "多行\n帮助");

        $this->assertSame("多行\n帮助", ConsoleCommandMeta::fromArray($meta->toArray())->help);
    }

    public function testFromCommandReadsHelpAndDropsGlobalOptions(): void
    {
        $command = new class extends \think\console\Command {
            protected function configure(): void
            {
                $this->setName('wise:demo:self')
                    ->setDescription('self command')
                    ->setHelp("usage:\n  php %command.full_name% --force")
                    ->addOption('force', 'f', \think\console\input\Option::VALUE_NONE, 'force run');
            }

            protected function execute(\think\console\Input $input, \think\console\Output $output): int
            {
                return 0;
            }
        };

        $command->getDefinition()->addOption(
            new \think\console\input\Option('quiet', 'q', \think\console\input\Option::VALUE_NONE, 'Do not output any message')
        );

        $meta = ConsoleCommandMeta::fromCommand('wise:demo:self', $command);

        $this->assertSame(['force'], array_column($meta->options, 'name'), '全局选项不得进入 Schema');
        $this->assertStringContainsString('%command.full_name%', $meta->help);
        $this->assertSame(
            'usage: php think wise:demo:self --force',
            $meta->helpSummary(200)
        );
    }


    public function testHelpIsSummarizedIntoDescription(): void
    {
        $meta = new ConsoleCommandMeta(
            name: 'wise:demo:rescore',
            description: '重算历史打分',
            help: "The <info>%command.name%</info> command rescored rows:\n\n"
                . "  <info>php %command.full_name% member --limit=100</info>\n第二行说明",
        );

        $desc = (new ConsoleCommandTool($meta, $this->guard()))->getDescription();

        $this->assertStringContainsString('帮助：', $desc);
        $this->assertStringContainsString('php think wise:demo:rescore member --limit=100', $desc);
        $this->assertStringNotContainsString('<info>', $desc);
        $this->assertStringNotContainsString("\n", $desc);
    }

    public function testHelpSummaryIsTruncated(): void
    {
        $meta = new ConsoleCommandMeta(name: 'wise:demo:x', description: 'd', help: str_repeat('a', 100));

        $this->assertSame(10, mb_strlen($meta->helpSummary(10), 'UTF-8'));
        $this->assertSame('', (new ConsoleCommandMeta(name: 'wise:demo:y'))->helpSummary());
    }

    public function testSchemaTypeInference(): void
    {
        $meta = $this->meta('wise:demo:rescore', [
            ['name' => 'model', 'required' => true, 'array' => false, 'default' => null, 'desc' => '目标模型，可选值：member, order'],
        ], [
            ['name' => 'limit', 'shortcut' => '', 'value_required' => true, 'value_optional' => false, 'array' => false, 'default' => '200', 'desc' => '最多处理条数，整数，默认 200'],
            ['name' => 'ratio', 'shortcut' => '', 'value_required' => true, 'value_optional' => false, 'array' => false, 'default' => null, 'desc' => '缩放比例，小数'],
        ]);

        $schema = $meta->toJsonSchema();

        $this->assertSame(['member', 'order'], $schema['properties']['model']['enum']);
        $this->assertSame('integer', $schema['properties']['limit']['type']);
        $this->assertSame(200, $schema['properties']['limit']['default']);
        $this->assertSame('number', $schema['properties']['ratio']['type']);
    }

    public function testInferenceCanBeDisabled(): void
    {
        $meta = $this->meta('wise:demo:x', [], [
            ['name' => 'limit', 'shortcut' => '', 'value_required' => true, 'value_optional' => false, 'array' => false, 'default' => '200', 'desc' => '整数，可选值：1, 2'],
        ]);

        $schema = $meta->toJsonSchema(false);

        $this->assertSame('string', $schema['properties']['limit']['type']);
        $this->assertArrayNotHasKey('enum', $schema['properties']['limit']);
    }

    public function testUsageExampleUsesEnumAndDefaults(): void
    {
        $meta = $this->meta('wise:demo:rescore', [
            ['name' => 'model', 'required' => true, 'array' => false, 'default' => null, 'desc' => '可选值：member, order'],
        ], [
            ['name' => 'limit', 'shortcut' => '', 'value_required' => true, 'value_optional' => false, 'array' => false, 'default' => '200', 'desc' => '整数'],
            ['name' => 'dry-run', 'shortcut' => '', 'value_required' => false, 'value_optional' => false, 'array' => false, 'default' => null, 'desc' => '预演'],
        ]);

        $this->assertSame(
            ['model' => 'member', 'limit' => '200'],
            json_decode($meta->usageExample(), true)
        );
        $this->assertSame('', $this->meta('wise:demo:noargs')->usageExample());
    }

    public function testLimitMetasKeepsLowRiskFirst(): void
    {
        $guard = $this->guard();
        $read  = $guard->annotate($this->meta('a:list'));
        $write = $guard->annotate($this->meta('a:sync'));
        $admin = $guard->annotate($this->meta('cache:clear'));

        $kept = ConsoleCommandToolProvider::limitMetas([$admin, $write, $read], 2);

        $this->assertCount(2, $kept);
        $this->assertSame('a:list', $kept[0]->name);
        $this->assertSame('a:sync', $kept[1]->name);
        $this->assertCount(3, ConsoleCommandToolProvider::limitMetas([$admin, $write, $read], 0), '0 = 不限');
    }


    public function testAllowlistAndDenylist(): void
    {
        $guard = $this->guard();

        $this->assertTrue($guard->isAllowed($this->meta('wise:agent:discover')));
        $this->assertTrue($guard->isAllowed($this->meta('cache:clear')));
        $this->assertTrue($guard->isAllowed($this->meta('list')));

        $this->assertFalse($guard->isAllowed($this->meta('migrate:run')), 'deny 优先');
        $this->assertFalse($guard->isAllowed($this->meta('plugin:disable:demo')));
        $this->assertFalse($guard->isAllowed($this->meta('foo:bar')), '未知命令默认拒绝');
    }

    public function testPluginCommandRequiresExplicitDeclaration(): void
    {
        $guard = $this->guard();

        $this->assertFalse($guard->isAllowed($this->meta('match3game:daily', [], [], false, 'plugin:match3game')));
    }

    public function testAssertAllowedThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->guard()->assertAllowed($this->meta('migrate:run'));
    }


    public function testRbacDecisionForConsolePermission(): void
    {
        $meta     = $this->meta('wise:agent:discover');
        $describe = (new AccessResolver())->resolveTool(new ConsoleCommandTool($meta, $this->guard()), $meta->getToolName());

        $authorizer = new CapabilityAuthorizer();

        $denied = $authorizer->evaluateGranted($describe, []);
        $this->assertFalse($denied->allowed);
        $this->assertStringContainsString('ai.cmd.wise:agent:discover', $denied->reason);

        $this->assertTrue($authorizer->evaluateGranted($describe, ['ai.cmd.wise:agent:discover'])->allowed);
        $this->assertTrue($authorizer->evaluateGranted($describe, [CapabilityAccess::wildcardIdent(CapabilityAccess::WRITE)])->allowed);
        $this->assertFalse($authorizer->evaluateGranted($describe, [CapabilityAccess::wildcardIdent(CapabilityAccess::READ)])->allowed);
    }


    public function testRiskGradingAndConfirmation(): void
    {
        $guard = $this->guard();

        $admin = $this->meta('cache:clear');
        $this->assertSame(ConsoleCommandMeta::RISK_ADMIN, $guard->riskOf($admin));
        $this->assertTrue($guard->requireConfirmation($admin));
        $this->assertSame(CapabilityAccess::ADMIN, $guard->accessOf($admin));
        $this->assertSame(CapabilityAccess::RISK_HIGH, $guard->riskLevelOf($admin));

        $write = $this->meta('wise:agent:discover');
        $this->assertSame(ConsoleCommandMeta::RISK_WRITE, $guard->riskOf($write));
        $this->assertTrue($guard->requireConfirmation($write));

        $read = $this->meta('list');
        $this->assertSame(ConsoleCommandMeta::RISK_READ, $guard->riskOf($read));
        $this->assertFalse($guard->requireConfirmation($read));
        $this->assertSame(CapabilityAccess::READ, $guard->accessOf($read));

        $this->assertSame(ConsoleCommandMeta::RISK_ADMIN, $guard->riskOf($this->meta('plugin:disable:x')));
    }

    public function testToolExposesRiskMetadata(): void
    {
        $tool = new ConsoleCommandTool($this->meta('wise:agent:discover'), $this->guard());

        $this->assertSame('ai.cmd.wise:agent:discover', $tool->getPermission());
        $this->assertSame(CapabilityAccess::WRITE, $tool->getAccess());
        $this->assertSame(CapabilityAccess::RISK_MEDIUM, $tool->getRiskLevel());
        $this->assertTrue($tool->requireConfirmation());
        $this->assertSame('cmd_wise_agent_discover', $tool->getName());
    }


    /**
     * @dataProvider injectionVectors
     */
    public function testInjectionIsRejected(string $vector): void
    {
        $tool = new ConsoleCommandTool($this->meta('wise:agent:demo', [], [
            ['name' => 'name', 'shortcut' => '', 'value_required' => true, 'value_optional' => false, 'array' => false, 'default' => null, 'desc' => '名称'],
        ]), $this->guard());

        $this->expectException(\InvalidArgumentException::class);
        $tool->buildCmdline(['name' => $vector]);
    }

    public static function injectionVectors(): array
    {
        return [
            'semicolon'   => ['a; rm -rf /'],
            'pipe'        => ['a | cat /etc/passwd'],
            'redirect'    => ['a > /etc/passwd'],
            'subshell'    => ['a $(whoami)'],
            'backtick'    => ['a `whoami`'],
            'newline'     => ["a\nrm -rf /"],
            'and'         => ['a && rm -rf /'],
            'brace'       => ['${IFS}rm'],
        ];
    }

    public function testSafeValueBuildsCmdlineWithEscaping(): void
    {
        $tool = new ConsoleCommandTool($this->meta('wise:agent:demo', [], [
            ['name' => 'name', 'shortcut' => '', 'value_required' => true, 'value_optional' => false, 'array' => false, 'default' => null, 'desc' => '名称'],
        ]), $this->guard());

        $cmdline = $tool->buildCmdline(['name' => 'hello-world']);

        $this->assertStringContainsString('--name=', $cmdline);
        $this->assertStringContainsString('hello-world', $cmdline);
        $this->assertMatchesRegularExpression('/think/i', $cmdline);
    }

    public function testUnknownParameterIsRejected(): void
    {
        $tool = new ConsoleCommandTool($this->meta('wise:agent:demo'), $this->guard());

        $this->expectException(\InvalidArgumentException::class);
        $tool->buildCmdline(['evil' => 'x']);
    }

    public function testMissingRequiredArgumentIsRejected(): void
    {
        $tool = new ConsoleCommandTool($this->meta('wise:agent:demo', [
            ['name' => 'table', 'required' => true, 'array' => false, 'default' => null, 'desc' => 'table'],
        ]), $this->guard());

        $this->expectException(\InvalidArgumentException::class);
        $tool->buildCmdline([]);
    }

    public function testJsonAutoAppendedOnlyOnce(): void
    {
        $meta    = $this->meta('wise:health:check', [], [], true);
        $cmdline = null;
        $tool    = new ConsoleCommandTool($meta, $this->guard(), function (string $cmd) use (&$cmdline): array {
            $cmdline = $cmd;
            return ['success' => true, 'error_code' => 0, 'message' => 'ok', 'command' => $cmd, 'output' => '{"code":0,"msg":"ok","data":{}}', 'exit_code' => 0, 'last_line' => '', 'execution_time' => 0];
        });

        $tool->execute([]);

        $this->assertSame(1, substr_count((string) $cmdline, '--json'));
    }

    public function testExecutedMethodIsPlatformAppropriate(): void
    {
        $captured = null;
        $tool     = new ConsoleCommandTool($this->meta('list'), $this->guard(), function (string $cmd, array $options) use (&$captured): array {
            $captured = $options;
            return ['success' => true, 'error_code' => 0, 'message' => 'ok', 'command' => $cmd, 'output' => 'ok', 'exit_code' => 0, 'last_line' => '', 'execution_time' => 0];
        });

        $tool->execute([]);

        $expected = CommandTool::METHOD_PROC_OPEN;
        $this->assertIsArray($captured);

        $options = $captured;
        $this->assertArrayHasKey('default_method', $options);
        $this->assertSame($expected, $options['default_method']);
        $this->assertGreaterThan(0, $options['timeout']);
    }


    public function testStructuredJsonResult(): void
    {
        $meta   = $this->meta('wise:health:check', [], [], true);
        $result = [
            'success' => true, 'error_code' => 0, 'message' => '执行成功', 'command' => 'probe',
            'output'  => "\x1B[32m" . json_encode(['code' => 0, 'msg' => 'ok', 'data' => ['a' => 1]]),
            'exit_code' => 0, 'last_line' => '', 'execution_time' => 0.83,
        ];

        $obs = ConsoleResult::normalize($meta, $result, 65536);

        $this->assertTrue($obs['structured']);
        $this->assertSame(0, $obs['code']);
        $this->assertSame('ok', $obs['msg']);
        $this->assertSame(['a' => 1], $obs['data']);
        $this->assertSame(830, $obs['duration_ms']);
        $this->assertFalse($obs['truncated']);
        $this->assertSame('', $obs['text']);
        $this->assertStringNotContainsString("\x1B", json_encode($obs));
    }

    public function testTextFallbackForCommandWithoutJson(): void
    {
        $meta   = $this->meta('list');
        $result = [
            'success' => true, 'error_code' => 0, 'message' => '执行成功', 'command' => 'list',
            'output'  => "\x1B[33mAvailable commands:\x1B[39m\n  wise:agent:discover\n",
            'exit_code' => 0, 'last_line' => '', 'execution_time' => 0.2,
        ];

        $obs = ConsoleResult::normalize($meta, $result, 65536);

        $this->assertFalse($obs['structured']);
        $this->assertNull($obs['data']);
        $this->assertStringContainsString('wise:agent:discover', $obs['text']);
        $this->assertStringNotContainsString("\x1B", $obs['text']);
    }

    public function testTruncationDetection(): void
    {
        $meta = $this->meta('list');

        $marked = ConsoleResult::normalize($meta, [
            'success' => true, 'error_code' => 0, 'output' => str_repeat('x', 20) . PHP_EOL . '... [输出被截断，超过 20 字节]',
        ], 20);
        $this->assertTrue($marked['truncated']);

        $sized = ConsoleResult::normalize($meta, [
            'success' => true, 'error_code' => 0, 'output' => str_repeat('y', 100),
        ], 100);
        $this->assertTrue($sized['truncated']);

        $broken = ConsoleResult::normalize($this->meta('wise:health:check', [], [], true), [
            'success' => true, 'error_code' => 0, 'output' => '{"code":0,"msg":"ok","data":{"a":1',
        ], 65536);
        $this->assertFalse($broken['structured'], '截断即非法 JSON → 回退文本');
    }


    public function testExecutorExceptionIsWrappedAsJson(): void
    {
        $tool = new ConsoleCommandTool($this->meta('list'), $this->guard(), function (): array {
            throw new \RuntimeException('boom');
        });

        $obs = json_decode($tool->execute([]), true);

        $this->assertIsArray($obs);
        $this->assertSame(1, $obs['code']);
        $this->assertSame('execution', $obs['stage']);
        $this->assertStringContainsString('boom', $obs['msg']);
    }

    public function testGuardRejectionIsWrappedAsJson(): void
    {
        $obs = json_decode((new ConsoleCommandTool($this->meta('migrate:run'), $this->guard()))->execute([]), true);

        $this->assertSame(1, $obs['code']);
        $this->assertSame('guard', $obs['stage']);
        $this->assertSame(0, $obs['duration_ms'], '未发生任何命令执行');
    }

    public function testInvalidArgumentsAreWrappedAsJson(): void
    {
        $tool = new ConsoleCommandTool($this->meta('wise:agent:demo', [], [
            ['name' => 'name', 'shortcut' => '', 'value_required' => true, 'value_optional' => false, 'array' => false, 'default' => null, 'desc' => '名称'],
        ]), $this->guard());

        $obs = json_decode($tool->execute(['name' => 'a; rm -rf /']), true);

        $this->assertSame(1, $obs['code']);
        $this->assertSame('arguments', $obs['stage']);
    }

    public function testCommandOwnCodeAndMsgArePreserved(): void
    {
        $obs = ConsoleResult::normalize($this->meta('wise:health:check', [], [], true), [
            'success'   => false,
            'error_code' => CommandTool::ERROR_EXECUTION_FAILED,
            'message'   => '执行失败，退出码: 1',
            'output'    => '{"code":1,"msg":"internal failure","data":null}',
            'exit_code' => 1,
        ], 65536);

        $this->assertSame(1, $obs['code']);
        $this->assertSame('internal failure', $obs['msg']);
        $this->assertTrue($obs['structured']);
    }

    public function testTimeoutResultMapsToErrorCode(): void
    {
        $obs = ConsoleResult::normalize($this->meta('list'), [
            'success' => false, 'error_code' => CommandTool::ERROR_TIMEOUT,
            'message' => '命令执行超时 (1秒)', 'output' => '', 'exit_code' => 0,
        ], 65536);

        $this->assertSame(CommandTool::ERROR_TIMEOUT, $obs['code']);
        $this->assertStringContainsString('超时', $obs['msg']);
    }
}
