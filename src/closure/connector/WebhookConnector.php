<?php
declare(strict_types=1);

namespace wise\agent\closure\connector;

use wise\agent\closure\ClosureEvent;
use wise\agent\closure\ConnectorResult;
use wise\agent\closure\PayloadMasker;

/**
 * 外部联动连接器＼�
 *
 * 把闭环事件按**统一信封**投递到 `wise-webhook`（签名 / 重试 / 断路器 / SSRF 防护 / 投递日志由它提供）。
 *
 * ## ⚠️ 两条硬约束
 *
 * 1. **目标 URL 只能来自配置**（`closure.webhook.endpoints[scene]`）。
 *    绝不接受事件字段或 LLM 提供的 URL —— 否则就是把"往任意地址发数据"的能力交给了不可信来源
 *    （尽管 wise-webhook 有 `assertOutboundUrlAllowed()` 兜底，B2 也不去开这个口子）。
 *    未配置 ⇒ `skipped`（**不是失败**：没配目标不是故障，验收 6 明确要求）。
 * 2. **默认走队列**（`closure.webhook.dispatch=queue`）：事件派发是**同步**的，
 *    在监听器里同步等一个外部 HTTP 会拖慢 AI 主链路；同步模式需显式开启
 *    （只有需要"立刻知道投递结果"的场景才这么做）。
 *
 * ## 信封（验收 6 逐字段断言的就是它）
 *
 * ```json
 * {
 *   "event": "ai_closure.approval_decided",
 *   "trace_id": "approval:AP1", "scene": "approval_decided", "result": "approved",
 *   "capability": {"kind":"tool","name":"menu.action.host.save","access":"write","risk":"high","write":true},
 *   "actor": {"type":"admin","id":7},
 *   "approval": {"id":42}, "plan": {"run_id":15},
 *   "occurred_at": "2026-10-16 21:30:00",
 *   "data": { …已脱敏（再脱一遍）… }
 * }
 * ```
 *
 * `payload_hash` 一并给出，便于接收方做**幂等去重**（同一次操作重投时指纹一致）。
 */
class WebhookConnector extends AbstractConnector
{
    public const NAME = 'webhook';

    public const DISPATCH_QUEUE = 'queue';
    public const DISPATCH_SYNC  = 'sync';

    public function name(): string
    {
        return self::NAME;
    }

    public function supports(ClosureEvent $event): bool
    {
        return in_array($event->scene, ClosureEvent::SCENES, true);
    }

    public function dispatch(ClosureEvent $event): ConnectorResult
    {
        if (($missing = $this->missingDownstream()) !== null) {
            return ConnectorResult::skipped(self::NAME, $missing);
        }

        $url = $this->urlFor($event->scene);

        if ($url === '') {
            return ConnectorResult::skipped(
                self::NAME,
                sprintf(
                    '未配置 closure.webhook.endpoints.%s ⇒ 不投递（目标 URL 只能来自配置，不接受事件来源）',
                    $event->scene
                )
            );
        }

        $mode   = $this->dispatchMode();
        $secret = (string) $this->closureOpt('webhook.secret', '');

        try {
            $outcome = $this->deliver($url, $this->envelope($event), $secret, $mode);
        } catch (\Throwable $e) {
            return ConnectorResult::failed(self::NAME, 'webhook 投递失败：' . $e->getMessage(), 0, $this->host($url));
        }

        if ($outcome['queued']) {
            return ConnectorResult::ok(self::NAME, $this->host($url), $this->json([
                'dispatch' => self::DISPATCH_QUEUE,
                'response' => $outcome['response'],
                'note'     => '已入队；最终投递结果与重试留在 webhook_sends（由 wise-webhook 负责）',
            ]));
        }

        return $outcome['ok']
            ? ConnectorResult::ok(self::NAME, $this->host($url), $this->json(['dispatch' => self::DISPATCH_SYNC, 'response' => $outcome['response']]))
            : ConnectorResult::failed(self::NAME, 'webhook 响应失败：' . $outcome['error'], 0, $this->host($url));
    }


    /**
     * 统一信封（`data` 里是**再脱敏**后的上下文摘要）
     *
     * @return array<string, mixed>
     */
    protected function envelope(ClosureEvent $event): array
    {
        return [
            'event'       => 'ai_closure.' . $event->scene,
            'trace_id'    => $event->traceId,
            'scene'       => $event->scene,
            'result'      => $event->result,
            'reason'      => $this->truncate($event->reason, 300),
            'capability'  => [
                'kind'   => $event->capabilityKind,
                'name'   => $event->capabilityName,
                'access' => $event->access,
                'risk'   => $event->risk,
                'write'  => $event->write,
            ],
            'actor'       => [
                'type' => $event->actorType,
                'id'   => $event->actorId,
            ],
            'approval'    => ['id' => $event->approvalId],
            'plan'        => ['run_id' => $event->planRunId],
            'session_id'  => $event->sessionId,
            'tracking_id' => $event->trackingId,
            'payload_hash' => $event->payloadHash(),
            'occurred_at' => $event->occurredAt(),
            'data'        => $this->boundedData($event->payload),
        ];
    }

    /**
     * `data` 的长度上限：**只截 `data`**，不动信封其余字段
     *
     * 理由：接收方多半按 `trace_id`/`scene` 路由与去重，截断信封会破坏这些关键字段。
     */
    protected function boundedData(array $payload): mixed
    {
        $masked = $this->masked($payload);
        $max    = $this->maxChars('webhook.max_payload_chars', 8000);
        $json   = $this->json($masked);
        $tooBig = mb_strlen($json, 'UTF-8') > $max;

        return $tooBig
            ? ['truncated' => true, 'json' => $this->truncate($json, $max)]
            : $masked;
    }

    protected function urlFor(string $scene): string
    {
        $endpoints = $this->closureArray('webhook.endpoints', []);

        return trim((string) ($endpoints[$scene] ?? ''));
    }

    protected function dispatchMode(): string
    {
        $mode = strtolower(trim((string) $this->closureOpt('webhook.dispatch', self::DISPATCH_QUEUE)));

        return $mode === self::DISPATCH_SYNC ? self::DISPATCH_SYNC : self::DISPATCH_QUEUE;
    }

    /**
     * 只记录主机名（不把完整 URL 写进投递行 —— URL 里可能带 token）
     */
    protected function host(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : 'unknown-host';
    }


    protected function requiredClasses(): array
    {
        return ['wise\webhook\server\WebhookCall' => 'wise-webhook 投递入口'];
    }

    /**
     * 真正投递（**唯一**接触 wise-webhook 的地方）
     *
     * @param array<string, mixed> $envelope
     * @return array{ok:bool, queued:bool, response:string, error:string}
     */
    protected function deliver(string $url, array $envelope, string $secret, string $mode): array
    {
        $class = 'wise\webhook\server\WebhookCall';

        $call = $class::create()->url($url)->payload($envelope);

        if ($secret !== '') {
            $call = $call->useSecret($secret);
        }

        if ($mode === self::DISPATCH_SYNC) {
            $result = (array) $call->dispatchSync();

            return [
                'ok'       => (bool) ($result['success'] ?? false),
                'queued'   => false,
                'response' => $this->truncate($this->json($result['response'] ?? ''), 1000),
                'error'    => (string) ($result['error'] ?? $result['response'] ?? 'unknown'),
            ];
        }

        $call->dispatchToQueue();

        return ['ok' => true, 'queued' => true, 'response' => 'queued', 'error' => ''];
    }
}
