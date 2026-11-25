<?php
declare(strict_types=1);

namespace wise\agent\test;

use PHPUnit\Framework\TestCase;
use wise\agent\menu\MenuActionInvoker;
use wise\agent\menu\MenuActionMeta;
use wise\agent\menu\MenuActionResult;

/**
 * A1-3 回归测试：动作结果归一化 + 入参校验 + 合成 Request
 *
 * 覆盖：
 *   · `MenuActionResult` 五种返回形态（框架 JSON / 裸数组 / HTML / 文本 / 标量）归一化
 *   · HTML 正文不外传、文本截断标记、异常同核结构、debug 字段不透传
 *   · `MenuActionInvoker::buildRequest()`：读写方法、get/post 双写、控制器/动作/pathinfo、
 *     **每次新建实例**（`Request::param()` 一次性合并语义）
 *   · `validateArguments()`：键形状、操作符白名单、Schema 严格白名单、通用 filters 模式、
 *     `id/ids` 语义、`status/sort` 语义、`ids` 上限、`limit` 收敛、`filters` 展开
 *   · `invoke()` 离线降级：HTML 动作拒绝 / 未知动作拒绝 / 容器不可用 → 同核结构化失败
 *
 * 容器与真实控制器调用无法离线覆盖，由集成验收脚本（boot App）负责；
 * 本测试保证「参数 → Request → 结果」这段纯逻辑可回归。
 *
 * ⚠️ **离线覆盖不到、但必须守住的契约**：`MenuActionInvoker::makeController()` 必须调用
 * `app()->make($class, [], true)` 强制新实例。`Container::make()` 会缓存构造结果
 * （`think-container/src/Container.php:244-264`），复用实例会让控制器把 `$this->request`
 * 钉死在上一次的合成请求上 → 参数串味、`initialize()` 不再执行、`RequestQueryBuilder`
 * 沿用旧 `queryParams`。ThinkPHP 自身的路由分发同样是 `make($class, [], true)`
 * （`think/route/dispatch/Controller.php:132`）。该契约由集成验收脚本
 * （连续多次不同参数调用互不污染）把关。
 */
class MenuActionInvokerTest extends TestCase
{
    private const CONTROLLER = 'app\\admin\\controller\\Menu';


    public function testNormalizeFrameworkJsonSuccess(): void
    {
        $payload = [
            'code'       => 0,
            'msg'        => '操作成功',
            'data'       => ['total' => 2, 'list' => [['id' => 1], ['id' => 2]]],
            'request_id' => 'req-abc',
            'timestamp'  => 1760000000,
            'debug'      => ['sql' => 'select * from menus'],
        ];

        $result = MenuActionResult::normalize($payload, [
            'controller'  => self::CONTROLLER,
            'action'      => 'getList',
            'menu_path'   => '/admin/menu/index',
            'duration_ms' => 46,
        ]);

        $this->assertSame(0, $result['code']);
        $this->assertSame('操作成功', $result['msg']);
        $this->assertTrue($result['structured']);
        $this->assertSame(['total' => 2, 'list' => [['id' => 1], ['id' => 2]]], $result['data']);
        $this->assertSame('', $result['text']);
        $this->assertFalse($result['truncated']);
        $this->assertSame('req-abc', $result['request_id']);
        $this->assertSame(1760000000, $result['timestamp']);
        $this->assertSame(46, $result['duration_ms']);
        $this->assertSame('getList', $result['action']);
        $this->assertSame('/admin/menu/index', $result['menu_path']);
        $this->assertArrayNotHasKey('debug', $result, 'debug 字段不得透传给 LLM');
    }

    public function testNormalizeFrameworkJsonError(): void
    {
        $result = MenuActionResult::normalize([
            'code' => 1,
            'msg'  => '记录不存在',
            'data' => null,
        ]);

        $this->assertSame(1, $result['code']);
        $this->assertSame('记录不存在', $result['msg']);
        $this->assertTrue($result['structured']);
        $this->assertNull($result['data']);
    }

    public function testNormalizeBareArrayResponse(): void
    {
        $rows = [['id' => 1, 'name' => '总览'], ['id' => 2, 'name' => '设置']];

        $result = MenuActionResult::normalize($rows);

        $this->assertSame(0, $result['code']);
        $this->assertSame('ok', $result['msg']);
        $this->assertTrue($result['structured']);
        $this->assertSame($rows, $result['data'], '裸数组响应应整体作为 data');
    }

    public function testNormalizeJsonResponseObject(): void
    {
        $response = new FakeJsonResponse(['code' => 0, 'msg' => 'ok', 'data' => ['a' => 1]]);

        $result = MenuActionResult::normalize($response);

        $this->assertTrue($result['structured']);
        $this->assertSame(['a' => 1], $result['data']);
        $this->assertSame(0, $result['code']);
    }

    public function testNormalizeNonJsonResponseFallsBackToText(): void
    {
        $result = MenuActionResult::normalize(new FakeHtmlResponse('<html><body>页面</body></html>'));

        $this->assertFalse($result['structured']);
        $this->assertSame('html', $result['msg']);
        $this->assertStringNotContainsString('<body', (string) $result['text']);
    }

    public function testNormalizeHtmlResponseDoesNotLeakBody(): void
    {
        $html = '<!DOCTYPE html><html><body><div id="app">' . str_repeat('x', 5000) . '</div></body></html>';

        $result = MenuActionResult::normalize($html, ['action' => 'index']);

        $this->assertFalse($result['structured']);
        $this->assertSame('html', $result['msg']);
        $this->assertSame(strlen($html), $result['data']['html_length']);
        $this->assertStringContainsString('未回传正文', $result['text']);
        $this->assertStringNotContainsString('<div', $result['text'], 'HTML 正文不得外传');
        $this->assertStringNotContainsString('<div', (string) json_encode($result['data']));
        $this->assertFalse($result['truncated']);
    }

    public function testNormalizePlainTextAndTruncation(): void
    {
        $short = MenuActionResult::normalize('操作成功');
        $this->assertFalse($short['structured']);
        $this->assertSame('text', $short['msg']);
        $this->assertSame('操作成功', $short['text']);
        $this->assertFalse($short['truncated']);

        $long  = MenuActionResult::normalize(str_repeat('字', 100), ['max_text_length' => 10]);
        $this->assertTrue($long['truncated']);
        $this->assertStringContainsString('已截断', $long['text']);
        $this->assertLessThan(40, mb_strlen($long['text']));
    }

    public function testNormalizeScalarAndNull(): void
    {
        $int = MenuActionResult::normalize(5);
        $this->assertTrue($int['structured']);
        $this->assertSame(5, $int['data']);
        $this->assertSame(0, $int['code']);

        $null = MenuActionResult::normalize(null);
        $this->assertTrue($null['structured']);
        $this->assertNull($null['data']);
        $this->assertSame('ok', $null['msg']);
    }

    public function testNormalizeObjectFallsBackToJsonText(): void
    {
        $result = MenuActionResult::normalize((object) ['a' => 1]);

        $this->assertFalse($result['structured']);
        $this->assertStringContainsString('"a":1', $result['text']);
    }

    public function testFailureAndExceptionShape(): void
    {
        $failure = MenuActionResult::failure('参数错误', 1, ['stage' => MenuActionResult::STAGE_ARGUMENTS]);

        foreach (['code', 'msg', 'structured', 'data', 'text', 'truncated'] as $key) {
            $this->assertArrayHasKey($key, $failure);
        }
        $this->assertSame(1, $failure['code']);
        $this->assertFalse($failure['structured']);
        $this->assertSame('参数错误', $failure['text']);
        $this->assertSame(MenuActionResult::STAGE_ARGUMENTS, $failure['stage']);

        $fromException = MenuActionResult::fromException(
            new \RuntimeException('boom'),
            ['controller' => self::CONTROLLER, 'action' => 'delete', 'duration_ms' => 3]
        );

        $this->assertSame(1, $fromException['code']);
        $this->assertSame('boom', $fromException['msg']);
        $this->assertSame(\RuntimeException::class, $fromException['error_type']);
        $this->assertSame(MenuActionResult::STAGE_EXECUTION, $fromException['stage']);
        $this->assertSame(3, $fromException['duration_ms']);
    }


    public function testBuildRequestForReadAction(): void
    {
        $meta    = MenuActionMeta::forAction(self::CONTROLLER, 'getList', ['menuPath' => '/admin/menu/index']);
        $invoker = new MenuActionInvoker();
        $params  = ['page' => 2, 'limit' => 20, 'name__like' => '菜单'];

        $request = $invoker->buildRequest($meta, $params);

        $this->assertSame('GET', $request->method(false));
        $this->assertSame('Menu', $request->controller());
        $this->assertSame('getList', $request->action());
        $this->assertSame('/admin/menu/getlist', $request->pathinfo());
        $this->assertSame($params, $request->param());
        $this->assertSame($params, $request->get());
        $this->assertSame($params, $request->post());
    }

    public function testBuildRequestUsesActionLevelHttpMethod(): void
    {
        $invoker = new MenuActionInvoker();

        $this->assertSame('GET', $invoker->methodOf(MenuActionMeta::forAction(self::CONTROLLER, 'getList')));
        $this->assertSame('POST', $invoker->methodOf(MenuActionMeta::forAction(self::CONTROLLER, 'save')));
        $this->assertSame('DELETE', $invoker->methodOf(MenuActionMeta::forAction(self::CONTROLLER, 'delete')));
        $this->assertSame('GET', $invoker->methodOf(MenuActionMeta::forAction(self::CONTROLLER, 'status')));

        $meta    = MenuActionMeta::forAction(self::CONTROLLER, 'delete', ['menuPath' => '/admin/menu/index']);
        $request = $invoker->buildRequest($meta, ['id' => 1]);

        $this->assertSame('DELETE', $request->method(false));
        $this->assertSame('/admin/menu/delete', $request->pathinfo());
    }

    public function testActionRouteDerivation(): void
    {
        $invoker = new MenuActionInvoker();

        $host = MenuActionMeta::forAction(self::CONTROLLER, 'delete', ['menuPath' => '/admin/menu/index']);
        $this->assertSame('/admin/menu/delete', $invoker->actionRouteOf($host));

        $plugin = MenuActionMeta::forAction('plugin\\smartstock\\controller\\Product', 'getList', [
            'menuPath' => '/plugin/smartstock/product',
        ]);
        $this->assertSame('/plugin/smartstock/product/getlist', $invoker->actionRouteOf($plugin));

        $parameterized = MenuActionMeta::forAction('app\\admin\\controller\\Plugin', 'save', [
            'menuPath' => '/admin/plugin/configIndex/name/smartstock',
        ]);
        $this->assertSame('/admin/plugin/save', $invoker->actionRouteOf($parameterized));

        $unmapped = MenuActionMeta::forAction(self::CONTROLLER, 'sort', ['menuPath' => '']);
        $this->assertSame('/admin/menu/sort', $invoker->actionRouteOf($unmapped));
    }

    public function testBuildRequestUsesHostRequestClass(): void
    {
        $origin  = new \app\Request();
        $request = (new MenuActionInvoker())->buildRequest(self::metaFor('getList'), ['limit' => 1], $origin);

        $this->assertSame(\app\Request::class, get_class($request));
    }

    private static function metaFor(string $action): MenuActionMeta
    {
        return MenuActionMeta::forAction(self::CONTROLLER, $action, ['menuPath' => '/admin/menu/index']);
    }

    public function testBuildRequestCopiesSessionFingerprintAndMarksAiSource(): void
    {
        $origin = new \think\Request();
        $origin->withServer(['REMOTE_ADDR' => '10.1.2.3', 'REQUEST_METHOD' => 'POST'])
            ->withHeader(['user-agent' => 'Mozilla/5.0', 'x-requested-with' => 'XMLHttpRequest'])
            ->withCookie(['PHPSESSID' => 'abc']);

        $meta    = MenuActionMeta::forAction(self::CONTROLLER, 'getList', ['menuPath' => '/admin/menu/index']);
        $request = (new MenuActionInvoker())->buildRequest($meta, ['limit' => 5], $origin);

        $this->assertSame('10.1.2.3', $request->ip());
        $this->assertSame('XMLHttpRequest', $request->header('x-requested-with'));
        $this->assertSame(['PHPSESSID' => 'abc'], $request->cookie());
        $this->assertStringContainsString('WiseAgent/menu-action', (string) $request->header('user-agent'));
        $this->assertSame(5, $request->param('limit'));
    }

    public function testBuildRequestForWriteActionUsesPost(): void
    {
        $meta    = MenuActionMeta::forAction(self::CONTROLLER, 'save');
        $invoker = new MenuActionInvoker();

        $request = $invoker->buildRequest($meta, ['name' => '新菜单']);

        $this->assertSame('POST', $request->method(false));
        $this->assertSame('新菜单', $request->param('name'));
    }

    public function testBuildRequestReturnsFreshInstanceEachTime(): void
    {
        $meta    = MenuActionMeta::forAction(self::CONTROLLER, 'getList');
        $invoker = new MenuActionInvoker();

        $first  = $invoker->buildRequest($meta, ['page' => 1]);
        $second = $invoker->buildRequest($meta, ['page' => 9]);

        $this->assertNotSame($first, $second, '必须每次新建 Request（param() 一次性合并语义）');
        $this->assertSame(1, $first->param('page'));
        $this->assertSame(9, $second->param('page'));

        $first->param();
        $first->withGet(['page' => 99]);
        $this->assertSame(1, $first->param('page'));
    }

    public function testBuildRequestKeepsLayerInControllerName(): void
    {
        $meta    = MenuActionMeta::forAction('app\\admin\\controller\\Market\\Plugin', 'getList');
        $invoker = new MenuActionInvoker();

        $this->assertSame('Market.Plugin', $invoker->buildRequest($meta, [])->controller());
    }


    public function testValidateRejectsUndeclaredParameterWhenSchemaIsStrict(): void
    {
        $meta = MenuActionMeta::forAction(self::CONTROLLER, 'save')->withParameters([
            'type'       => 'object',
            'properties' => ['name' => ['type' => 'string'], 'status' => ['type' => 'string']],
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('未在该动作的 Schema 中声明');

        (new MenuActionInvoker())->validateArguments($meta, ['name' => 'x', 'evil' => 'y']);
    }

    public function testValidateAllowsFieldKeysInGenericFiltersMode(): void
    {
        $meta = MenuActionMeta::forAction(self::CONTROLLER, 'getList')->withParameters([
            'type'       => 'object',
            'properties' => [
                'page'    => ['type' => 'integer'],
                'limit'   => ['type' => 'integer'],
                'filters' => ['type' => 'array'],
            ],
        ]);

        $params = (new MenuActionInvoker())->validateArguments($meta, [
            'page'         => 1,
            'status__eq'   => 1,
            'amount__gte'  => 1000,
        ]);

        $this->assertSame(1, $params['page']);
        $this->assertSame(1, $params['status__eq']);
        $this->assertSame(1000, $params['amount__gte']);
    }

    public function testValidateRejectsMalformedKeysAndOperators(): void
    {
        $invoker = new MenuActionInvoker();
        $meta    = MenuActionMeta::forAction(self::CONTROLLER, 'getList');

        $cases = [
            ['a;b' => 1],
            ['name__exists' => 1],
            ['name__' => 1],
            ['__eq' => 1],
            ['字段' => 1],
        ];

        foreach ($cases as $arguments) {
            try {
                $invoker->validateArguments($meta, $arguments);
                $this->fail('应拒绝：' . json_encode($arguments, JSON_UNESCAPED_UNICODE));
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('非法', $e->getMessage());
            }
        }
    }

    public function testValidateIdSemantics(): void
    {
        $invoker = new MenuActionInvoker();
        $meta    = MenuActionMeta::forAction(self::CONTROLLER, 'delete');

        try {
            $invoker->validateArguments($meta, []);
            $this->fail('delete 无 id/ids 应被拒绝');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('id 与 ids 至少提供一个', $e->getMessage());
        }

        $ok = $invoker->validateArguments($meta, ['ids' => ['1', '2', 3]]);
        $this->assertSame([1, 2, 3], $ok['ids'], 'ids 应整数化');

        try {
            $invoker->validateArguments($meta, ['ids' => array_fill(0, 101, 1)]);
            $this->fail('ids 超上限应被拒绝');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('最多', $e->getMessage());
        }
    }

    public function testValidateStatusAndSortSemantics(): void
    {
        $invoker = new MenuActionInvoker();

        $status = MenuActionMeta::forAction(self::CONTROLLER, 'status');
        try {
            $invoker->validateArguments($status, ['id' => 1]);
            $this->fail('status 缺失应被拒绝');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('status 必须提供', $e->getMessage());
        }
        $this->assertSame(['id' => 1, 'status' => 0], $invoker->validateArguments($status, ['id' => 1, 'status' => '0']));

        $sort = MenuActionMeta::forAction(self::CONTROLLER, 'sort');
        try {
            $invoker->validateArguments($sort, ['id' => 1, 'sort' => 1, 'sort_field' => 'evil_col']);
            $this->fail('sort_field 非白名单应被拒绝');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('sort_field 只允许', $e->getMessage());
        }
        $this->assertSame(
            ['id' => 1, 'sort' => 5, 'sort_field' => 'weigh'],
            $invoker->validateArguments($sort, ['id' => 1, 'sort' => '5', 'sort_field' => 'weigh'])
        );
    }

    public function testValidateClampsLimit(): void
    {
        $invoker = new MenuActionInvoker(['max_limit' => 50]);
        $meta    = MenuActionMeta::forAction(self::CONTROLLER, 'getList');

        $params = $invoker->validateArguments($meta, ['limit' => 99999, 'page' => 0]);

        $this->assertSame(50, $params['limit']);
        $this->assertSame(1, $params['page']);
    }

    public function testValidateExpandsFiltersArray(): void
    {
        $invoker = new MenuActionInvoker();
        $meta    = MenuActionMeta::forAction(self::CONTROLLER, 'getList');

        $params = $invoker->validateArguments($meta, [
            'filters' => ['status__eq=1', 'create_time__between=2026-09-01,2026-09-30'],
            'limit'   => 10,
        ]);

        $this->assertArrayNotHasKey('filters', $params, 'filters 应被展开为一维参数');
        $this->assertSame('1', $params['status__eq']);
        $this->assertSame('2026-09-01,2026-09-30', $params['create_time__between']);
        $this->assertSame(10, $params['limit']);
    }

    public function testValidateRejectsMalformedFiltersElement(): void
    {
        $invoker = new MenuActionInvoker();
        $meta    = MenuActionMeta::forAction(self::CONTROLLER, 'getList');

        $this->expectException(\InvalidArgumentException::class);
        $invoker->validateArguments($meta, ['filters' => ['bad-element']]);
    }

    public function testValidateRejectsNestedArrayValue(): void
    {
        $invoker = new MenuActionInvoker();
        $meta    = MenuActionMeta::forAction(self::CONTROLLER, 'getList');

        $this->expectException(\InvalidArgumentException::class);
        $invoker->validateArguments($meta, ['name__eq' => ['nested' => 1]]);
    }


    public function testInvokeRejectsHtmlActionBeforeExecution(): void
    {
        $invoker = new MenuActionInvoker();
        $result  = $invoker->invoke(MenuActionMeta::forAction(self::CONTROLLER, 'index'), []);

        $this->assertSame(1, $result['code']);
        $this->assertSame(MenuActionResult::STAGE_ACTION, $result['stage']);
        $this->assertStringContainsString('不提供 AI 执行', $result['msg']);

        $steps = implode('|', array_map(static fn(array $s): string => $s['step'], $invoker->trace()));
        $this->assertStringContainsString('action.rejected', $steps);
    }

    public function testInvokeRejectsUnknownAction(): void
    {
        $result = (new MenuActionInvoker())->invoke(MenuActionMeta::forAction(self::CONTROLLER, 'sync'), []);

        $this->assertSame(1, $result['code']);
        $this->assertSame(MenuActionResult::STAGE_ACTION, $result['stage']);
    }

    public function testInvokeRejectsInvalidArgumentsWithStage(): void
    {
        $result = (new MenuActionInvoker())->invoke(MenuActionMeta::forAction(self::CONTROLLER, 'delete'), []);

        $this->assertSame(1, $result['code']);
        $this->assertSame(MenuActionResult::STAGE_ARGUMENTS, $result['stage']);
        $this->assertStringContainsString('id 与 ids 至少提供一个', $result['msg']);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('text', $result);
    }

    public function testInvokeDegradesGracefullyWithoutContainer(): void
    {
        $result = (new MenuActionInvoker())->invoke(
            MenuActionMeta::forAction(self::CONTROLLER, 'getList'),
            ['page' => 1, 'limit' => 5]
        );

        $this->assertSame(1, $result['code']);
        $this->assertSame(MenuActionResult::STAGE_EXECUTION, $result['stage']);
        $this->assertStringContainsString('Container is not available', $result['msg']);
        $this->assertArrayHasKey('duration_ms', $result);
    }
}

/**
 * 测试替身：绕开 Cookie 注入，仅置 `data`（instanceof 分支与真实 Json 一致）
 */
class FakeJsonResponse extends \think\response\Json
{
    public function __construct(mixed $data)
    {
        $this->data = $data;
    }
}

/**
 * 测试替身：非 JSON 的 Response（如 HTML 页面响应）
 */
class FakeHtmlResponse extends \think\Response
{
    public function __construct(mixed $data)
    {
        $this->data = $data;
    }

    public function output($data): string
    {
        return is_scalar($data) ? (string) $data : '';
    }
}
