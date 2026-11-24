<?php
declare(strict_types=1);

namespace wise\agent\closure\connector;

use wise\operationlog\storage\StorageInterface;
use wise\agent\closure\ClosureEvent;
use wise\agent\closure\ConnectorResult;
use wise\agent\closure\PayloadMasker;

/**
 * 审计连接器＼�
 *
 * 把闭环事件写进 `wise-operation-log` 的 `operation_log` 表，**与人工操作日志同表、可比对**。
 *
 * ## ⚠️ 决策 1：默认 `via='storage'`（直存），不走事件的缓冲通道
 *
 * `OperationLogListener::handle()` **只把日志放进缓冲区**（`listener/OperationLogListener.php:58-80`），
 * 真正落库依赖 ① HTTP 中间件 `finally`（`middleware/OperationLog.php:64/95`）或
 * ② `register_shutdown_function`（`OperationLogServiceProvider.php:72/97-102`）。
 * 而闭环事件**大量发生在常驻队列 worker 里**（`AiAgentJob` / `PlanJob` 执行写操作、
 * `ApprovalDecided` 之后的自动续跑）——worker 不退出 ⇒ shutdown flush 不触发 ⇒ **日志会积压甚至丢失**。
 *
 * 因此默认走 `StorageInterface::save()`（包自带的公共存储接口，**不是**绕开它自己 insert）。
 * `via='event'` 仍可用，但连接器会在派发后**主动** `OperationLogListener::flush()`（见 {@see self::saveViaEvent()}）。
 *
 * ## ⚠️ 决策 2：零 schema 变更的 AI 标记
 *
 * `operation_log` **不加任何列**（不写 `trace_id` 列）：贯通键放 `extra` JSON、
 * AI 身份沿用既有约定的 user-agent 字符串标记
 * （A1 用 `WiseAgent/menu-action`，见 `MenuActionInvoker.php:304-311`；这里用 `WiseAgent/closure`）。
 * 「不新增列」由 `tests/ClosureAuditConnectorTest` 的**列清单守门测试**保证
 * （写入数组的键必须 ⊆ `operation_log` 的实际列）。
 *
 * ## 出参约定（回放时读得懂）
 *
 * | 列 | 值 |
 * |---|---|
 * | `channel` | `ai.closure`（可配置）—— 后台按 channel 就能筛出「AI 闭环」 |
 * | `level` | `rejected`/`failure` → `WARNING`；`cancelled` → `NOTICE`；其余 → `INFO` |
 * | `content` | `AI 闭环｜场景=…｜能力=…｜结论=…｜原因=…｜trace=…` |
 * | `tags` | `ai,closure,<scene>,<write\|read>,result=<结论>` |
 * | `extra` | `trace_id` / `scene` / `result` / `payload_hash` / `tracking_id` / `approval_id` / `plan_run_id` / `capability` |
 * | `params` | **再脱敏后**的 payload（截断） |
 * | `duration_ms` | `null` —— 派发耗时由 `ClosureDispatcher` 记在投递行里，这里不编造 |
 */
class AuditConnector extends AbstractConnector
{
    public const NAME = 'audit';

    /** 默认 channel（AI 闭环专用，便于后台筛选） */
    public const DEFAULT_CHANNEL = 'ai.closure';

    /**
     * AI 身份标记（与 A1 的 `WiseAgent/menu-action` 同一套「零 schema 变更」约定）
     */
    public const USER_AGENT_MARKER = 'WiseAgent/closure';

    /**
     * `operation_log` 的**实际列清单**（守门测试共用这一份）
     *
     * 来源：迁移 `wise-operation-log/database/migrations/20251023000001_create_operation_log_table.php`
     * （13 个数据列 + 自动 `id`）。
     *
     * @var string[]
     */
    public const LOG_COLUMNS = [
        'channel', 'level', 'content', 'params', 'duration_ms',
        'operator_id', 'operator_name', 'request_url', 'ip', 'user_agent',
        'tags', 'extra', 'create_time',
    ];

    private ?StorageInterface $storage = null;

    /**
     * @param array<string, mixed> $options `closure.*` 覆盖项
     * @param PayloadMasker|null   $masker  出站脱敏器（`null` = 按配置构造；**第 2 位**是构造约定，
     *                                      见 {@see \wise\agent\closure\ClosureConnectorFactory}）
     */
    public function __construct(
        array $options = [],
        ?PayloadMasker $masker = null,
        ?StorageInterface $storage = null
    ) {
        parent::__construct($options, $masker);
        $this->storage = $storage;
    }

    public function name(): string
    {
        return self::NAME;
    }

    /**
     * 审计**对所有场景**都可用
     *
     * 「哪些场景要审计、读操作是否审计」由 {@see \wise\agent\closure\ClosureRouter} 判定
     * （`audit_scenes` + `audit_write_only`）—— 连接器这里只回答「我认所有事件」，
     * 避免两处判定漂移＼�。
     */
    public function supports(ClosureEvent $event): bool
    {
        return in_array($event->scene, ClosureEvent::SCENES, true);
    }

    public function dispatch(ClosureEvent $event): ConnectorResult
    {
        if (($missing = $this->missingDownstream()) !== null) {
            return ConnectorResult::skipped(self::NAME, $missing, $this->channel());
        }

        $via = $this->via();

        if ($via === null) {
            return ConnectorResult::failed(
                self::NAME,
                sprintf('closure.audit.via 取值非法：%s（只能是 storage|event）', (string) $this->closureOpt('audit.via', 'storage')),
                0,
                $this->channel()
            );
        }

        $log = $this->buildLog($event);

        try {
            if ($via === 'event') {
                $this->saveViaEvent($log);
            } else {
                $this->storage()->save($log);
            }
        } catch (\Throwable $e) {
            return ConnectorResult::failed(self::NAME, '审计写入失败：' . $e->getMessage(), 0, $this->channel());
        }

        return ConnectorResult::ok(self::NAME, $this->channel(), $this->json([
            'via'      => $via,
            'level'    => $log['level'],
            'trace_id' => $event->traceId,
        ]));
    }


    /**
     * 组装一条 `operation_log` 行
     *
     * 键集合必须 ⊆ {@see self::LOG_COLUMNS}（守门测试会断言）——
     * 这里**不允许**出现任何新列，否则「零 schema 变更」就是空话。
     *
     * @return array<string, mixed>
     */
    protected function buildLog(ClosureEvent $event): array
    {
        $extra = [
            'trace_id'     => $event->traceId,
            'scene'        => $event->scene,
            'result'       => $event->result,
            'capability'   => $event->capability(),
            'payload_hash' => $event->payloadHash(),
            'tracking_id'  => $event->trackingId,
            'approval_id'  => $event->approvalId,
            'plan_run_id'  => $event->planRunId,
            'actor_type'   => $event->actorType,
            'connector'    => self::NAME,
        ];

        return [
            'channel'       => $this->channel(),
            'level'         => $this->levelOf($event),
            'content'       => $this->contentOf($event),
            'params'        => $this->json($this->masked($event->payload), $this->maxChars('audit.max_payload_chars', 2000)),
            'duration_ms'   => null,
            'operator_id'   => $event->actorId > 0 ? $event->actorId : null,
            'operator_name' => $event->actorId > 0 ? $event->actorType : 'system',
            'request_url'   => '',
            'ip'            => '',
            'user_agent'    => self::USER_AGENT_MARKER,
            'tags'          => $this->tagsOf($event),
            'extra'         => $this->json($extra),
            'create_time'   => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * 级别映射（沿用 `operation_log.level` 既有取值语义）
     */
    protected function levelOf(ClosureEvent $event): string
    {
        return match ($event->result) {
            ClosureEvent::RESULT_FAILURE, ClosureEvent::RESULT_REJECTED => 'WARNING',
            ClosureEvent::RESULT_CANCELLED => 'NOTICE',
            default => 'INFO',
        };
    }

    protected function contentOf(ClosureEvent $event): string
    {
        $parts = [
            '场景=' . $event->scene,
            '能力=' . ($event->capability() !== '' ? $event->capability() : '—'),
            '结论=' . $event->result,
        ];

        if ($event->reason !== '') {
            $parts[] = '原因=' . $event->reason;
        }

        $parts[] = 'trace=' . $event->traceId;

        return 'AI 闭环｜' . implode('｜', $parts);
    }

    protected function tagsOf(ClosureEvent $event): string
    {
        return $this->truncate(implode(',', array_filter([
            'ai',
            'closure',
            $event->scene,
            $event->write ? 'write' : 'read',
            'result=' . $event->result,
        ])), 255);
    }

    protected function channel(): string
    {
        $channel = trim((string) $this->closureOpt('audit.channel', self::DEFAULT_CHANNEL));

        return $channel !== '' ? $channel : self::DEFAULT_CHANNEL;
    }

    /**
     * @return string|null `storage` / `event`；非法取值返回 `null`
     */
    protected function via(): ?string
    {
        $via = strtolower(trim((string) $this->closureOpt('audit.via', 'storage')));

        return in_array($via, ['storage', 'event'], true) ? $via : null;
    }


    protected function requiredClasses(): array
    {
        return $this->via() === 'event'
            ? [
                'wise\operationlog\event\OperationLogEvent'          => 'wise-operation-log 事件通道',
                'wise\operationlog\listener\OperationLogListener'    => 'wise-operation-log 事件监听器',
            ]
            : ['wise\operationlog\storage\StorageInterface' => 'wise-operation-log 存储驱动'];
    }

    /**
     * 走事件通道 + **主动** flush（见类注释「决策 1」）
     *
     * @param array<string, mixed> $logData
     */
    protected function saveViaEvent(array $logData): void
    {
        if (!function_exists('event')) {
            throw new \RuntimeException('event() 不可用：无法走事件通道，请把 closure.audit.via 设为 storage');
        }

        event(new \wise\operationlog\event\OperationLogEvent($logData));

        \wise\operationlog\listener\OperationLogListener::flush();
    }

    protected function storage(): StorageInterface
    {
        if ($this->storage === null) {
            $class = 'wise\operationlog\storage\DatabaseStorage';

            if (!class_exists($class)) {
                throw new \RuntimeException('wise-operation-log 未安装：缺少 ' . $class);
            }

            $this->storage = new $class();
        }

        return $this->storage;
    }
}
