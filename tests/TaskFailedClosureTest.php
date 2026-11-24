<?php
declare(strict_types=1);

namespace wise\agent\test;

use PHPUnit\Framework\TestCase;
use wise\agent\closure\ClosureEvent;
use wise\agent\closure\ClosureEventFactory;
use wise\agent\closure\ClosureRouter;

/**
 * C1-2：task_failed 场景的归一化与路由（纯离线，验收 4 的可离线部分）
 */
class TaskFailedClosureTest extends TestCase
{
    public function testFromTaskFailedNormalizes(): void
    {
        $event = (new ClosureEventFactory([]))->fromTaskFailed([
            'tracking_id' => 'track_abc',
            'session_id'  => 's1',
            'user_id'     => 7,
            'user_type'   => 'admin',
            'job_type'    => 'agent',
            'attempts'    => 4,
            'last_error'  => 'boom',
            'reason'      => 'Max retries exceeded (3): boom',
        ]);

        $this->assertSame(ClosureEvent::SCENE_TASK_FAILED, $event->scene);
        $this->assertSame(ClosureEvent::RESULT_FAILURE, $event->result);
        $this->assertSame('agent', $event->capabilityKind, '任务级场景复用 agent 类别，绕过读写过滤');
        $this->assertSame('agent', $event->capabilityName);
        $this->assertSame(7, $event->actorId, '发起人身份随 meta 进入事件');
        $this->assertSame('admin', $event->actorType);
        $this->assertSame('track_abc', $event->trackingId);
        $this->assertSame('track:track_abc', $event->traceId, 'trace 与 tracking_id 贯通');
        $this->assertSame(4, $event->payload['attempts']);
        $this->assertSame('boom', $event->payload['last_error']);
        $this->assertSame('agent', $event->payload['job_type']);
        $this->assertStringContainsString('Max retries exceeded', $event->reason);
    }

    public function testFromTaskFailedFallsBackToRandomTraceWithoutTrackingId(): void
    {
        $event = (new ClosureEventFactory([]))->fromTaskFailed([
            'job_type' => 'plan',
        ]);

        $this->assertMatchesRegularExpression(
            '/^cl_[0-9a-f]{16}$/',
            $event->traceId,
            '无 tracking_id 时回落到随机 trace'
        );
        $this->assertSame(0, $event->actorId, '无 user_id 时 actorId=0（通知会 skipped）');
        $this->assertSame('plan', $event->capabilityName);
    }

    public function testRouterRoutesTaskFailedToNotificationAndAudit(): void
    {
        $event = (new ClosureEventFactory([]))->fromTaskFailed([
            'tracking_id' => 'track_abc',
            'user_id'     => 7,
            'job_type'    => 'agent',
            'reason'      => 'boom',
        ]);

        $explain = (new ClosureRouter([]))->explain($event);

        $this->assertContains('notification', $explain['kept']);
        $this->assertContains('audit', $explain['kept']);
        $this->assertSame('', $explain['reason'], '场景已配置，路由不落空');
    }

    public function testRouterTreatsTaskFailedAsConsequential(): void
    {
        $event = (new ClosureEventFactory([]))->fromTaskFailed([
            'job_type' => 'agent',
        ]);

        $explain = (new ClosureRouter(['only_write_capabilities' => true]))->explain($event);

        $this->assertContains('notification', $explain['kept'], '任务失败是「值得惊动别人」的事件');
    }
}
