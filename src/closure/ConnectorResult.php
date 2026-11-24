<?php
declare(strict_types=1);

namespace wise\agent\closure;

/**
 * 连接器派发结果＼�
 *
 * ## 三种状态，语义必须严格区分（这是「可观测」的全部意义）
 *
 * | 状态 | 含义 | 是否算失败 | 能否补投 |
 * |---|---|---|---|
 * | `ok` | 真的投出去了（下游返回成功） | 否 | 否 |
 * | `skipped` | **没有投**（连接器不支持该事件 / 未注册 / 场景不匹配） | 否 | 否（补投无意义） |
 * | `failed` | 投了但失败（下游返回失败或抛异常） | 是 | 是（`closure retry`） |
 *
 * 把 `skipped` 记成 `failed` 会让运维去补投一堆**本就不该发**的东西；
 * 把 `failed` 记成 `skipped` 则会让失败彻底消失 —— 两者都不能接受。
 *
 * ## `slow` 是**告警**，不是失败（B2 实现期修正的设计）
 *
 * 一个「耗时 5s 但确实发送成功」的通知若被记为 `failed`，`closure retry` 就会**再发一次**。
 * 因此改为：超过 `closure.timeout_ms` 时仍按真实结果记 `ok`/`failed`，**额外**标记 `slow=true`
 * 并在轨迹里给出可读告警 —— 超时阈值用于**发现拖慢**，不用于改写事实。
 */
final class ConnectorResult implements \JsonSerializable
{
    public const STATUS_OK      = 'ok';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_FAILED  = 'failed';

    /**
     * @param string $connector  连接器名（notification / webhook / audit）
     * @param string $status     见 `STATUS_*`
     * @param string $target     投递目标（渠道名 / URL 主机 / 审计 channel）—— 便于回放时看清「发给谁」
     * @param string $response   下游响应摘要（截断）
     * @param string $error      失败原因 / 跳过原因（两者都用它承载，靠 `status` 区分）
     * @param int    $durationMs 耗时（毫秒）
     * @param bool   $slow       是否超过 `closure.timeout_ms`（**告警，不影响 `status`**）
     */
    public function __construct(
        public readonly string $connector,
        public readonly string $status,
        public readonly string $target = '',
        public readonly string $response = '',
        public readonly string $error = '',
        public readonly int $durationMs = 0,
        public readonly bool $slow = false,
    ) {
    }

    public static function ok(
        string $connector,
        string $target = '',
        string $response = '',
        int $durationMs = 0,
        bool $slow = false
    ): self {
        return new self($connector, self::STATUS_OK, $target, $response, '', $durationMs, $slow);
    }

    /**
     * 没有投（未注册 / 不支持该事件 / 场景不匹配）
     */
    public static function skipped(string $connector, string $reason, string $target = ''): self
    {
        return new self($connector, self::STATUS_SKIPPED, $target, '', $reason);
    }

    /**
     * @param string $response 下游响应摘要（B2-3 补：失败时也要留下它）
     *
     * ⚠️ 失败路径**同样要留响应**：否则「部分通道失败」这种情形只剩一句人话，
     * 补投时得靠猜是哪个通道、错在哪儿。`ai_closure_deliveries` 本来就有 `response` 列，
     * 不留白等于把证据丢掉。
     */
    public static function failed(
        string $connector,
        string $error,
        int $durationMs = 0,
        string $target = '',
        bool $slow = false,
        string $response = ''
    ): self {
        return new self($connector, self::STATUS_FAILED, $target, $response, $error, $durationMs, $slow);
    }

    public function isOk(): bool
    {
        return $this->status === self::STATUS_OK;
    }

    /**
     * 回填耗时/慢告警（派发器统一计时；连接器自己不必操心计时）
     *
     * ⚠️ 只覆盖"更慢"的情形：连接器若自报了耗时，以**较大者**为准（取保守值）。
     */
    public function withTiming(int $durationMs, bool $slow): self
    {
        if ($durationMs <= $this->durationMs && $slow === $this->slow) {
            return $this;
        }

        return new self(
            $this->connector,
            $this->status,
            $this->target,
            $this->response,
            $this->error,
            max($this->durationMs, $durationMs),
            $slow || $this->slow,
        );
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function isSkipped(): bool
    {
        return $this->status === self::STATUS_SKIPPED;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'connector'   => $this->connector,
            'status'      => $this->status,
            'target'      => $this->target,
            'response'    => $this->response,
            'error'       => $this->error,
            'duration_ms' => $this->durationMs,
            'slow'        => $this->slow,
        ];
    }

    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        return $this->toArray();
    }
}
