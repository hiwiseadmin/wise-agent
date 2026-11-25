<?php
declare(strict_types=1);

namespace wise\agent\test;

use PHPUnit\Framework\TestCase;
use think\Validate;
use wise\agent\form\FormSchema;
use wise\agent\form\FormSchemaIntrospector;
use wise\agent\form\FormSchemaToolProvider;
use wise\agent\form\FormValidateReport;
use wise\agent\form\FormValidateTool;
use wise\agent\form\FormValidationBridge;
use wise\agent\menu\MenuActionIntrospector;
use wise\agent\menu\MenuActionMeta;
use wise\agent\security\CapabilityAccess;
use wise\agent\tool\ToolRegistry;
use wise\form\schema\DataFilter;
use wise\form\schema\Form;
use wise\form\schema\ValidationGenerator;
use wise\form\schema\field\Number;
use wise\form\schema\field\Select;
use wise\form\schema\field\Text;

require_once __DIR__ . '/support/WiseAgentConfigLoader.php';

/**
 * A2-2 回归：表单校验桥 + `form_validate`（附录四 验收 4、5、7）
 *
 * 三个验收的验证方式：
 * - **验收 4（对拍）**：把桥的结论与「按 `CrudAction` 文档范本独立复算」的结果逐条比对；
 * - **验收 5（诚实边界）**：未覆写 `validateBySchema()` 时必须回传 `enforced=false` 与明确提示；
 * - **验收 7（只读断言）**：工具分类、不确认、deny-only，且不产生任何写操作。
 *
 * 全部离线；夹具自带（不跨测试文件复用替身类）。
 */
class FormValidationTest extends TestCase
{
    protected function setUp(): void
    {
        FormSchemaIntrospector::resetCache();
    }

    private function introspector(): TestableFormValidateIntrospector
    {
        return new TestableFormValidateIntrospector();
    }

    private function bridge(): FormValidationBridge
    {
        return new FormValidationBridge();
    }

    private function profileSchema(): FormSchema
    {
        return $this->introspector()->forController(ProfileFormController::class);
    }

    /**
     * ⚠️ 用**真实工具类**（不子类化）：`BaseTool::getName()` 是按类名推导的，
     * 用测试替身会得到 `testable_form_validate` 而不是 `form_validate`。
     */
    private function tool(array $options = []): FormValidateTool
    {
        return new FormValidateTool($options, new TestableFormValidateIntrospector($options), new FormValidationBridge($options));
    }

    private function decode(string $json): array
    {
        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded, '响应必须是 JSON');

        return $decoded;
    }


    public function testBridgeIsThinWrapperOverValidationGenerator(): void
    {
        $schema = $this->profileSchema();
        $data   = ['name' => '张三', 'type' => 'company', 'company_name' => ''];

        $expected = ValidationGenerator::generateFor($schema->validationSchema(), $data);

        $this->assertSame($expected, $this->bridge()->effectiveRules($schema, $data), '有效规则必须与范本完全一致');
        $this->assertSame(ValidationGenerator::generate($schema->validationSchema()), $this->bridge()->rulesOf($schema));
    }

    public function testConclusionMatchesCanonicalPattern(): void
    {
        $schema = $this->profileSchema();

        $tail = ['amount' => 10, 'items' => []];

        $okData = ['name' => '张三', 'type' => 'personal', 'company_name' => ''] + $tail;

        $badData = ['name' => '张三', 'type' => 'company', 'company_name' => ''] + $tail;

        foreach ([$okData, $badData] as $data) {
            $schemaArray = $schema->validationSchema();

            $validate = new Validate();
            $expectedPassed = $validate->check($data, ValidationGenerator::generateFor($schemaArray, $data));
            $expectedAccepted = (new DataFilter())->filter($schemaArray, $data);

            $report = $this->bridge()->check($schema, $data);

            $this->assertSame($expectedPassed, $report->passed, '结论必须与范本一致');
            $this->assertSame($expectedAccepted, $report->accepted, 'accepted 必须等于 DataFilter 的输出');
        }
    }

    public function testConditionalRequiredBothDirections(): void
    {
        $bridge = $this->bridge();
        $schema = $this->profileSchema();

        $company = $bridge->check($schema, ['name' => '张三', 'type' => 'company', 'items' => []]);
        $this->assertFalse($company->passed);
        $this->assertContains('company_name', array_column($company->errors, 'field'), '条件成立时必填必须生效');

        $personal = $bridge->check($schema, ['name' => '张三', 'type' => 'personal', 'items' => []]);
        $this->assertTrue($personal->passed, '条件不成立时不要求（反向用例）');
    }

    /**
     * 记录框架语义：可重复组**容器缺失**时，`items.*.sku` 的 `require` 也会报错
     *
     * 真实表单提交会带上 `items => []`（空数组 = 无行，校验通过），因此 A2 不额外处理；
     * 但 AI 若调用 `form_validate` 时漏传容器，报告里会看到这条错误 —— 这是**如实呈现**，不是误报。
     */
    public function testRepeaterRequiredFiresWhenContainerAbsent(): void
    {
        $report = $this->bridge()->check($this->profileSchema(), ['name' => '张三', 'type' => 'personal']);

        $this->assertFalse($report->passed);
        $this->assertNotEmpty(
            array_filter(array_column($report->errors, 'field'), static fn(string $f): bool => str_starts_with($f, 'items')),
            '容器缺失时也应看到可重复组相关错误：' . json_encode($report->errors, JSON_UNESCAPED_UNICODE)
        );

        $withEmpty = $this->bridge()->check($this->profileSchema(), ['name' => '张三', 'type' => 'personal', 'items' => []]);
        $this->assertTrue($withEmpty->passed, '显式传空数组（无行）时应通过');
    }

    public function testRequiredFieldErrorIsReportedPerField(): void
    {
        $report = $this->bridge()->check($this->profileSchema(), ['type' => 'personal']);

        $this->assertFalse($report->passed);
        $this->assertContains('name', array_column($report->errors, 'field'));
        $this->assertNotSame('', $report->firstError());
        $this->assertStringContainsString('校验不通过', $report->summary());
    }

    public function testCoercedDroppedAndDefaultedFields(): void
    {
        $report = $this->bridge()->check($this->profileSchema(), [
            'name'        => '张三',
            'type'        => 'personal',
            'amount'      => '12.5',
            'not_in_form' => 'x',
        ]);

        $this->assertContains('amount', $report->coerced, '类型转换必须被指出');
        $this->assertSame(12.5, $report->accepted['amount']);
        $this->assertContains('not_in_form', $report->droppedSchema);
        $this->assertArrayNotHasKey('not_in_form', $report->accepted);

        $notes = implode(' ', array_column($report->notes, 'message'));
        $this->assertStringContainsString('默认值', $notes);
    }

    public function testRepeaterRowValidationUsesDotSyntax(): void
    {
        $report = $this->bridge()->check($this->profileSchema(), [
            'name'  => '张三',
            'type'  => 'personal',
            'items' => [['sku' => 'A-1'], ['sku' => '']],
        ]);

        $this->assertFalse($report->passed, '可重复组子字段必填必须逐行生效');
        $this->assertContains('items.*.sku', array_column($report->errors, 'field'));
    }

    public function testInjectionPayloadStaysLiteral(): void
    {
        $payload = "x'; DROP TABLE ws_admins; --";
        $report  = $this->bridge()->check($this->profileSchema(), [
            'name'  => $payload,
            'type'  => 'personal',
            'items' => [],
        ]);

        $this->assertSame($payload, $report->accepted['name'], '载荷只能作为值，且原样落在值位置');
        $this->assertNotContains($payload, array_keys($report->accepted));
        $this->assertTrue($report->passed, '载荷不影响规则求值');
    }


    public function testEnforcedIsFalseWhenValidateBySchemaNotOverridden(): void
    {
        $bridge = $this->bridge();

        $this->assertFalse($bridge->isEnforced('app\\admin\\controller\\Permission'));
        $this->assertStringContainsString('真实提交当前不执行 Schema 校验', $bridge->enforcedNote('app\\admin\\controller\\Permission'));

        $this->assertFalse($bridge->isEnforced(ProfileFormController::class));
    }

    public function testEnforcedIsTrueWhenControllerOverrides(): void
    {
        $bridge = $this->bridge();

        $this->assertTrue($bridge->isEnforced(EnforcingFormController::class));
        $this->assertStringContainsString('会执行同一套 Schema 校验', $bridge->enforcedNote(EnforcingFormController::class));
    }

    public function testReportNeverClaimsPassedMeansSubmittable(): void
    {
        $report = $this->bridge()->check($this->profileSchema(), ['name' => '张三', 'type' => 'personal', 'items' => []]);

        $this->assertTrue($report->passed);
        $this->assertFalse($report->enforced, '未覆写时必须如实回传 enforced=false');
        $this->assertStringContainsString('预检通过 ≠ 提交会被系统校验通过', $report->summary() . $report->enforcedNote);
    }


    public function testToolIsReadOnly(): void
    {
        $tool = $this->tool();

        $this->assertSame('form_validate', $tool->getName(), '工具名由类名推导（BaseTool 约定）');
        $this->assertSame(CapabilityAccess::READ, $tool->getAccess());
        $this->assertSame(CapabilityAccess::RISK_LOW, $tool->getRiskLevel());
        $this->assertFalse($tool->requireConfirmation());
        $this->assertSame('', $tool->getPermission(), 'deny-only：默认不产生人工没有的权限面');
    }

    public function testToolExposesPermissionOnlyWhenExplicitlyEnabled(): void
    {
        $this->assertSame('form.validate', $this->tool(['require_ai_permission' => true])->getPermission());
    }

    /**
     * 把「框架文档范本的写法在 think-validate 下静默失效」钉死（防止将来有人「优化」回去）
     *
     * - 范本：`new Validate($rules)->check($data)` ⇒ 规则被构造参数吞掉（构造函数零参数）⇒ **恒为 true**
     * - 可用：`(new Validate())->check($data, $rules)`
     */
    public function testDocblockValidateFormIsSilentlyBroken(): void
    {
        $rules = ['name' => 'require|string'];
        $data  = ['name' => ''];

        $this->assertTrue(
            (new Validate($rules))->check($data),
            '范本写法不校验（这就是 A2 不能照抄它的原因）'
        );

        $this->assertFalse((new Validate())->check($data, $rules), '规则交给 check() 才生效');
    }

    public function testToolParameterSchema(): void
    {
        $schema = $this->tool()->getParameters();

        $this->assertSame(['target', 'data'], $schema['required']);
        $this->assertSame(['target', 'data', 'is_new'], array_keys($schema['properties']));
    }

    public function testToolReportsUnknownTargetWithAvailableList(): void
    {
        $result = $this->decode($this->tool()->execute(['target' => 'nope.nope', 'data' => []]));
        $json   = json_encode($result, JSON_UNESCAPED_UNICODE);

        $this->assertSame(1, $result['code'], $json);
        $this->assertContains('host.profile_form_controller', $result['available_targets'] ?? [], $json);
    }

    public function testToolRejectsMissingTargetAndBadData(): void
    {
        $missing = $this->decode($this->tool()->execute(['data' => []]));
        $this->assertSame(1, $missing['code']);
        $this->assertStringContainsString('缺少 target', $missing['msg']);

        $bad = $this->decode($this->tool()->execute(['target' => 'host.profile_form_controller', 'data' => 'x']));
        $this->assertSame(1, $bad['code']);
        $this->assertStringContainsString('data 必须是', $bad['msg']);
    }

    public function testToolRejectsTargetWithoutFormHonestly(): void
    {
        $result = $this->decode($this->tool()->execute([
            'target' => 'host.blank_form_controller',
            'data'   => ['x' => 1],
        ]));
        $json = json_encode($result, JSON_UNESCAPED_UNICODE);

        $this->assertSame(1, $result['code'], $json);
        $this->assertSame(FormSchema::SOURCE_NONE, $result['schema_source'] ?? null, $json);
        $this->assertStringContainsString('没有可用的表单声明', (string) $result['msg'], $json);
    }

    public function testToolSuccessResponseContract(): void
    {
        $result = $this->decode($this->tool()->execute([
            'target' => 'host.profile_form_controller',
            'data'   => ['name' => '张三', 'type' => 'personal', 'amount' => '9', 'items' => []],
        ]));
        $json = json_encode($result, JSON_UNESCAPED_UNICODE);

        $this->assertSame(0, $result['code'], $json);
        $this->assertTrue($result['structured'], $json);

        $data = $result['data'];
        foreach ([
            'target', 'readonly', 'passed', 'errors', 'accepted', 'coerced', 'dropped_schema',
            'dropped_whitelist', 'not_writable', 'enforced', 'enforced_note', 'schema_source', 'notes',
        ] as $key) {
            $this->assertArrayHasKey($key, $data, "契约缺字段 {$key}：" . $json);
        }

        $this->assertTrue($data['readonly'], '响应必须声明只读');
        $this->assertSame('host.profile_form_controller', $data['target']);
        $this->assertTrue($data['passed'], $json);
        $this->assertSame(9, $data['accepted']['amount']);
        $this->assertSame(FormSchema::SOURCE_DEFINE_FORM, $data['schema_source'], $json);
        $this->assertFalse($data['enforced'], '真实提交未启用校验必须如实回传');
    }

    public function testToolReportsErrorsWithCodeZeroBecauseCallSucceeded(): void
    {
        $result = $this->decode($this->tool()->execute([
            'target' => 'host.profile_form_controller',
            'data'   => ['type' => 'personal'],
        ]));

        $this->assertSame(0, $result['code']);
        $this->assertFalse($result['data']['passed']);
        $this->assertNotEmpty($result['data']['errors']);
    }

    public function testToolReportsNotWritableSubmittedFields(): void
    {
        $result = $this->decode($this->tool()->execute([
            'target' => 'host.profile_form_controller',
            'data'   => ['name' => '张三', 'type' => 'personal', 'admin_id' => 999],
        ]));

        $this->assertContains('admin_id', $result['data']['not_writable'], '归属字段必须被点名（不为其生成值）');

        $notes = implode(' ', array_column($result['data']['notes'], 'message'));
        $this->assertStringContainsString('不可写字段', $notes);
    }

    public function testReportSummaryMentionsErrorCountWhenFailed(): void
    {
        $report = $this->bridge()->check($this->profileSchema(), ['name' => '张三', 'type' => 'personal']);

        $this->assertInstanceOf(FormValidateReport::class, $report);
        $this->assertStringContainsString('1 项错误', $report->summary());
        $this->assertStringContainsString('items.*.sku', $report->firstError());
    }


    public function testProviderIsGatedByEnabledSwitch(): void
    {
        $this->assertFalse((new FormSchemaToolProvider())->isEnabled(), '默认关闭（需显式开启）');
        $this->assertSame([], (new FormSchemaToolProvider())->getTools(), '关闭时不注册任何工具');

        $provider = new FormSchemaToolProvider(['enabled' => true]);
        $this->assertTrue($provider->isEnabled());
        $this->assertSame('form_schema', $provider->getNamespace());
    }

    public function testProviderRegistersFormToolsUnderBuiltinKeys(): void
    {
        $registry = ToolRegistry::newInstance();
        $count    = (new FormSchemaToolProvider(self::toolSwitches()))->registerTo($registry);

        $this->assertSame(3, $count, '默认工具集（generate 关闭）共 3 个');
        foreach (['form_fields', 'form_fill', 'form_validate'] as $name) {
            $this->assertTrue($registry->has($name), "必须用 builtin 精确键注册（LLM 回调名 = getName()）：{$name}");
        }

        $this->assertInstanceOf(FormValidateTool::class, $registry->get('form_validate'));

        $withGenerate = ToolRegistry::newInstance();
        $countAll     = (new FormSchemaToolProvider(self::toolSwitches(['generate' => true])))->registerTo($withGenerate);

        $this->assertSame(4, $countAll);
        $this->assertTrue($withGenerate->has('form_generate'));
    }

    /**
     * 与出厂配置一致的开关集合（`form_schema.tools`）
     *
     * @param array<string, bool> $overrides
     * @return array<string, mixed>
     */
    private static function toolSwitches(array $overrides = []): array
    {
        return [
            'enabled' => true,
            'tools'   => array_merge([
                'fields'   => true,
                'fill'     => true,
                'validate' => true,
                'generate' => false,
            ], $overrides),
        ];
    }

    public function testGenerateToolIsOptIn(): void
    {
        $off = array_map(
            static fn(object $t): string => $t->getName(),
            (new FormSchemaToolProvider(self::toolSwitches()))->getTools()
        );

        $this->assertSame(['form_fields', 'form_fill', 'form_validate'], $off);
        $this->assertNotContains('form_generate', $off, 'generate 默认不注册（附录四 §8）');

        $on = array_map(
            static fn(object $t): string => $t->getName(),
            (new FormSchemaToolProvider(self::toolSwitches(['generate' => true])))->getTools()
        );

        $this->assertContains('form_generate', $on, '显式开启后才注册');
    }

    /**
     * 出厂配置的安全默认值（防止有人「顺手」把它们改成放纵的默认）
     */
    public function testShippedConfigKeepsSafeDefaults(): void
    {
        $config = \wise_agent_test_config()['form_schema'] ?? [];

        $this->assertFalse($config['enabled'] ?? true, '总开关默认关闭');
        $this->assertFalse($config['tools']['generate'] ?? true, 'form_generate 默认关闭');
        $this->assertSame('never', $config['generate']['write_mode'] ?? null, '写盘默认 never');
        $this->assertSame([], $config['generate']['allowed_paths'] ?? null, '白名单默认为空 ⇒ 一律拒绝');
        $this->assertTrue($config['generate']['require_confirmation'] ?? false, '写盘默认需要确认');
        $this->assertTrue($config['check_anchor_permission'] ?? false, '锚点权限默认开启（验收 12）');
        $this->assertFalse($config['require_ai_permission'] ?? true, 'deny-only：默认不产生人工没有的权限面');
    }

    public function testProviderHonorsPerToolSwitch(): void
    {
        $provider = new FormSchemaToolProvider(self::toolSwitches(['validate' => false]));

        $this->assertFalse($provider->isToolEnabled('validate'));
        $this->assertSame(
            ['form_fields', 'form_fill'],
            array_map(static fn(object $t): string => $t->getName(), $provider->getTools()),
            'tools.validate=false 时只关掉该工具，不影响其它工具'
        );

        $allOff = new FormSchemaToolProvider(self::toolSwitches([
            'fields'   => false,
            'fill'     => false,
            'validate' => false,
            'generate' => false,
        ]));
        $registry = ToolRegistry::newInstance();

        $this->assertSame(0, $allOff->registerTo($registry));
        $this->assertFalse($registry->has('form_validate'));
    }

    public function testProviderExecuteRoutesToTool(): void
    {
        $provider = new FormSchemaToolProvider(self::toolSwitches());

        $context = new \wise\agent\contract\RequestContext();

        $payload = json_decode((string) $provider->execute('form_validate', ['data' => []], $context), true);
        $this->assertSame(1, $payload['code']);
        $this->assertStringContainsString('缺少 target', $payload['msg']);

        $missing = json_decode((string) $provider->execute('form_nope', [], $context), true);
        $this->assertSame(1, $missing['code']);
        $this->assertStringContainsString('not found', $missing['msg'], '未注册的工具名必须诚实报错');

        $generate = json_decode((string) $provider->execute('form_generate', ['fields' => []], $context), true);
        $this->assertSame(1, $generate['code']);
        $this->assertStringContainsString('not found', $generate['msg']);
    }

    public function testToolRejectsOversizedDataPayload(): void
    {
        $tool = $this->tool(['max_data_chars' => 40]);

        $result = $this->decode($tool->execute([
            'target' => 'host.profile_form_controller',
            'data'   => ['name' => str_repeat('长', 100)],
        ]));

        $this->assertSame(1, $result['code'], '超限必须拒绝，不得进入校验');
        $this->assertStringContainsString('data 体量超限', (string) $result['msg']);
        $this->assertSame(40, $result['limit']);

        $ok = $this->decode($this->tool(['max_data_chars' => 4000])->execute([
            'target' => 'host.profile_form_controller',
            'data'   => ['name' => '张三', 'type' => 'personal', 'items' => []],
        ]));
        $this->assertSame(0, $ok['code']);
    }

    public function testReportAcceptedFieldsCanBeReducedToNames(): void
    {
        $result = $this->decode($this->tool(['report_accepted_fields' => false])->execute([
            'target' => 'host.profile_form_controller',
            'data'   => ['name' => '张三', 'type' => 'personal', 'amount' => '9', 'items' => []],
        ]));

        $this->assertSame(0, $result['code']);
        $this->assertIsArray($result['data']['accepted'], '仍要给出「会被写入哪些字段」');
        $this->assertContains('name', $result['data']['accepted'], '只给字段名');
        $this->assertNotContains('张三', $result['data']['accepted'], '不回传库内值');

        $notes = implode(' ', array_column($result['data']['notes'], 'message'));
        $this->assertStringContainsString('仅给出字段名', $notes);
    }

    public function testProviderMetadata(): void
    {
        $metadata = (new FormSchemaToolProvider())->getMetadata();

        $this->assertSame('Form Schema Introspection', $metadata['label']);
        $this->assertStringContainsString('只读', $metadata['description']);
    }
}


/**
 * 目标注册表走夹具内省器（离线），其余行为与产品一致
 */
class TestableFormValidateIntrospector extends FormSchemaIntrospector
{
    protected function metaIntrospector(): MenuActionIntrospector
    {
        return new ProfileFormMetaIntrospector();
    }
}

/**
 * 假内省器：声明「ProfileFormController」有 save、「EnforcingFormController」有 update、
 * 「BlankFormController」有 save（用于覆盖「有目标但无表单」的诚实回退）
 */
class ProfileFormMetaIntrospector extends MenuActionIntrospector
{
    public function resolve(bool $withParameters = true): array
    {
        return [
            MenuActionMeta::forAction(ProfileFormController::class, 'save', ['menuPath' => '/admin/profile/index']),
            MenuActionMeta::forAction(EnforcingFormController::class, 'update', ['menuPath' => '/admin/enforcing/index']),
            MenuActionMeta::forAction(BlankFormController::class, 'save', ['menuPath' => '/admin/blank/index']),
        ];
    }
}

/**
 * 声明了完整表单的夹具控制器（条件必填 + 可重复组 + 数值 + 归属字段）
 */
class ProfileFormController
{
    protected function getModel(): ProfileFormModel
    {
        return new ProfileFormModel();
    }

    protected function defineForm(Form $form): void
    {
        $form->add(Text::make('name', '姓名')->required()->placeholder('请输入姓名'));
        $form->add(Select::make('type', '类型')->options(['company' => '企业', 'personal' => '个人']));
        $form->add(Text::make('company_name', '企业名称')->requiredIf('type', '=', 'company'));
        $form->add(Number::make('amount', '金额'));

        $form->repeater('items', '明细', function (Form $f): void {
            $f->add(Text::make('sku', 'SKU')->required());
        });

        $form->add(Text::make('admin_id', '归属管理员'));
    }
}

/**
 * 覆写了 `validateBySchema()` 的夹具控制器（用于验收 5 的 true 分支）
 */
class EnforcingFormController extends ProfileFormController
{
    protected function validateBySchema(array $data, bool $isNew): array
    {
        return $data;
    }
}

/**
 * 有动作但**没有表单声明**的夹具控制器
 */
class BlankFormController
{
}

class ProfileFormModel
{
    public function getTable(): string
    {
        return 'profile_forms';
    }

    /**
     * @return string[]|null
     */
    public function getAllowWriteFields(): ?array
    {
        return null;
    }
}
