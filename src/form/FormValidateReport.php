<?php
declare(strict_types=1);

namespace wise\agent\form;

/**
 * 表单预检报告（A2-2）
 *
 * 这是 `form_validate` 的全部结论。三层语义必须分开表达，不能混成一句「通过/不通过」：
 *
 * | 字段 | 含义 | 依据 |
 * |---|---|---|
 * | `passed` / `errors` | **Schema 契约层**结论 | `ValidationGenerator::generateFor()` + `ThinkPHP\Validate` |
 * | `accepted` / `coerced` | **实际会被写入的字段**（含类型转换与默认值补齐） | `DataFilter::filter()` |
 * | `droppedSchema` / `droppedWhitelist` | 会被丢弃的输入字段 | 前者 Schema 未定义，后者模型白名单不允许 |
 * | `enforced` / `enforcedNote` | **真实提交链路是否真的会校验** | 反射 `validateBySchema()` 是否被控制器覆写 |
 *
 * 实测：`CrudAction::validateBySchema()` 默认是**空钩子**（`return $data;`），全仓 **0 个控制器覆写**。
 * 也就是说真实提交**当前不做 Schema 校验**。如果 A2 只说「预检通过」，AI 会把它当成
 * 「提交一定成功」——那是**误导**。因此报告里永远带着这一行，让模型自己知道边界。
 */
final class FormValidateReport implements \JsonSerializable
{
    /** 校验通过 */
    public const PASSED = true;

    /**
     * @param array<int, array{field:string, rule:string, message:string}> $errors 校验错误（逐条规则）
     * @param array<string, mixed> $accepted   `DataFilter` 之后的数据 = **实际会被写入的字段**
     * @param string[]             $coerced    被类型转换过的字段（值发生了变化）
     * @param string[]             $droppedSchema    因「Schema 未定义」被丢弃的输入字段
     * @param string[]             $droppedWhitelist 因「模型白名单不允许」被丢弃的字段
     * @param string[]             $notWritable      目录里标记为不可写的字段（敏感/系统/归属/hidden）
     * @param bool   $enforced     真实提交链路是否会执行 Schema 校验
     * @param string $enforcedNote 人类可读说明（未启用时必须给出明确提示）
     * @param array<int, array{code:string, message:string}> $notes 其他诚实标注
     */
    public function __construct(
        public readonly bool $passed,
        public readonly array $errors = [],
        public readonly array $accepted = [],
        public readonly array $coerced = [],
        public readonly array $droppedSchema = [],
        public readonly array $droppedWhitelist = [],
        public readonly array $notWritable = [],
        public readonly bool $enforced = false,
        public readonly string $enforcedNote = '',
        public readonly string $schemaSource = FormSchema::SOURCE_DEFINE_FORM,
        public readonly array $notes = [],
    ) {
    }

    public function firstError(): string
    {
        return $this->errors === [] ? '' : (string) $this->errors[0]['message'];
    }

    /**
     * 一句话结论（工具响应的 `msg`）
     */
    public function summary(): string
    {
        if ($this->passed) {
            return $this->enforced
                ? '校验通过（真实提交链路会执行同一套校验）'
                : 'Schema 契约层预检通过（注意：该控制器的真实提交当前不执行 Schema 校验）';
        }

        return sprintf('校验不通过：%d 项错误（首个：%s）', count($this->errors), $this->firstError());
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'passed'             => $this->passed,
            'errors'             => $this->errors,
            'accepted'           => $this->accepted,
            'coerced'            => $this->coerced,
            'dropped_schema'     => $this->droppedSchema,
            'dropped_whitelist'  => $this->droppedWhitelist,
            'not_writable'       => $this->notWritable,
            'enforced'           => $this->enforced,
            'enforced_note'      => $this->enforcedNote,
            'schema_source'      => $this->schemaSource,
            'notes'              => $this->notes,
        ];
    }

    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        return $this->toArray();
    }
}
