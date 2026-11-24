<?php
declare(strict_types=1);

namespace wise\agent\query\exception;

use wise\agent\exception\AiException;

/**
 * 查询意图非法（A3-2）
 *
 * 所有「无法编译成合法查询参数」的情况都抛本异常 —— **绝不交给 `RequestQueryBuilder`
 * 去静默跳过**（附录三 现状事实 4：未知字段 / 排序字段不匹配时 Builder 会静默忽略，
 * 让 AI 误以为「筛过了」）。
 *
 * ## 与工具响应的 `code` 映射
 *
 *   - `code:1` = 失败（意图非法：形状错误、未知字段、非法操作符、超上限）
 *   - `code:2` = **需要向用户澄清**（取值歧义）→ {@see AmbiguousValueException}
 *
 * 本异常对应 `code:1`；`AmbiguousValueException` 覆盖为 `code:2`。
 * 两者都提供 {@see self::toResult()}，A3-3 的工具直接用它构造响应。
 */
class QueryParamException extends AiException
{
    /** 失败 */
    public const CODE_INVALID = 1;

    /** 需要澄清（歧义） */
    public const CODE_AMBIGUOUS = 2;

    /** 输入类错误 → HTTP 400（不是服务端 500） */
    protected int $statusCode = 400;

    /**
     * 工具响应里的 `code`
     */
    public function resultCode(): int
    {
        return self::CODE_INVALID;
    }

    /**
     * 给用户的纠错提示（子类可覆盖得更具体）
     */
    public function hint(): string
    {
        return '请检查查询意图：字段与操作符必须来自工具参数 Schema。';
    }

    /**
     * 构造工具响应片段：`{code, msg, data, hint}`
     *
     * @return array{code:int, msg:string, data:array<string, mixed>, hint:string}
     */
    public function toResult(): array
    {
        return [
            'code' => $this->resultCode(),
            'msg'  => $this->getMessage(),
            'data' => $this->getData(),
            'hint' => $this->hint(),
        ];
    }
}
