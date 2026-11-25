<?php
declare(strict_types=1);

namespace wise\agent\form;

use wise\agent\schema\security\SecurityGuard;

/**
 * 表单字段目录条目（A2-1）
 *
 * 把 `defineForm()` 声明的**一个字段**的全部元数据保留下来。与 A1-1
 * （`MenuActionSchemaResolver::normalizeFormSchema()`）的差别是本类**不丢信息**：
 * A1-1 只抽 13 个键，丢弃了 `placeholder` / `group`（分组）/ `dependencies`（联动）/
 * `conditional_rules`（条件必填）/ `renderable` / `rules`（自定义校验）/
 * `props.{step,min,max,format,range}` —— 恰恰是「解释表单 / 智能填充」必需的语义。
 *
 * ## 元数据来源（全部来自 `AbstractField`，已反射核验）
 *
 * `toArray()` 给出 `{name,type,label,placeholder,default,required,help,rules,renderable,
 * props{multiple,range,min,max,step,format,options},dependencies,group,conditional_rules}`；
 * 字段**类名**（`input`）另从字段对象取（`getFields()`），用于回写代码草案时保持原字段类。
 *
 * ## `writable` 的判定口径（易错点）
 *
 * 这里的 `writable` 表示「**这条写入链路真的能写吗**」，因此按**真实写闸门**推导：
 * 模型 `$allowWriteField`（`CrudAction::filterDataGeneric()` 的读取对象）——
 * **无白名单 = 可写**（`null` 表示不过滤）。
 *
 * ⚠️ 不要用 `SecurityGuard::isFieldWritable()` 直接当结论：它的语义是「无白名单**拒绝**写」，
 * 那是**自动 CRUD 工具**的安全默认值，不是 `CrudAction` 的真实行为。A2 只借用它的
 * 敏感/系统字段清单（`SENSITIVE_PATTERNS` / `SYSTEM_FIELDS`）。
 *
 * 在此之上 A2 再叠三条**加严**（属于「AI 可以少于人工」）：
 * 1. 归属类字段（`admin_id` / `user_id` / `created_by` …）默认不填 —— 由系统或人工维护；
 * 2. `hidden` 类型字段默认不填 —— 通常由服务端注入；
 * 3. 敏感字段（`password` / `*_token` / `*_hash` …）既不写也不读。
 */
final class FormFieldSchema implements \JsonSerializable
{
    /** 不可写原因（回传给 AI，避免「为什么没填」的困惑） */
    public const DENY_SENSITIVE = 'sensitive';
    public const DENY_SYSTEM    = 'system';
    public const DENY_OWNER     = 'owner_field';
    public const DENY_HIDDEN    = 'hidden_type';
    public const DENY_WHITELIST = 'not_in_allow_write_field';

    /**
     * @param string $key     字段名（表单字段名 = 真实字段名，表单不做别名）
     * @param string $label   中文标签
     * @param string $type    归一化类型（= `FieldType` 取值，17 项之一）
     * @param string $input   声明它的字段类短名（如 `SwitchField`），回写草案时保持原类
     * @param bool   $required 是否必填（含条件必填的「基线」）
     * @param mixed  $default 默认值
     * @param string $help    说明文字（`->help()`）
     * @param string $placeholder 占位提示
     * @param array<int, array{value:mixed, label:string}> $options 可选值（select/radio/checkbox）
     * @param bool   $multiple 是否多选
     * @param mixed  $min / $max / $step 数值约束
     * @param bool   $range   是否范围型
     * @param string $format  日期时间格式
     * @param array  $rules   自定义校验规则（`->rules()` 原值，逐字保留）
     * @param string $group   分组名（`Form::group()` 生成）
     * @param array  $dependencies 联动声明
     * @param array  $conditionalRules 条件必填声明（`requiredIf`/`requiredUnless`）
     * @param mixed  $renderable 渲染开关（`->renderable()` 原值）
     * @param bool   $writable 真实写入链路是否允许写
     * @param bool   $readable 是否允许读（敏感字段为 false）
     * @param string $denyReason 不可写原因（见 DENY_* 常量）
     * @param string $source   来源：`defineForm` | `crudSchema` | `db`
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label = '',
        public readonly string $type = 'text',
        public readonly string $input = '',
        public readonly bool $required = false,
        public readonly mixed $default = null,
        public readonly string $help = '',
        public readonly string $placeholder = '',
        public readonly array $options = [],
        public readonly bool $multiple = false,
        public readonly mixed $min = null,
        public readonly mixed $max = null,
        public readonly mixed $step = null,
        public readonly bool $range = false,
        public readonly string $format = '',
        public readonly array $rules = [],
        public readonly ?string $group = null,
        public readonly array $dependencies = [],
        public readonly array $conditionalRules = [],
        public readonly mixed $renderable = true,
        public readonly bool $writable = true,
        public readonly bool $readable = true,
        public readonly string $denyReason = '',
        public readonly string $source = 'defineForm',
    ) {
    }

    /**
     * 是否可由 AI 填充（`form_fill` 只产出这些字段）
     */
    public function isFillable(): bool
    {
        return $this->writable && $this->readable;
    }

    /**
     * 字段名是否为敏感字段（**单一实现**：目录内省、草案生成都走这里）
     *
     * 清单复用 `SecurityGuard::SENSITIVE_PATTERNS`，匹配方式与其保持一致（包含匹配）。
     */
    public static function isSensitive(string $field): bool
    {
        foreach (SecurityGuard::SENSITIVE_PATTERNS as $pattern) {
            if (stripos($field, (string) $pattern) !== false) {
                return true;
            }
        }

        return false;
    }

    public function hasEnum(): bool
    {
        return $this->options !== [];
    }

    /**
     * 可选值的展示形态（`启用(1)`），供 Prompt 与目录描述复用
     *
     * @return string[]
     */
    public function optionLabels(): array
    {
        $out = [];
        foreach ($this->options as $option) {
            $label = (string) ($option['label'] ?? '');
            $value = $option['value'] ?? null;

            if ($label === '') {
                $out[] = (string) $value;
                continue;
            }

            $out[] = (string) $value === $label ? $label : $label . '(' . $value . ')';
        }

        return $out;
    }

    /**
     * 单行描述（Prompt 精简维度用）
     */
    public function describe(int $maxEnumValues = 16): string
    {
        $parts = [$this->key . ($this->label !== '' && $this->label !== $this->key ? "（{$this->label}）" : '')];
        $parts[] = $this->type;

        if ($this->required) {
            $parts[] = '必填';
        }

        if ($this->hasEnum()) {
            $labels = $this->optionLabels();
            $parts[] = count($labels) <= $maxEnumValues
                ? '可选:' . implode('|', $labels)
                : sprintf('可选:%d 项', count($labels));
        }

        if ($this->conditionalRules !== []) {
            $parts[] = '条件必填';
        }

        if (!$this->writable) {
            $parts[] = $this->denyReason !== '' ? '不可写(' . $this->denyReason . ')' : '不可写';
        }

        return implode(' ', $parts);
    }

    /**
     * 还原成 `Form::toArray()` 的**原生字段形态**（`props` 需保持嵌套）
     *
     * ⚠️ 为什么必须单独有这个方法：`toArray()` 把 `props` 摊平了（`min/max/step/range/format/options`
     * 提到顶层）——那对「注入 Prompt、给 AI 看」更方便，但 `ValidationGenerator` 与 `DataFilter`
     * 读的是 `props.multiple` / `props.range`（`DataFilter.php:108-113`）。若把摊平形态喂给它们，
     * `range` 型日期与 multiple 型选择会**静默丢失类型转换**。
     *
     * @return array<string, mixed>
     */
    public function toFormFieldArray(): array
    {
        $props = [];

        if ($this->multiple) {
            $props['multiple'] = true;
        }

        if ($this->range) {
            $props['range'] = true;
        }

        foreach (['min' => $this->min, 'max' => $this->max, 'step' => $this->step] as $key => $value) {
            if ($value !== null && $value !== '') {
                $props[$key] = $value;
            }
        }

        if ($this->format !== '') {
            $props['format'] = $this->format;
        }

        if ($this->options !== []) {
            $props['options'] = $this->optionMap();
        }

        return [
            'name'              => $this->key,
            'type'              => $this->type,
            'label'             => $this->label,
            'placeholder'       => $this->placeholder,
            'default'           => $this->default,
            'required'          => $this->required,
            'help'              => $this->help,
            'rules'             => $this->rules,
            'renderable'        => $this->renderable,
            'group'             => $this->group,
            'dependencies'      => $this->dependencies,
            'conditional_rules' => $this->conditionalRules,
            'props'             => $props,
        ];
    }

    /**
     * 选项还原为 `value => label` 关联数组（`Form` 的原生形态）
     *
     * @return array<int|string, string>
     */
    public function optionMap(): array
    {
        $map = [];
        foreach ($this->options as $option) {
            $value = $option['value'] ?? null;

            if ($value === null) {
                continue;
            }

            $map[$value] = (string) ($option['label'] ?? '');
        }

        return $map;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key'               => $this->key,
            'label'             => $this->label,
            'type'              => $this->type,
            'input'             => $this->input,
            'required'          => $this->required,
            'default'           => $this->default,
            'help'              => $this->help,
            'placeholder'       => $this->placeholder,
            'options'           => $this->options,
            'multiple'          => $this->multiple,
            'min'               => $this->min,
            'max'               => $this->max,
            'step'              => $this->step,
            'range'             => $this->range,
            'format'            => $this->format,
            'rules'             => $this->rules,
            'group'             => $this->group,
            'dependencies'      => $this->dependencies,
            'conditional_rules' => $this->conditionalRules,
            'renderable'        => $this->renderable,
            'writable'          => $this->writable,
            'readable'          => $this->readable,
            'deny_reason'       => $this->denyReason,
            'source'            => $this->source,
        ];
    }

    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        return $this->toArray();
    }
}
