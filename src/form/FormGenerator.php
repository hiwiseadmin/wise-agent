<?php
declare(strict_types=1);

namespace wise\agent\form;

use wise\form\schema\enums\FieldType;
use wise\form\schema\Form;
use wise\form\schema\ValidationGenerator;

/**
 * 表单代码草案生成器＼�
 *
 * **纯计算**：字段规格 → `Form` 对象 → ① `Form::toArray()`（权威 schema）
 * → ② `defineForm()` 代码片段 → ③ 三道自检。**本类不写任何文件**（写盘见 {@see FormDraftWriter}）。
 *
 * ## 字段类映射是「单一来源」
 *
 * 映射直接由 **`FieldType` 枚举**推导（`FieldType::tryFrom($type)->getClass()`），
 * 因此不存在「生成器映射表」与「框架类型枚举」两份表漂移的可能；
 * 未知类型即 `null` → 该字段进入 `errors`（要求澄清，不猜）。
 *
 * ## 三道自检＼�
 *
 * | # | 检查 | 失败含义 |
 * |---|---|---|
 * | 1 | 类型合法性（必须命中 `FieldType` 17 项） | AI 编造了字段类型 |
 * | 2 | `Form::fromArray($schema)` 可实例化（结构往返） | 生成的 schema 结构非法 |
 * | 3 | `ValidationGenerator::generate($schema)` 不抛异常 | 规则无法生成（条件必填求值失败等） |
 *
 * ## 刻意不做＼�
 *
 * - **不生成 `rules()` 里的自定义正则**：只要规格里带正则形状的规则即拒绝（要求人工写），
 *   避免 AI 造出过宽/过窄的校验；
 * - **不发明字段类型**：只能落在 17 种里，否则要求澄清；
 * - **不碰 `range()`**：它是日期区间开关（`AbstractField::range(array $default = [])`），
 *   与数值范围无关，数值约束只用 `min/max/step`。
 */
class FormGenerator
{
    /** 拒绝原因：未知字段类型 */
    public const REJECT_UNKNOWN_TYPE = 'unknown_field_type';

    /** 拒绝原因：字段名非法 */
    public const REJECT_BAD_NAME = 'invalid_field_name';

    /** 拒绝原因：字段名重复 */
    public const REJECT_DUPLICATE_NAME = 'duplicate_field_name';

    /** 拒绝原因：自定义正则规则（刻意不支持） */
    public const REJECT_REGEX_RULE = 'regex_rule_not_allowed';

    /** 拒绝原因：枚举/多选字段缺少可选值 */
    public const REJECT_MISSING_OPTIONS = 'missing_options';

    /** 字段名合法形态 */
    public const NAME_PATTERN = '/^[a-z_][a-z0-9_]*$/i';

    /**
     * 全部允许的字段类型（= `FieldType` 全集）
     *
     * @return string[]
     */
    public static function allowedTypes(): array
    {
        return array_map(static fn(FieldType $t): string => $t->value, FieldType::cases());
    }

    /**
     * 校验 + 归一化字段规格，然后生成草案
     *
     * @param array<int, array<string, mixed>> $specs 字段规格（LLM 产出或显式给出）
     */
    public function draft(string $formName, array $specs): FormDraft
    {
        [$normalized, $errors] = $this->normalizeSpecs($specs);

        $notes = [];

        if ($errors !== []) {
            return new FormDraft(
                ok: false,
                formName: $formName,
                specs: $normalized,
                errors: $errors,
                notes: [[
                    'code'    => 'draft.rejected',
                    'message' => '字段规格存在非法项，未生成草案。请修正后重试；不要自行发明字段类型。'
                        . '允许的类型：' . implode(', ', self::allowedTypes()),
                ]],
            );
        }

        $schema   = $this->buildSchema($formName, $normalized);
        $rendered = $this->renderCode($formName, $normalized);
        $checked  = $this->selfCheck($schema);

        foreach ($normalized as $spec) {
            if (FormFieldSchema::isSensitive((string) $spec['name'])) {
                $notes[] = [
                    'code'    => 'draft.sensitive',
                    'message' => sprintf(
                        '声明里包含敏感字段 `%s`：请确认列表页/详情页不回显，并把它排除在写白名单之外。',
                        $spec['name']
                    ),
                ];
            }
        }

        if (!$checked['instantiable'] || !$checked['rules_ok']) {
            $notes[] = [
                'code'    => 'draft.selfcheck_failed',
                'message' => '草案未通过三道自检，请勿直接落盘（详见 validated.errors）。',
            ];
        }

        return new FormDraft(
            ok: true,
            formName: $formName,
            specs: $normalized,
            schema: $schema,
            code: $rendered['code'],
            useStatements: $rendered['use'],
            validated: $checked,
            notes: $notes,
        );
    }

    /**
     * 归一化 + 校验规格
     *
     * @param array<int, array<string, mixed>> $specs
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>} [specs, errors]
     */
    public function normalizeSpecs(array $specs): array
    {
        $out    = [];
        $errors = [];
        $seen   = [];

        foreach (array_values($specs) as $index => $raw) {
            if (!is_array($raw)) {
                $errors[] = ['field' => '#' . $index, 'code' => self::REJECT_BAD_NAME, 'message' => '字段规格必须是对象'];

                continue;
            }

            $name  = trim((string) ($raw['name'] ?? ''));
            $label = trim((string) ($raw['label'] ?? ''));
            $type  = strtolower(trim((string) ($raw['type'] ?? '')));

            if ($name === '' || !preg_match(self::NAME_PATTERN, $name)) {
                $errors[] = [
                    'field'   => $name !== '' ? $name : '#' . $index,
                    'code'    => self::REJECT_BAD_NAME,
                    'message' => '字段名必须是字母/下划线开头的标识符（与真实列名一致，表单不做别名）',
                ];

                continue;
            }

            if (isset($seen[$name])) {
                $errors[] = ['field' => $name, 'code' => self::REJECT_DUPLICATE_NAME, 'message' => '字段名重复'];

                continue;
            }

            $seen[$name] = true;

            if (FieldType::tryFrom($type) === null) {
                $errors[] = [
                    'field'   => $name,
                    'code'    => self::REJECT_UNKNOWN_TYPE,
                    'message' => sprintf('未知字段类型 `%s`；必须是 %s 之一', $type, implode('/', self::allowedTypes())),
                ];

                continue;
            }

            $options = $this->normalizeOptions($raw['options'] ?? []);

            if (in_array($type, ['select', 'radio', 'checkbox'], true) && $options === []) {
                $errors[] = [
                    'field'   => $name,
                    'code'    => self::REJECT_MISSING_OPTIONS,
                    'message' => 'select/radio/checkbox 必须给出 options（value => label 或 [{value,label}]）；'
                        . '若取值来自关联表，请人工补 `->options()`，不要编造候选值',
                ];

                continue;
            }

            $rules = $raw['rules'] ?? [];

            if ($this->looksLikeRegex($rules)) {
                $errors[] = [
                    'field'   => $name,
                    'code'    => self::REJECT_REGEX_RULE,
                    'message' => '不接受自定义正则规则：正则过宽/过窄的风险无法自动评估，请人工编写 `->rules()`',
                ];

                continue;
            }

            $out[] = [
                'name'        => $name,
                'label'       => $label !== '' ? $label : $name,
                'type'        => $type,
                'required'    => (bool) ($raw['required'] ?? false),
                'default'     => $raw['default'] ?? null,
                'placeholder' => trim((string) ($raw['placeholder'] ?? '')),
                'help'        => trim((string) ($raw['help'] ?? '')),
                'options'     => $options,
                'multiple'    => (bool) ($raw['multiple'] ?? false),
                'min'         => $raw['min'] ?? null,
                'max'         => $raw['max'] ?? null,
                'step'        => $raw['step'] ?? null,
                'format'      => trim((string) ($raw['format'] ?? '')),
            ];
        }

        return [$out, $errors];
    }

    /**
     * 用**真实字段类**构造 `Form`，再取 `toArray()` 作为权威 schema
     *
     * 为什么不用字符串拼 schema：手写数组很容易与 `AbstractField::buildProps()` 的形状漂移
     * （尤其 `props` 嵌套与 `options` 的 `value => label` 形态），
     * 而 `ValidationGenerator` / `DataFilter` 读的正是 `props.*`。
     *
     * @param array<int, array<string, mixed>> $specs
     * @return array<string, mixed>
     */
    public function buildSchema(string $formName, array $specs): array
    {
        $form = new Form($formName);

        foreach ($specs as $spec) {
            $form->add($this->buildField($spec));
        }

        return $form->toArray();
    }

    /**
     * 渲染 `defineForm()` 片段
     *
     * @param array<int, array<string, mixed>> $specs
     * @return array{code:string, use:string[]}
     */
    public function renderCode(string $formName, array $specs): array
    {
        $lines = [];
        $uses  = ['use wise\form\schema\Form;'];

        $lines[] = '    /**';
        $lines[] = sprintf(
            '     * 表单声明：%s（由 wise-agent form_generate 生成，请人工复核后合入）',
            $formName !== '' ? $formName : 'form'
        );
        $lines[] = '     */';
        $lines[] = '    protected function defineForm(Form $form): void';
        $lines[] = '    {';

        foreach ($specs as $spec) {
            $lines[] = '        ' . $this->renderLine($spec);
        }

        $lines[] = '    }';

        return ['code' => implode("\n", $lines), 'use' => $uses];
    }

    /**
     * 三道自检＼�
     *
     * @param array<string, mixed> $schema
     * @return array{type_ok:bool, instantiable:bool, rules_ok:bool, errors:string[]}
     */
    public function selfCheck(array $schema): array
    {
        $errors = [];

        $typeOk = true;
        foreach ((array) ($schema['fields'] ?? []) as $field => $def) {
            $type = is_array($def) ? (string) ($def['type'] ?? '') : '';

            if (FieldType::tryFrom($type) === null) {
                $typeOk   = false;
                $errors[] = sprintf('字段 %s 的类型 `%s` 不在 FieldType 全集内', $field, $type);
            }
        }

        $instantiable = true;
        try {
            Form::fromArray($schema);
        } catch (\Throwable $e) {
            $instantiable = false;
            $errors[]     = 'Form::fromArray() 失败：' . $e->getMessage();
        }

        $rulesOk = true;
        try {
            ValidationGenerator::generate($schema);
        } catch (\Throwable $e) {
            $rulesOk = false;
            $errors[] = 'ValidationGenerator::generate() 抛出异常：' . $e->getMessage();
        }

        return [
            'type_ok'      => $typeOk,
            'instantiable' => $instantiable,
            'rules_ok'     => $rulesOk,
            'errors'       => $errors,
        ];
    }

    /**
     * 与现有声明的差异＼�
     *
     * 两侧都用「同一套属性摘要」比较（type / label / required / multiple / default / options），
     * `options` 会先排序再比较 —— 否则同一组候选值只因书写顺序不同就会被报成「变更」。
     *
     * @param array<string, mixed> $schema 新草案 schema
     * @return array{added:string[], removed:string[], changed:array<string, array<string, array{0:mixed,1:mixed}>>, unchanged:string[]}
     */
    public function diff(array $schema, ?FormSchema $existing): array
    {
        $new = $this->fieldSummary($schema);
        $old = $existing === null ? [] : $this->existingSummary($existing);

        $added   = array_values(array_diff(array_keys($new), array_keys($old)));
        $removed = array_values(array_diff(array_keys($old), array_keys($new)));
        $changed = [];
        $same    = [];

        foreach (array_intersect(array_keys($new), array_keys($old)) as $key) {
            $delta = [];

            foreach (['type', 'required', 'label', 'options', 'multiple', 'default'] as $attr) {
                $before = $old[$key][$attr] ?? null;
                $after  = $new[$key][$attr] ?? null;

                if ($before !== $after) {
                    $delta[$attr] = [$before, $after];
                }
            }

            if ($delta === []) {
                $same[] = (string) $key;
            } else {
                $changed[(string) $key] = $delta;
            }
        }

        return [
            'added'     => $added,
            'removed'   => $removed,
            'changed'   => $changed,
            'unchanged' => $same,
        ];
    }


    /**
     * @param array<string, mixed> $spec
     */
    protected function buildField(array $spec): object
    {
        $class = FieldType::from((string) $spec['type'])->getClass();

        $field = $class::make((string) $spec['name'], (string) $spec['label']);

        if (($spec['placeholder'] ?? '') !== '') {
            $field->placeholder((string) $spec['placeholder']);
        }

        if (($spec['help'] ?? '') !== '') {
            $field->help((string) $spec['help']);
        }

        if (($spec['default'] ?? null) !== null) {
            $field->default($spec['default']);
        }

        if (($spec['required'] ?? false)) {
            $field->required();
        }

        if (($spec['options'] ?? []) !== []) {
            $field->options($spec['options']);
        }

        if (($spec['multiple'] ?? false)) {
            $field->multiple();
        }

        if (($spec['min'] ?? null) !== null) {
            $field->min($spec['min']);
        }

        if (($spec['max'] ?? null) !== null) {
            $field->max($spec['max']);
        }

        if (($spec['step'] ?? null) !== null) {
            $field->step((float) $spec['step']);
        }

        if (($spec['format'] ?? '') !== '') {
            $field->format((string) $spec['format']);
        }

        return $field;
    }

    /**
     * @param array<string, mixed> $spec
     */
    protected function renderLine(array $spec): string
    {
        $class = (new \ReflectionClass(FieldType::from((string) $spec['type'])->getClass()))->getShortName();

        $chain = '';

        if (($spec['options'] ?? []) !== []) {
            $chain .= '->options(' . $this->renderOptions($spec['options']) . ')';
        }

        if (($spec['multiple'] ?? false)) {
            $chain .= '->multiple()';
        }

        if (($spec['default'] ?? null) !== null) {
            $chain .= '->default(' . $this->renderValue($spec['default']) . ')';
        }

        if (($spec['required'] ?? false)) {
            $chain .= '->required()';
        }

        if (($spec['placeholder'] ?? '') !== '') {
            $chain .= '->placeholder(' . $this->renderValue($spec['placeholder']) . ')';
        }

        if (($spec['help'] ?? '') !== '') {
            $chain .= '->help(' . $this->renderValue($spec['help']) . ')';
        }

        foreach (['min', 'max'] as $key) {
            if (($spec[$key] ?? null) !== null) {
                $chain .= '->' . $key . '(' . $this->renderValue($spec[$key]) . ')';
            }
        }

        if (($spec['step'] ?? null) !== null) {
            $chain .= '->step(' . $this->renderValue($spec['step']) . ')';
        }

        if (($spec['format'] ?? '') !== '') {
            $chain .= '->format(' . $this->renderValue($spec['format']) . ')';
        }

        return sprintf(
            '$form->add(%s::make(%s, %s)%s);',
            $class,
            $this->renderValue($spec['name']),
            $this->renderValue($spec['label']),
            $chain
        );
    }

    /**
     * @param array<int|string, mixed> $options
     */
    protected function renderOptions(array $options): string
    {
        $parts = [];
        foreach ($options as $value => $label) {
            $parts[] = sprintf('%s => %s', $this->renderValue($value), $this->renderValue($label));
        }

        return '[' . implode(', ', $parts) . ']';
    }

    protected function renderValue(mixed $value): string
    {
        return match (true) {
            is_int($value)  => (string) $value,
            is_float($value) => var_export($value, true),
            is_bool($value) => $value ? 'true' : 'false',
            $value === null => 'null',
            default         => "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], (string) $value) . "'",
        };
    }

    /**
     * @return array<int|string, string>
     */
    protected function normalizeOptions(mixed $options): array
    {
        if (!is_array($options)) {
            return [];
        }

        $map = [];

        if (array_is_list($options) && $options !== [] && is_array($options[0] ?? null)) {
            foreach ($options as $option) {
                $value = $option['value'] ?? null;
                if ($value === null) {
                    continue;
                }

                $map[$value] = (string) ($option['label'] ?? '');
            }

            return $map;
        }

        foreach ($options as $value => $label) {
            if (is_array($label)) {
                continue;
            }

            $map[$value] = (string) $label;
        }

        return $map;
    }

    /**
     * 是否包含「正则形状」的规则（拒绝）
     */
    protected function looksLikeRegex(mixed $rules): bool
    {
        if (is_string($rules)) {
            $rules = [$rules];
        }

        if (!is_array($rules)) {
            return false;
        }

        foreach ($rules as $rule) {
            if (!is_string($rule)) {
                return true;
            }

            $trimmed = trim($rule);

            if ($trimmed === '') {
                continue;
            }

            if (preg_match('#^/.*/[a-z]*$#', $trimmed) === 1
                || stripos($trimmed, 'regex') !== false
                || preg_match('/[\[\]\(\)\*\?\^\$\{\}\|]/', $trimmed) === 1
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * 新草案（`Form::toArray()` 形态）→ 属性摘要
     *
     * @param array<string, mixed> $schema
     * @return array<string, array<string, mixed>>
     */
    private function fieldSummary(array $schema): array
    {
        $summary = [];

        foreach ((array) ($schema['fields'] ?? []) as $name => $def) {
            if (!is_array($def)) {
                continue;
            }

            $props = (array) ($def['props'] ?? []);

            $summary[(string) $name] = [
                'type'     => (string) ($def['type'] ?? ''),
                'label'    => (string) ($def['label'] ?? ''),
                'required' => (bool) ($def['required'] ?? false),
                'multiple' => (bool) ($props['multiple'] ?? false),
                'default'  => $def['default'] ?? null,
                'options'  => $this->canonicalOptions($this->normalizeOptions($props['options'] ?? [])),
            ];
        }

        return $summary;
    }

    /**
     * 现有声明（`FormSchema` 目录）→ **同一套**属性摘要
     *
     * ⚠️ 不能复用 `fieldSummary($existing->toArray())`：`FormFieldSchema::toArray()` 是**摊平形态**
     * （`options` 在顶层且是 `[{value,label}]`），与本类的 `props.options` 形态不同，
     * 直接比较会把所有枚举字段误报成「变更」。
     *
     * @return array<string, array<string, mixed>>
     */
    private function existingSummary(FormSchema $existing): array
    {
        $summary = [];

        foreach ($existing->all() as $key => $field) {
            $summary[(string) $key] = [
                'type'     => $field->type,
                'label'    => $field->label,
                'required' => $field->required,
                'multiple' => $field->multiple,
                'default'  => $field->default,
                'options'  => $this->canonicalOptions($field->optionMap()),
            ];
        }

        return $summary;
    }

    /**
     * 选项归一化：转成**按 value 排序的键值对列表**，保证「同一组候选值」在两侧比较结果一致
     *
     * 为什么不用 `['value' => 'label']` 关联数组：PHP 会把数字串键转成 int，
     * 于是 `[0 => '禁用', 1 => '启用']` 在 JSON 里会退化成**列表**，`0`/`1` 与 `'0'`/`'1'`
     * 两种写法看起来也不一样。键值对列表既 JSON 安全，又能稳定表达「候选值集合」。
     *
     * @param array<int|string, mixed> $map
     * @return array<int, array{value:string, label:string}>
     */
    private function canonicalOptions(array $map): array
    {
        $pairs = [];

        foreach ($map as $value => $label) {
            $pairs[] = ['value' => (string) $value, 'label' => (string) $label];
        }

        usort($pairs, static fn(array $a, array $b): int => strcmp($a['value'], $b['value']));

        return $pairs;
    }
}
