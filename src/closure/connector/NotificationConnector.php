<?php
declare(strict_types=1);

namespace wise\agent\closure\connector;

use wise\agent\closure\ClosureEvent;
use wise\agent\closure\ConnectorResult;
use wise\agent\closure\PayloadMasker;

/**
 * 通知连接器＼�
 *
 * 把闭环事件投递到 `wise-notification`（多渠道 / 模板 / 结果留痕）。
 *
 * ## ⚠️ 三条实测出来的关键约束（勿凭印象改）
 *
 * 1. **`template()` 只对「数组消息」生效**：`NotificationManager::resolveMessage()`（`:220-250`）
 *    在 `$message instanceof Message` 时**直接返回**，根本不看模板。
 *    因此要走模板，必须传**数组**（数组同时充当模板变量）。
 * 2. **模板找不到时 `send()` 会抛 `RuntimeException`**（`:232-236`）。
 *    所以这里先 `NotificationTemplate::getByCode()` **预检**，缺失时直接用兜底文案的 `Message` ——
 *    而不是先发一次再补救（那会多一次无谓投递，也可能在队列里留下垃圾）。
 * 3. **`send()` 可能返回 `null`**（免打扰/中间件拦截/无可用通道）或 `BatchSendResult`（多渠道）。
 *    返回 `null` ⇒ 记 `skipped` 并写明原因，**不假装成功、也不算失败**（补投没有意义）。
 *
 * ## 收件人：只覆盖「事件里确实有的身份」（不做第二套审批人解析）
 *
 * - `approval_decided` / `agent_completed` / `tool_executed` ⇒ 发给**事件发起人**（`actor_id`）；
 * - `approval_requested` ⇒ 需要**审批人名单**，而事件里没有 ⇒ 从
 *   `closure.notification.recipients.approval_requested` 显式配置；
 *   未配置 ⇒ `skipped` 并写明「审批人名单不在事件里」。
 *
 * 刻意**不**复刻 `ApprovalManager::resolveApprovers()`（它还要按权限→角色→用户查库，
 * 抄一份必然漂移）；B2 的定位是「补齐其它通道」，站内信仍由既有实现发。
 *
 * ## 双发去重（验收 5）
 *
 * `ApprovalManager::notify()`（`:991-1031`）**没有开关**地给审批人/申请人发站内信，
 * 因此 B2 默认在这些场景**跳过 `database` 通道**（`skip_channels_by_scene`），只补 email/IM。
 * 跳完没剩通道 ⇒ `skipped` + 可读原因（**看得见**，不是静默不发）。
 * `takeover=true` 时忽略跳过清单（全通道接管；此时与内置站内信重复**无法消除**）。
 *
 * ## 不设优先级
 *
 * 刻意不调 `priority()`：`urgent` 会绕过免打扰与日配额（`NotificationManager.php:196-203`），
 * 那是**业务策略**，不是连接器该替使用者决定的事。
 */
class NotificationConnector extends AbstractConnector
{
    public const NAME = 'notification';

    /** 兜底渠道（未配置时） */
    public const DEFAULT_CHANNELS = ['database'];

    private ?object $sender = null;

    /**
     * @param array<string, mixed> $options `closure.*` 覆盖项
     */
    public function __construct(array $options = [], ?PayloadMasker $masker = null, ?object $sender = null)
    {
        parent::__construct($options, $masker);
        $this->sender = $sender;
    }

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

        $recipients = $this->recipientsFor($event);

        if ($recipients === []) {
            return ConnectorResult::skipped(
                self::NAME,
                $event->scene === ClosureEvent::SCENE_APPROVAL_REQUESTED
                    ? '审批人名单不在闭环事件里：请在 closure.notification.recipients.approval_requested 显式配置，'
                        . '或依赖 ApprovalManager 的内置站内信（closure.notification.takeover=false 时默认如此）'
                    : '事件没有可用的收件人（actor_id 为 0 且未配置 recipients）'
            );
        }

        $channels = $this->channelsFor($event);

        if ($channels === []) {
            return ConnectorResult::skipped(
                self::NAME,
                sprintf(
                    '场景 `%s` 的全部通道都被 closure.notification.skip_channels_by_scene 跳过'
                    . '（避免与 ApprovalManager 内置站内信双发）；如需接管请设 closure.notification.takeover=true',
                    $event->scene
                ),
                implode(',', $this->configuredChannels($event))
            );
        }

        $message      = $this->messageFor($event);
        $results      = [];
        $notes        = [];
        $sawNull      = false;
        $unresolvable = [];

        foreach ($recipients as $userId) {
            $notifiable = $this->notifiableFor($userId);

            if ($notifiable === null) {
                $unresolvable[] = $userId;

                continue;
            }

            $outcome = $this->send($channels, $notifiable, $message['payload'], $message['template']);

            if ($outcome['template_missing']) {
                $notes[] = [
                    'code'    => 'closure.template_missing',
                    'message' => sprintf('模板 `%s` 不存在，已改用兜底文案', (string) $message['template']),
                ];
            }

            foreach ($outcome['results'] as $one) {
                $results[] = $one + ['user_id' => $userId];
            }

            if ($outcome['null_result']) {
                $sawNull = true;
                $notes[] = [
                    'code'    => 'closure.send_returned_null',
                    'message' => sprintf('user #%d：send() 返回 null（免打扰/中间件拦截/无可用通道）', $userId),
                ];
            }
        }

        if ($results === [] && $unresolvable !== []) {
            return ConnectorResult::skipped(
                self::NAME,
                '收件人不可解析（不是 Notifiable）：#' . implode(', #', $unresolvable)
                . '；请检查 wise-auth.user_models 配置与用户是否存在',
                implode(',', $channels)
            );
        }

        foreach ($unresolvable as $userId) {
            $results[] = [
                'channel'     => implode(',', $channels),
                'success'     => false,
                'status'      => 'failed',
                'error'       => 'user #' . $userId . ' 不是可通知对象（Notifiable）',
                'duration_ms' => 0,
                'user_id'     => $userId,
            ];
        }

        return $this->resultOf($channels, $results, $notes, $sawNull);
    }


    /**
     * @return array{payload:\wise\notification\contract\Message|array<string,mixed>, template:string}
     */
    protected function messageFor(ClosureEvent $event): array
    {
        $templateCode = trim((string) $this->templateFor($event->scene));

        if ($templateCode !== '' && $this->templateExists($templateCode)) {
            return ['payload' => $this->templateVars($event), 'template' => $templateCode];
        }

        return [
            'payload'  => $this->fallbackMessage($event),
            'template' => $templateCode,
        ];
    }

    protected function fallbackMessage(ClosureEvent $event): \wise\notification\contract\Message
    {
        $messageClass = 'wise\notification\contract\Message';

        return new $messageClass($this->fallbackTitle($event), $this->fallbackContent($event), [
            'trace_id'    => $event->traceId,
            'scene'       => $event->scene,
            'result'      => $event->result,
            'capability'  => $event->capability(),
            'approval_id' => $event->approvalId,
            'plan_run_id' => $event->planRunId,
        ]);
    }

    protected function fallbackTitle(ClosureEvent $event): string
    {
        return match ($event->scene) {
            ClosureEvent::SCENE_APPROVAL_REQUESTED => 'AI 能力审批待处理',
            ClosureEvent::SCENE_APPROVAL_DECIDED   => 'AI 能力审批结果',
            ClosureEvent::SCENE_AGENT_COMPLETED    => 'AI 任务已完成',
            default                                => 'AI 操作结果',
        };
    }

    protected function fallbackContent(ClosureEvent $event): string
    {
        $parts = array_filter([
            $event->capability() !== '' ? $event->capability() : null,
            '结论：' . $event->result,
            $event->reason !== '' ? '（' . $event->reason . '）' : null,
            $event->approvalId > 0 ? '审批单 #' . $event->approvalId : null,
            'trace=' . $event->traceId,
        ]);

        return implode(' | ', $parts);
    }

    /**
     * 模板变量（含 `title`/`content` 兜底，模板可以只用其一）
     *
     * @return array<string, mixed>
     */
    protected function templateVars(ClosureEvent $event): array
    {
        return [
            'title'       => $this->fallbackTitle($event),
            'content'     => $this->fallbackContent($event),
            'scene'       => $event->scene,
            'result'      => $event->result,
            'reason'      => $event->reason,
            'capability'  => $event->capability(),
            'trace_id'    => $event->traceId,
            'approval_id' => $event->approvalId,
            'plan_run_id' => $event->planRunId,
            'actor_id'    => $event->actorId,
            'occurred_at' => $event->occurredAt(),
            'data'        => $this->masked($event->payload),
        ];
    }


    /**
     * 生效渠道 = 场景渠道 − 跳过渠道（`takeover=true` 时不减）
     *
     * @return string[]
     */
    protected function channelsFor(ClosureEvent $event): array
    {
        $channels = $this->configuredChannels($event);

        if ($this->closureBool('notification.takeover', false)) {
            return $channels;
        }

        $skip = $this->closureArray('notification.skip_channels_by_scene', []);
        $skip = is_array($skip[$event->scene] ?? null) ? array_map('strval', $skip[$event->scene]) : [];

        return array_values(array_diff($channels, $skip));
    }

    /**
     * @return string[]
     */
    protected function configuredChannels(ClosureEvent $event): array
    {
        $byScene = $this->closureArray('notification.channels_by_scene', []);
        $channels = $byScene[$event->scene] ?? $this->closureArray('notification.channels', self::DEFAULT_CHANNELS);

        $channels = is_array($channels) ? array_map('strval', $channels) : [];

        return array_values(array_filter(array_unique($channels), static fn(string $c): bool => $c !== ''));
    }

    /**
     * @return int[]
     */
    protected function recipientsFor(ClosureEvent $event): array
    {
        $configured = $this->closureArray('notification.recipients', []);
        $ids        = $configured[$event->scene] ?? [];

        if (!is_array($ids) || $ids === []) {
            return $event->actorId > 0 ? [$event->actorId] : [];
        }

        return array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0)));
    }

    protected function templateFor(string $scene): string
    {
        $templates = $this->closureArray('notification.templates', []);

        return (string) ($templates[$scene] ?? '');
    }

    protected function templateExists(string $code): bool
    {
        $class = 'wise\notification\model\NotificationTemplate';

        if (!class_exists($class)) {
            return false;
        }

        try {
            $locale = (string) $this->closureOpt('notification.locale', 'zh_CN');

            return $class::getByCode($code, $locale) !== null;
        } catch (\Throwable) {
            return false;
        }
    }


    protected function requiredClasses(): array
    {
        return [
            'wise\notification\facade\Notification'  => 'wise-notification 门面',
            'wise\notification\contract\Notifiable'  => 'wise-notification 可通知契约',
            'wise\notification\contract\Message'     => 'wise-notification 消息对象',
        ];
    }

    /**
     * 收件人对象（默认按既有约定从 `wise-auth.user_models.admin` 解析）
     *
     * 与 `ApprovalManager::notify()`（`:1008-1022`）同一套解析方式 —— 不另造一套用户模型约定。
     */
    protected function notifiableFor(int $userId): ?object
    {
        $modelClass = (string) config('wise-auth.user_models.admin', 'app\admin\model\Admin');

        if (!class_exists($modelClass)) {
            return null;
        }

        try {
            $user = $modelClass::find($userId);
        } catch (\Throwable) {
            return null;
        }

        return is_object($user) && $user instanceof \wise\notification\contract\Notifiable ? $user : null;
    }

    /**
     * 真正发送（**唯一**接触门面的地方）
     *
     * @param string[] $channels
     * @param object   $notifiable
     * @param mixed    $payload    `Message` 或模板变量数组
     * @param string   $template   模板 code（空 = 不用模板）
     * @return array{results:array<int,array<string,mixed>>, null_result:bool, template_missing:bool}
     */
    protected function send(array $channels, object $notifiable, mixed $payload, string $template): array
    {
        if ($this->sender !== null) {
            $raw = $this->sender->send($channels, $notifiable, $payload, $template);
        } else {
            $facade = 'wise\notification\facade\Notification';
            $call   = $facade::channel($channels);

            if ($this->closureBool('notification.sync', false)) {
                $call = $call->sync();
            }

            if ($template !== '' && is_array($payload)) {
                $call = $call->template($template);
            }

            $raw = $call->send($notifiable, $payload);
        }

        $results = [];
        $null    = false;

        if ($raw === null) {
            $null = true;
        } elseif (is_array($raw)) {
            foreach ($raw as $one) {
                $results[] = $this->normalizeResult($one);
            }
        } else {
            $results[] = $this->normalizeResult($raw);
        }

        return [
            'results'          => $results,
            'null_result'      => $null,
            'template_missing' => $template !== '' && !is_array($payload),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function normalizeResult(mixed $result): array
    {
        if (is_object($result)) {
            $status  = (string) ($result->status ?? '');
            $success = (bool) ($result->success ?? false);
            $channel = (string) ($result->channel ?? '');
            $error   = (string) ($result->error ?? '');
            $duration = (int) ($result->durationMs ?? 0);
        } else {
            $data    = is_array($result) ? $result : [];
            $status  = (string) ($data['status'] ?? '');
            $success = (bool) ($data['success'] ?? false);
            $channel = (string) ($data['channel'] ?? '');
            $error   = (string) ($data['error'] ?? '');
            $duration = (int) ($data['duration_ms'] ?? 0);
        }

        return [
            'channel'     => $channel,
            'success'     => $success,
            'status'      => $status,
            'error'       => $this->truncate($error, 300),
            'duration_ms' => $duration,
        ];
    }

    /**
     * @param string[] $channels
     * @param array<int, array<string, mixed>> $results
     * @param array<int, array{code:string,message:string}> $notes
     * @param bool $sawNull 是否发生过 `send()` 返回 `null`（用于给出准确原因）
     */
    protected function resultOf(array $channels, array $results, array $notes, bool $sawNull = false): ConnectorResult
    {
        $attempted = array_filter($results, static fn(array $r): bool => ($r['status'] ?? '') !== 'skipped');
        $failed    = array_filter($attempted, static fn(array $r): bool => ($r['success'] ?? false) !== true);

        $response = $this->json([
            'channels' => $channels,
            'results'  => $results,
            'notes'    => $notes,
        ]);

        if ($attempted === []) {
            return ConnectorResult::skipped(
                self::NAME,
                $sawNull
                    ? 'send() 返回 null（免打扰 / 中间件拦截 / 无可用通道）：本次没有任何投递发生，补投无意义'
                    : '没有任何收件人是可通知对象（Notifiable）',
                implode(',', $channels)
            );
        }

        if ($failed === []) {
            return ConnectorResult::ok(self::NAME, implode(',', $channels), $response);
        }

        $errors = implode(' | ', array_map(
            static fn(array $r): string => ($r['channel'] ?? '?') . ': ' . (string) ($r['error'] ?? 'unknown'),
            $failed
        ));

        if (count($failed) === count($attempted)) {
            return ConnectorResult::failed(
                self::NAME,
                '通知全部失败：' . $errors,
                0,
                implode(',', $channels),
                false,
                $response
            );
        }

        return ConnectorResult::failed(
            self::NAME,
            sprintf('部分通道失败（%d/%d）：%s', count($failed), count($attempted), $errors),
            0,
            implode(',', $channels),
            false,
            $response
        );
    }
}
