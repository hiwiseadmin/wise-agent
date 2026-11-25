<?php
declare(strict_types=1);

namespace wise\agent\test;

use PHPUnit\Framework\TestCase;
use wise\agent\closure\ClosureEvent;
use wise\agent\closure\connector\NotificationConnector;
use wise\agent\security\CapabilityAccess;
use wise\notification\contract\Notifiable;

/**
 * B2-3 回归：通知连接器（附录六 验收 5）
 *
 * 离线：`send()` 与 `notifiableFor()` 是接缝，不依赖 wise-notification 的库与队列。
 */
class ClosureNotificationConnectorTest extends TestCase
{
    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function options(array $overrides = []): array
    {
        return array_merge([
            'notification' => [
                'templates'              => [],
                'channels_by_scene'      => [
                    ClosureEvent::SCENE_APPROVAL_REQUESTED => ['database', 'email'],
                    ClosureEvent::SCENE_APPROVAL_DECIDED   => ['database'],
                ],
                'skip_channels_by_scene' => [
                    ClosureEvent::SCENE_APPROVAL_REQUESTED => ['database'],
                    ClosureEvent::SCENE_APPROVAL_DECIDED   => ['database'],
                ],
                'recipients'             => [],
                'sync'                   => false,
                'takeover'               => false,
            ],
        ], $overrides);
    }

    private function event(string $scene = ClosureEvent::SCENE_APPROVAL_DECIDED, int $actorId = 7): ClosureEvent
    {
        return new ClosureEvent(
            traceId: 'approval:AP1',
            scene: $scene,
            capabilityKind: 'tool',
            capabilityName: 'menu.action.host.stock.save',
            access: CapabilityAccess::WRITE,
            risk: CapabilityAccess::RISK_HIGH,
            write: true,
            result: $scene === ClosureEvent::SCENE_APPROVAL_REQUESTED
                ? ClosureEvent::RESULT_PENDING
                : ClosureEvent::RESULT_APPROVED,
            reason: '库存调整',
            payload: ['permission' => 'p', 'decider_id' => 9],
            approvalId: 42,
            actorId: $actorId,
            actorType: 'admin',
        );
    }

    /**
     * @param array<string, mixed> $options
     */
    private function connector(array $options, ?TestableNotificationConnector $custom = null): TestableNotificationConnector
    {
        return $custom ?? new TestableNotificationConnector($options);
    }


    public function testTemplateHitSendsArrayPayloadWithTemplateCode(): void
    {
        $connector = $this->connector($this->options(['notification' => [
            'templates'         => [ClosureEvent::SCENE_APPROVAL_DECIDED => 'ai_closure.decided'],
            'channels_by_scene' => [ClosureEvent::SCENE_APPROVAL_DECIDED => ['email']],
            'existing_templates' => ['ai_closure.decided'],
        ]]));

        $result = $connector->dispatch($this->event());

        $this->assertTrue($result->isOk(), $result->error);
        $this->assertSame('ai_closure.decided', $connector->sent[0]['template']);
        $this->assertIsArray($connector->sent[0]['payload'], '模板只对**数组消息**生效（Message 会绕过模板）');
        $this->assertSame('approval_decided', $connector->sent[0]['payload']['scene']);
        $this->assertSame('approval:AP1', $connector->sent[0]['payload']['trace_id']);
        $this->assertArrayHasKey('title', $connector->sent[0]['payload'], '模板变量里带 title/content 兜底');
        $this->assertSame(['email'], $connector->sent[0]['channels']);
    }

    public function testTemplateMissingFallsBackToMessageAndNotes(): void
    {
        $connector = $this->connector($this->options(['notification' => [
            'templates'          => [ClosureEvent::SCENE_APPROVAL_DECIDED => 'ai_closure.nope'],
            'channels_by_scene'  => [ClosureEvent::SCENE_APPROVAL_DECIDED => ['email']],
            'existing_templates' => [],
        ]]));

        $result = $connector->dispatch($this->event());

        $this->assertTrue($result->isOk(), $result->error);
        $this->assertInstanceOf(\wise\notification\contract\Message::class, $connector->sent[0]['payload'], '缺失模板时用兜底文案');
        $this->assertSame('', $connector->sent[0]['template'], '不带着不存在的模板 code 去发');
        $this->assertStringContainsString('template_missing', $result->response, '必须留 note，让人知道模板没配上');

        $message = $connector->sent[0]['payload'];
        $this->assertSame('AI 能力审批结果', $message->title);
        $this->assertStringContainsString('menu.action.host.stock.save', $message->content);
        $this->assertStringContainsString('trace=approval:AP1', $message->content);
    }


    public function testSendResultIsConsumedIntoResponse(): void
    {
        $connector = $this->connector($this->options(['notification' => [
            'channels_by_scene' => [ClosureEvent::SCENE_APPROVAL_DECIDED => ['email']],
        ]]));

        $result = $connector->dispatch($this->event());

        $this->assertTrue($result->isOk());
        $this->assertStringContainsString('"channel":"email"', $result->response);
        $this->assertStringContainsString('"status":"sent"', $result->response);
        $this->assertSame('email', $result->target);
    }

    public function testQueuedStatusIsStillOkButVisible(): void
    {
        $connector = $this->connector($this->options(['notification' => [
            'channels_by_scene' => [ClosureEvent::SCENE_APPROVAL_DECIDED => ['email']],
        ]]));
        $connector->resultTemplate = ['success' => true, 'channel' => 'email', 'status' => 'queued'];

        $result = $connector->dispatch($this->event());

        $this->assertTrue($result->isOk(), '入队即成功（最终投递与重试由 wise-notification 负责）');
        $this->assertStringContainsString('"status":"queued"', $result->response, '但要如实标出它只是入队');
    }

    public function testBatchResultPartialFailureIsReportedAsFailed(): void
    {
        $connector = $this->connector($this->options(['notification' => [
            'channels_by_scene' => [ClosureEvent::SCENE_APPROVAL_DECIDED => ['email', 'sms']],
        ]]));
        $connector->batchResults = [
            ['success' => true, 'channel' => 'email', 'status' => 'sent'],
            ['success' => false, 'channel' => 'sms', 'status' => 'failed', 'error' => '网关超时'],
        ];

        $result = $connector->dispatch($this->event());

        $this->assertTrue($result->isFailed(), '部分失败按失败上报，补投才有意义');
        $this->assertStringContainsString('部分通道失败（1/2）', $result->error);
        $this->assertStringContainsString('网关超时', $result->error);
        $this->assertStringContainsString('"channel":"email"', $result->response);
    }

    public function testNullResultIsSkippedNotFailed(): void
    {
        $connector = $this->connector($this->options(['notification' => [
            'channels_by_scene' => [ClosureEvent::SCENE_APPROVAL_DECIDED => ['email']],
        ]]));
        $connector->nullResult = true;

        $result = $connector->dispatch($this->event());

        $this->assertTrue($result->isSkipped(), '免打扰/中间件拦截不是失败，补投也没有意义');
        $this->assertStringContainsString('返回 null', $result->error);
    }

    public function testNotifiableObjectIsRequired(): void
    {
        $connector = $this->connector($this->options(['notification' => [
            'channels_by_scene' => [ClosureEvent::SCENE_APPROVAL_DECIDED => ['email']],
        ]]));
        $connector->notifiable = null;

        $result = $connector->dispatch($this->event());

        $this->assertTrue($result->isSkipped());
        $this->assertSame([], $connector->sent, '找不到可通知对象时不发送');
    }


    public function testSkipChannelsByScenePreventsDuplicateDatabaseNotification(): void
    {
        $connector = $this->connector($this->options());

        $result = $connector->dispatch($this->event());

        $this->assertTrue($result->isSkipped());
        $this->assertSame([], $connector->sent, '站内信由 ApprovalManager 内置通知发，B2 不重复发');
        $this->assertStringContainsString('skip_channels_by_scene', $result->error);
        $this->assertStringContainsString('takeover', $result->error, '给出"如何接管"的出口');
        $this->assertSame('database', $result->target, '如实列出被配置的渠道');
    }

    public function testTakeoverSendsAllConfiguredChannels(): void
    {
        $connector = $this->connector($this->options(['notification' => [
            'channels_by_scene'      => [ClosureEvent::SCENE_APPROVAL_DECIDED => ['database', 'email']],
            'skip_channels_by_scene' => [ClosureEvent::SCENE_APPROVAL_DECIDED => ['database']],
            'takeover'               => true,
        ]]));

        $result = $connector->dispatch($this->event());

        $this->assertTrue($result->isOk(), $result->error);
        $this->assertSame(['database', 'email'], $connector->sent[0]['channels'], 'takeover 时忽略跳过清单');
    }

    public function testNonTriggeredChannelIsDroppedButOthersStillSend(): void
    {
        $connector = $this->connector($this->options());

        $result = $connector->dispatch($this->event(ClosureEvent::SCENE_APPROVAL_REQUESTED, 0));

        $this->assertTrue($result->isSkipped());
        $this->assertStringContainsString('审批人名单不在闭环事件里', $result->error);
    }

    public function testApprovalRequestedUsesConfiguredApprovers(): void
    {
        $connector = $this->connector($this->options(['notification' => [
            'channels_by_scene'      => [ClosureEvent::SCENE_APPROVAL_REQUESTED => ['database', 'email']],
            'skip_channels_by_scene' => [ClosureEvent::SCENE_APPROVAL_REQUESTED => ['database']],
            'recipients'             => [ClosureEvent::SCENE_APPROVAL_REQUESTED => [101, 102]],
        ]]));

        $result = $connector->dispatch($this->event(ClosureEvent::SCENE_APPROVAL_REQUESTED, 7));

        $this->assertTrue($result->isOk(), $result->error);
        $this->assertCount(2, $connector->sent, '按配置发给审批人');
        $this->assertSame(['email'], $connector->sent[0]['channels'], '站内信仍在跳过清单里');
        $this->assertSame([101, 102], $connector->userIds);
    }

    public function testActorIsDefaultRecipientWhenNoConfig(): void
    {
        $connector = $this->connector($this->options(['notification' => [
            'channels_by_scene' => [ClosureEvent::SCENE_APPROVAL_DECIDED => ['email']],
        ]]));

        $connector->dispatch($this->event());

        $this->assertSame([7], $connector->userIds, '事件里的发起人就是默认收件人');
    }

    public function testDownstreamMissingIsSkippedNotFailed(): void
    {
        $result = (new NotificationMissingDownstreamConnector($this->options()))->dispatch($this->event());

        $this->assertTrue($result->isSkipped());
        $this->assertStringContainsString('不可用', $result->error);
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
 * 把发送与收件人解析换成记录接缝
 */
class TestableNotificationConnector extends NotificationConnector
{
    /** @var array<int, array{channels:array, payload:mixed, template:string}> */
    public array $sent = [];

    /** @var int[] */
    public array $userIds = [];

    public object|null $notifiable;

    public bool $nullResult = false;

    /** @var array<int, array<string, mixed>>|null */
    public ?array $batchResults = null;

    /** @var array<string, mixed> */
    public array $resultTemplate = ['success' => true, 'channel' => 'email', 'status' => 'sent'];

    public function __construct(array $options = [])
    {
        parent::__construct($options);
        $this->notifiable = new ClosureFakeNotifiable();
    }

    protected function requiredClasses(): array
    {
        return [];
    }

    protected function notifiableFor(int $userId): ?object
    {
        $this->userIds[] = $userId;

        return $this->notifiable;
    }

    protected function templateExists(string $code): bool
    {
        $existing = $this->closureArray('notification.existing_templates', []);

        return in_array($code, array_map('strval', $existing), true);
    }

    protected function send(array $channels, object $notifiable, mixed $payload, string $template): array
    {
        $this->sent[] = [
            'channels' => $channels,
            'payload'  => $payload,
            'template' => is_array($payload) ? $template : '',
        ];

        if ($this->nullResult) {
            return ['results' => [], 'null_result' => true, 'template_missing' => false];
        }

        $results = $this->batchResults ?? [$this->resultTemplate];

        return [
            'results'          => array_map(fn(array $r): array => $this->normalizeResult($r), $results),
            'null_result'      => false,
            'template_missing' => $template !== '' && !is_array($payload),
        ];
    }
}

/**
 * 强制"下游缺失"
 */
class NotificationMissingDownstreamConnector extends NotificationConnector
{
    protected function requiredClasses(): array
    {
        return ['wise\notification\not\installed\Notification' => 'wise-notification 门面'];
    }
}

/**
 * 最小可通知对象（实现 wise-notification 的契约）
 */
class ClosureFakeNotifiable implements Notifiable
{
    public function routeNotificationFor(string $channel): string|array
    {
        return 'user@example.com';
    }

    public function wantsNotification(string $channel, ?string $type = null): bool
    {
        return true;
    }

    public function quietHours(): ?array
    {
        return null;
    }

    public function maxDailyNotifications(string $channel): ?int
    {
        return null;
    }

    public function getNotifiableId(): string
    {
        return '7';
    }

    public function getNotifiableType(): string
    {
        return 'admin';
    }
}
