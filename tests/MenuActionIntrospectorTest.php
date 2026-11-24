<?php
declare(strict_types=1);

namespace wise\agent\test;

use PHPUnit\Framework\TestCase;
use wise\agent\framework\MenuActionIntrospector as FrameworkMenuActionIntrospector;
use wise\agent\menu\MenuActionIntrospector;
use wise\agent\menu\MenuActionMeta;
use wise\agent\menu\MenuActionSchemaResolver;

/**
 * A1-2 回归测试：菜单 path → 控制器动作内省（含权限锚点）
 *
 * 覆盖：
 *   · parsePath 纯函数：admin / plugin / 点分控制器 / 参数化路径 / 非法输入
 *   · 动作枚举：真实 CrudAction 控制器（离线反射）+ 「重写」判定 + 非 CRUD 控制器排除
 *   · 权限锚点选举：非参数化 > type=menu > 段数 > sort > id
 *   · 权限标识：与 MenuPermissionBridge 的 `path_to_dot` 同源回落
 *   · 范围过滤：allow / deny / extra_controllers / extra_actions
 *   · 缓存 round-trip 与失效标记
 *
 * 约束：测试不引导 ThinkPHP 应用（`app()` / `config()` 均不可用），
 * DB 与容器交互由 `FakeMenuIntrospector` 覆写为注入实现，保证离线可断言。
 */
class MenuActionIntrospectorTest extends TestCase
{
    protected function setUp(): void
    {
        MenuActionIntrospector::resetMemoryCache();
        MenuActionSchemaResolver::clearCache();
    }


    public function testParseAdminPaths(): void
    {
        $parsed = MenuActionIntrospector::parsePath('/admin/menu/index');

        $this->assertNotNull($parsed);
        $this->assertSame('app\\admin\\controller\\Menu', $parsed['controller']);
        $this->assertSame('menu', $parsed['controller_path']);
        $this->assertSame('index', $parsed['action']);
        $this->assertSame(MenuActionMeta::SOURCE_HOST, $parsed['source']);
        $this->assertSame('host', $parsed['ident']);
        $this->assertFalse($parsed['parameterized']);
        $this->assertSame([], $parsed['extras']);
    }

    public function testParseAdminShortPathUsesDefaultAction(): void
    {
        $parsed = MenuActionIntrospector::parsePath('/admin/menu', 'index');

        $this->assertNotNull($parsed);
        $this->assertSame('app\\admin\\controller\\Menu', $parsed['controller']);
        $this->assertSame('index', $parsed['action']);

        $custom = MenuActionIntrospector::parsePath('/admin/menu', 'list');
        $this->assertNotNull($custom);
        $this->assertSame('list', $custom['action'], '默认动作应由调用方注入（宿主 route.default_action）');
    }

    public function testParseParameterizedAdminPath(): void
    {
        $parsed = MenuActionIntrospector::parsePath('/admin/plugin/configIndex/name/smartstock');

        $this->assertNotNull($parsed);
        $this->assertSame('app\\admin\\controller\\Plugin', $parsed['controller']);
        $this->assertSame('configIndex', $parsed['action']);
        $this->assertTrue($parsed['parameterized']);
        $this->assertSame(['name' => 'smartstock'], $parsed['extras']);
    }

    public function testParsePluginPaths(): void
    {
        $short = MenuActionIntrospector::parsePath('/plugin/smartstock/product');
        $this->assertNotNull($short);
        $this->assertSame('plugin\\smartstock\\controller\\Product', $short['controller']);
        $this->assertSame('index', $short['action']);
        $this->assertSame('plugin:smartstock', $short['source']);
        $this->assertSame('smartstock', $short['ident']);
        $this->assertFalse($short['parameterized']);

        $long = MenuActionIntrospector::parsePath('/plugin/smartstock/product/delete');
        $this->assertNotNull($long);
        $this->assertSame('delete', $long['action']);
        $this->assertFalse($long['parameterized']);

        $snake = MenuActionIntrospector::parsePath('/plugin/smartstock/product_category');
        $this->assertNotNull($snake);
        $this->assertSame('plugin\\smartstock\\controller\\ProductCategory', $snake['controller']);
    }

    public function testParsePluginPathWithExtraParams(): void
    {
        $parsed = MenuActionIntrospector::parsePath('/plugin/smartstock/product/detail/type/index');

        $this->assertNotNull($parsed);
        $this->assertSame('detail', $parsed['action'], '第 4 段是动作，之后才是参数');
        $this->assertTrue($parsed['parameterized']);
        $this->assertSame(['type' => 'index'], $parsed['extras']);
    }

    public function testParseDottedControllerAsLayer(): void
    {
        $parsed = MenuActionIntrospector::parsePath('/admin/market.plugin/index');

        $this->assertNotNull($parsed);
        $this->assertSame('app\\admin\\controller\\Market\\Plugin', $parsed['controller'], '点分控制器 → 分层命名空间');
        $this->assertSame('index', $parsed['action']);
    }

    public function testParsePathRejectsInvalidInput(): void
    {
        $cases = [
            ''                              => '空 path',
            '/'                             => '仅斜杠',
            'admin'                         => 'admin 段不足',
            '/admin'                        => '无控制器段',
            '/plugin/smartstock'            => 'plugin 段不足',
            '/plugin//product'              => 'ident 为空',
            '/plugin/demo-sandbox/product'  => '含 `-` 的 ident 不是合法命名空间',
            '/foo/bar/index'                => '未知前缀',
            '/admin/menu/1status'           => '动作段非法（以数字开头）',
        ];

        foreach ($cases as $path => $label) {
            $this->assertNull(MenuActionIntrospector::parsePath((string) $path), $label . '：' . $path);
        }
    }


    public function testControllerActionsOnRealCrudController(): void
    {
        $introspector = new FakeMenuIntrospector([]);
        $actions      = $introspector->controllerActions('app\\admin\\controller\\Menu');

        $this->assertCount(12, $actions);

        foreach (MenuActionMeta::EXPOSED_ACTIONS as $action) {
            $this->assertArrayHasKey($action, $actions);
        }
        foreach (['index', 'add', 'edit', 'preview'] as $action) {
            $this->assertArrayHasKey($action, $actions);
        }

        $this->assertArrayNotHasKey('formatPaginate', $actions);
        $this->assertArrayNotHasKey('sync', $actions);
        $this->assertArrayNotHasKey('getModel', $actions);
    }

    public function testCustomizedFlagDetectsControllerOverride(): void
    {
        $introspector = new FakeMenuIntrospector([]);
        $actions      = $introspector->controllerActions('app\\admin\\controller\\Menu');

        foreach (['getList', 'index', 'add', 'edit', 'preview', 'delete', 'forceDelete', 'status', 'sort'] as $action) {
            $this->assertTrue($actions[$action]['customized'], $action . ' 由控制器 public 方法承载');
        }
    }

    /**
     * fail-closed：未声明 public 入口的动作，人工与 AI 都不应看到
     */
    public function testActionsWithoutPublicEntryAreNotExposed(): void
    {
        $introspector = new FakeMenuIntrospector([]);
        $actions      = $introspector->controllerActions('app\\admin\\controller\\LoginLog');

        $this->assertArrayHasKey('getList', $actions);
        $this->assertArrayHasKey('index', $actions);

        $this->assertArrayNotHasKey('save', $actions, '只读页未声明 public save()');
        $this->assertArrayNotHasKey('delete', $actions);
        $this->assertArrayNotHasKey('status', $actions);
    }

    public function testNonCrudControllersAreExcluded(): void
    {
        $introspector = new FakeMenuIntrospector([]);

        $this->assertFalse($introspector->isCrudController('plugin\\smartstock\\controller\\Warehouse'));
        $this->assertSame([], $introspector->controllerActions('plugin\\smartstock\\controller\\Warehouse'));

        $this->assertFalse($introspector->isCrudController('App\\Nope\\NotAController'));
        $this->assertSame([], $introspector->controllerActions('App\\Nope\\NotAController'));
    }


    public function testIntrospectBuildsMetasWithPermissionAnchor(): void
    {
        $introspector = new FakeMenuIntrospector([
            ['id' => 1, 'name' => '菜单管理', 'path' => '/admin/menu/index', 'type' => 'menu', 'sort' => 1, 'status' => 1],
            ['id' => 2, 'name' => '同步菜单', 'path' => '/admin/menu/sync', 'type' => 'button', 'sort' => 2, 'status' => 1],
        ]);

        $metas = $introspector->introspect(false);

        $this->assertCount(12, $metas);
        $this->assertArrayHasKey('app\\admin\\controller\\Menu::getList', $metas);

        $meta = $metas['app\\admin\\controller\\Menu::getList'];

        $this->assertSame('/admin/menu/index', $meta->menuPath, '锚点应选非按钮的菜单型记录');
        $this->assertSame('菜单管理', $meta->menuName);
        $this->assertSame('admin.menu.index', $meta->menuIdent);
        $this->assertSame('menu.action.host.menu.getList', $meta->getPermission());
        $this->assertSame('ma_host_menu_getlist', $meta->getToolName());
        $this->assertTrue($meta->customized, 'Menu::getList 被重写');
        $this->assertTrue(MenuActionIntrospector::IDENT_FALLBACK === $introspector->identSource());
    }

    public function testPermissionIdentMatchesMenuPermissionBridge(): void
    {
        $this->assertSame('admin.menu.index', MenuActionIntrospector::fallbackIdent('/admin/menu/index'));
        $this->assertSame('plugin.smartstock.product', MenuActionIntrospector::fallbackIdent('/plugin/smartstock/product'));
        $this->assertSame('plugin.smartstock.alert.rule_index', MenuActionIntrospector::fallbackIdent('/plugin/smartstock/alert/ruleIndex'));
        $this->assertSame('admin.plugin.config_index.name.smartstock', MenuActionIntrospector::fallbackIdent('/admin/plugin/configIndex/name/smartstock'));
    }

    public function testAnchorPrefersNonParameterizedOverType(): void
    {
        $introspector = new FakeMenuIntrospector([
            ['id' => 1, 'name' => '参数化', 'path' => '/admin/menu/status/1', 'type' => 'menu', 'sort' => 1],
            ['id' => 2, 'name' => '按钮', 'path' => '/admin/menu/delete', 'type' => 'button', 'sort' => 99],
        ]);

        $metas = $introspector->introspect(false);

        $this->assertSame('按钮', $metas['app\\admin\\controller\\Menu::getList']->menuName, '非参数化优先于类型');
    }

    public function testAnchorPrefersMenuTypeWhenBothNonParameterized(): void
    {
        $introspector = new FakeMenuIntrospector([
            ['id' => 1, 'name' => '按钮', 'path' => '/admin/menu/delete', 'type' => 'button', 'sort' => 1],
            ['id' => 2, 'name' => '菜单', 'path' => '/admin/menu/index', 'type' => 'menu', 'sort' => 9],
        ]);

        $metas = $introspector->introspect(false);

        $this->assertSame('菜单', $metas['app\\admin\\controller\\Menu::getList']->menuName);
        $this->assertSame('/admin/menu/index', $metas['app\\admin\\controller\\Menu::getList']->menuPath);
    }

    public function testIntrospectSkipsUnparsableAndNonCrudMenus(): void
    {
        $introspector = new FakeMenuIntrospector([
            ['id' => 1, 'name' => '手写列表', 'path' => '/plugin/smartstock/product', 'type' => 'menu', 'sort' => 1],
            ['id' => 2, 'name' => '非法路径', 'path' => '/foo/bar/baz', 'type' => 'menu', 'sort' => 2],
            ['id' => 3, 'name' => '空路径', 'path' => '', 'type' => 'dir', 'sort' => 3],
            ['id' => 4, 'name' => '菜单管理', 'path' => '/admin/menu/index', 'type' => 'menu', 'sort' => 4],
        ]);

        $metas = $introspector->introspect(false);

        $controllers = array_unique(array_map(static fn(MenuActionMeta $m): string => $m->controller, array_values($metas)));
        $this->assertSame(['app\\admin\\controller\\Menu'], array_values($controllers));

        $steps = implode('|', array_map(static fn(array $s): string => $s['step'], $introspector->trace()));
        $this->assertStringContainsString('path.skipped', $steps);
        $this->assertStringContainsString('controller.skipped', $steps);
    }

    public function testControllerScopeFilters(): void
    {
        $rows = [
            ['id' => 1, 'name' => '菜单管理', 'path' => '/admin/menu/index', 'type' => 'menu', 'sort' => 1],
            ['id' => 2, 'name' => '权限管理', 'path' => '/admin/permission/index', 'type' => 'menu', 'sort' => 2],
        ];

        $denied = (new FakeMenuIntrospector($rows, ['deny_controllers' => ['*permission*']]))->introspect(false);
        $this->assertCount(12, $denied);
        $this->assertArrayHasKey('app\\admin\\controller\\Menu::getList', $denied);
        $this->assertArrayNotHasKey('app\\admin\\controller\\Permission::getList', $denied);

        $allowed = (new FakeMenuIntrospector($rows, ['allow_controllers' => ['app\\admin\\controller\\Permission']]))->introspect(false);
        $this->assertCount(11, $allowed);
        $this->assertArrayNotHasKey('app\\admin\\controller\\Menu::getList', $allowed);
        $this->assertArrayHasKey('app\\admin\\controller\\Permission::getList', $allowed);
    }

    public function testUnmappedControllerRequiresExplicitOptIn(): void
    {
        $class = 'app\\admin\\controller\\Menu';

        $off = (new FakeMenuIntrospector([], ['extra_controllers' => [$class]]))->introspect(false);
        $this->assertSame([], $off, '无菜单锚点默认不暴露');

        $on = (new FakeMenuIntrospector([], [
            'extra_controllers'           => [$class],
            'allow_unmapped_controllers'  => true,
        ]))->introspect(false);

        $this->assertCount(12, $on);
        $meta = $on[$class . '::getList'];
        $this->assertSame('', $meta->menuPath);
        $this->assertSame('', $meta->menuIdent, '无锚点 → 无菜单权限标识（A1-4 需回落到 AI 能力权限层）');
        $this->assertSame('menu.action.host.menu.getList', $meta->getPermission());
    }

    public function testExtraActionsWhitelist(): void
    {
        $class = 'app\\admin\\controller\\Menu';

        $base = (new FakeMenuIntrospector([]))->controllerActions($class);
        $this->assertArrayNotHasKey('sync', $base);

        $withExtra = (new FakeMenuIntrospector([], ['extra_actions' => [$class . '::sync']]))->controllerActions($class);
        $this->assertArrayHasKey('sync', $withExtra);
        $this->assertTrue($withExtra['sync']['customized']);
    }

    public function testParametersFilledWhenRequested(): void
    {
        $rows = [['id' => 1, 'name' => '菜单管理', 'path' => '/admin/menu/index', 'type' => 'menu', 'sort' => 1]];

        $withParams = (new FakeMenuIntrospector($rows))->introspect(true);
        $getList    = $withParams['app\\admin\\controller\\Menu::getList'];

        $this->assertSame('object', $getList->parameters['type'] ?? null);
        $this->assertArrayHasKey('page', $getList->parameters['properties']);

        $delete = $withParams['app\\admin\\controller\\Menu::delete'];
        $this->assertSame(['id', 'ids'], array_keys($delete->parameters['properties']));

        $withoutParams = (new FakeMenuIntrospector($rows))->introspect(false);
        $this->assertSame([], $withoutParams['app\\admin\\controller\\Menu::getList']->parameters);
    }


    public function testCacheRoundTripPreservesIdentity(): void
    {
        $introspector = new FakeMenuIntrospector([
            ['id' => 1, 'name' => '菜单管理', 'path' => '/admin/menu/index', 'type' => 'menu', 'sort' => 1],
        ]);

        $metas = $introspector->introspect(true);
        $list  = MenuActionIntrospector::toArrayList($metas);
        $back  = MenuActionIntrospector::fromArrayList($list);

        $this->assertCount(count($metas), $back);
        $this->assertSame(array_keys($metas), array_keys($back));

        $original = $metas['app\\admin\\controller\\Menu::getList'];
        $restored = $back['app\\admin\\controller\\Menu::getList'];

        $this->assertSame($original->getPermission(), $restored->getPermission());
        $this->assertSame($original->getToolName(), $restored->getToolName());
        $this->assertSame($original->menuPath, $restored->menuPath);
        $this->assertSame($original->menuIdent, $restored->menuIdent);
        $this->assertSame($original->access, $restored->access);
        $this->assertSame($original->risk, $restored->risk);
        $this->assertSame($original->customized, $restored->customized);
        $this->assertSame($original->parameters, $restored->parameters);
    }

    public function testFromArrayListSkipsInvalidEntries(): void
    {
        $back = MenuActionIntrospector::fromArrayList([
            'not-an-array',
            ['action' => 'getList'],
            ['controller' => 'app\\admin\\controller\\Menu'],
            ['controller' => 'app\\admin\\controller\\Menu', 'action' => 'getList'],
        ]);

        $this->assertCount(1, $back);
        $this->assertArrayHasKey('app\\admin\\controller\\Menu::getList', $back);
    }

    public function testMemoryCacheIsReusedUnlessForced(): void
    {
        $rows = [['id' => 1, 'name' => '菜单管理', 'path' => '/admin/menu/index', 'type' => 'menu', 'sort' => 1]];

        $first  = (new FakeMenuIntrospector($rows))->resolve(false);
        $second = (new FakeMenuIntrospector($rows))->resolve(false);

        $this->assertSame(
            array_keys($first),
            array_keys($second),
            '进程内缓存命中时不再重新内省'
        );

        MenuActionIntrospector::resetMemoryCache();
    }


    public function testFrameworkIntrospectorExposesDelegation(): void
    {
        $this->assertTrue(method_exists(FrameworkMenuActionIntrospector::class, 'introspectActions'));
        $this->assertTrue(method_exists(FrameworkMenuActionIntrospector::class, 'registerTools'));

        $this->assertSame([], (new FrameworkMenuActionIntrospector())->introspectActions());
    }
}


/**
 * 离线可测的内省器：菜单行、权限标识、容器可用性全部可控
 */
class FakeMenuIntrospector extends MenuActionIntrospector
{
    /** @param array<int, array<string, mixed>> $rows */
    public function __construct(private array $rows, array $options = [])
    {
        parent::__construct($options, null);
    }

    protected function loadMenuRows(): array
    {
        return $this->rows;
    }

    protected function identOf(string $path): string
    {
        return MenuActionIntrospector::fallbackIdent($path);
    }

    protected function useContainer(): bool
    {
        return false;
    }
}
