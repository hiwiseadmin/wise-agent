<?php
declare(strict_types=1);

namespace wise\agent\closure;

/**
 * 一次闭环派发的汇总结果＼�
 *
 * ## 与「是否落库」的对应关系（一条明确规则，避免两种"跳过"混淆）
 *
 * | 情况 | `status` | 是否落 `ai_closure_events` |
 * |---|---|---|
 * | 没有任何连接器被路由（场景未配置 / 读写与风险全被过滤） | `skipped` | **不落库**（并写 ai 日志，`eventId=0`） |
 * | 路由到了连接器，但全部 `skipped`（未注册 / 不支持该事件） | `skipped` | 落库（**"该触发但没投成"必须留痕**） |
 * | 全部成功 | `ok` | 落库 |
 * | 部分成功部分失败 | `partial` | 落库 |
 * | 全部失败 | `failed` | 落库 |
 *
 * 规则的理由：`ai_closure_events` 只承载「B2 认为该触发」的事件；
 * 一次只读查询不该在审计表里留一行（噪音），但"该触发却没投成"必须能查出来（不能丢）。
 */
final class ClosureDispatchResult implements \JsonSerializable
{
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_OK      = 'ok';
    public const STATUS_PARTIAL = 'partial';
    public const STATUS_FAILED  = 'failed';

    /**
     * @param string $status     见 `STATUS_*`
     * @param int    $eventId    落库的事件 id（未落库时 0）
     * @param array  $deliveries {@see ConnectorResult}[]
     * @param array  $dropped    未投递的连接器与原因
     * @param array  $notes      诚实标注（access/risk 未知、落库失败、slow 告警…）
     */
    public function __construct(
        public readonly string $status,
        public readonly string $traceId,
        public readonly string $scene,
        public readonly int $eventId = 0,
        public readonly array $deliveries = [],
        public readonly array $dropped = [],
        public readonly array $notes = [],
        public readonly int $durationMs = 0,
        public readonly string $error = '',
    ) {
    }

    public function isSkipped(): bool
    {
        return $this->status === self::STATUS_SKIPPED;
    }

    public function isOk(): bool
    {
        return $this->status === self::STATUS_OK;
    }

    public function okCount(): int
    {
        return count(array_filter($this->deliveries, static fn(ConnectorResult $r): bool => $r->isOk()));
    }

    public function failedCount(): int
    {
        return count(array_filter($this->deliveries, static fn(ConnectorResult $r): bool => $r->isFailed()));
    }

    public function skippedCount(): int
    {
        return count(array_filter($this->deliveries, static fn(ConnectorResult $r): bool => $r->isSkipped()));
    }

    /**
     * @return string[] 失败连接器名（补投时定位用）
     */
    public function failedConnectors(): array
    {
        return array_values(array_map(
            static fn(ConnectorResult $r): string => $r->connector,
            array_filter($this->deliveries, static fn(ConnectorResult $r): bool => $r->isFailed())
        ));
    }

    public function summary(): string
    {
        return sprintf(
            '[%s] %s（trace=%s）：成功 %d / 失败 %d / 跳过 %d%s',
            $this->status,
            $this->scene,
            $this->traceId,
            $this->okCount(),
            $this->failedCount(),
            $this->skippedCount(),
            $this->error !== '' ? '；错误：' . $this->error : ''
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status'      => $this->status,
            'trace_id'    => $this->traceId,
            'scene'       => $this->scene,
            'event_id'    => $this->eventId,
            'deliveries'  => array_map(static fn(ConnectorResult $r): array => $r->toArray(), $this->deliveries),
            'dropped'     => $this->dropped,
            'notes'       => $this->notes,
            'duration_ms' => $this->durationMs,
            'error'       => $this->error,
        ];
    }

    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        return $this->toArray();
    }
}
