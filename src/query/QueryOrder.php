<?php
declare(strict_types=1);

namespace wise\agent\query;

use wise\agent\query\exception\QueryParamException;

/**
 * 排序条件（A3-2 中间表示）
 *
 * 编译产物是 `RequestQueryBuilder` 认的 `order={field} {asc|desc}`（多字段逗号分隔）。
 *
 * > 为什么要显式产出 `order`：Builder 有默认排序 `['id' => 'desc']`
 * > （`app/common/library/RequestQueryBuilder.php:140`），不显式指定时 AI 的排序意图会被默认值盖掉
 */
final class QueryOrder implements \JsonSerializable
{
    public const DIRECTIONS = ['asc', 'desc'];

    public function __construct(
        public readonly string $field,
        public readonly string $direction = 'asc',
    ) {
    }

    /**
     * @param array<string, mixed> $data
     * @throws QueryParamException
     */
    public static function fromArray(array $data): self
    {
        $field = trim((string) ($data['field'] ?? ''));
        if ($field === '') {
            throw new QueryParamException('orders[].field 不能为空');
        }

        $direction = strtolower(trim((string) ($data['direction'] ?? $data['dir'] ?? 'asc')));
        if ($direction === '') {
            $direction = 'asc';
        }

        if (!in_array($direction, self::DIRECTIONS, true)) {
            throw new QueryParamException(sprintf(
                'orders[].direction 只允许 %s（收到 %s）',
                implode('/', self::DIRECTIONS),
                $direction
            ));
        }

        return new self($field, $direction);
    }

    /**
     * 编译产物片段：`amount desc`
     */
    public function toParam(): string
    {
        return $this->field . ' ' . $this->direction;
    }

    /**
     * @return array{field:string, direction:string}
     */
    public function toArray(): array
    {
        return ['field' => $this->field, 'direction' => $this->direction];
    }

    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        return $this->toArray();
    }
}
