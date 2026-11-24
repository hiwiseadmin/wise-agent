<?php
declare(strict_types=1);

namespace wise\agent\test;

use PHPUnit\Framework\TestCase;
use wise\agent\closure\ClosureEvent;
use wise\agent\closure\ClosureTimeline;
use wise\agent\closure\ConnectorResult;
use wise\agent\security\CapabilityAccess;

require_once __DIR__ . '/support/ClosureMemoryStore.php';

/**
 * B2-4 回归：闭环回放聚合（附录六 验收 2）
 *
 * 「走完闭环的高危写操作」应能取回 **≥4 类**记录；每个来源的**关联强度**必须如实标出。
 */
class ClosureTimelineTest extends TestCase
{
    private \ClosureMemoryStore $store;

    private TestableClosureTimeline $timeline;

    protected function setUp(): void
    {
        $this->store    = new \ClosureMemoryStore();
        $this->timeline = new TestableClosureTimeline([], $this->store);
    }

    /**
     * 播一条"走完闭环"的 trace：事件 + 两类投递
     */
    private function seedClosureEvent(): void
    {
        $event = new ClosureEvent(
            traceId: 'approval:AP1',
            scene: ClosureEvent::SCENE_APPROVAL_DECIDED,
            capabilityKind: 'tool',
            capabilityName: 'menu.action.host.stock.save',
            access: CapabilityAccess::WRITE,
            risk: CapabilityAccess::RISK_HIGH,
            write: true,
            result: ClosureEvent::RESULT_APPROVED,
            payload: ['decider_id' => 9],
            approvalId: 42,
            planRunId: 15,
            trackingId: 'track-1',
            actorId: 7,
        );

        $eventId = $this->store->createEvent($event, 'partial');

        $this->store->appendDelivery($eventId, 'approval:AP1', ConnectorResult::ok('audit', 'ai.closure', '{"via":"storage"}'));
        $this->store->appendDelivery($eventId, 'approval:AP1', ConnectorResult::failed('notification', '全部通道失败：sms: 网关超时'));
    }

    /**
     * 六源齐全
     */
    private function seedAllSources(): void
    {
        $this->seedClosureEvent();

        $this->timeline->approval = [[
            'ident' => 'AP1', 'status' => 'approved', 'requester_id' => 7, 'decider_id' => 9,
            'decision_note' => '同意', 'decided_at' => '2026-10-16 10:00:05', 'create_time' => '2026-10-16 10:00:00',
        ]];
        $this->timeline->execution = [[
            'tracking_id' => 'track-1', 'agent_type' => 'plan', 'status' => 2, 'steps' => 3,
            'total_tokens' => 1200, 'create_time' => '2026-10-16 10:00:10',
        ]];
        $this->timeline->plan = [
            ['_kind' => 'run', 'run_id' => 15, 'status' => 'completed', 'current_step' => 3, 'total_steps' => 3, 'create_time' => '2026-10-16 10:00:06'],
            ['_kind' => 'step', 'step_id' => 's2', 'status' => 'completed', 'target' => 'menu.action.host.stock.save', 'create_time' => '2026-10-16 10:00:08'],
        ];
        $this->timeline->notification = [[
            'channel' => 'email', 'status' => 'sent', 'notifiable_type' => 'admin', 'notifiable_id' => 7,
            'create_time' => '2026-10-16 10:00:11',
        ]];
        $this->timeline->webhook = [[
            'url' => 'https://erp.example.com/hook', 'status' => 'success', 'response_code' => 200,
            'create_time' => '2026-10-16 10:00:12',
        ]];
    }

    public function testFullClosureYieldsAtLeastFourSourceKinds(): void
    {
        $this->seedAllSources();

        $timeline = $this->timeline->build('approval:AP1');

        $this->assertSame(6, $timeline['source_count'], '六源齐全（验收 2 要求 ≥4）');
        $this->assertSame([], $timeline['missing']);
        $this->assertStringContainsString('≥4', (string) $timeline['note']);

        $sources = $timeline['sources'];

        $this->assertSame(
            3,
            $sources[ClosureTimeline::SOURCE_CLOSURE]['count'],
            '闭环来源 = 1 条事件 + 2 条投递'
        );
        $this->assertSame(1, $sources[ClosureTimeline::SOURCE_APPROVAL]['count']);
        $this->assertSame(2, $sources[ClosureTimeline::SOURCE_PLAN]['count'], '运行 1 行 + 步骤 1 行');
    }

    public function testEachSourceDeclaresHowItWasMatched(): void
    {
        $this->seedAllSources();

        $sources = $this->timeline->build('approval:AP1')['sources'];

        $this->assertStringContainsString('trace_id=approval:AP1', $sources[ClosureTimeline::SOURCE_CLOSURE]['matched_by']);
        $this->assertStringContainsString('ident=AP1', $sources[ClosureTimeline::SOURCE_APPROVAL]['matched_by']);
        $this->assertStringContainsString('tracking_id=track-1', $sources[ClosureTimeline::SOURCE_EXECUTION]['matched_by']);
        $this->assertStringContainsString('plan_run_id=15', $sources[ClosureTimeline::SOURCE_PLAN]['matched_by']);
        $this->assertStringContainsString(
            '弱匹配',
            $sources[ClosureTimeline::SOURCE_NOTIFICATION]['matched_by'],
            '通知历史只能按 JSON 文本包含匹配 ⇒ 必须如实标注为弱匹配'
        );
        $this->assertStringContainsString('LIKE', $sources[ClosureTimeline::SOURCE_WEBHOOK]['matched_by']);
    }

    public function testTimelineIsChronologicalAndReadable(): void
    {
        $this->seedAllSources();

        $timeline = $this->timeline->build('approval:AP1');
        $items    = $timeline['timeline'];

        $this->assertNotEmpty($items);

        $times = array_map(static fn(array $i): string => (string) $i['at'], $items);
        $sorted = $times;
        sort($sorted);

        $this->assertSame($sorted, $times, '时间线必须按时间升序');
        $this->assertSame('approval', $items[0]['source'], '最早的是审批申请（10:00:00）');

        $summaries = implode("\n", array_map(static fn(array $i): string => (string) $i['summary'], $items));

        $this->assertStringContainsString('闭环事件 approval_decided', $summaries);
        $this->assertStringContainsString('投递 audit：ok', $summaries);
        $this->assertStringContainsString('投递 notification：failed', $summaries);
        $this->assertStringContainsString('审批单 AP1：approved', $summaries);
        $this->assertStringContainsString('计划运行 #15：completed', $summaries);
        $this->assertStringContainsString('通知 email：sent', $summaries);
        $this->assertStringContainsString('webhook https://erp.example.com/hook：success', $summaries);
    }

    public function testMissingSourceDegradesIndependently(): void
    {
        $this->seedAllSources();
        $this->timeline->webhookFails = true;

        $timeline = $this->timeline->build('approval:AP1');

        $this->assertSame(5, $timeline['source_count'], '一个来源坏了不影响其它来源');
        $this->assertCount(1, $timeline['missing']);
        $this->assertStringContainsString('webhook', $timeline['missing'][0]);

        $webhook = $timeline['sources'][ClosureTimeline::SOURCE_WEBHOOK];

        $this->assertTrue($webhook['unavailable']);
        $this->assertStringContainsString('not found', (string) $webhook['error']);
        $this->assertSame(1, $timeline['sources'][ClosureTimeline::SOURCE_APPROVAL]['count'], '其余来源照常返回');
    }

    public function testMissingCorrelationKeysAreExplainedInsteadOfSilentlyEmpty(): void
    {
        $this->store->createEvent(new ClosureEvent(
            traceId: 'cl_abcdef',
            scene: ClosureEvent::SCENE_TOOL_EXECUTED,
            capabilityKind: 'tool',
            capabilityName: 'x',
            access: CapabilityAccess::WRITE,
            write: true,
            result: ClosureEvent::RESULT_SUCCESS,
        ), 'ok');

        $sources = $this->timeline->build('cl_abcdef')['sources'];

        $this->assertSame(0, $sources[ClosureTimeline::SOURCE_EXECUTION]['count']);
        $this->assertStringContainsString('未携带 tracking_id', $sources[ClosureTimeline::SOURCE_EXECUTION]['matched_by']);
        $this->assertSame(0, $sources[ClosureTimeline::SOURCE_PLAN]['count']);
        $this->assertStringContainsString('未携带 plan_run_id', $sources[ClosureTimeline::SOURCE_PLAN]['matched_by']);
        $this->assertStringContainsString('不是 approval:*', $sources[ClosureTimeline::SOURCE_APPROVAL]['matched_by']);
        $this->assertFalse($sources[ClosureTimeline::SOURCE_EXECUTION]['unavailable'], '没有关联键 ≠ 来源不可用');
    }

    public function testEmptyTraceIsRejectedHonestly(): void
    {
        $timeline = $this->timeline->build('   ');

        $this->assertSame('', $timeline['trace_id']);
        $this->assertSame(0, $timeline['source_count']);
        $this->assertSame('trace_id 为空', $timeline['note']);
    }
}


/**
 * 把六个数据来源换成可注入的数组（保留 `build()` 的全部组装逻辑）
 */
class TestableClosureTimeline extends ClosureTimeline
{
    /** @var array<int, array<string, mixed>> */
    public array $approval = [];

    /** @var array<int, array<string, mixed>> */
    public array $execution = [];

    /** @var array<int, array<string, mixed>> */
    public array $plan = [];

    /** @var array<int, array<string, mixed>> */
    public array $notification = [];

    /** @var array<int, array<string, mixed>> */
    public array $webhook = [];

    public bool $webhookFails = false;

    protected function approvalRows(string $ident): array
    {
        return $this->approval;
    }

    protected function executionRows(string $trackingId): array
    {
        return $this->execution;
    }

    protected function planRows(int $runId): array
    {
        return $this->plan;
    }

    protected function notificationRows(string $traceId): array
    {
        return $this->notification;
    }

    protected function webhookRows(string $traceId): array
    {
        if ($this->webhookFails) {
            throw new \RuntimeException('SQLSTATE: table webhook_sends not found');
        }

        return $this->webhook;
    }
}
