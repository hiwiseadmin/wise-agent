<?php
declare(strict_types=1);

namespace wise\agent\test;

use PHPUnit\Framework\TestCase;
use wise\agent\form\FormFieldSchema;
use wise\agent\form\FormSchema;
use wise\agent\form\FormSchemaIntrospector;
use wise\agent\menu\MenuActionMeta;
use wise\form\schema\Form;
use wise\form\schema\field\Date;
use wise\form\schema\field\Datetime;
use wise\form\schema\field\Hidden;
use wise\form\schema\field\Number;
use wise\form\schema\field\Select;
use wise\form\schema\field\SwitchField;
use wise\form\schema\field\Text;
use wise\form\schema\field\Textarea;

/**
 * A2-1 回归：表单 Schema 内省（附录四 验收 1、2）
 *
 * 全部离线：假控制器**不使用宿主 trait**，只声明 `defineForm()` / `getModel()`
 * （内省器只要求 `hasMethod('defineForm')`），因此不依赖宿主控制器与数据库。
 */
class FormSchemaIntrospectorTest extends TestCase
{
    protected function setUp(): void
    {
        FormSchemaIntrospector::resetCache();
    }

    private function inspector(?object $model = null, array $options = [], array $fallback = []): TestableFormSchemaIntrospector
    {
        return new TestableFormSchemaIntrospector($options, $model, $fallback);
    }


    public function testIntrospectsFullMetadataFromDefineForm(): void
    {
        $schema = $this->inspector()->forController(FakeFormController::class);

        $this->assertSame(FormSchema::SOURCE_DEFINE_FORM, $schema->source);
        $this->assertSame('host.fake_form_controller', $schema->target, '目标名 = {ident}.{控制器类名蛇形}');
        $this->assertFalse($schema->isEmpty());

        $title = $schema->get('title');
        $this->assertNotNull($title);
        $this->assertSame('标题', $title->label);
        $this->assertSame('text', $title->type);
        $this->assertTrue($title->required);
        $this->assertSame('请输入标题', $title->placeholder, 'A1-1 丢失项：placeholder');
        $this->assertSame('最多 50 字', $title->help);
        $this->assertSame('Text', $title->input, '字段类名（回写草案时保持原类）');
        $this->assertNull($title->group, '未放进 group() 回调的字段不带分组');

        $this->assertSame('basic', $schema->get('code')->group);

        $amount = $schema->get('amount');
        $this->assertSame(0, $amount->min);
        $this->assertSame(10000, $amount->max);
        $this->assertSame(0.01, $amount->step);
        $this->assertFalse($amount->range);

        $this->assertTrue($schema->get('period')->range);

        $tax = $schema->get('tax_id');
        $this->assertNotEmpty($tax->conditionalRules, 'A1-1 丢失项：conditional_rules');

        $reason = $schema->get('reason');
        $this->assertNotNull($reason);
        $this->assertNotEmpty($reason->dependencies, 'A1-1 丢失项：dependencies');

        $code = $schema->get('code');
        $this->assertNotEmpty($code->rules, 'A1-1 丢失项：rules');

        $keys = array_keys($title->toArray());
        foreach ([
            'key', 'label', 'type', 'input', 'required', 'default', 'help', 'placeholder', 'options',
            'multiple', 'min', 'max', 'step', 'range', 'format', 'rules', 'group', 'dependencies',
            'conditional_rules', 'renderable', 'writable', 'readable', 'deny_reason', 'source',
        ] as $expected) {
            $this->assertContains($expected, $keys, "缺少元数据键 {$expected}");
        }
    }

    public function testFieldClassAndTypeMapping(): void
    {
        $schema = $this->inspector()->forController(FakeFormController::class);

        $this->assertSame('Select', $schema->get('status')->input);
        $this->assertSame('select', $schema->get('status')->type);
        $this->assertSame('SwitchField', $schema->get('is_top')->input);
        $this->assertSame('switch', $schema->get('is_top')->type);
        $this->assertSame('datetime', $schema->get('publish_at')->type);
        $this->assertSame('textarea', $schema->get('remark')->type);
        $this->assertSame('hidden', $schema->get('tenant_id')->type);
    }

    public function testOptionsAreNormalizedWithValueAndLabel(): void
    {
        $schema  = $this->inspector()->forController(FakeFormController::class);
        $options = $schema->get('status')->options;

        $this->assertSame([
            ['value' => 1, 'label' => '启用'],
            ['value' => 0, 'label' => '禁用'],
        ], $options);
        $this->assertSame(['启用(1)', '禁用(0)'], $schema->get('status')->optionLabels());
    }

    public function testGroupsAndRepeatersAreCaptured(): void
    {
        $schema = $this->inspector()->forController(FakeFormController::class);

        $groupNames = array_map(static fn(array $g): string => (string) ($g['name'] ?? ''), $schema->groups);
        $this->assertContains('basic', $groupNames, '分组声明必须保留');

        $this->assertNotEmpty($schema->repeaters, '可重复组必须保留');
    }


    public function testDenyReasonsForSensitiveSystemOwnerAndHidden(): void
    {
        $schema = $this->inspector()->forController(FakeFormController::class);

        $this->assertSame(FormFieldSchema::DENY_SENSITIVE, $schema->get('password')->denyReason);
        $this->assertFalse($schema->get('password')->readable, '敏感字段既不写也不读');

        $this->assertSame(FormFieldSchema::DENY_SYSTEM, $schema->get('id')->denyReason);
        $this->assertSame(FormFieldSchema::DENY_OWNER, $schema->get('admin_id')->denyReason, '归属字段默认不填');
        $this->assertSame(FormFieldSchema::DENY_HIDDEN, $schema->get('tenant_id')->denyReason, 'hidden 类型默认不填');

        $this->assertTrue($schema->get('title')->writable, '普通字段可写');
        $this->assertContains('title', $schema->fillableKeys());
        $this->assertNotContains('password', $schema->fillableKeys());
        $this->assertArrayHasKey('admin_id', $schema->deniedKeys());
    }

    public function testModelWriteWhitelistNarrowsWritability(): void
    {
        $schema = $this->inspector(new FakeFormModel(['title', 'status']))->forController(FakeFormController::class);

        $this->assertTrue($schema->get('title')->writable, '白名单内可写');
        $this->assertFalse($schema->get('remark')->writable, '白名单外不可写');
        $this->assertSame(FormFieldSchema::DENY_WHITELIST, $schema->get('remark')->denyReason);
    }

    public function testOwnerAndHiddenCanBeOptedIn(): void
    {
        $schema = $this->inspector(null, ['fill_owner_fields' => true, 'fill_hidden' => true])
            ->forController(FakeFormController::class);

        $this->assertTrue($schema->get('admin_id')->writable, '显式放开后归属字段可填');
        $this->assertTrue($schema->get('tenant_id')->writable);
        $this->assertFalse($schema->get('password')->writable);
        $this->assertFalse($schema->get('id')->writable);
    }


    public function testFallsBackToCrudSchemaWithHonestSource(): void
    {
        $schema = $this->inspector(null, [], [
            'title'  => ['name' => 'title', 'label' => '标题', 'type' => 'string', 'required' => true, 'source' => 'form'],
            'status' => [
                'name' => 'status', 'label' => '状态', 'type' => 'enum',
                'enum' => ['1', '0'], 'enum_labels' => ['启用', '禁用'], 'source' => 'form',
            ],
        ])->forController(NoFormController::class);

        $this->assertSame(FormSchema::SOURCE_CRUD_SCHEMA, $schema->source);
        $this->assertSame('text', $schema->get('title')->type);
        $this->assertSame(FormSchema::SOURCE_CRUD_SCHEMA, $schema->get('title')->source);
        $this->assertSame([
            ['value' => 1, 'label' => '启用'],
            ['value' => 0, 'label' => '禁用'],
        ], $schema->get('status')->options, '回退目录的枚举也要归一成 [{value,label}]');

        $notes = implode(' ', array_map(static fn(array $n): string => $n['message'], $schema->notes()));
        $this->assertStringContainsString('缺少分组/联动/条件必填', $notes, '回退必须如实说明元数据不全');
    }

    public function testDbFallbackIsMarkedAsInferenceNotDeclaration(): void
    {
        $schema = $this->inspector(null, [], [
            'title' => ['name' => 'title', 'label' => '标题', 'type' => 'string', 'source' => 'db'],
            'qty'   => ['name' => 'qty', 'label' => '数量', 'type' => 'integer', 'source' => 'db'],
        ])->forController(NoFormController::class);

        $this->assertSame(FormSchema::SOURCE_DB, $schema->source);
        $this->assertSame('number', $schema->get('qty')->type, 'integer → number');
        $this->assertSame('text', $schema->get('title')->type, 'string → text');

        $notes = implode(' ', array_map(static fn(array $n): string => $n['message'], $schema->notes()));
        $this->assertStringContainsString('表结构推断', $notes);
        $this->assertStringContainsString('不是表单声明', $notes);
    }

    public function testMixedFallbackSourcesAreLabelledConsistently(): void
    {
        $schema = $this->inspector(null, [], [
            'title'  => ['name' => 'title', 'label' => '标题', 'type' => 'string', 'source' => 'db'],
            'status' => [
                'name' => 'status', 'label' => '状态', 'type' => 'enum',
                'enum' => ['1'], 'enum_labels' => ['启用'], 'source' => 'form',
            ],
        ])->forController(NoFormController::class);

        $this->assertSame(FormSchema::SOURCE_CRUD_SCHEMA, $schema->source);
        $this->assertSame(FormSchema::SOURCE_CRUD_SCHEMA, $schema->get('title')->source, '靠前字段不能被标成 db');
        $this->assertSame(FormSchema::SOURCE_CRUD_SCHEMA, $schema->get('status')->source);
    }

    public function testEmptyWhenNothingAvailable(): void
    {
        $schema = $this->inspector()->forController(NoFormController::class);

        $this->assertSame(FormSchema::SOURCE_NONE, $schema->source);
        $this->assertTrue($schema->isEmpty());
        $this->assertSame('', $schema->toPrompt());

        $notes = implode(' ', array_map(static fn(array $n): string => $n['message'], $schema->notes()));
        $this->assertStringContainsString('无法解释表单', $notes);
    }

    public function testDefineFormFailureFallsBackInsteadOfExploding(): void
    {
        $schema = $this->inspector(null, [], [
            'title' => ['name' => 'title', 'label' => '标题', 'type' => 'string', 'source' => 'db'],
        ])->forController(BrokenFormController::class);

        $this->assertSame(FormSchema::SOURCE_DB, $schema->source, 'defineForm 抛错必须回退，不能冒泡');
    }


    public function testTargetNamingMatchesA1A3Convention(): void
    {
        $this->assertSame('host.menu', MenuActionMeta::targetNameOf('app\\admin\\controller\\Menu'));
        $this->assertSame('host.login_log', MenuActionMeta::targetNameOf('app\\admin\\controller\\LoginLog'));
        $this->assertSame('host.market.plugin', MenuActionMeta::targetNameOf('app\\admin\\controller\\Market\\Plugin'));
        $this->assertSame('smartstock.product', MenuActionMeta::targetNameOf('plugin\\smartstock\\controller\\Product'));
        $this->assertSame(
            'smartstock.admin.warehouse',
            MenuActionMeta::targetNameOf('plugin\\smartstock\\controller\\admin\\Warehouse')
        );

        $this->assertSame('host.fake_form_controller', $this->inspector()->targetOf(FakeFormController::class));
    }


    public function testPromptListsFieldsAndDegradesWhenOverBudget(): void
    {
        $schema = $this->inspector()->forController(FakeFormController::class);

        $rich = $schema->toPrompt(4000, 30);
        $this->assertStringContainsString('host.fake_form_controller', $rich);
        $this->assertStringContainsString('标题', $rich);
        $this->assertStringContainsString('必填', $rich);
        $this->assertStringContainsString('启用(1)', $rich);

        $lean = $schema->toPrompt(40, 30);
        $this->assertStringContainsString('host.fake_form_controller', $lean, '超预算也要保住目标名');
        $this->assertLessThanOrEqual(120, mb_strlen($lean, 'UTF-8'));
    }

    public function testSensitiveFieldsAreExcludedFromPrompt(): void
    {
        $schema = $this->inspector()->forController(FakeFormController::class);
        $prompt = $schema->toPrompt(4000, 30);

        $this->assertStringNotContainsString('password', $prompt, '敏感字段不进 Prompt');
    }

    public function testResultIsCachedUntilReset(): void
    {
        $first  = $this->inspector()->forController(FakeFormController::class);
        $second = $this->inspector()->forController(FakeFormController::class);

        $this->assertSame($first, $second, '同一请求内复用');

        FormSchemaIntrospector::resetCache();
        $this->assertNotSame($first, $this->inspector()->forController(FakeFormController::class));
    }
}


/**
 * 可注入「模型 / 回退目录」的内省器
 */
class TestableFormSchemaIntrospector extends FormSchemaIntrospector
{
    /**
     * @param array<string, mixed> $options
     * @param array<string, array<string, mixed>> $fallback
     */
    public function __construct(
        array $options = [],
        private ?object $model = null,
        private array $fallback = []
    ) {
        parent::__construct($options);
    }

    protected function modelOf(string $controllerClass): ?object
    {
        return $this->model;
    }

    protected function fallbackFields(string $controllerClass): array
    {
        return $this->fallback;
    }
}

/**
 * 假模型：只提供内省用到的两个方法（离线，不依赖 ThinkPHP 模型）
 */
class FakeFormModel
{
    /** @param string[]|null $allowWrite */
    public function __construct(private ?array $allowWrite = null)
    {
    }

    public function getTable(): string
    {
        return 'fake_forms';
    }

    /**
     * @return string[]|null
     */
    public function getAllowWriteFields(): ?array
    {
        return $this->allowWrite;
    }
}

/**
 * 声明了完整表单的假控制器（覆盖：分组 / 联动 / 条件必填 / 自定义规则 / 各类字段 / 敏感与归属字段）
 */
class FakeFormController
{
    protected function getModel(): FakeFormModel
    {
        return new FakeFormModel();
    }

    protected function defineForm(Form $form): void
    {
        $form->add(Text::make('title', '标题')->required()->placeholder('请输入标题')->help('最多 50 字'));

        $form->group('basic', '基本信息', null, false, function (Form $f): void {
            $f->add(Text::make('code', '编码')->rules(['alphaNum']));
        });

        $form->add(Select::make('type', '类型')->options(['company' => '企业', 'personal' => '个人']));
        $form->add(Text::make('tax_id', '税号')->requiredIf('type', '=', 'company'));

        $form->add(Select::make('status', '状态')->options(['1' => '启用', '0' => '禁用']));

        $form->when('status', '=', '1', function (Form $f): void {
            $f->add(Textarea::make('reason', '启用原因'));
        });

        $form->add(Number::make('amount', '金额')->min(0)->max(10000)->step(0.01));
        $form->add(Date::make('period', '统计周期')->range());

        $form->add(SwitchField::make('is_top', '置顶'));
        $form->add(Datetime::make('publish_at', '发布时间'));
        $form->add(Textarea::make('remark', '备注'));

        $form->repeater('items', '明细', function (Form $f): void {
            $f->add(Text::make('sku', 'SKU'));
        });

        $form->add(Text::make('admin_id', '归属管理员'));
        $form->add(Text::make('password', '密码'));
        $form->add(Text::make('id', '主键'));
        $form->add(Hidden::make('tenant_id', '租户'));
    }
}

/**
 * 未声明表单的假控制器（未定义 defineForm）
 */
class NoFormController
{
    protected function getModel(): FakeFormModel
    {
        return new FakeFormModel();
    }
}

/**
 * defineForm 抛错的假控制器（模拟「声明依赖 $this->request」等场景）
 */
class BrokenFormController
{
    protected function defineForm(Form $form): void
    {
        throw new \RuntimeException('依赖未初始化的控制器状态');
    }
}
