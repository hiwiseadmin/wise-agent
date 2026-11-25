<?php
declare(strict_types=1);

namespace wise\agent\query\exception;

/**
 * 「华东」可能同时命中「华东区」与「华东南区」——此时**绝不能猜第一个**，
 * 而是把候选原样交回 Agent，由它追问用户。
 *
 * 这是 A3 与 A1/A4 结果契约的唯一差异：`code:2`（需要澄清）而非 `code:1`（失败）。
 * 区分二者的意义在于 ReAct 循环的行为完全不同：`code:1` 应该换参数重试，
 * `code:2` 应该**停下来问用户**。
 */
class AmbiguousValueException extends QueryParamException
{
    /**
     * @param string $field      歧义发生在哪个字段（对外键名）
     * @param string $keyword    用户/AI 给出的原始词
     * @param array<int, array{value:mixed, label:string}> $candidates 候选（≥2 条）
     * @param string $source     接地级别：`enum`（枚举标签）| `relation`（关联表）
     */
    public function __construct(
        private readonly string $field,
        private readonly string $keyword,
        private readonly array $candidates,
        private readonly string $source = 'relation'
    ) {
        parent::__construct(
            sprintf('取值「%s」在字段 %s 上不唯一（%d 个候选）', $keyword, $field, count($candidates)),
            0,
            null,
            [
                'field'      => $field,
                'keyword'    => $keyword,
                'candidates' => array_values($candidates),
                'source'     => $source,
            ]
        );
    }

    public function resultCode(): int
    {
        return self::CODE_AMBIGUOUS;
    }

    public function hint(): string
    {
        return '请让用户确认是哪一个候选；不要猜测，也不要自行取第一个。';
    }

    public function field(): string
    {
        return $this->field;
    }

    public function keyword(): string
    {
        return $this->keyword;
    }

    /**
     * @return array<int, array{value:mixed, label:string}>
     */
    public function candidates(): array
    {
        return $this->candidates;
    }

    public function source(): string
    {
        return $this->source;
    }

    /**
     * @return array{code:int, msg:string, data:array<string, mixed>, hint:string}
     */
    public function toResult(): array
    {
        return [
            'code' => $this->resultCode(),
            'msg'  => 'ambiguous',
            'data' => $this->getData(),
            'hint' => $this->hint(),
        ];
    }
}
