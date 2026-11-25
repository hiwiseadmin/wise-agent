<?php
declare(strict_types=1);

namespace wise\agent\query;

use wise\agent\query\exception\QueryParamException;

/**
 * 单个筛选条件（A3-2 中间表示）
 *
 * 与「查询参数」的区别：这里是**意图**（`字段 + 操作符 + 原始值/时间词`），
 *
 * ## 时间区间走独立通道
 *
 * 时间类条件不把「上月」当值，而是分三路：`time_expr`（自然语言）/ `time_start` / `time_end`
 * （绝对日期，LLM 直接给）。跨字段的一致性由 {@see TimeRangeResolver} 保证，绝不交给 LLM 算日期。
 */
final class QueryFilter implements \JsonSerializable
{
    /**
     * @param string      $field     对外键名（必须命中 {@see QueryFieldCatalog}，含同义词）
     * @param string      $op        操作符（必须在该字段的 `operators` 白名单内）
     * @param mixed       $value     取值（标量或标量数组；时间区间条件可为 null）
     * @param string|null $timeExpr  时间词（如 `上月`）
     * @param string|null $timeStart 绝对起始日
     * @param string|null $timeEnd   绝对结束日
     */
    public function __construct(
        public readonly string $field,
        public readonly string $op = 'eq',
        public readonly mixed $value = null,
        public readonly ?string $timeExpr = null,
        public readonly ?string $timeStart = null,
        public readonly ?string $timeEnd = null,
    ) {
    }

    /**
     * 从工具参数构造（**强校验**，非法即抛）
     *
     * ⚠️ 值只允许**标量或标量数组**：嵌套结构一律拒绝 —— 这是「自然语言只作为值参与编译」
     * 再做形状校验。
     *
     * @param array<string, mixed> $data
     * @throws QueryParamException
     */
    public static function fromArray(array $data): self
    {
        $field = trim((string) ($data['field'] ?? ''));
        if ($field === '') {
            throw new QueryParamException('filters[].field 不能为空');
        }

        $op = strtolower(trim((string) ($data['op'] ?? 'eq')));
        if ($op === '') {
            $op = 'eq';
        }

        $value = $data['value'] ?? null;
        self::assertScalarOrScalarArray($value, $field);

        foreach (['time_expr', 'time_start', 'time_end'] as $key) {
            if (isset($data[$key]) && !is_scalar($data[$key])) {
                throw new QueryParamException(sprintf('filters[].%s 必须是字符串', $key));
            }
        }

        return new self(
            field: $field,
            op: $op,
            value: $value,
            timeExpr: self::stringOrNull($data['time_expr'] ?? null),
            timeStart: self::stringOrNull($data['time_start'] ?? null),
            timeEnd: self::stringOrNull($data['time_end'] ?? null),
        );
    }

    /**
     * 是否带时间词/绝对日期（决定是否走 {@see TimeRangeResolver}）
     */
    public function hasTimeInput(): bool
    {
        return $this->timeExpr !== null || $this->timeStart !== null || $this->timeEnd !== null;
    }

    public function isRangeOperator(): bool
    {
        return in_array($this->op, ['between', 'notbetween'], true);
    }

    public function isListOperator(): bool
    {
        return in_array($this->op, ['in', 'notin'], true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'field'      => $this->field,
            'op'         => $this->op,
            'value'      => $this->value,
            'time_expr'  => $this->timeExpr,
            'time_start' => $this->timeStart,
            'time_end'   => $this->timeEnd,
        ], static fn($v): bool => $v !== null);
    }

    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        return $this->toArray();
    }

    /**
     * @throws QueryParamException
     */
    private static function assertScalarOrScalarArray(mixed $value, string $field): void
    {
        if ($value === null || is_scalar($value)) {
            return;
        }

        if (!is_array($value)) {
            throw new QueryParamException(sprintf('字段 %s 的值类型不支持（仅标量或标量数组）', $field));
        }

        foreach ($value as $item) {
            if (!is_scalar($item)) {
                throw new QueryParamException(sprintf(
                    '字段 %s 的数组值只能包含标量（禁止嵌套）',
                    $field
                ));
            }
        }
    }

    private static function stringOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
