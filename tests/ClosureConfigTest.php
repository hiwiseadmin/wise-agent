<?php
declare(strict_types=1);

namespace wise\agent\test;

use PHPUnit\Framework\TestCase;
use wise\agent\closure\ClosureEvent;
use wise\agent\closure\connector\AuditConnector;
use wise\agent\closure\ClosureDispatcher;
use wise\agent\closure\ClosureEventFactory;
use wise\agent\closure\ClosureRouter;

require_once __DIR__ . '/support/WiseAgentConfigLoader.php';

/**
 * B2-4 守门：出厂配置的**安全默认值**与**键名一致性**
 *
 * 这类测试的价值在 B2-4 实测过：B2-1 的存储读 `closure.tables.*`（复数），
 * 而配置节写的是 `closure.table.*`（单数）—— 两边对不上时会**静默退回代码默认表名**，
 * 「配置改了却不生效」这种问题在运行期极难发现，静态断言能一次拦住。
 */
class ClosureConfigTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function config(): array
    {
        return \wise_agent_test_config()['closure'] ?? [];
    }

    public function testShippedDefaultsAreConservative(): void
    {
        $config = $this->config();

        $this->assertFalse($config['enabled'] ?? true, '总开关默认关闭');
        $this->assertFalse($config['auto_resume_plans'] ?? true, '批准后自动续跑默认关（它是行为改变）');
        $this->assertTrue($config['audit_write_only'] ?? false, '审计默认只覆盖写/未知能力（避免为每次列表查询写一行）');
        $this->assertTrue($config['only_write_capabilities'] ?? false, '读操作默认不触发通知/webhook');
        $this->assertFalse($config['notification']['takeover'] ?? true, '默认不接管通知（避免与内置站内信双发）');
        $this->assertFalse($config['notification']['sync'] ?? true, '默认交给 wise-notification 的队列与重试');
        $this->assertSame('storage', $config['audit']['via'] ?? null, '审计默认直存（常驻 worker 里事件缓冲不会 flush）');
        $this->assertSame('queue', $config['webhook']['dispatch'] ?? null, 'webhook 默认入队（同步等外部 HTTP 会拖慢主链路）');
        $this->assertSame([], $config['webhook']['endpoints'] ?? null, '默认没有外部端点 ⇒ 一律 skipped（URL 只能来自配置）');
        $this->assertSame([], $config['mask_fields'] ?? null, '空 ⇒ 用 PayloadMasker::DEFAULT_FIELDS');
        $this->assertSame('ai.closure', $config['audit']['channel'] ?? null);
        $this->assertSame(AuditConnector::DEFAULT_CHANNEL, $config['audit']['channel'] ?? null, '配置值与代码常量同源');
    }

    /**
     * 去重默认值必须覆盖「ApprovalManager 会自己发站内信」的两个场景
     */
    public function testShippedDedupDefaultsCoverApprovalScenes(): void
    {
        $skip = $this->config()['notification']['skip_channels_by_scene'] ?? [];

        foreach ([ClosureEvent::SCENE_APPROVAL_REQUESTED, ClosureEvent::SCENE_APPROVAL_DECIDED] as $scene) {
            $this->assertContains('database', (array) ($skip[$scene] ?? []), $scene . ' 默认跳过站内信（内置通知无开关）');
        }
    }

    /**
     * 配置键必须与代码读的键一致（表名、场景路由、连接器清单）
     */
    public function testConfigKeysMatchWhatTheCodeReads(): void
    {
        $config = $this->config();

        $table = $config['table'] ?? [];

        foreach (['events', 'deliveries', 'runs', 'steps'] as $key) {
            $this->assertNotEmpty($table[$key] ?? '', 'closure.table.' . $key . ' 必须存在（否则会静默退回代码默认表名）');
        }

        $this->assertSame('ai_closure_events', $table['events']);
        $this->assertSame('ai_closure_deliveries', $table['deliveries']);
        $this->assertSame('ai_plan_runs', $table['runs'], '与 B1 的计划表名一致');
        $this->assertSame('ai_plan_steps', $table['steps']);

        $this->assertIsArray($config['connectors'] ?? null);
        $this->assertIsArray($config['scenes'] ?? null);
        $this->assertIsArray($config['audit_scenes'] ?? null);

        foreach (ClosureEvent::SCENES as $scene) {
            $this->assertArrayHasKey($scene, $config['scenes'], '默认场景路由必须覆盖 ' . $scene);
        }

        $router = new ClosureRouter($config);
        $probe  = new ClosureEvent(
            traceId: 't',
            scene: ClosureEvent::SCENE_APPROVAL_DECIDED,
            capabilityKind: 'tool',
            capabilityName: 'x',
            access: 'write',
            risk: 'high',
            write: true,
            result: ClosureEvent::RESULT_APPROVED
        );

        $this->assertSame(['notification', 'webhook', 'audit'], $router->connectorsFor($probe));
    }

    /**
     * **代码侧**真的读 `closure.table.*`（单数）—— 光断言配置里有这些键是不够的
     *
     * B2-4 实测到的 bug 正是「配置写 `table.*`、代码读 `tables.*`」：
     * 那种情况下配置守门测试**依然会通过**（配置侧没错），只有断言"代码实际用了哪个键"才能拦住。
     * 因此这里用反射直接问存储实现：「给定这份配置，你落的表叫什么？」
     */
    public function testStoreActuallyReadsTheSingularTableKeys(): void
    {
        $store = new \wise\agent\closure\store\DbClosureTraceStore($this->config());

        $this->assertSame('ai_closure_events', $this->tableOf($store, 'tableEvents'));
        $this->assertSame('ai_closure_deliveries', $this->tableOf($store, 'tableDeliveries'));

        $custom = new \wise\agent\closure\store\DbClosureTraceStore([
            'table' => ['events' => 'my_closure_events', 'deliveries' => 'my_closure_deliveries'],
        ]);

        $this->assertSame('my_closure_events', $this->tableOf($custom, 'tableEvents'));
        $this->assertSame('my_closure_deliveries', $this->tableOf($custom, 'tableDeliveries'));

        $link = new \wise\agent\closure\store\DbClosurePlanLink([
            'table' => ['runs' => 'my_runs', 'steps' => 'my_steps'],
        ]);

        $this->assertSame('my_runs', $this->tableOf($link, 'tableRuns'));
        $this->assertSame('my_steps', $this->tableOf($link, 'tableSteps'));
    }

    private function tableOf(object $instance, string $method): string
    {
        $reflection = new \ReflectionMethod($instance, $method);
        $reflection->setAccessible(true);

        return (string) $reflection->invoke($instance);
    }

    /**
     * 监听器与派发器读的是同一份配置项（`enabled` / `timeout_ms` / `max_payload_chars`）
     */
    public function testDispatcherAndFactoryReadTheSameKeys(): void
    {
        $config = $this->config();

        $this->assertArrayHasKey('timeout_ms', $config);
        $this->assertArrayHasKey('max_payload_chars', $config);
        $this->assertArrayHasKey('mask_depth', $config);
        $this->assertArrayHasKey('retain_days', $config);

        $this->assertInstanceOf(ClosureDispatcher::class, new ClosureDispatcher($config));
        $this->assertInstanceOf(ClosureEventFactory::class, new ClosureEventFactory($config));
    }
}
