<?php
declare(strict_types=1);

namespace wise\agent\test;

use PHPUnit\Framework\TestCase;
use wise\agent\form\FormDraftWriter;
use wise\agent\form\FormFieldsTool;
use wise\agent\form\FormGenerateTool;
use wise\agent\form\FormGenerator;
use wise\agent\form\FormSchemaIntrospector;
use wise\agent\menu\MenuActionIntrospector;
use wise\agent\menu\MenuActionMeta;
use wise\agent\menu\MenuActionResult;
use wise\agent\security\CapabilityAccess;
use wise\form\schema\field\Select;
use wise\form\schema\field\Text;
use wise\form\schema\Form;

/**
 * A2-4 回归：`form_generate` + `FormDraftWriter` + 锚点权限（附录四 验收 8、9、12、13）
 *
 * - **验收 8（草案质量）**：三道自检逐项通过；非法规格逐条被拒（不猜）；`diff` 准确；
 * - **验收 9（assisted 写盘）**：白名单 / 确认 / 模式三重前置；写盘成功 + 语法检查 + 审计留痕；
 *   语法失败**自动回滚**；**仅改 `defineForm()` 段**（其它代码字节不变）；
 * - **验收 12（权限一致）**：锚点判定被拒 ⇒ 与 A1 相同的 403（`stage=guard`）；
 * - **验收 13（契约同构）**：`code/msg/structured/text` 与 A1/A3 同核。
 *
 * 写盘用例全部在**系统临时目录**里做，setUp/tearDown 自建自清，不碰仓库文件。
 */
class FormGenerateTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        FormSchemaIntrospector::resetCache();

        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wise_form_gen_' . bin2hex(random_bytes(6));
        @mkdir($this->root . DIRECTORY_SEPARATOR . 'app', 0777, true);
    }

    protected function tearDown(): void
    {
        if ($this->root !== '' && is_dir($this->root)) {
            foreach (glob($this->root . '/app/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->root . DIRECTORY_SEPARATOR . 'app');
            @rmdir($this->root);
        }
    }

    private function introspector(): TestableGenerateIntrospector
    {
        return new TestableGenerateIntrospector();
    }

    /**
     * @param array<string, mixed> $options `form_schema.*` 覆盖项
     */
    private function tool(array $options = [], ?string $root = null): TestableFormGenerateTool
    {
        return new TestableFormGenerateTool(
            $options,
            $this->introspector(),
            new FormGenerator(),
            new TestableFormDraftWriter($options['generate'] ?? [], $root ?? $this->root)
        );
    }

    private function decode(string $json): array
    {
        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded, '响应必须是 JSON');

        return $decoded;
    }

    /**
     * 一份典型规格（覆盖多种字段类型）
     *
     * @return array<int, array<string, mixed>>
     */
    private function specs(): array
    {
        return [
            ['name' => 'title', 'label' => '标题', 'type' => 'text', 'required' => true, 'placeholder' => '请输入标题'],
            ['name' => 'status', 'label' => '状态', 'type' => 'select', 'options' => ['1' => '启用', '0' => '禁用'], 'default' => '1'],
            ['name' => 'amount', 'label' => '金额', 'type' => 'number', 'min' => 0, 'max' => 10000, 'step' => 0.01],
            ['name' => 'publish_at', 'label' => '发布时间', 'type' => 'datetime'],
            ['name' => 'is_top', 'label' => '置顶', 'type' => 'switch'],
            ['name' => 'remark', 'label' => '备注', 'type' => 'textarea', 'help' => '最多 200 字'],
        ];
    }


    public function testDraftPassesAllThreeSelfChecks(): void
    {
        $draft = (new FormGenerator())->draft('demo_forms', $this->specs());

        $this->assertTrue($draft->ok);
        $this->assertTrue($draft->valid(), json_encode($draft->validated, JSON_UNESCAPED_UNICODE));
        $this->assertTrue($draft->validated['type_ok'], '① 类型必须全部命中 FieldType');
        $this->assertTrue($draft->validated['instantiable'], '② Form::fromArray() 必须可往返');
        $this->assertTrue($draft->validated['rules_ok'], '③ ValidationGenerator::generate() 不得抛异常');
        $this->assertSame([], $draft->validated['errors']);
    }

    public function testDraftCodeUsesRealFieldClassesAndKeepsConstraints(): void
    {
        $draft = (new FormGenerator())->draft('demo_forms', $this->specs());

        $this->assertStringContainsString('protected function defineForm(Form $form): void', $draft->code);

        $this->assertStringContainsString("Text::make('title', '标题')->required()->placeholder('请输入标题')", $draft->code);
        $this->assertStringContainsString("Select::make('status', '状态')->options([1 => '启用', 0 => '禁用'])->default('1')", $draft->code);
        $this->assertStringContainsString('Number::make(\'amount\', \'金额\')->min(0)->max(10000)->step(0.01)', $draft->code);
        $this->assertStringContainsString('Datetime::make(', $draft->code);
        $this->assertStringContainsString('SwitchField::make(', $draft->code);
        $this->assertStringContainsString('Textarea::make(', $draft->code);
        $this->assertStringContainsString('->help(', $draft->code);

        $this->assertContains('use wise\form\schema\Form;', $draft->useStatements);
        $this->assertStringNotContainsString('use wise\form\schema', $draft->code, 'use 语句单独回传，不混在片段里');

        $this->assertStringNotContainsString('->range(', $draft->code);
    }

    public function testEveryAllowedTypeIsAcceptedAndEveryMappedClassExists(): void
    {
        $generator = new FormGenerator();

        foreach (FormGenerator::allowedTypes() as $type) {
            $spec = ['name' => 'f_' . $type, 'label' => $type, 'type' => $type];

            if (in_array($type, ['select', 'radio', 'checkbox'], true)) {
                $spec['options'] = ['1' => '是'];
            }

            $draft = $generator->draft('t', [$spec]);

            $this->assertTrue($draft->ok, "类型 {$type} 必须被接受：" . json_encode($draft->errors, JSON_UNESCAPED_UNICODE));
        }

        $this->assertCount(17, FormGenerator::allowedTypes(), '= FieldType 全集（17 项）');
    }

    public function testUnknownTypeIsRejectedWithClarificationNotGuessed(): void
    {
        $draft = (new FormGenerator())->draft('t', [
            ['name' => 'weird', 'label' => '怪字段', 'type' => 'markdown_editor'],
        ]);

        $this->assertFalse($draft->ok);
        $this->assertSame('', $draft->code, '非法规格不得产出半成品代码');
        $this->assertSame(FormGenerator::REJECT_UNKNOWN_TYPE, $draft->errors[0]['code']);
        $this->assertStringContainsString('markdown_editor', $draft->errors[0]['message']);
        $this->assertStringContainsString('允许的类型', $draft->notes[0]['message']);
    }

    public function testRegexRuleIsRejectedAsDesigned(): void
    {
        $draft = (new FormGenerator())->draft('t', [
            ['name' => 'code', 'label' => '编码', 'type' => 'text', 'rules' => ['/^[A-Z]{3}-\d{4}$/']],
        ]);

        $this->assertFalse($draft->ok);
        $this->assertSame(FormGenerator::REJECT_REGEX_RULE, $draft->errors[0]['code']);
        $this->assertStringContainsString('人工编写', $draft->errors[0]['message']);
    }

    public function testInvalidNamesDuplicatesAndMissingOptionsAreRejected(): void
    {
        $draft = (new FormGenerator())->draft('t', [
            ['name' => 'bad-name', 'label' => 'X', 'type' => 'text'],
            ['name' => 'dup', 'label' => 'D1', 'type' => 'text'],
            ['name' => 'dup', 'label' => 'D2', 'type' => 'text'],
            ['name' => 'status', 'label' => '状态', 'type' => 'select'],
        ]);

        $codes = array_column($draft->errors, 'code');

        $this->assertFalse($draft->ok);
        $this->assertContains(FormGenerator::REJECT_BAD_NAME, $codes);
        $this->assertContains(FormGenerator::REJECT_DUPLICATE_NAME, $codes);
        $this->assertContains(FormGenerator::REJECT_MISSING_OPTIONS, $codes);
    }

    public function testDiffAgainstExistingDeclarationIsAccurate(): void
    {
        $generator = new FormGenerator();
        $existing  = $this->introspector()->forController(GenFormController::class);

        $draft = $generator->draft($existing->formName, [
            ['name' => 'title', 'label' => '标题', 'type' => 'text', 'required' => true],
            ['name' => 'status', 'label' => '状态', 'type' => 'select', 'options' => ['1' => '启用', '0' => '禁用']],
            ['name' => 'amount', 'label' => '金额', 'type' => 'number'],
        ]);

        $diff = $generator->diff($draft->schema, $existing);

        $this->assertSame(['amount'], $diff['added']);
        $this->assertSame(['remark'], $diff['removed']);
        $this->assertSame(['title'], $diff['unchanged']);
        $this->assertArrayHasKey('status', $diff['changed']);
        $this->assertArrayHasKey('options', $diff['changed']['status']);
    }

    public function testDiffAgainstNothingMarksEverythingAdded(): void
    {
        $generator = new FormGenerator();
        $draft     = $generator->draft('t', $this->specs());

        $diff = $generator->diff($draft->schema, null);

        $this->assertSame(['title', 'status', 'amount', 'publish_at', 'is_top', 'remark'], $diff['added']);
        $this->assertSame([], $diff['removed']);
        $this->assertSame([], $diff['changed']);
    }


    private function makeTargetFile(string $eol = "\n"): string
    {
        $file = $this->root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'GenFormController.php';

        $source = <<<'PHP'
<?php
namespace app\admin\controller;

use wise\form\schema\Form;
use wise\form\schema\field\Select;
use wise\form\schema\field\Text;

class GenFormController
{
    protected function defineForm(Form $form): void
    {
        $form->add(Text::make('title', '标题')->required());   // 旧声明
    }

    public function index()
    {
        return 'untouched';
    }
}
PHP;

        $source = str_replace(["\r\n", "\r"], "\n", $source);

        if ($eol !== "\n") {
            $source = str_replace("\n", $eol, $source);
        }

        file_put_contents($file, $source);

        return $file;
    }

    private function assistedOptions(array $overrides = []): array
    {
        return [
            'write_mode'    => FormDraftWriter::MODE_ASSISTED,
            'allowed_paths' => ['app'],
            'require_confirmation' => true,
        ] + $overrides;
    }

    public function testWriteRefusedWhenModeIsNeverAndDiskUntouched(): void
    {
        $file   = $this->makeTargetFile();
        $before = (string) file_get_contents($file);

        $result = $this->decode($this->tool([], $this->root)->execute([
            'target' => GenFormController::TARGET,
            'fields' => $this->specs(),
            'write'  => true,
            'confirm' => true,
            'file'   => 'app/GenFormController.php',
        ]));

        $this->assertFalse($result['data']['written']);
        $this->assertSame('never', $result['data']['write_mode']);
        $this->assertSame(0, $result['code'], 'write_mode=never 时「不写盘」不是失败，只是没写');
        $this->assertSame($before, (string) file_get_contents($file), '磁盘必须零变更');
    }

    public function testWriteRefusedWhenPathNotAllowed(): void
    {
        $file   = $this->makeTargetFile();
        $before = (string) file_get_contents($file);

        $writer = new TestableFormDraftWriter($this->assistedOptions(), $this->root);
        $result = $writer->write(
            (new FormGenerator())->draft('gen_form_controller', $this->specs()),
            'other/Nope.php',
            true,
            GenFormController::TARGET
        );

        $this->assertFalse($result['ok']);
        $this->assertSame(FormDraftWriter::REFUSE_PATH, $result['refused']);
        $this->assertSame($before, (string) file_get_contents($file), '路径不在白名单 ⇒ 磁盘不变');
    }

    public function testWriteRefusedWithoutConfirmation(): void
    {
        $file   = $this->makeTargetFile();
        $before = (string) file_get_contents($file);

        $writer = new TestableFormDraftWriter($this->assistedOptions(), $this->root);
        $result = $writer->write(
            (new FormGenerator())->draft('gen_form_controller', $this->specs()),
            'app/GenFormController.php',
            false,
            GenFormController::TARGET
        );

        $this->assertFalse($result['ok']);
        $this->assertSame(FormDraftWriter::REFUSE_CONFIRM, $result['refused']);
        $this->assertSame($before, (string) file_get_contents($file));
    }

    public function testWriteSucceedsAndOnlyDefineFormBodyChanges(): void
    {
        $file   = $this->makeTargetFile();
        $before = (string) file_get_contents($file);

        $result = $this->decode($this->tool([
            'generate' => $this->assistedOptions(),
        ], $this->root)->execute([
            'target'  => GenFormController::TARGET,
            'fields'  => $this->specs(),
            'write'   => true,
            'confirm' => true,
            'file'    => 'app/GenFormController.php',
        ]));

        $json = json_encode($result, JSON_UNESCAPED_UNICODE);
        $after = (string) file_get_contents($file);

        $this->assertSame(0, $result['code'], $json);
        $this->assertTrue($result['data']['written'], $json);
        $this->assertNotNull($result['data']['backup']);
        $this->assertFileExists((string) $result['data']['backup'], '写前必须备份');

        $this->assertSame('wise-agent:form_generate', $result['data']['audit']['ai_source'] ?? null, $json);
        $this->assertSame('form_generate.write', $result['data']['audit']['action'] ?? null, $json);

        $this->assertStringContainsString('->options([1 => \'启用\', 0 => \'禁用\'])', $after);
        $this->assertStringNotContainsString('旧声明', $after);
        $this->assertStringContainsString("    public function index()\n    {\n        return 'untouched';\n    }", $after);
        $this->assertSame(substr($before, 0, 20), substr($after, 0, 20));
        $this->assertStringContainsString('use wise\form\schema\field\Text;', $after, '文件头部的 use 不受影响');

        $this->assertSame([], $this->lintFile($file), '写盘后的文件必须能通过 php -l');
    }

    public function testSyntaxFailureRollsBackToBackup(): void
    {
        $file   = $this->makeTargetFile();
        $before = (string) file_get_contents($file);

        $writer = new TestableFormDraftWriter($this->assistedOptions(), $this->root);
        $writer->lintResult = ['ok' => false, 'output' => 'PHP Parse error: 语法错误'];

        $result = $writer->write(
            (new FormGenerator())->draft('gen_form_controller', $this->specs()),
            'app/GenFormController.php',
            true,
            GenFormController::TARGET
        );

        $this->assertFalse($result['ok']);
        $this->assertSame(FormDraftWriter::REFUSE_LINT, $result['refused']);
        $this->assertSame($before, (string)file_get_contents($file), '语法检查失败必须回滚');
        $this->assertSame('form_generate.write_rolled_back', $result['audit']['action'] ?? null);
        $this->assertNotEmpty($result['notes']);
    }

    public function testWriteRefusedWhenDefineFormNotFound(): void
    {
        $file = $this->root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'NoForm.php';
        file_put_contents($file, "<?php\nclass NoForm { public function index() {} }\n");
        $before = (string) file_get_contents($file);

        $writer = new TestableFormDraftWriter($this->assistedOptions(), $this->root);
        $result = $writer->write(
            (new FormGenerator())->draft('x', $this->specs()),
            'app/NoForm.php',
            true
        );

        $this->assertFalse($result['ok']);
        $this->assertSame(FormDraftWriter::REFUSE_NO_DEFINE_FORM, $result['refused']);
        $this->assertSame($before, (string) file_get_contents($file));
    }

    public function testWriteRefusedForInvalidDraft(): void
    {
        $file = $this->makeTargetFile();

        $writer = new TestableFormDraftWriter($this->assistedOptions(), $this->root);
        $draft  = (new FormGenerator())->draft('t', [['name' => 'x', 'type' => 'nope']]);
        $result = $writer->write($draft, 'app/GenFormController.php', true);

        $this->assertFalse($result['ok']);
        $this->assertSame(FormDraftWriter::REFUSE_INVALID_DRAFT, $result['refused']);
    }

    public function testWriterPreservesTargetFileLineEndings(): void
    {
        $file = $this->makeTargetFile("\r\n");

        $writer = new TestableFormDraftWriter($this->assistedOptions(), $this->root);
        $result = $writer->write(
            (new FormGenerator())->draft('gen_form_controller', $this->specs()),
            'app/GenFormController.php',
            true,
            GenFormController::TARGET
        );

        $after = (string) file_get_contents($file);

        $this->assertTrue($result['ok'], json_encode($result['notes'], JSON_UNESCAPED_UNICODE));
        $this->assertStringContainsString("->placeholder('请输入标题'));\r\n", $after, '插入的行必须沿用 CRLF');
        $this->assertSame(0, preg_match('/(?<!\r)\n/', $after), 'CRLF 文件里不得出现裸 LF');
    }

    /**
     * 回归：`bodyOf()` 必须只留下字段声明行
     *
     * ⚠️ 曾用 `preg_split('/\R/')` 切分代码 —— 非 UTF-8 模式下 `\R` 会把中文注释里的 0x85
     * 字节当作分隔符，把「…合入）」劈成半个字符，写进文件后直接 Parse error。
     */
    public function testBodyOfKeepsOnlyFieldLinesWithoutMultibyteSplitting(): void
    {
        $draft = (new FormGenerator())->draft('demo_forms', $this->specs());
        $body  = (new TestableFormDraftWriter([], $this->root))->bodyOf($draft->code);

        $this->assertCount(count($this->specs()), $body);

        foreach ($body as $line) {
            $this->assertStringStartsWith('$form->add(', $line, '方法体只允许字段声明行：' . $line);
        }

        $this->assertStringNotContainsString('表单声明', implode("\n", $body), 'docblock 不得进方法体');
    }

    public function testReplaceDefineFormSurvivesBracesInStringsAndComments(): void
    {
        $source = <<<'PHP'
<?php
class C
{
    protected function defineForm(Form $form): void
    {
        $form->add(Text::make('a', '} 不在字符串外'));   // 注释里的 } 也不算
        $form->add(Text::make('b', '{'));
    }

    protected function other(): string { return 'x'; }
}
PHP;

        $writer  = new TestableFormDraftWriter([], $this->root);
        $patched = $writer->replaceDefineForm($source, ["\$form->add(Text::make('new', '新'));"]);

        $this->assertNotNull($patched);
        $this->assertStringContainsString("Text::make('new', '新')", $patched);
        $this->assertStringNotContainsString('不在字符串外', $patched);
        $this->assertStringContainsString("protected function other(): string { return 'x'; }", $patched, 'defineForm 之后的代码必须原样保留');
    }


    public function testAnchorDenialProducesSame403AsA1(): void
    {
        $fields = new DenyingFieldsTool([], $this->introspector());
        $result = $this->decode($fields->execute(['target' => GenFormController::TARGET]));

        $json = json_encode($result, JSON_UNESCAPED_UNICODE);

        $this->assertSame(403, $result['code'], $json);
        $this->assertSame('guard', $result['stage'], $json);
        $this->assertSame('rbac', $result['blocked_by'] ?? null, $json);
        $this->assertStringContainsString('被 RbacMiddleware 拒绝', (string) $result['msg'], $json);
        $this->assertArrayNotHasKey('fields', $result['data'] ?? [], '被拒时不回传任何目录内容');
    }

    public function testAnchorDenialAlsoCoversFillAndGenerateTools(): void
    {
        $fill = $this->decode((new DenyingFillTool([], $this->introspector()))->execute([
            'target' => GenFormController::TARGET,
            'values' => ['title' => 'x'],
        ]));

        $this->assertSame(403, $fill['code']);
        $this->assertSame('guard', $fill['stage']);

        $generate = $this->decode($this->tool([], $this->root)->execute([
            'target' => GenFormController::TARGET,
            'fields' => $this->specs(),
        ]));

        $this->assertSame(0, $generate['code'], json_encode($generate, JSON_UNESCAPED_UNICODE));
    }

    public function testAnchorGuardIsSkippedWithoutRequestContext(): void
    {
        $guard = new \wise\agent\form\FormAnchorGuard();

        $this->assertNull($guard->check(GenFormController::class, GenFormController::TARGET), '离线/CLI 不做判定');
        $this->assertNotEmpty($guard->trace());
        $this->assertSame('anchor.skipped', $guard->trace()[0]['step']);
    }

    public function testAnchorGuardCanBeTurnedOffExplicitly(): void
    {
        $guard = new \wise\agent\form\FormAnchorGuard(['check_anchor_permission' => false]);

        $this->assertNull($guard->check(GenFormController::class));
        $this->assertSame('anchor.skipped', $guard->trace()[0]['step']);
    }


    public function testSuccessResponseHasSameEnvelopeAsA1A3(): void
    {
        $result = $this->decode($this->tool([
            'generate' => ['write_mode' => 'never'],
        ], $this->root)->execute([
            'target' => GenFormController::TARGET,
            'fields' => $this->specs(),
        ]));

        foreach (['code', 'msg', 'structured', 'data', 'text'] as $key) {
            $this->assertArrayHasKey($key, $result, '契约缺字段 ' . $key);
        }

        $this->assertSame(0, $result['code']);
        $this->assertTrue($result['structured']);

        foreach (['code', 'use_statements', 'validated', 'errors', 'diff', 'written', 'notes'] as $key) {
            $this->assertArrayHasKey($key, $result['data'], 'data 缺字段 ' . $key);
        }
    }

    public function testInvalidSpecsReturnCodeOneWithHint(): void
    {
        $result = $this->decode($this->tool([], $this->root)->execute([
            'target' => GenFormController::TARGET,
            'fields' => [['name' => 'x', 'type' => 'unknown_type']],
        ]));

        $this->assertSame(1, $result['code']);
        $this->assertTrue($result['structured']);
        $this->assertStringContainsString('修正 errors', (string) ($result['hint'] ?? ''));
        $this->assertSame(FormGenerator::REJECT_UNKNOWN_TYPE, $result['data']['errors'][0]['code']);
    }

    public function testToolIsReadOnlyByDefaultAndWriteWhenAssisted(): void
    {
        $read = new FormGenerateTool([], $this->introspector(), new FormGenerator(), new TestableFormDraftWriter([], $this->root));

        $this->assertSame('form_generate', $read->getName());
        $this->assertSame('never', $read->writeMode());
        $this->assertSame(CapabilityAccess::READ, $read->getAccess(), '只出草案时就是只读工具');
        $this->assertFalse($read->requireConfirmation());

        $write = $this->tool(['generate' => $this->assistedOptions()], $this->root);

        $this->assertSame(CapabilityAccess::WRITE, $write->getAccess(), 'assisted 时必须按写入工具分类');
        $this->assertTrue($write->requireConfirmation());
        $this->assertSame('form.generate', $this->tool(['require_ai_permission' => true], $this->root)->getPermission());
    }

    public function testAtLeastOneFieldIsRequired(): void
    {
        $result = $this->decode($this->tool([], $this->root)->execute(['target' => GenFormController::TARGET]));

        $this->assertSame(1, $result['code']);
        $this->assertStringContainsString('缺少 fields', (string) $result['msg']);
        $this->assertNotEmpty($result['allowed_types']);
    }


    /**
     * @return string[] php -l 的输出（空数组 = 通过）
     */
    private function lintFile(string $file): array
    {
        if (!function_exists('exec')) {
            return [];
        }

        $out  = [];
        $code = 0;
        @exec(sprintf('%s -l %s 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg($file)), $out, $code);

        return $code === 0 ? [] : $out;
    }
}


class TestableGenerateIntrospector extends FormSchemaIntrospector
{
    protected function metaIntrospector(): MenuActionIntrospector
    {
        return new GenerateTestMetaIntrospector();
    }
}

class GenerateTestMetaIntrospector extends MenuActionIntrospector
{
    public function resolve(bool $withParameters = true): array
    {
        return [
            MenuActionMeta::forAction(GenFormController::class, 'save', ['menuPath' => '/admin/gen_form/index']),
        ];
    }
}

/**
 * 可注入「写盘根目录 / 语法检查结果」的写盘器
 */
class TestableFormDraftWriter extends FormDraftWriter
{
    /** @var array{ok:bool, output:string, skipped?:bool}|null null = 用真实 php -l */
    public ?array $lintResult = null;

    protected function lint(string $file): array
    {
        return $this->lintResult ?? parent::lint($file);
    }
}

class TestableFormGenerateTool extends FormGenerateTool
{
}

/**
 * 锚点判定被拒的目录工具（模拟「无该菜单权限的管理员」）
 */
class DenyingFieldsTool extends FormFieldsTool
{
    protected function anchorGuard(): \wise\agent\form\FormAnchorGuard
    {
        return new DenyingAnchorGuard();
    }
}

class DenyingFillTool extends \wise\agent\form\FormFillTool
{
    protected function anchorGuard(): \wise\agent\form\FormAnchorGuard
    {
        return new DenyingAnchorGuard();
    }
}

/**
 * 固定拒绝的锚点判定器（结构**逐字段对齐** A1 的 `translateBlocked()`）
 */
class DenyingAnchorGuard extends \wise\agent\form\FormAnchorGuard
{
    public function check(string $controller, string $target = ''): ?array
    {
        return MenuActionResult::failure(
            '与人工访问 /admin/gen_form/getList 的判定一致：被 RbacMiddleware 拒绝',
            403,
            [
                'target'     => $target,
                'controller' => $controller,
                'action'     => 'getList',
                'blocked_by' => 'rbac',
                'stage'      => MenuActionResult::STAGE_GUARD,
                'hint'       => '该表单目标绑定的菜单没有对当前用户开放。',
            ]
        );
    }
}

/**
 * 已声明表单的夹具控制器（用于 diff）
 */
class GenFormController
{
    public const TARGET = 'host.gen_form_controller';

    protected function defineForm(Form $form): void
    {
        $form->add(Text::make('title', '标题')->required());
        $form->add(Select::make('status', '状态')->options(['1' => '开启']));
        $form->add(Text::make('remark', '备注'));
    }
}
