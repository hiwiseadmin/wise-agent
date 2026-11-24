<?php
declare(strict_types=1);

namespace wise\agent\form;

use wise\agent\menu\MenuActionResult;
use wise\agent\query\exception\QueryParamException;
use wise\agent\security\CapabilityAccess;
use wise\agent\tool\BaseTool;

/**
 * `form_fields` —— 表单目录解释器（A2-3）
 *
 * 需要时由 Agent 主动调用本工具取回（含分组 / 联动 / 条件必填 / 自定义校验 / 可写性）。
 *
 * ## 与 `form_fields` 的三层职责
 *
 * | 层 | 内容 | 归属 |
 * |---|---|---|
 * | ① 常驻 | 目标清单 + 精简字段（key/label/type/必填/枚举个数） | `FormSchemaContextProvider`（受 `max_prompt_chars`） |
 * | ② 按需 | 完整目录（本工具） | `form_fields` |
 * | ③ Schema 内约束 | 枚举候选写进参数 description | `FormFillTool::getParameters()` |
 *
 * ## 诚实标注（与 A2-2 同一条铁律）
 *
 * 目录里永远带 `source`（`defineForm` / `crudSchema` / `db` / `none`）与 `enforced`
 * （该控制器的真实提交是否真的会执行 Schema 校验），并原样回传 `notes` ——
 * 「表结构推断」不会被讲成「表单声明」。
 *
 * ## 只读
 * `getAccess()=READ`、`getRiskLevel()=LOW`、`requireConfirmation()=false`、默认 `getPermission()=''`。
 *
 * `introspector()` / `bridge()` 是 `protected` 接缝，故本类**不加 `final`**。
 */
class FormFieldsTool extends BaseTool
{
    use FormAnchorAware;

    public const NAME = 'form_fields';

    /** @var array<string, mixed> */
    private array $options;

    private FormSchemaIntrospector $introspector;

    private FormValidationBridge $bridge;

    /**
     * @param array<string, mixed> $options `form_schema.*` 覆盖项
     */
    public function __construct(
        array $options = [],
        ?FormSchemaIntrospector $introspector = null,
        ?FormValidationBridge $bridge = null
    ) {
        $this->options      = $options;
        $this->introspector = $introspector ?? new FormSchemaIntrospector($options);
        $this->bridge       = $bridge ?? new FormValidationBridge($options);
    }

    public function getDescription(): string
    {
        return '查看某个表单目标的完整字段目录：字段名、标签、类型、是否必填、默认值、枚举候选、'
            . '分组、联动（填了 A 才出现 B）、条件必填、数值/日期约束、以及**哪些字段不能由 AI 填写**。'
            . '目录里还带 source（是表单声明还是表结构推断）与 enforced（真实提交是否真的会校验）。'
            . '调用 form_fill / form_validate 之前先用它确认字段名与取值，不要编造。'
            . '可用目标：' . $this->targetHint();
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
                    'description' => '表单目标，与列表查询目标同一命名，如 host.permission / smartstock.product',
                ],
                'include_raw' => [
                    'type'        => 'boolean',
                    'description' => '是否额外回传原始校验规则串与字段类名（默认 false，只给可读描述）',
                ],
            ],
            'required'   => ['target'],
        ];
    }

    public function getAccess(): string
    {
        return CapabilityAccess::READ;
    }

    public function getRiskLevel(): string
    {
        return CapabilityAccess::RISK_LOW;
    }

    public function requireConfirmation(): bool
    {
        return false;
    }

    /**
     * deny-only：默认不暴露权限面（同 A1-5 / A3）
     */
    public function getPermission(): string
    {
        if (!(bool) $this->opt('require_ai_permission', false)) {
            return '';
        }

        return 'form.fields';
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function execute(array $arguments): string
    {
        $targetName = trim((string) ($arguments['target'] ?? ''));

        if ($targetName === '') {
            return $this->encode(MenuActionResult::failure('缺少 target：请用 form_fields 前先给出表单目标', QueryParamException::CODE_INVALID, [
                'available_targets' => $this->introspector()->availableTargets(),
                'stage'             => MenuActionResult::STAGE_ARGUMENTS,
            ]));
        }

        try {
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

            $schema = $this->introspector()->forController($controller);

            if ($schema->isEmpty()) {
                return $this->encode(MenuActionResult::failure(
                    sprintf('目标 %s 没有可用的表单声明', $targetName),
                    QueryParamException::CODE_INVALID,
                    [
                        'target'        => $targetName,
                        'schema_source' => $schema->source,
                        'notes'         => $schema->notes(),
                        'hint'          => '该控制器未声明 defineForm，且没有可用的 CRUD Schema / 表结构。',
                        'stage'         => MenuActionResult::STAGE_ARGUMENTS,
                    ]
                ));
            }

            return $this->encode(MenuActionResult::normalize([
                'code' => 0,
                'msg'  => sprintf('目标 %s：%d 个字段（可填 %d 个）', $targetName, count($schema->all()), count($schema->fillableKeys())),
                'data' => $this->describe($schema, (bool) ($arguments['include_raw'] ?? false)) + [
                    'target'   => $targetName,
                    'readonly' => true,
                ],
            ]));
        } catch (\Throwable $e) {
            return $this->encode(MenuActionResult::fromException($e, [
                'target' => $targetName,
                'stage'  => MenuActionResult::STAGE_EXECUTION,
            ]));
        }
    }

    /**
     * 目录的可读描述（`protected` 接缝：单测可覆写）
     *
     * @return array<string, mixed>
     */
    protected function describe(FormSchema $schema, bool $includeRaw = false): array
    {
        $fields = [];
        foreach ($schema->all() as $key => $field) {
            $fields[$key] = $field->toArray() + [
                'description' => $field->describe($this->maxEnumValues()),
            ];

            if (!$includeRaw) {
                unset($fields[$key]['rules']);
            }
        }

        return [
            'form_name'          => $schema->formName,
            'schema_source'      => $schema->source,
            'has_native_schema'  => $schema->hasNativeSchema(),
            'groups'             => $schema->groups,
            'repeaters'          => $schema->repeaters,
            'fields'             => $fields,
            'fillable'           => $schema->fillableKeys(),
            'denied'             => $schema->deniedKeys(),
            'required'           => $schema->requiredKeys(),
            'conditional'        => $schema->conditionalKeys(),
            'validation'         => [
                'rules'           => $this->bridge()->describeRules($schema),
                'enforced'        => $this->bridge()->isEnforced($schema->controller),
                'enforced_note'   => $this->bridge()->enforcedNote($schema->controller),
            ],
            'notes'              => $schema->notes(),
        ];
    }

    protected function introspector(): FormSchemaIntrospector
    {
        return $this->introspector;
    }

    protected function bridge(): FormValidationBridge
    {
        return $this->bridge;
    }

    private function maxEnumValues(): int
    {
        return max(1, (int) $this->opt('max_enum_values', 16));
    }

    private function targetHint(): string
    {
        $targets = $this->introspector()->availableTargets();

        if ($targets === []) {
            return '（当前没有可用的表单目标）';
        }

        $shown = array_slice($targets, 0, 8);

        return implode(', ', $shown) . (count($targets) > 8 ? ' 等，更多见目标清单' : '');
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function encode(array $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_UNICODE) ?: '{"code":1,"msg":"json encode failed"}';
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
}
