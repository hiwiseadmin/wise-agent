<?php
declare(strict_types=1);

namespace wise\agent\form;

/**
 * 表单 Schema 目录（A2-1）
 *
 * 「某控制器的 `defineForm()` 声明」的结构化快照：字段 + 分组 + 可重复组 + 表单级元数据。
 *
 * ## `source` 是诚实标记＼�
 *
 * | source | 含义 | AI 应如何理解 |
 * |---|---|---|
 * | `defineForm` | 控制器声明了表单（首选路径） | 这是权威的表单契约 |
 * | `crudSchema` | 未反射到 `defineForm`，退回框架的 CRUD Schema | 契约等价，但字段语义可能不全 |
 * | `db` | 连 Schema 也没有，按表结构推断 | **不是声明**，只是推断（`notes` 里必须说明） |
 * | `none` | 三路都失败 | 该控制器没有可解释的表单 |
 *
 * 这一层存在的原因：A1-1 在「解释控制器动作」时已经踩过同一个坑——
 * 把「表结构推断」当成「表单声明」会让 AI 讲错字段语义。因此 A2 把来源本身作为数据回传。
 */
final class FormSchema implements \JsonSerializable
{
    public const SOURCE_DEFINE_FORM = 'defineForm';
    public const SOURCE_CRUD_SCHEMA = 'crudSchema';
    public const SOURCE_DB          = 'db';
    public const SOURCE_NONE        = 'none';

    /** @var array<int, array<string, mixed>> */
    private array $notes = [];

    /**
     * @param string $target     目标名（`host.permission`，与 A1/A3 同一命名约定）
     * @param string $controller 控制器 FQCN
     * @param string $formName   表单名（`defineForm` 时的 `Form` 名）
     * @param string $source     见 SOURCE_* 常量
     * @param array<string, FormFieldSchema> $fields 字段目录（键 = 字段名）
     * @param array<int, array<string, mixed>> $groups 分组（来自 `Form::getGroups()`）
     * @param array<int, array<string, mixed>> $repeaters 可重复组
     * @param array<string, mixed>|null $rawSchema `Form::toArray()` 的**原样数组**（rich 路径才有）
     */
    public function __construct(
        public readonly string $target,
        public readonly string $controller,
        public readonly string $formName = '',
        public readonly string $source = self::SOURCE_DEFINE_FORM,
        public readonly array $fields = [],
        public readonly array $groups = [],
        public readonly array $repeaters = [],
        public readonly ?array $rawSchema = null,
    ) {
    }

    /**
     * 交给 `ValidationGenerator` / `DataFilter` 的 schema 数组
     *
     * ⚠️ **优先用原生数组**（{@see self::$rawSchema}）：`Form::toArray()` 的原样输出才是
     * 「校验语义的唯一真相」——从目录反推只会得到「看起来一样」的第二份，任何细微差异
     * （如 `props.multiple`、`rules` 的空值表达）都会让 A2 的预检结论与真实提交**悄悄分叉**，
     *
     * 回退路径（`crudSchema` / DB 推断）没有原生数组，只能由目录重建；
     * 此时会追加便签说明「语义可能不全」。
     *
     * @return array<string, mixed>
     */
    public function validationSchema(): array
    {
        if ($this->rawSchema !== null) {
            return $this->rawSchema;
        }

        $fields = [];
        foreach ($this->fields as $key => $field) {
            $fields[$key] = $field->toFormFieldArray();
        }

        return [
            'form'      => ['name' => $this->formName],
            'fields'    => $fields,
            'repeaters' => $this->repeaters,
        ];
    }

    /**
     * `validationSchema()` 是否来自原生数组（false = 目录重建，语义可能不全）
     */
    public function hasNativeSchema(): bool
    {
        return $this->rawSchema !== null;
    }

    /**
     * @return array<string, FormFieldSchema>
     */
    public function all(): array
    {
        return $this->fields;
    }

    public function get(string $key): ?FormFieldSchema
    {
        return $this->fields[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return isset($this->fields[$key]);
    }

    public function isEmpty(): bool
    {
        return $this->fields === [];
    }

    /**
     * @return string[]
     */
    public function keys(): array
    {
        return array_keys($this->fields);
    }

    /**
     * 可由 AI 填充的字段（`form_fill` 的允许集）
     *
     * @return string[]
     */
    public function fillableKeys(): array
    {
        $keys = [];
        foreach ($this->fields as $key => $field) {
            if ($field->isFillable()) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * 不可写字段 → 原因（回传给 AI，回答「为什么没填这个字段」）
     *
     * @return array<string, string>
     */
    public function deniedKeys(): array
    {
        $denied = [];
        foreach ($this->fields as $key => $field) {
            if (!$field->writable || !$field->readable) {
                $denied[$key] = $field->denyReason !== '' ? $field->denyReason : 'not_writable';
            }
        }

        return $denied;
    }

    /**
     * @return string[]
     */
    public function requiredKeys(): array
    {
        $keys = [];
        foreach ($this->fields as $key => $field) {
            if ($field->required) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * 带条件必填的字段（真正的必填取决于数据，见 `ValidationGenerator::generateFor()`）
     *
     * @return string[]
     */
    public function conditionalKeys(): array
    {
        $keys = [];
        foreach ($this->fields as $key => $field) {
            if ($field->conditionalRules !== []) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * 可读字段（`readable=false` 的敏感字段不进 Prompt，也不回传）
     *
     * @return array<string, FormFieldSchema>
     */
    public function readableFields(): array
    {
        return array_filter($this->fields, static fn(FormFieldSchema $f): bool => $f->readable);
    }

    /**
     * @return array<int, array{value:mixed, label:string}>
     */
    public function optionsOf(string $key): array
    {
        return $this->fields[$key]->options ?? [];
    }

    public function addNote(string $code, string $message): self
    {
        $this->notes[] = ['code' => $code, 'message' => $message];

        return $this;
    }

    /**
     * @return array<int, array{code:string, message:string}>
     */
    public function notes(): array
    {
        return $this->notes;
    }

    /**
     * LLM 可读的精简描述（A2-3 的上下文注入会用；此处先提供，便于 A2-1 单测与 `form_fields`）
     *
     * @param int $maxChars      单目标字符预算（超出即降级为「字段名清单」）
     * @param int $maxFields     字段数上限
     * @param int $maxEnumValues 枚举展示上限
     */
    public function toPrompt(int $maxChars = 1200, int $maxFields = 30, int $maxEnumValues = 16): string
    {
        if ($this->isEmpty()) {
            return '';
        }

        $header = $this->target . '（表单' . ($this->formName !== '' ? ' ' . $this->formName : '') . '）' . PHP_EOL;

        $lines = [];
        $count = 0;

        foreach ($this->readableFields() as $key => $field) {
            if ($count >= $maxFields) {
                $lines[] = sprintf('    …其余 %d 个字段用 form_fields 查看', count($this->fields) - $maxFields);
                break;
            }

            $lines[] = '    ' . $field->describe($maxEnumValues);
            $count++;
        }

        $text = $header . implode(PHP_EOL, $lines);

        if (mb_strlen($text, 'UTF-8') > $maxChars) {
            $text = $this->target . '：' . implode(',', array_slice($this->fillableKeys(), 0, $maxFields));
        }

        return $text;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $fields = [];
        foreach ($this->fields as $key => $field) {
            $fields[$key] = $field->toArray();
        }

        return [
            'target'     => $this->target,
            'controller' => $this->controller,
            'form_name'  => $this->formName,
            'source'     => $this->source,
            'fields'     => $fields,
            'groups'     => $this->groups,
            'repeaters'  => $this->repeaters,
            'notes'      => $this->notes,
        ];
    }

    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        return $this->toArray();
    }
}
