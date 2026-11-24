<?php
declare(strict_types=1);

namespace wise\agent\test;

use PHPUnit\Framework\TestCase;
use think\Request;
use think\Response;
use wise\agent\menu\MenuActionGuard;
use wise\agent\menu\MenuActionInvoker;
use wise\agent\menu\MenuActionMeta;
use wise\agent\menu\MenuActionParityChecker;
use wise\agent\menu\MenuActionPermissionMap;
use wise\agent\menu\MenuActionPipeline;
use wise\agent\menu\MenuActionPipelineResponse;
use wise\agent\menu\MenuActionResult;
use wise\agent\menu\MenuActionTool;
use wise\agent\security\CapabilityAccess;

/**
 * A1-5 回归测试：人工同款中间件链 + 动作级权限点目录 + 瘦身后的护栏
 *
 * 覆盖：
 *   · `MenuActionPermissionMap`：基路径/动作路由/HTTP 方法（与 `menu_permission.method` 同源）/白名单旁路/存在性
 *   · `MenuActionPipeline`：链装配、类不存在剔除、`$next` 未调用即拦截、fail-closed、
 *     中间件异常、内层返回值带出（供 AuditLog/OperationLog 看到合理响应）
 *   · `MenuActionGuard`（瘦身版）：只保留加严职责（access/risk/confirm/沙箱/预演/annotate），不含任何「允许」逻辑
 *   · `MenuActionTool`：`getPermission()` 默认空串（deny-only）、描述携带人工访问路径、执行委托 invoker
 *
 * 离线约束：中间件容器解析由 `TestablePipeline` 覆写；数据库由覆写接缝注入。
 */
class MenuActionPipelineTest extends TestCase
{
    private const CONTROLLER = 'app\\admin\\controller\\Menu';

    protected function setUp(): void
    {
        MenuActionPermissionMap::resetCache();
    }

    private function meta(string $action, string $path = '/admin/menu/index', array $extra = []): MenuActionMeta
    {
        return MenuActionMeta::forAction(self::CONTROLLER, $action, array_merge([
            'menuPath'  => $path,
            'menuName'  => '菜单管理',
            'menuIdent' => 'admin.menu.index',
        ], $extra));
    }


    public function testBasePathDerivation(): void
    {
        $this->assertSame('/admin/menu', MenuActionPermissionMap::basePathOf('/admin/menu/index'));
        $this->assertSame('/admin/menu', MenuActionPermissionMap::basePathOf('/admin/menu'));
        $this->assertSame('/admin/menu', MenuActionPermissionMap::basePathOf('/admin/menu/delete'));

        $this->assertSame('/plugin/smartstock/product', MenuActionPermissionMap::basePathOf('/plugin/smartstock/product'));
        $this->assertSame('/plugin/smartstock/product', MenuActionPermissionMap::basePathOf('/plugin/smartstock/product/index'));

        $this->assertSame('/admin/plugin', MenuActionPermissionMap::basePathOf('/admin/plugin/configIndex/name/smartstock'));

        $this->assertSame('/admin/market.plugin', MenuActionPermissionMap::basePathOf('/admin/market.plugin/index'));
    }

    public function testActionRouteIsLowercased(): void
    {
        $this->assertSame('/admin/menu/getlist', MenuActionPermissionMap::routeOf('/admin/menu/index', 'getList'));
        $this->assertSame('/admin/menu/delete', MenuActionPermissionMap::routeOf('/admin/menu/index', 'delete'));
        $this->assertSame(
            '/plugin/smartstock/product/forcedelete',
            MenuActionPermissionMap::routeOf('/plugin/smartstock/product', 'forceDelete')
        );
    }

    public function testHttpMethodMatchesMenuPermissionConfig(): void
    {
        $this->assertSame('POST', MenuActionPermissionMap::methodOf('save'));
        $this->assertSame('POST', MenuActionPermissionMap::methodOf('update'));
        $this->assertSame('POST', MenuActionPermissionMap::methodOf('edit'));
        $this->assertSame('DELETE', MenuActionPermissionMap::methodOf('delete'));
        $this->assertSame('DELETE', MenuActionPermissionMap::methodOf('remove'));
        $this->assertSame('GET', MenuActionPermissionMap::methodOf('getList'));
        $this->assertSame('GET', MenuActionPermissionMap::methodOf('index'));
        $this->assertSame('GET', MenuActionPermissionMap::methodOf('status'));
        $this->assertSame('GET', MenuActionPermissionMap::methodOf('restore'));
        $this->assertSame('GET', MenuActionPermissionMap::methodOf('whatever'));
    }

    public function testWhitelistRouteBypass(): void
    {
        $map = new TestablePermissionMap(['whitelist_routes' => ['/admin/login/*', '/admin/index/lockscreen']]);

        $this->assertTrue($map->isBypassedRoute('/admin/login/index'));
        $this->assertTrue($map->isBypassedRoute('/admin/index/lockscreen'));
        $this->assertFalse($map->isBypassedRoute('/admin/menu/delete'));
    }

    public function testUnreachableWhenNoPermissionDataAndNoBypass(): void
    {
        $this->assertNull((new TestablePermissionMap())->hasPermissionPoint('/admin/menu/delete'));
        $this->assertNull((new TestablePermissionMap())->loadRouteMap());
        $this->assertNull((new TestablePermissionMap())->isHumanReachable('/admin/menu/index', 'delete'));

        $bypassed = new TestablePermissionMap(['whitelist_routes' => ['/admin/search/*']]);
        $this->assertTrue($bypassed->isHumanReachable('/admin/search/index', 'getList'));
    }

    public function testPermissionPointLookupFromInjectedMap(): void
    {
        $map = new TestablePermissionMap([], [
            'admin/menu/getlist' => ['ident' => 'admin.menu.getlist', 'method' => 'GET'],
            'admin/menu/delete'  => ['ident' => 'admin.menu.delete', 'method' => 'DELETE'],
        ]);

        $this->assertTrue($map->hasPermissionPoint('/admin/menu/delete'));
        $this->assertTrue($map->hasPermissionPoint('admin/menu/delete'), '前导斜杠无关');
        $this->assertFalse($map->hasPermissionPoint('/admin/menu/sort'));
        $this->assertFalse($map->hasPermissionPoint('/admin/menu/forcedelete'));

        $this->assertSame(
            ['route' => '/admin/menu/delete', 'ident' => 'admin.menu.delete', 'method' => 'DELETE', 'action' => 'delete'],
            $map->describe('/admin/menu/index', 'delete')
        );

        $this->assertTrue($map->isHumanReachable('/admin/menu/index', 'delete'));
        $this->assertFalse($map->isHumanReachable('/admin/menu/index', 'sort'));
    }


    public function testChainPassesAndCarriesResult(): void
    {
        $pipeline = new TestablePipeline(['pipeline' => [PassThroughMiddleware::class]]);

        $outcome = $pipeline->run(new Request(), static fn() => ['code' => 0, 'msg' => 'ok', 'data' => [1]]);

        $this->assertTrue($outcome['passed']);
        $this->assertSame(['code' => 0, 'msg' => 'ok', 'data' => [1]], $outcome['result']);
        $this->assertNull($outcome['blocked_by']);
        $this->assertSame(200, $outcome['response']->getCode());
    }

    public function testChainBlocksWhenMiddlewareDoesNotCallNext(): void
    {
        $pipeline = new TestablePipeline(['pipeline' => [BlockingMiddleware::class, PassThroughMiddleware::class]]);

        $outcome = $pipeline->run(new Request(), static fn() => 'never');

        $this->assertFalse($outcome['passed']);
        $this->assertNull($outcome['result'], '被拦时内层执行体不得被调用');
        $this->assertSame(BlockingMiddleware::class, $outcome['blocked_by']);
        $this->assertSame(403, $outcome['response']->getCode());
    }

    public function testOuterMiddlewareIsReportedAsBlocker(): void
    {
        $pipeline = new TestablePipeline([
            'pipeline' => [BlockingMiddleware::class, AnotherBlockingMiddleware::class],
        ]);

        $outcome = $pipeline->run(new Request(), static fn() => 'never');

        $this->assertFalse($outcome['passed']);
        $this->assertSame(BlockingMiddleware::class, $outcome['blocked_by'], '最外层先命中者为拦截者');
    }

    public function testMissingClassIsDroppedFromChain(): void
    {
        $pipeline = new TestablePipeline([
            'pipeline' => ['App\\NoSuch\\Middleware', PassThroughMiddleware::class],
        ]);

        $outcome = $pipeline->run(new Request(), static fn() => 'ok');

        $this->assertTrue($outcome['passed'], '类不存在 = 该包未安装，从链中剔除而非拦截');
        $steps = implode('|', array_map(static fn(array $s): string => $s['step'], $pipeline->trace()));
        $this->assertStringContainsString('pipeline.skipped', $steps);
    }

    public function testUninstantiableMiddlewareFailsClosed(): void
    {
        $pipeline = new TestablePipeline(['pipeline' => [NeedsHardDependencyMiddleware::class]]);

        $outcome = $pipeline->run(new Request(), static fn() => 'never');

        $this->assertFalse($outcome['passed'], '安全语义缺失时绝不放行');
        $this->assertSame(NeedsHardDependencyMiddleware::class, $outcome['blocked_by']);

        $steps = implode('|', array_map(static fn(array $s): string => $s['step'], $pipeline->trace()));
        $this->assertStringContainsString('pipeline.failClosed', $steps);
    }

    public function testFailClosedCanBeRelaxedExplicitly(): void
    {
        $pipeline = new TestablePipeline([
            'pipeline'             => [NeedsHardDependencyMiddleware::class],
            'pipeline_fail_closed' => false,
        ]);

        $outcome = $pipeline->run(new Request(), static fn() => 'ok');

        $this->assertTrue($outcome['passed'], '显式放宽后跳过该中间件（形成已知缺口）');
    }

    public function testMiddlewareExceptionIsReportedAsError(): void
    {
        $pipeline = new TestablePipeline(['pipeline' => [ThrowingMiddleware::class]]);

        $outcome = $pipeline->run(new Request(), static fn() => 'never');

        $this->assertFalse($outcome['passed']);
        $this->assertInstanceOf(\RuntimeException::class, $outcome['error']);
        $this->assertNull($outcome['blocked_by'], '异常不等于「拦截」，由调用方按执行期异常处理');
    }

    public function testDefaultChainAndExemptionsAreDeclared(): void
    {
        $this->assertContains('wise\\auth\\middleware\\RbacMiddleware', MenuActionPipeline::DEFAULT_CHAIN);
        $this->assertContains('wise\\login\\middleware\\IpGuardMiddleware', MenuActionPipeline::DEFAULT_CHAIN);
        $this->assertContains('wise\\login\\middleware\\TwoFactorMiddleware', MenuActionPipeline::DEFAULT_CHAIN);
        $this->assertContains('app\\common\\middleware\\XssFilterMiddleware', MenuActionPipeline::DEFAULT_CHAIN);
        $this->assertContains('wise\\operationlog\\middleware\\AuditLog', MenuActionPipeline::DEFAULT_CHAIN);
        $this->assertContains('wise\\operationlog\\middleware\\OperationLog', MenuActionPipeline::DEFAULT_CHAIN);

        $this->assertArrayHasKey('app\\common\\middleware\\VerifyCsrfToken', MenuActionPipeline::EXCLUDED_MIDDLEWARE);
        $this->assertArrayHasKey('app\\admin\\middleware\\MenuStaleSync', MenuActionPipeline::EXCLUDED_MIDDLEWARE);
    }


    public function testGuardKeepsOnlyHardeningResponsibilities(): void
    {
        $guard = new TestableGuard();

        foreach (['menuPermissionDecision', 'authorize', 'onToolAuthorize', 'userPermissions'] as $method) {
            $this->assertFalse(method_exists($guard, $method), $method . ' 应立即移除（判定唯一来源是中间件链）');
        }
    }

    public function testGuardAccessRiskAndConfirmation(): void
    {
        $guard = new TestableGuard();

        $this->assertSame(CapabilityAccess::READ, $guard->accessOf($this->meta('getList')));
        $this->assertSame(CapabilityAccess::RISK_LOW, $guard->riskLevelOf($this->meta('getList')));
        $this->assertFalse($guard->requireConfirmation($this->meta('getList')));

        $this->assertSame(CapabilityAccess::ADMIN, $guard->accessOf($this->meta('forceDelete')));
        $this->assertSame(CapabilityAccess::RISK_HIGH, $guard->riskLevelOf($this->meta('forceDelete')));
        $this->assertTrue($guard->requireConfirmation($this->meta('forceDelete')));
        $this->assertTrue($guard->requireConfirmation($this->meta('delete')));
    }

    public function testGuardAiPermissionIsOptInOnly(): void
    {
        $this->assertFalse((new TestableGuard())->requireAiPermission(), '默认不产生人工没有的权限面');
        $this->assertTrue((new TestableGuard(requireAiPermission: true))->requireAiPermission());
    }

    public function testGuardAnnotate(): void
    {
        $guard = new TestableGuard();

        $this->assertTrue($guard->annotate($this->meta('getList'))->enabled);
        $this->assertFalse($guard->annotate($this->meta('index'))->enabled, 'HTML 动作静态不可执行');
    }

    public function testGuardSandbox(): void
    {
        $guard = new TestableGuard();

        $this->assertFalse($guard->isSandboxed($this->meta('getList')), '读动作不受沙箱影响');
        $this->assertTrue($guard->isSandboxed($this->meta('delete')));
        $this->assertFalse((new TestableGuard(readOnly: false))->isSandboxed($this->meta('delete')));
    }

    public function testGuardPreview(): void
    {
        $guard = new TestableGuard();

        $byIds = $guard->preview($this->meta('delete'), ['ids' => [3, 1, 3]]);
        $this->assertSame('dry-run', $byIds['msg']);
        $this->assertTrue($byIds['structured']);
        $this->assertTrue($byIds['data']['blocked']);
        $this->assertSame([3, 1], $byIds['data']['target_ids']);
        $this->assertSame(3, $byIds['data']['would_affect']);
        $this->assertStringContainsString('read_only', $byIds['data']['note']);

        $create = $guard->preview($this->meta('save'), ['name' => '新菜单']);
        $this->assertTrue($create['data']['would_create']);
        $this->assertSame(['name' => '新菜单'], $create['data']['fields']);
    }


    public function testToolPermissionIsEmptyByDefault(): void
    {
        $tool = new MenuActionTool($this->meta('delete'), new TestableGuard(), new RecordingInvoker());
        $this->assertSame('', $tool->getPermission(), '默认不暴露 menu.action.*');

        $strict = new MenuActionTool($this->meta('delete'), new TestableGuard(requireAiPermission: true), new RecordingInvoker());
        $this->assertSame('menu.action.host.menu.delete', $strict->getPermission());
    }

    public function testToolDescriptionCarriesHumanEntryPointAndSandbox(): void
    {
        $tool = new MenuActionTool($this->meta('delete'), new TestableGuard(), new RecordingInvoker());
        $text = $tool->getDescription();

        $this->assertStringContainsString('app\\admin\\controller\\Menu::delete', $text);
        $this->assertStringContainsString('/admin/menu/delete', $text, '给出人工访问路径');
        $this->assertStringContainsString('只读沙箱', $text);
        $this->assertStringContainsString('必须提供 id 或 ids', $text);
        $this->assertStringContainsString('权限与人工操作完全一致', $text);
    }

    public function testToolDelegatesToInvoker(): void
    {
        $invoker = new RecordingInvoker();
        $tool    = new MenuActionTool($this->meta('getList'), new TestableGuard(), $invoker);

        $result = json_decode($tool->execute(['limit' => 5]), true);

        $this->assertSame(1, $invoker->calls);
        $this->assertSame(['limit' => 5], $invoker->lastArguments);
        $this->assertSame(0, $result['code']);
    }

    public function testToolReturnsStructuredFailureOnInvokerThrow(): void
    {
        $tool   = new MenuActionTool($this->meta('getList'), new TestableGuard(), new ThrowingInvoker());
        $result = json_decode($tool->execute([]), true);

        $this->assertSame(1, $result['code']);
        $this->assertSame('boom', $result['msg']);
    }


    /**
     * @param array<string, array{ident:string, method:string}> $rows
     * @param string[]                                          $engineRoutes 引擎认为可达的 route
     */
    private function checker(array $engineRoutes = [], array $chain = ['wise\\auth\\middleware\\RbacMiddleware'], array $options = []): TestableParityChecker
    {
        $map = new TestablePermissionMap([], [
            'admin/menu/index'    => ['ident' => 'admin.menu.index', 'method' => 'GET'],
            'admin/menu/getlist'  => ['ident' => 'admin.menu.getlist', 'method' => 'GET'],
        ]);

        return new TestableParityChecker(
            $options,
            $map,
            new MenuActionInvoker(),
            $engineRoutes,
            $chain,
            [
                $this->meta('getList'),
                $this->meta('delete'),
            ]
        );
    }

    private function toolFor(string $action, string $permission = ''): object
    {
        return new class($action, $permission) {
            public function __construct(private string $action, private string $permission)
            {
            }

            public function getName(): string
            {
                return MenuActionMeta::forAction('app\\admin\\controller\\Menu', $this->action)->getToolName();
            }

            public function getPermission(): string
            {
                return $this->permission;
            }
        };
    }

    public function testParityReportsNoDriftWhenConsistent(): void
    {
        $checker = $this->checker(['/admin/menu/getlist']);
        $report  = $checker->check(null, [$this->toolFor('getList')]);

        $this->assertTrue($report['ok']);
        $this->assertSame([], $report['drifts']);
        $this->assertSame(2, $report['checked']);
        $this->assertSame(1, $report['reachable']);
        $this->assertStringContainsString('一致', $checker->summary($report));
    }

    public function testParityDetectsAmplification(): void
    {
        $checker = $this->checker(['/admin/menu/getlist']);
        $report  = $checker->check(null, [$this->toolFor('getList'), $this->toolFor('delete')]);

        $this->assertFalse($report['ok']);
        $this->assertSame(MenuActionParityChecker::DRIFT_AMPLIFICATION, $report['drifts'][0]['type']);
        $this->assertStringContainsString('发现致命漂移', $checker->summary($report));
    }

    public function testParityDetectsAiPermissionFace(): void
    {
        $checker = $this->checker(['/admin/menu/getlist']);
        $report  = $checker->check(null, [$this->toolFor('getList', 'menu.action.host.menu.getList')]);

        $this->assertFalse($report['ok']);
        $types = array_column($report['drifts'], 'type');
        $this->assertContains(MenuActionParityChecker::DRIFT_AI_FACE, $types);
    }

    public function testParityAllowsDenyOnlyWhenStrictModeEnabled(): void
    {
        $checker = $this->checker(['/admin/menu/getlist'], ['wise\\auth\\middleware\\RbacMiddleware'], ['require_ai_permission' => true]);
        $report  = $checker->check(null, [$this->toolFor('getList', 'menu.action.host.menu.getList')]);

        $types = array_column($report['drifts'], 'type');
        $this->assertNotContains(MenuActionParityChecker::DRIFT_AI_FACE, $types, '显式加严模式下不算漂移');
    }

    public function testParityDetectsMissingPermissionCheckInPipeline(): void
    {
        $checker = $this->checker(['/admin/menu/getlist'], ['wise\\login\\middleware\\IpGuardMiddleware']);
        $report  = $checker->check(null, [$this->toolFor('getList')]);

        $this->assertFalse($report['ok']);
        $this->assertSame(MenuActionParityChecker::DRIFT_PIPELINE, $report['drifts'][0]['type']);
    }

    public function testParityReportsUnregisteredAsHintOnly(): void
    {
        $checker = $this->checker(['/admin/menu/getlist', '/admin/menu/delete']);
        $report  = $checker->check(null, [$this->toolFor('getList')]);

        $types = array_column($report['drifts'], 'type');
        $this->assertContains(MenuActionParityChecker::DRIFT_UNREGISTERED, $types);
        $this->assertTrue($report['ok'], '缩小是允许的，不判致命');
    }

    public function testParitySkipsWhenEngineUnavailable(): void
    {
        $checker = $this->checker([]);
        $report  = $checker->check(null, [$this->toolFor('getList')]);

        $this->assertTrue($report['ok'], '引擎不可用时不误报放大');
    }
}


/**
 * 可注入权限点映射 / 白名单的目录
 */
class TestablePermissionMap extends MenuActionPermissionMap
{
    /** @param array<string, array{ident:string, method:string}> $rows */
    public function __construct(array $options = [], private array $rows = [])
    {
        parent::__construct($options);
    }

    public function loadRouteMap(): ?array
    {
        return $this->rows === [] ? null : $this->rows;
    }
}

/**
 * 可直接 `new` 出中间件（离线无容器）的链路执行器
 */
class TestablePipeline extends MenuActionPipeline
{
    protected function instantiate(string $class): ?object
    {
        try {
            return new $class();
        } catch (\Throwable) {
            return null;
        }
    }
}

class PassThroughMiddleware
{
    public function handle(Request $request, \Closure $next): Response
    {
        return $next($request);
    }
}

class BlockingMiddleware
{
    public function handle(Request $request, \Closure $next): Response
    {
        return new MenuActionPipelineResponse('blocked', 403);
    }
}

class AnotherBlockingMiddleware
{
    public function handle(Request $request, \Closure $next): Response
    {
        return new MenuActionPipelineResponse('blocked-2', 403);
    }
}

class ThrowingMiddleware
{
    public function handle(Request $request, \Closure $next): Response
    {
        throw new \RuntimeException('mw boom');
    }
}

class NeedsHardDependencyMiddleware
{
    public function __construct(private \Closure $mustHave)
    {
    }

    public function handle(Request $request, \Closure $next): Response
    {
        return $next($request);
    }
}

/**
 * 可注入加严开关 / 探测结果的护栏
 */
class TestableGuard extends MenuActionGuard
{
    public function __construct(
        private bool $readOnly = true,
        private bool $requireAiPermission = false,
        array $options = []
    ) {
        parent::__construct(array_merge([
            'read_only'            => $readOnly,
            'require_ai_permission' => $requireAiPermission,
        ], $options));
    }

    protected function probeRows(MenuActionMeta $meta, array $ids, int $limit): array
    {
        return ['total' => 3, 'samples' => [['id' => $ids[0] ?? 1, 'name' => '样本']]];
    }
}

/**
 * 记录调用次数的执行器替身
 */
class RecordingInvoker extends MenuActionInvoker
{
    public int $calls = 0;

    /** @var array<string, mixed> */
    public array $lastArguments = [];

    public function invoke(MenuActionMeta $meta, array $arguments): array
    {
        $this->calls++;
        $this->lastArguments = $arguments;

        return MenuActionResult::normalize(
            ['code' => 0, 'msg' => 'ok', 'data' => ['action' => $meta->action, 'arguments' => $arguments]],
            ['controller' => $meta->controller, 'action' => $meta->action]
        );
    }
}

class ThrowingInvoker extends MenuActionInvoker
{
    public function invoke(MenuActionMeta $meta, array $arguments): array
    {
        throw new \RuntimeException('boom');
    }
}

/**
 * 可注入「权限引擎判定 / 中间件链 / 内省结果」的对拍校验器
 */
class TestableParityChecker extends MenuActionParityChecker
{
    /** @param string[] $engineRoutes 引擎认为可达的路由（空数组 = 引擎不可用） */
    public function __construct(
        array $options,
        MenuActionPermissionMap $map,
        MenuActionInvoker $invoker,
        private array $engineRoutes,
        private array $chain,
        private array $metas
    ) {
        parent::__construct($options, $map, $invoker);
    }

    protected function routeIdents(string $route, string $method): ?array
    {
        if ($this->engineRoutes === []) {
            return null;
        }

        return in_array($route, $this->engineRoutes, true) ? ['ident-of-' . ltrim($route, '/')] : [];
    }

    protected function pipelineChain(): array
    {
        return $this->chain;
    }

    protected function introspect(): array
    {
        return $this->metas;
    }
}
