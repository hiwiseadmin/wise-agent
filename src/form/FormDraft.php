<?php
declare(strict_types=1);

namespace wise\agent\form;

/**
 * `defineForm()` 代码草案（A2-4）
 *
 * 这是 `form_generate` **唯一的产物形态**：无论是否写盘，工具都先把草案算成这个对象。
 *
 * ## 为什么 `ok=false` 也要有结构化产物
 *
 * 字段规格非法（未知类型 / 名字非法 / 重复 / 自定义正则）时**不猜**，而是把
 * 必须落到 17 种类型之一，否则要求澄清」）。
 */
final class FormDraft implements \JsonSerializable
{
    /**
     * @param bool   $ok            规格是否合法（`false` 时 `code` 为空、`errors` 非空）
     * @param string $formName      表单名（`Form::getName()`）
     * @param array  $specs         归一化后的字段规格
     * @param array  $schema        `Form::toArray()` 形态（可直接喂 `Form::fromArray()` / `ValidationGenerator`）
     * @param string $code          `defineForm()` 片段（**不含** `use` 语句）
     * @param array  $useStatements 代码片段需要的 `use` 语句
     * @param array  $validated     三道自检结论（见 `FormGenerator::selfCheck()`）
     * @param array  $errors        `[['field'=>..,'code'=>..,'message'=>..]]`
     * @param array  $notes         诚实标注
     */
    public function __construct(
        public readonly bool $ok,
        public readonly string $formName = '',
        public readonly array $specs = [],
        public readonly array $schema = [],
        public readonly string $code = '',
        public readonly array $useStatements = [],
        public readonly array $validated = [],
        public readonly array $errors = [],
        public readonly array $notes = [],
    ) {
    }

    /**
     * 三道自检是否全过
     */
    public function valid(): bool
    {
        return $this->ok
            && ($this->validated['type_ok'] ?? false) === true
            && ($this->validated['instantiable'] ?? false) === true
            && ($this->validated['rules_ok'] ?? false) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'ok'             => $this->ok,
            'form_name'      => $this->formName,
            'code'           => $this->code,
            'use_statements' => $this->useStatements,
            'validated'      => $this->validated,
            'errors'         => $this->errors,
            'schema'         => $this->schema,
            'notes'         => $this->notes,
        ];
    }

    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        return $this->toArray();
    }
}
