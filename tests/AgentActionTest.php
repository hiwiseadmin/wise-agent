<?php
declare(strict_types=1);

namespace wise\agent\test;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use wise\agent\attribute\AgentAction;
use wise\agent\attribute\AgentActionReader;
use wise\agent\menu\MenuActionIntrospector;
use wise\agent\menu\MenuActionMeta;
use wise\agent\security\CapabilityAccess;

/**
 * `#[AgentAction]` 协议回归测试（Phase 1）
 *
 * 覆盖：
 *   · 读取器：合法注解归一化、非法 access / 缺 params / 非 public 方法被跳过并记错
 *   · 内省器：非 CRUD 控制器只要声明注解即可被发现
 *   · 元数据：注解动作可生成工具、参数来自注解、风险/访问类型/确认策略生效
 */
class AgentActionTest extends TestCase
{
    protected function setUp(): void
    {
        MenuActionIntrospector::resetMemoryCache();
    }

    public function testReaderNormalizesValidAnnotation(): void
    {
        $reader = new AgentActionReader();
        $meta   = $reader->read(new ReflectionClass(FakeAnnotatedController::class), 'resetPassword');

        $this->assertNotNull($meta);
        $this->assertSame('重置密码', $meta['label']);
        $this->assertSame('write', $meta['access']);
        $this->assertSame('high', $meta['risk']);
        $this->assertTrue($meta['confirm']);
        $this->assertSame(['id'], $meta['required']);
        $this->assertSame('object', $meta['schema']['type']);
        $this->assertArrayHasKey('id', $meta['schema']['properties']);
        $this->assertSame([], $reader->errors());
    }

    public function testReaderRejectsInvalidAccess(): void
    {
        $reader = new AgentActionReader();
        $meta   = $reader->read(new ReflectionClass(FakeAnnotatedController::class), 'badAccess');

        $this->assertNull($meta);
        $this->assertCount(1, $reader->errors());
        $this->assertStringContainsString('access 非法', $reader->errors()[0]['message']);
    }

    public function testReaderRejectsWriteActionWithoutParams(): void
    {
        $reader = new AgentActionReader();
        $meta   = $reader->read(new ReflectionClass(FakeAnnotatedController::class), 'badParams');

        $this->assertNull($meta);
        $this->assertStringContainsString('必须声明 params', $reader->errors()[0]['message']);
    }

    public function testReaderRejectsNonPublicMethod(): void
    {
        $reader = new AgentActionReader();
        $meta   = $reader->read(new ReflectionClass(FakeAnnotatedController::class), 'hiddenAction');

        $this->assertNull($meta);
        $this->assertStringContainsString('public', $reader->errors()[0]['message']);
    }

    public function testNonCrudControllerWithAnnotationIsIntrospectable(): void
    {
        $introspector = new MenuActionIntrospector([], null, new AgentActionReader());

        $this->assertFalse($introspector->isCrudController(FakeAnnotatedController::class));
        $this->assertTrue($introspector->isAgentCapableController(FakeAnnotatedController::class));

        $actions = $introspector->controllerActions(FakeAnnotatedController::class);

        $this->assertArrayHasKey('resetPassword', $actions);
        $this->assertSame('重置密码', $actions['resetPassword']['annotation']['label']);

        $this->assertArrayNotHasKey('badAccess', $actions);
        $this->assertArrayNotHasKey('badParams', $actions);
        $this->assertArrayNotHasKey('hiddenAction', $actions);
    }

    public function testAnnotatedMetaBecomesToolCandidateWithAnnotationSchema(): void
    {
        $reader     = new AgentActionReader();
        $annotation = $reader->read(new ReflectionClass(FakeAnnotatedController::class), 'resetPassword');
        $meta       = MenuActionMeta::forAction(FakeAnnotatedController::class, 'resetPassword', [
            'menuPath'   => '/admin/fake/index',
            'annotation' => $annotation,
        ]);

        $this->assertTrue($meta->fromAnnotation);
        $this->assertTrue($meta->isToolCandidate());
        $this->assertSame(CapabilityAccess::WRITE, $meta->access);
        $this->assertSame(CapabilityAccess::RISK_HIGH, $meta->risk);
        $this->assertTrue($meta->requireConfirmation(), 'high 风险恒需确认');
        $this->assertSame('重置密码', $meta->getLabel());
        $this->assertSame('object', $meta->parameters['type']);
        $this->assertSame(['id'], $meta->parameters['required']);
    }

    public function testAnnotationCanOverridePermissionIdent(): void
    {
        $reader     = new AgentActionReader();
        $annotation = $reader->read(new ReflectionClass(FakeAnnotatedController::class), 'customPermission');
        $meta       = MenuActionMeta::forAction(FakeAnnotatedController::class, 'customPermission', [
            'annotation' => $annotation,
        ]);

        $this->assertSame('ai.custom.fake', $meta->getPermission());
    }

    public function testHtmlActionIsNeverToolCandidateEvenIfAnnotated(): void
    {
        $reader     = new AgentActionReader();
        $annotation = $reader->read(new ReflectionClass(FakeAnnotatedController::class), 'index');
        $meta       = MenuActionMeta::forAction(FakeAnnotatedController::class, 'index', [
            'annotation' => $annotation,
        ]);

        $this->assertTrue($meta->fromAnnotation);
        $this->assertFalse($meta->isToolCandidate(), 'HTML 动作即使被注解也不生成工具');
    }

    public function testMetaRoundTripKeepsAnnotation(): void
    {
        $reader     = new AgentActionReader();
        $annotation = $reader->read(new ReflectionClass(FakeAnnotatedController::class), 'resetPassword');
        $meta       = MenuActionMeta::forAction(FakeAnnotatedController::class, 'resetPassword', [
            'annotation' => $annotation,
        ]);

        $restored = MenuActionMeta::fromArray($meta->toArray());

        $this->assertTrue($restored->fromAnnotation);
        $this->assertSame('重置密码', $restored->getLabel());
        $this->assertSame($meta->parameters, $restored->parameters);
    }


    public function testStandardAndCustomActionsAreEnumerated(): void
    {
        $introspector = new MenuActionIntrospector([], null, new AgentActionReader());
        $actions      = $introspector->controllerActions(FakeCrudAnnotatedController::class);

        $this->assertArrayHasKey('index', $actions);
        $this->assertArrayHasKey('getList', $actions);
        $this->assertArrayHasKey('save', $actions);

        $this->assertArrayNotHasKey('delete', $actions);
        $this->assertArrayNotHasKey('status', $actions);

        $this->assertArrayHasKey('resetPassword', $actions);
        $this->assertArrayNotHasKey('badCustom', $actions, '非法注解不得进入动作清单');

        $this->assertSame('保存文章', $actions['save']['annotation']['label']);
        $this->assertSame([], $actions['getList']['annotation']);
    }

    public function testAnnotatedStandardActionOverridesSchemaAndRisk(): void
    {
        $reader     = new AgentActionReader();
        $annotation = $reader->read(new ReflectionClass(FakeCrudAnnotatedController::class), 'save');
        $meta       = MenuActionMeta::forAction(FakeCrudAnnotatedController::class, 'save', [
            'menuPath'   => '/admin/fake/save',
            'annotation' => $annotation,
            'customized' => true,
        ]);

        $this->assertTrue($meta->fromAnnotation);
        $this->assertTrue($meta->isToolCandidate());
        $this->assertSame(CapabilityAccess::WRITE, $meta->access);
        $this->assertSame(CapabilityAccess::RISK_HIGH, $meta->risk);
        $this->assertSame('保存文章', $meta->getLabel());
        $this->assertSame(['title'], $meta->parameters['required']);
        $this->assertArrayHasKey('title', $meta->parameters['properties']);
    }

    public function testUnannotatedStandardActionStillGeneratesTool(): void
    {
        $meta = MenuActionMeta::forAction(FakeCrudAnnotatedController::class, 'getList', [
            'menuPath'   => '/admin/fake/index',
            'customized' => true,
        ]);

        $this->assertFalse($meta->fromAnnotation);
        $this->assertTrue($meta->isToolCandidate());
        $this->assertSame(CapabilityAccess::READ, $meta->access);
        $this->assertFalse($meta->requireConfirmation(), '只读动作无需确认');
    }
}

/**
 * 测试夹具：**不使用 CrudAction** 的普通控制器
 */
class FakeAnnotatedController
{
    #[AgentAction(
        label: '重置密码',
        description: '按管理员 ID 重置登录密码',
        params: [
            'id'       => ['type' => 'integer', 'description' => '管理员 ID'],
            'password' => ['type' => 'string', 'description' => '新密码'],
        ],
        required: ['id'],
        access: 'write',
        risk: 'high',
    )]
    public function resetPassword(): void
    {
    }

    #[AgentAction(label: '非法访问类型', access: 'nope')]
    public function badAccess(): void
    {
    }

    #[AgentAction(label: '缺少参数', access: 'write')]
    public function badParams(): void
    {
    }

    #[AgentAction(label: '非 public 方法', access: 'read')]
    protected function hiddenAction(): void
    {
    }

    #[AgentAction(label: '自定义权限标识', access: 'read', permission: 'ai.custom.fake')]
    public function customPermission(): void
    {
    }

    #[AgentAction(label: '页面入口', access: 'read')]
    public function index(): void
    {
    }
}

/**
 * 测试夹具：真实使用 CrudTrait 的控制器
 *
 * 覆盖两类动作：
 *   - 标准动作（getList / save）——有 public 包装即暴露；
 *   - 自定义动作（resetPassword）——必须带 #[AgentAction]。
 */
class FakeCrudAnnotatedController
{
    use \app\common\controller\traits\CrudTrait;

    protected function getModel(): \think\Model
    {
        throw new \RuntimeException('测试夹具不访问数据库');
    }

    public function index()
    {
        return $this->_index();
    }

    public function getList()
    {
        return $this->_getList();
    }

    #[AgentAction(
        label: '保存文章',
        description: '新增一篇文章',
        params: [
            'title'  => ['type' => 'string', 'description' => '标题'],
            'status' => ['type' => 'integer', 'description' => '状态'],
        ],
        required: ['title'],
        access: 'write',
        risk: 'high',
    )]
    public function save()
    {
        return $this->_save();
    }

    #[AgentAction(
        label: '重置密码',
        description: '按 ID 重置密码',
        params: ['id' => ['type' => 'integer', 'description' => 'ID']],
        required: ['id'],
        access: 'write',
    )]
    public function resetPassword(): void
    {
    }

    #[AgentAction(label: '非法访问类型', access: 'nope')]
    public function badCustom(): void
    {
    }
}
