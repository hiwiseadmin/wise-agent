<?php
declare(strict_types=1);

namespace wise\agent\form;

use wise\agent\menu\MenuActionResult;
use wise\agent\query\exception\QueryParamException;
use wise\agent\security\CapabilityAccess;
use wise\agent\tool\BaseTool;

/**
 * `form_generate` —— 表单代码草案生成（A2-4）
 *
 * 输入字段规格（由外层 Agent 从自然语言中**结构化**出来），输出 `defineForm()` 代码草案 +
 * 三道自检结论 + 与现有声明的 `diff`；**默认不写盘**（`write_mode=never`）。
 *
 * ## 为什么「自然语言 → 字段类型」这一步不在这里做
 *
 * 因此本工具的参数 Schema 里 `fields[].type` 直接枚举 17 项（LLM 选不到第 18 种），
 * 工具只负责「校验 + 渲染 + 自检 + 差异」——与 A2-3 的 `form_fill` **同一条设计原则**：
 * 工具保持确定性，语义推断交给外层 Agent。
 *
 * ## 风险等级随配置变化（可直接核对）
 *
 * | `write_mode` | `getAccess()` | `requireConfirmation()` | 行为 |
 * |---|---|---|---|
 * | `never`（默认） | `READ` | `false` | 只回草案，**磁盘零变更** |
 * | `assisted` | `WRITE` | `true` | 满足 6 项前置才写盘（见 {@see FormDraftWriter}） |
 *
 * 这样「工具的风险分类」不会撒谎：只出草案时它就是一个只读工具。
 *
 * ## 可测试性
 * `introspector()` / `generator()` / `writer()` 是 `protected` 接缝，故本类**不加 `final`**。
 */
class FormGenerateTool extends BaseTool
{
    use FormAnchorAware;

    public const NAME = 'form_generate';

    /** @var array<string, mixed> */
    private array $options;

    private FormSchemaIntrospector $introspector;

    private FormGenerator $generator;

    private FormDraftWriter $writer;

    /**
     * @param array<string, mixed> $options `form_schema.*` 覆盖项
     */
    public function __construct(
        array $options = [],
        ?FormSchemaIntrospector $introspector = null,
        ?FormGenerator $generator = null,
        ?FormDraftWriter $writer = null
    ) {
        $this->options      = $options;
        $this->introspector = $introspector ?? new FormSchemaIntrospector($options);
        $this->generator    = $generator ?? new FormGenerator();
        $this->writer       = $writer ?? new FormDraftWriter($options);
    }

    public function getDescription(): string
    {
        $mode = $this->writeMode();

        return '生成 `CrudSchemaTrait::defineForm()` 的代码草案（含分组/必填/枚举/数值约束），'
            . '并回传三道自检结论（类型合法 / 可实例化 / 规则可生成）与和现有声明的 diff。'
            . '字段类型必须是这 17 种之一：' . implode('/', FormGenerator::allowedTypes()) . '；'
            . '不接受自定义正则规则（请人工写）。'
            . ($mode === FormDraftWriter::MODE_ASSISTED
                ? '当前 write_mode=assisted：只有目标文件在白名单内、且带 confirm=true 时才会写盘（写前备份、写后 php -l 校验，失败自动回滚）。'
                : '当前 write_mode=never：**不会写任何文件**，草案请人工复核后粘贴，或用 `php think wise:crud` 落盘。');
    }

    /**
     * @return array<string, mixed>
     */
    public function getParameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'target'      => [
                    'type'        => 'string',
                    'description' => '表单目标（用于取表单名与算 diff），如 host.permission；新建时可省略。',
                ],
                'fields'      => [
                    'type'        => 'array',
                    'description' => '字段规格列表（由你从自然语言中结构化出来）。字段名必须与真实列名一致；不要编造字段类型。',
                    'items'       => [
                        'type'       => 'object',
                        'properties' => [
                            'name'        => ['type' => 'string', 'description' => '字段名（字母/下划线开头）'],
                            'label'       => ['type' => 'string', 'description' => '中文标签'],
                            'type'        => [
                                'type'        => 'string',
                                'enum'        => FormGenerator::allowedTypes(),
                                'description' => '字段类型（只能取枚举里的值）',
                            ],
                            'required'    => ['type' => 'boolean', 'description' => '是否必填'],
                            'default'     => ['type' => 'string', 'description' => '默认值'],
                            'placeholder' => ['type' => 'string', 'description' => '占位提示'],
                            'help'        => ['type' => 'string', 'description' => '字段说明'],
                            'options'     => [
                                'type'        => 'object',
                                'description' => '可选值（value → label）；select/radio/checkbox 必填。枚举语义请先用 form_fields 参考同表其它字段。',
                            ],
                            'multiple'    => ['type' => 'boolean', 'description' => '是否多选'],
                            'min'         => ['type' => 'number', 'description' => '数值最小值（不要用 range）'],
                            'max'         => ['type' => 'number', 'description' => '数值最大值'],
                            'step'        => ['type' => 'number', 'description' => '数值步长'],
                            'format'      => ['type' => 'string', 'description' => '日期时间格式，如 Y-m-d'],
                        ],
                        'required'   => ['name', 'type'],
                    ],
                ],
                'instruction' => [
                    'type'        => 'string',
                    'description' => '原始自然语言需求（仅用于回显与审计，不参与字段类型推断）。',
                ],
                'write'       => ['type' => 'boolean', 'description' => '是否请求写盘（默认 false，仅在 write_mode=assisted 时有效）'],
                'confirm'     => ['type' => 'boolean', 'description' => '人工确认标记（assisted 模式下必须为 true）'],
                'file'        => ['type' => 'string', 'description' => '目标文件（相对应用根，如 app/admin/controller/Foo.php；仅写盘时需要）'],
            ],
            'required'   => ['fields'],
        ];
    }

    /**
     * 风险分类**随配置变化**：只出草案时是只读工具
     */
    public function getAccess(): string
    {
        return $this->writeMode() === FormDraftWriter::MODE_ASSISTED
            ? CapabilityAccess::WRITE
            : CapabilityAccess::READ;
    }

    public function getRiskLevel(): string
    {
        return $this->writeMode() === FormDraftWriter::MODE_ASSISTED
            ? CapabilityAccess::RISK_MEDIUM
            : CapabilityAccess::RISK_LOW;
    }

    /**
     * 写盘一律需要人工确认－置条件 ③）
     */
    public function requireConfirmation(): bool
    {
        return $this->writeMode() === FormDraftWriter::MODE_ASSISTED
            && (bool) $this->genOpt('require_confirmation', true);
    }

    public function getPermission(): string
    {
        if (!(bool) $this->opt('require_ai_permission', false)) {
            return '';
        }

        return 'form.generate';
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function execute(array $arguments): string
    {
        $targetName  = trim((string) ($arguments['target'] ?? ''));
        $fields      = $arguments['fields'] ?? [];
        $instruction = trim((string) ($arguments['instruction'] ?? ''));
        $file        = trim((string) ($arguments['file'] ?? ''));
        $wantWrite   = (bool) ($arguments['write'] ?? false);
        $confirmed   = (bool) ($arguments['confirm'] ?? false);

        if (!is_array($fields) || $fields === []) {
            return $this->encode(MenuActionResult::failure('缺少 fields：请给出字段规格列表（name + type 必填）', QueryParamException::CODE_INVALID, [
                'allowed_types' => FormGenerator::allowedTypes(),
                'stage'         => MenuActionResult::STAGE_ARGUMENTS,
            ]));
        }

        $controller = null;
        $existing   = null;
        $formName   = '';

        if ($targetName !== '') {
            $controller = $this->introspector()->resolveTarget($targetName);

            if ($controller === null) {
                return $this->encode(MenuActionResult::failure(
                    sprintf('未知表单目标 %s', $targetName),
                    QueryParamException::CODE_INVALID,
                    [
                        'target'            => $targetName,
                        'available_targets' => $this->introspector()->availableTargets(),
                        'stage'             => MenuActionResult::STAGE_ARGUMENTS,
                    ]
                ));
            }

            if (($blocked = $this->anchorBlocked($controller, $targetName)) !== null) {
                return $this->encode($blocked);
            }
        }

        if ($targetName === '' && $this->writeMode() === FormDraftWriter::MODE_ASSISTED) {
            return $this->encode(MenuActionResult::failure(
                '省略 target 时无法做锚点权限判定，write_mode=assisted 下不允许写盘；请显式指定 target，或保持 write_mode=never（仅回草案）',
                QueryParamException::CODE_INVALID,
                ['stage' => MenuActionResult::STAGE_ARGUMENTS]
            ));
        }

        try {
            if ($controller !== null) {
                $existing = $this->introspector()->forController($controller);
                $formName = $existing->formName !== ''
                    ? $existing->formName
                    : substr((string) strrchr('\\' . $controller, '\\'), 1);
            }

            $draft = $this->generator()->draft($formName !== '' ? $formName : 'form', $fields);

            $data = [
                'target'         => $targetName,
                'write_mode'     => $this->writeMode(),
                'instruction'    => $instruction,
                'ok'             => $draft->ok,
                'form_name'      => $draft->formName,
                'code'           => $draft->code,
                'use_statements' => $draft->useStatements,
                'validated'      => $draft->validated,
                'errors'         => $draft->errors,
                'diff'           => $this->generator()->diff($draft->schema, $existing),
                'written'        => false,
                'file'           => null,
                'backup'         => null,
                'audit'          => null,
                'notes'          => array_merge($draft->notes, $instruction !== '' ? [[
                    'code'    => 'draft.instruction',
                    'message' => '本草案依据的原始需求：' . $instruction,
                ]] : []),
            ];

            if (!$draft->ok) {
                return $this->encode(MenuActionResult::failure(
                    '字段规格存在非法项，未生成草案',
                    QueryParamException::CODE_INVALID,
                    [
                        'structured' => true,
                        'data'       => $data,
                        'text'       => '',
                        'target'     => $targetName,
                        'stage'      => MenuActionResult::STAGE_ARGUMENTS,
                        'hint'       => '请修正 errors 后重试；字段类型必须是 17 种之一，且不要写自定义正则规则。',
                    ]
                ));
            }

            if (!$wantWrite || $this->writeMode() !== FormDraftWriter::MODE_ASSISTED) {
                if ($wantWrite && $file === '') {
                    $data['notes'][] = ['code' => 'write.skipped', 'message' => '未提供 file，未写盘'];
                }

                return $this->encode(MenuActionResult::normalize([
                    'code' => 0,
                    'msg'  => sprintf('已生成 %d 个字段的草案（write_mode=%s，未写盘）', count($draft->specs), $this->writeMode()),
                    'data' => $data,
                ]));
            }

            if ($file === '') {
                return $this->encode(MenuActionResult::failure('请求写盘但缺少 file（相对应用根的目标文件）', QueryParamException::CODE_INVALID, [
                    'target' => $targetName,
                    'stage'  => MenuActionResult::STAGE_ARGUMENTS,
                ]));
            }

            $write = $this->writer()->write($draft, $file, $confirmed, $targetName);

            $data['written'] = (bool) $write['written'];
            $data['file']    = $write['file'];
            $data['backup']  = $write['backup'];
            $data['audit']   = $write['audit'];
            $data['notes']   = array_merge($data['notes'], $write['notes']);

            if (!$write['ok']) {
                return $this->encode(MenuActionResult::failure(
                    '写盘被拒绝：' . $write['reason'],
                    QueryParamException::CODE_INVALID,
                    [
                        'structured' => true,
                        'data'       => $data + ['refused' => $write['refused']],
                        'text'       => '',
                        'target'     => $targetName,
                        'stage'      => MenuActionResult::STAGE_ARGUMENTS,
                        'hint'       => '写盘需要：write_mode=assisted + 目标文件在 allowed_paths 白名单内 + confirm=true + 草案通过三道自检。',
                    ]
                ));
            }

            return $this->encode(MenuActionResult::normalize([
                'code' => 0,
                'msg'  => sprintf('草案已写入 %s（备份 %s，已通过 php -l）', $write['file'], (string) $write['backup']),
                'data' => $data,
            ]));
        } catch (\Throwable $e) {
            return $this->encode(MenuActionResult::fromException($e, [
                'target' => $targetName,
                'stage'  => MenuActionResult::STAGE_EXECUTION,
            ]));
        }
    }


    protected function introspector(): FormSchemaIntrospector
    {
        return $this->introspector;
    }

    protected function generator(): FormGenerator
    {
        return $this->generator;
    }

    protected function writer(): FormDraftWriter
    {
        return $this->writer;
    }

    /**
     * 写盘模式（默认 `never`：**配置里没写就是永不写盘**）
     */
    public function writeMode(): string
    {
        $mode = strtolower(trim((string) $this->genOpt('write_mode', FormDraftWriter::MODE_NEVER)));

        return $mode === FormDraftWriter::MODE_ASSISTED
            ? FormDraftWriter::MODE_ASSISTED
            : FormDraftWriter::MODE_NEVER;
    }

    private function genOpt(string $key, mixed $default): mixed
    {
        $generate = $this->opt('generate', []);

        if (is_array($generate) && array_key_exists($key, $generate)) {
            return $generate[$key];
        }

        return $default;
    }

    private function opt(string $key, mixed $default): mixed
    {
        if (array_key_exists($key, $this->options)) {
            return $this->options[$key];
        }

        if (!function_exists('config')) {
            return $default;
        }

        try {
            return config('wise-agent.form_schema.' . $key, $default) ?? $default;
        } catch (\Throwable) {
            return $default;
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function encode(array $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_UNICODE) ?: '{"code":1,"msg":"json encode failed"}';
    }
}
