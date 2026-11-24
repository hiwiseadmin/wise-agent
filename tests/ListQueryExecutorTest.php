<?php
declare(strict_types=1);

namespace wise\agent\test;

use PHPUnit\Framework\TestCase;
use think\Request;
use wise\agent\menu\MenuActionIntrospector;
use wise\agent\menu\MenuActionMeta;
use wise\agent\menu\MenuActionPipeline;
use wise\agent\menu\MenuActionPipelineResponse;
use wise\agent\query\ListQueryTarget;
use wise\agent\query\ListQueryTargetResolver;
use wise\agent\query\ListQueryTool;
use wise\agent\query\executor\ListQueryExecutorInterface;
use wise\agent\query\executor\ListQueryExecutorResolver;
use wise\agent\query\executor\MenuActionListQueryExecutor;
use wise\agent\query\executor\StandaloneListQueryExecutor;
use wise\agent\tool\ToolRegistry;
use think\Response;

/**
 * A3-4 回归：执行路径选择 + 独立执行兜底 + 筛选能力三态（附录三 验收 3、12、13）
 *
 * 全部离线：中间件链用测试替身，取数由 `protected` 接缝覆写。
 */
class ListQueryExecutorTest extends TestCase
{
    private const TARGET = 'host.demo';
    private const CONTROLLER = 'app\\admin\\controller\\Demo';

    /** 供测试替身引用（private 常量不能被外部类读取） */
    public const CONTROLLER_FQCN = 'app\\admin\\controller\\Demo';

    protected function setUp(): void
    {
        ListQueryTargetResolver::resetCache();
    }

    private function target(string $mode = ListQueryTarget::MODE_AUTO, string $filterSupport = ListQueryTarget::FILTER_SUPPORTED): ListQueryTarget
    {
        return new ListQueryTarget(self::TARGET, self::CONTROLLER, $mode, 'search', '/admin/demo/index', '演示列表', [], [], 'getList', [], $filterSupport);
    }


    public function testModelSearchAlwaysUsesStandalone(): void
    {
        $resolver = new ListQueryExecutorResolver([], null, null, new KnowingIntrospector());

        $this->assertSame(
            ListQueryExecutorResolver::PATH_STANDALONE,
            $resolver->pathOf($this->target(ListQueryTarget::MODE_MODEL_SEARCH))
        );
    }

    public function testUsesMenuActionWhenA1KnowsTheAction(): void
    {
        $resolver = new ListQueryExecutorResolver(['menu_actions_enabled' => true], null, null, new KnowingIntrospector());

        $this->assertSame(ListQueryExecutorResolver::PATH_MENU_ACTION, $resolver->pathOf($this->target()));
    }

    public function testFallsBackToStandaloneWhenA1Unavailable(): void
    {
        $resolver = new ListQueryExecutorResolver(['menu_actions_enabled' => true], null, null, new EmptyIntrospector());

        $this->assertSame(ListQueryExecutorResolver::PATH_STANDALONE, $resolver->pathOf($this->target()));
    }

    public function testStandaloneFallbackCanBeDisabled(): void
    {
        $resolver = new ListQueryExecutorResolver(
            ['menu_actions_enabled' => true, 'standalone_fallback' => false],
            null,
            null,
            new EmptyIntrospector()
        );

        $this->assertSame(ListQueryExecutorResolver::PATH_MENU_ACTION, $resolver->pathOf($this->target()));
    }

    public function testResolverDelegatesToChosenExecutor(): void
    {
        $menuAction = new RecordingExecutor('menu_action');
        $standalone = new RecordingExecutor('standalone');

        $resolver = new ListQueryExecutorResolver(
            ['menu_actions_enabled' => true],
            $menuAction,
            $standalone,
            new KnowingIntrospector()
        );

        $this->assertSame('menu_action', $resolver->execute($this->target(), [])['executor']);
        $this->assertSame('standalone', $resolver->execute($this->target(ListQueryTarget::MODE_MODEL_SEARCH), [])['executor']);
    }


    public function testStandaloneRunsFetchInsidePipelineAndNormalizes(): void
    {
        $executor = new RecordingStandaloneExecutor(
            [],
            new InstantiablePipeline(['pipeline' => [QueryPassthroughMiddleware::class]])
        );

        $result = $executor->execute($this->target(ListQueryTarget::MODE_MODEL_SEARCH), ['limit' => 20]);

        $this->assertSame([['limit' => 20]], $executor->fetched, json_encode($result, JSON_UNESCAPED_UNICODE));

        $this->assertSame(0, $result['code']);
        $this->assertTrue($result['structured']);
        $this->assertSame(2, $result['data']['total']);
        $this->assertSame('standalone', $result['executor']);
        $this->assertSame(ListQueryTarget::MODE_MODEL_SEARCH, $result['mode']);
    }

    public function testStandaloneRejectsWhenPipelineBlocksWithoutFetching(): void
    {
        $executor = new RecordingStandaloneExecutor(
            [],
            new InstantiablePipeline(['pipeline' => [BlockingQueryMiddleware::class]])
        );

        $result = $executor->execute($this->target(), ['limit' => 20]);

        $this->assertSame(403, $result['code']);
        $this->assertSame([], $executor->fetched, '被拦截时不得取数');
        $this->assertSame(BlockingQueryMiddleware::class, $result['blocked_by']);
        $this->assertSame('guard', $result['stage']);
        $this->assertSame('standalone', $result['executor']);
        $this->assertStringContainsString('/admin/demo/getlist', $result['msg'], '拒绝信息给出人工访问路径');
    }

    public function testStandaloneFailClosedWhenMiddlewareMissing(): void
    {
        $executor = new RecordingStandaloneExecutor(
            [],
            new MenuActionPipeline(['pipeline' => ['wise\\agent\\test\\NotInstalledMiddleware']])
        );

        $result = $executor->execute($this->target(ListQueryTarget::MODE_MODEL_SEARCH), []);

        $this->assertSame(0, $result['code']);
        $this->assertCount(1, $executor->fetched);
    }


    public function testFilterSupportParsing(): void
    {
        $supported = ListQueryTarget::fromArray('host.a', ['controller' => self::CONTROLLER]);
        $declared  = ListQueryTarget::fromArray('host.b', ['controller' => self::CONTROLLER, 'supports_filters' => false]);
        $unknown   = ListQueryTarget::fromArray('host.c', ['controller' => self::CONTROLLER, 'filter_support' => 'unknown']);

        $this->assertSame(ListQueryTarget::FILTER_SUPPORTED, $supported->filterSupport, '手工声明默认支持');
        $this->assertTrue($supported->supportsFilters());

        $this->assertSame(ListQueryTarget::FILTER_UNSUPPORTED, $declared->filterSupport);
        $this->assertFalse($declared->supportsFilters());

        $this->assertSame(ListQueryTarget::FILTER_UNKNOWN, $unknown->filterSupport);
        $this->assertFalse($unknown->supportsFilters(), 'unknown 一律不放行（否则可能静默返回全量）');
        $this->assertFalse($unknown->filterSupportKnown());
    }

    public function testResolverDetectsFilterSupportStates(): void
    {
        $this->assertSame(
            ListQueryTarget::FILTER_SUPPORTED,
            (new ExposedTargetResolver('', []))->detect(false)
        );

        $this->assertSame(
            ListQueryTarget::FILTER_UNSUPPORTED,
            (new ExposedTargetResolver('return $this->success("ok", MenuModel::order("id")->select()->toArray());', []))->detect(true)
        );

        $this->assertSame(
            ListQueryTarget::FILTER_UNKNOWN,
            (new ExposedTargetResolver('$this->buildQueryParams(); return $this->success();', ['customized_getlist_policy' => 'strict']))->detect(true)
        );

        $this->assertSame(
            ListQueryTarget::FILTER_SUPPORTED,
            (new ExposedTargetResolver('$this->buildQueryParams();', ['customized_getlist_policy' => 'trust']))->detect(true)
        );

        $this->assertSame(
            ListQueryTarget::FILTER_UNKNOWN,
            (new ExposedTargetResolver(null, []))->detect(true)
        );
    }

    public function testToolRejectsFiltersWhenSupportUnknown(): void
    {
        $target = $this->target(ListQueryTarget::MODE_AUTO, ListQueryTarget::FILTER_UNKNOWN);

        $tool = new ListQueryTool([], new SingleTargetResolver($target), new RecordingExecutor('none'));
        $result = json_decode($tool->execute([
            'target'  => self::TARGET,
            'filters' => [['field' => 'status', 'op' => 'eq', 'value' => 1]],
        ]), true);

        $this->assertSame(1, $result['code']);
        $this->assertSame(ListQueryTarget::FILTER_UNKNOWN, $result['filter_support']);
        $this->assertStringContainsString('无法确认', $result['msg']);
        $this->assertStringContainsString('supports_filters', $result['hint'], '给出可执行的修复指引');
    }

    public function testToolAllowsPagingOnUnknownSupportTarget(): void
    {
        $target = $this->target(ListQueryTarget::MODE_AUTO, ListQueryTarget::FILTER_UNKNOWN);
        $tool   = new ListQueryTool([], new SingleTargetResolver($target), new RecordingExecutor('none'));

        $result = json_decode($tool->execute(['target' => self::TARGET, 'limit' => 10]), true);

        $this->assertSame(0, $result['code'], (string) ($result['msg'] ?? ''));
        $this->assertSame(ListQueryTarget::FILTER_UNKNOWN, $result['filter_support']);
        $this->assertSame(10, $result['params']['limit']);
    }

    public function testToolRejectsOrdersTooWhenSupportUnsupported(): void
    {
        $target = $this->target(ListQueryTarget::MODE_AUTO, ListQueryTarget::FILTER_UNSUPPORTED);
        $tool   = new ListQueryTool([], new SingleTargetResolver($target), new RecordingExecutor('none'));

        $result = json_decode($tool->execute([
            'target' => self::TARGET,
            'orders' => [['field' => 'id', 'direction' => 'desc']],
        ]), true);

        $this->assertSame(1, $result['code']);
        $this->assertStringContainsString('一定会被忽略', $result['msg']);
    }
}


class KnowingIntrospector extends MenuActionIntrospector
{
    public function resolve(bool $withParameters = true): array
    {
        return [
            ListQueryExecutorTest::class => MenuActionMeta::forAction('app\\admin\\controller\\Demo', 'getList', [
                'menuPath' => '/admin/demo/index',
            ]),
        ];
    }
}

class EmptyIntrospector extends MenuActionIntrospector
{
    public function resolve(bool $withParameters = true): array
    {
        return [];
    }
}

class SingleTargetResolver extends ListQueryTargetResolver
{
    public function __construct(private ListQueryTarget $target)
    {
        parent::__construct(null, []);
    }

    public function resolve(bool $force = false): array
    {
        return [$this->target->name => $this->target];
    }
}

class RecordingExecutor implements ListQueryExecutorInterface
{
    public function __construct(private string $name, private array $extra = [])
    {
    }

    public function execute(ListQueryTarget $target, array $params): array
    {
        return array_merge([
            'code'       => 0,
            'msg'        => 'ok',
            'structured' => true,
            'data'       => ['total' => 0, 'list' => []],
            'text'       => '',
            'truncated'  => false,
            'executor'   => $this->name,
        ], $this->extra);
    }
}

class RecordingStandaloneExecutor extends StandaloneListQueryExecutor
{
    /** @var array<int, array<string, mixed>> */
    public array $fetched = [];

    /**
     * 覆写取数接缝（离线：不触库）
     */
    protected function fetch(ListQueryTarget $target, array $params, Request $request): array
    {
        $this->fetched[] = $params;

        return [
            'code' => 0,
            'msg'  => '操作成功',
            'data' => ['total' => 2, 'list' => [['id' => 1], ['id' => 2]], 'page' => 1, 'limit' => 20],
        ];
    }
}

class QueryPassthroughMiddleware
{
    public function handle(Request $request, \Closure $next): Response
    {
        return $next($request);
    }
}

/**
 * 可离线实例化中间件的链
 *
 * ⚠️ 真实 `MenuActionPipeline::instantiate()` 用 `app()` 从容器解析中间件，**裸 CLI 下没有这个
 * 全局助手 ⇒ fail-closed**（这是 A1-5 有意的安全语义：安全语义缺失时绝不放行，因此也没有
 * 「CLI 逃逸阀」）。单测里覆写该接缝，才能验证链的**编排**行为。
 */
class InstantiablePipeline extends MenuActionPipeline
{
    protected function instantiate(string $class): ?object
    {
        return class_exists($class) ? new $class() : null;
    }
}

class BlockingQueryMiddleware
{
    public function handle(Request $request, \Closure $next): Response
    {
        return new MenuActionPipelineResponse('blocked', 403);
    }
}

/**
 * 暴露筛选能力判定的解析器
 *
 * ⚠️ 只覆写 `methodBody()`（读源码的接缝），**判定逻辑仍走真实实现**
 * （`detectFilterSupport()` / `bodyUsesBuilder()`），否则测的是副本而不是产品代码。
 */
class ExposedTargetResolver extends ListQueryTargetResolver
{
    public function __construct(private ?string $body, private array $options = [])
    {
        parent::__construct(null, $options);
    }

    protected function methodBody(MenuActionMeta $meta): ?string
    {
        return $this->body;
    }

    /**
     * @param bool $customized 该 getList 是否被控制器重写
     */
    public function detect(bool $customized): string
    {
        $meta = new MenuActionMeta(
            controller: ListQueryExecutorTest::CONTROLLER_FQCN,
            action: 'getList',
            source: MenuActionMeta::SOURCE_HOST,
            menuPath: '/admin/demo/index',
            menuName: '演示列表',
            customized: $customized,
        );

        return $this->detectFilterSupport($meta);
    }
}
