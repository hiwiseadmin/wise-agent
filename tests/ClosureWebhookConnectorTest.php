<?php
declare(strict_types=1);

namespace wise\agent\test;

use PHPUnit\Framework\TestCase;
use wise\agent\closure\ClosureEvent;
use wise\agent\closure\connector\WebhookConnector;
use wise\agent\security\CapabilityAccess;

/**
 * B2-3 回归：外部联动连接器（附录六 验收 6）
 *
 * 离线：`deliver()` 是接缝，不真发 HTTP；信封组装与 URL 来源才是要锁死的契约。
 */
class ClosureWebhookConnectorTest extends TestCase
{
    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function options(array $overrides = []): array
    {
        return array_merge([
            'webhook' => [
                'endpoints'         => ['approval_decided' => 'https://erp.example.com/hooks/ai?token=s3cr3t'],
                'secret'            => '',
                'dispatch'          => 'queue',
                'max_payload_chars' => 8000,
            ],
        ], $overrides);
    }

    private function event(array $payload = []): ClosureEvent
    {
        return new ClosureEvent(
            traceId: 'approval:AP1',
            scene: ClosureEvent::SCENE_APPROVAL_DECIDED,
            capabilityKind: 'tool',
            capabilityName: 'menu.action.host.stock.save',
            access: CapabilityAccess::WRITE,
            risk: CapabilityAccess::RISK_HIGH,
            write: true,
            result: ClosureEvent::RESULT_APPROVED,
            reason: '库存调整',
            payload: $payload,
            approvalId: 42,
            planRunId: 15,
            sessionId: 'S1',
            trackingId: 'track-1',
            actorId: 7,
            actorType: 'admin',
        );
    }

    /**
     * @param array<string, mixed> $options
     */
    private function connector(array $options, ?TestableWebhookConnector $custom = null): TestableWebhookConnector
    {
        return $custom ?? new TestableWebhookConnector($options);
    }


    public function testEnvelopeHasEveryContractField(): void
    {
        $connector = $this->connector($this->options());

        $result = $connector->dispatch($this->event(['permission' => 'menu.action.host.stock.save', 'decider_id' => 9]));

        $this->assertTrue($result->isOk(), $result->error);
        $this->assertCount(1, $connector->delivered);

        $envelope = $connector->delivered[0]['envelope'];

        $this->assertSame('ai_closure.approval_decided', $envelope['event']);
        $this->assertSame('approval:AP1', $envelope['trace_id']);
        $this->assertSame('approval_decided', $envelope['scene']);
        $this->assertSame('approved', $envelope['result']);
        $this->assertSame('库存调整', $envelope['reason']);

        $this->assertSame(
            ['kind' => 'tool', 'name' => 'menu.action.host.stock.save', 'access' => 'write', 'risk' => 'high', 'write' => true],
            $envelope['capability']
        );
        $this->assertSame(['type' => 'admin', 'id' => 7], $envelope['actor']);
        $this->assertSame(['id' => 42], $envelope['approval']);
        $this->assertSame(['run_id' => 15], $envelope['plan']);
        $this->assertSame('S1', $envelope['session_id']);
        $this->assertSame('track-1', $envelope['tracking_id']);
        $this->assertSame(64, strlen($envelope['payload_hash']), '载荷指纹供接收方做幂等去重');
        $this->assertSame('menu.action.host.stock.save', $envelope['data']['permission']);
        $this->assertNotEmpty($envelope['occurred_at']);
    }

    public function testDataIsRemaskedBeforeLeaving(): void
    {
        $connector = $this->connector($this->options());

        $connector->dispatch($this->event(['password' => 'p@ss', 'keyword' => '手机', 'total_tokens' => 10]));

        $data = $connector->delivered[0]['envelope']['data'];

        $this->assertSame('***', $data['password'], '出站前再脱敏一次（双保险）');
        $this->assertSame('手机', $data['keyword']);
        $this->assertSame(10, $data['total_tokens']);
    }


    public function testMissingEndpointIsSkippedNotFailed(): void
    {
        $result = $this->connector($this->options(['webhook' => ['endpoints' => []]]))->dispatch($this->event());

        $this->assertTrue($result->isSkipped(), '没配目标不是故障');
        $this->assertStringContainsString('未配置 closure.webhook.endpoints.approval_decided', $result->error);
    }

    public function testUrlNeverComesFromEventPayload(): void
    {
        $connector = $this->connector($this->options(['webhook' => ['endpoints' => []]]));

        $result = $connector->dispatch($this->event([
            'url'     => 'https://evil.example.net/steal',
            'webhook' => 'https://evil.example.net/steal2',
        ]));

        $this->assertTrue($result->isSkipped());
        $this->assertSame([], $connector->delivered, '事件来源的 URL 一律不投递');

        $configured = $this->connector($this->options());
        $configured->dispatch($this->event(['url' => 'https://evil.example.net/steal']));

        $this->assertStringStartsWith('https://erp.example.com/hooks/ai', $configured->delivered[0]['url']);
    }

    public function testTargetRecordsHostOnlyNotTheSecretBearingUrl(): void
    {
        $result = $this->connector($this->options())->dispatch($this->event());

        $this->assertSame('erp.example.com', $result->target, '投递行只留主机名（URL 里可能带 token）');
        $this->assertStringNotContainsString('s3cr3t', $result->target);
    }


    public function testQueueModeIsDefaultAndReportsQueued(): void
    {
        $connector = $this->connector($this->options());

        $result = $connector->dispatch($this->event());

        $this->assertSame('queue', $connector->delivered[0]['mode'], '默认入队（同步等外部 HTTP 会拖慢 AI 主链路）');
        $this->assertTrue($result->isOk());
        $this->assertStringContainsString('"dispatch":"queue"', $result->response);
        $this->assertStringContainsString('webhook_sends', $result->response, '如实说明最终结果要去哪看');
    }

    public function testSyncModeMapsFailureResponse(): void
    {
        $connector = $this->connector($this->options(['webhook' => [
            'endpoints' => ['approval_decided' => 'https://erp.example.com/hook'],
            'dispatch'  => 'sync',
        ]]));

        $connector->syncOutcome = ['ok' => false, 'queued' => false, 'response' => 'HTTP 500', 'error' => 'HTTP 500'];

        $result = $connector->dispatch($this->event());

        $this->assertSame('sync', $connector->delivered[0]['mode']);
        $this->assertTrue($result->isFailed());
        $this->assertStringContainsString('HTTP 500', $result->error);
    }

    public function testSecretIsPassedOnlyWhenConfigured(): void
    {
        $without = $this->connector($this->options());
        $without->dispatch($this->event());
        $this->assertSame('', $without->delivered[0]['secret'], '未配置 ⇒ 用 wise-webhook 自己的 server.secret');

        $with = $this->connector($this->options(['webhook' => [
            'endpoints' => ['approval_decided' => 'https://erp.example.com/hook'],
            'secret'    => 'closure-secret',
        ]]));
        $with->dispatch($this->event());
        $this->assertSame('closure-secret', $with->delivered[0]['secret']);
    }

    public function testOversizedDataIsTruncatedWithoutBreakingTheEnvelope(): void
    {
        $connector = $this->connector($this->options(['webhook' => [
            'endpoints'         => ['approval_decided' => 'https://erp.example.com/hook'],
            'max_payload_chars' => 64,
        ]]));

        $connector->dispatch($this->event(['big' => str_repeat('长', 200)]));

        $envelope = $connector->delivered[0]['envelope'];

        $this->assertTrue($envelope['data']['truncated'], '只截 data，信封字段必须完好');
        $this->assertSame('approval:AP1', $envelope['trace_id']);
        $this->assertSame(['id' => 42], $envelope['approval']);
    }

    public function testDownstreamMissingIsSkippedNotFailed(): void
    {
        $result = (new WebhookMissingDownstreamConnector($this->options()))->dispatch($this->event());

        $this->assertTrue($result->isSkipped());
        $this->assertStringContainsString('不可用', $result->error);
    }

    public function testDeliveryExceptionIsReportedAsFailed(): void
    {
        $connector = $this->connector($this->options());
        $connector->throwOnDeliver = new \RuntimeException('connection refused');

        $result = $connector->dispatch($this->event());

        $this->assertTrue($result->isFailed(), '投递抛异常必须如实上报（不冒泡到主链路）');
        $this->assertStringContainsString('connection refused', $result->error);
    }

    public function testSupportsAllScenes(): void
    {
        $connector = $this->connector($this->options());

        foreach (ClosureEvent::SCENES as $scene) {
            $this->assertTrue($connector->supports(new ClosureEvent('t', $scene)));
        }
    }
}


/**
 * 把真实投递换成记录接缝
 */
class TestableWebhookConnector extends WebhookConnector
{
    /** @var array<int, array{url:string, envelope:array, secret:string, mode:string}> */
    public array $delivered = [];

    /** @var array{ok:bool, queued:bool, response:string, error:string} */
    public array $syncOutcome = ['ok' => true, 'queued' => false, 'response' => 'HTTP 200', 'error' => ''];

    public ?\Throwable $throwOnDeliver = null;

    protected function deliver(string $url, array $envelope, string $secret, string $mode): array
    {
        if ($this->throwOnDeliver !== null) {
            throw $this->throwOnDeliver;
        }

        $this->delivered[] = ['url' => $url, 'envelope' => $envelope, 'secret' => $secret, 'mode' => $mode];

        return $mode === 'sync'
            ? $this->syncOutcome
            : ['ok' => true, 'queued' => true, 'response' => 'queued', 'error' => ''];
    }
}

/**
 * 强制"下游缺失"
 */
class WebhookMissingDownstreamConnector extends WebhookConnector
{
    protected function requiredClasses(): array
    {
        return ['wise\webhook\not\installed\WebhookCall' => 'wise-webhook 投递入口'];
    }
}
