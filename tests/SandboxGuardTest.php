<?php
declare(strict_types=1);

namespace wise\agent\test;

use PHPUnit\Framework\TestCase;
use wise\agent\event\ToolExecute;
use wise\agent\security\CapabilityAccess;
use wise\agent\security\SandboxGuard;
use wise\agent\tool\ToolRegistry;

/**
 * C3-1：SandboxGuard 跨能力只读沙箱（验收 11、12 的可离线部分）
 */
class SandboxGuardTest extends TestCase
{
    private function fakeTool(string $name, string $access): \wise\agent\contract\ToolInterface
    {
        return new class($name, $access) implements \wise\agent\contract\ToolInterface {
            public int $executeCount = 0;

            public function __construct(
                private string $name,
                private string $access,
            ) {
            }

            public function getName(): string { return $this->name; }
            public function getDescription(): string { return 'fake'; }
            public function getParameters(): array { return ['type' => 'object', 'properties' => []]; }
            public function execute(array $arguments): string { $this->executeCount++; return '{}'; }
            public function getPermission(): string { return ''; }
            public function requireConfirmation(): bool { return false; }
            public function getAccess(): string { return $this->access; }
            public function getRiskLevel(): string { return CapabilityAccess::RISK_MEDIUM; }
        };
    }

    private function beforeEvent(string $toolName, array $arguments = [], bool $skip = false): ToolExecute
    {
        $event = new ToolExecute(ToolExecute::PHASE_BEFORE, $toolName, $arguments);
        $event->skip = $skip;
        return $event;
    }

    public function testWriteToolHitSandboxSkipsExecution(): void
    {
        $name = 'fake_write_' . uniqid();
        $tool = $this->fakeTool($name, CapabilityAccess::WRITE);
        ToolRegistry::instance()->register($tool, $name);

        $event = $this->beforeEvent($name, ['id' => 1]);
        (new SandboxGuard(['enabled' => true, 'mode' => 'all_writes']))->handle($event);

        $this->assertTrue($event->skip, '写类能力命中沙箱 ⇒ skip');
        $this->assertSame(0, $tool->executeCount, '真工具未被调用');

        $result = json_decode($event->mockResult, true);
        $this->assertSame('sandbox', $result['required_action']);
        $this->assertTrue($result['sandbox']);
        $this->assertSame($name, $result['tool_name']);
        $this->assertSame('unknown', $result['preview']['surface'], '非 menu.action 工具标 surface=unknown');
    }

    public function testReadToolNotAffected(): void
    {
        $name = 'fake_read_' . uniqid();
        ToolRegistry::instance()->register($this->fakeTool($name, CapabilityAccess::READ), $name);

        $event = $this->beforeEvent($name, ['q' => 'x']);
        (new SandboxGuard(['enabled' => true, 'mode' => 'all_writes']))->handle($event);

        $this->assertFalse($event->skip, '读类能力不受沙箱影响');
        $this->assertNull($event->mockResult);
    }

    public function testModeOffDisables(): void
    {
        $name = 'fake_write_off_' . uniqid();
        ToolRegistry::instance()->register($this->fakeTool($name, CapabilityAccess::WRITE), $name);

        $event = $this->beforeEvent($name);
        (new SandboxGuard(['enabled' => true, 'mode' => 'off']))->handle($event);

        $this->assertFalse($event->skip);
    }

    public function testEnabledFalseDisables(): void
    {
        $name = 'fake_write_disabled_' . uniqid();
        ToolRegistry::instance()->register($this->fakeTool($name, CapabilityAccess::WRITE), $name);

        $event = $this->beforeEvent($name);
        (new SandboxGuard(['enabled' => false, 'mode' => 'all_writes']))->handle($event);

        $this->assertFalse($event->skip);
    }

    public function testNewOnlyRespectsTrustedCapabilities(): void
    {
        $trusted = 'fake_write_trusted_' . uniqid();
        $fresh   = 'fake_write_fresh_' . uniqid();
        ToolRegistry::instance()->register($this->fakeTool($trusted, CapabilityAccess::WRITE), $trusted);
        ToolRegistry::instance()->register($this->fakeTool($fresh, CapabilityAccess::WRITE), $fresh);

        $guard = new SandboxGuard([
            'enabled' => true,
            'mode' => 'new_only',
            'trusted_capabilities' => ['fake_write_trusted'],
        ]);

        $trustedEvent = $this->beforeEvent($trusted);
        $guard->handle($trustedEvent);
        $this->assertFalse($trustedEvent->skip, '可信清单前缀命中 ⇒ 不受沙箱影响');

        $freshEvent = $this->beforeEvent($fresh);
        $guard->handle($freshEvent);
        $this->assertTrue($freshEvent->skip, '不在可信清单 ⇒ 视为新接入，命中沙箱');
    }

    public function testSkipGuardDoesNotOverrideSecurityBlock(): void
    {
        $name = 'fake_write_skip_' . uniqid();
        ToolRegistry::instance()->register($this->fakeTool($name, CapabilityAccess::WRITE), $name);

        $event = $this->beforeEvent($name, [], true);
        $event->mockResult = '{"error":"Permission denied"}';

        (new SandboxGuard(['enabled' => true, 'mode' => 'all_writes']))->handle($event);

        $this->assertSame('{"error":"Permission denied"}', $event->mockResult, '双保险：已被拦下不出手，不覆盖权限拒绝');
    }

    public function testGrayDeniedBlocksExecution(): void
    {
        $name = 'fake_write_gray_denied_' . uniqid();
        ToolRegistry::instance()->register($this->fakeTool($name, CapabilityAccess::WRITE), $name);

        $gray = new \wise\agent\security\GrayRelease([
            'enabled' => true,
            'rules'   => ['*' => ['percent' => 100, 'denied' => [$name]]],
        ]);

        $event = $this->beforeEvent($name, []);
        (new SandboxGuard(['enabled' => false], $gray, fn () => ['user_type' => 'admin', 'user_id' => 7, 'roles' => []]))
            ->handle($event);

        $this->assertTrue($event->skip, '灰度 denied ⇒ skip');
        $result = json_decode($event->mockResult, true);
        $this->assertSame('denied', $result['required_action']);
        $this->assertTrue($result['gray']);
    }

    public function testGrayNotReleasedDegradesToSandboxPreview(): void
    {
        $name = 'fake_write_gray_sandbox_' . uniqid();
        ToolRegistry::instance()->register($this->fakeTool($name, CapabilityAccess::WRITE), $name);

        $gray = new \wise\agent\security\GrayRelease([
            'enabled' => true,
            'rules'   => ['*' => ['percent' => 0]],
        ]);

        $event = $this->beforeEvent($name, []);
        (new SandboxGuard(['enabled' => false], $gray, fn () => ['user_type' => 'admin', 'user_id' => 7, 'roles' => []]))
            ->handle($event);

        $this->assertTrue($event->skip, '未放量 ⇒ 降级为沙箱预演');
        $result = json_decode($event->mockResult, true);
        $this->assertSame('sandbox', $result['required_action'], '不是报错，而是预演');
        $this->assertSame('gray_not_released', $result['sandbox_source']);
        $this->assertTrue($result['gray']);
        $this->assertSame('not_released', $result['gray_reason']);
    }

    public function testGrayAllowedContinuesToSandbox(): void
    {
        $name = 'fake_write_gray_allowed_' . uniqid();
        ToolRegistry::instance()->register($this->fakeTool($name, CapabilityAccess::WRITE), $name);

        $gray = new \wise\agent\security\GrayRelease([
            'enabled' => true,
            'rules'   => ['*' => ['percent' => 100]],
        ]);

        $event = $this->beforeEvent($name, []);
        (new SandboxGuard(['enabled' => false], $gray, fn () => ['user_type' => 'admin', 'user_id' => 7, 'roles' => []]))
            ->handle($event);

        $this->assertFalse($event->skip, 'gray allowed 且 sandbox disabled ⇒ 不拦截');
    }
}
