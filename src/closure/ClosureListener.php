<?php
declare(strict_types=1);

namespace wise\agent\closure;

use wise\agent\event\AgentComplete;
use wise\agent\event\ApprovalDecided;
use wise\agent\event\ApprovalRequested;
use wise\agent\event\ToolExecute;
use wise\agent\security\CapabilityAuthorizer;
use wise\support\context\ContextualLogger;

/**
 * 闭环事件监听器＼�
 *
 * **B2 与既有事件的唯一接点**：`AgentServiceProvider` 把它挂到四个既有事件上，
 * 它负责「归一化 → （可选）自动续跑 → 派发」三步，**自己不判定场景/读写/风险**
 * （那是 {@see ClosureRouter} 的事），也不投递（那是连接器的事）。
 *
 * ## 三条必须守住的性质
 *
 * 1. **绝不抛异常**：`ToolExecute` 的 `Event::trigger` 在 `AgentManager` 里**未包 try/catch**
 *    （`AgentManager.php:927/968`）—— 监听器抛异常会**打断 AI 主链路**。因此整体兜底 + 记日志。
 * 2. **关闭时零开销**：`closure.enabled=false`（默认）时立即返回，不构建任何对象、不碰数据库。
 * 3. **用途单一**：它只订阅、只归一、只转发；要改行为请改路由/连接器配置，不要在这里加 `if`。
 *
 * ## 「自动续跑」为什么在这里触发（而不是在连接器里）
 *
 * 续跑结论需要**随同一条通知/审计**到达申请人（验收 8：不续跑时给出可读原因）。
 * 因此顺序是：归一化 → 续跑 → 把结论写进**载荷** → 再派发。
 * 若放在派发之后，结论就只能落日志，用户看不到。
 */
class ClosureListener
{
    use ClosureOptions;

    private ?ClosureDispatcher $dispatcher = null;

    private ?ClosureEventFactory $factory = null;

    private ?ClosurePlanResumer $resumer = null;

    /**
     * @param array<string, mixed> $options `closure.*` 覆盖项
     */
    public function __construct(
        array $options = [],
        ?ClosureDispatcher $dispatcher = null,
        ?ClosureEventFactory $factory = null,
        ?ClosurePlanResumer $resumer = null
    ) {
        $this->setClosureOptions($options);
        $this->dispatcher = $dispatcher;
        $this->factory    = $factory;
        $this->resumer    = $resumer;
    }

    /**
     * 本监听器订阅的事件（**单一事实来源**：注册与 `discover` 都读它，避免两处清单漂移）
     *
     * @return string[]
     */
    public static function events(): array
    {
        return [
            ApprovalRequested::class,
            ApprovalDecided::class,
            ToolExecute::class,
            AgentComplete::class,
        ];
    }

    public function enabled(): bool
    {
        return $this->closureBool('enabled', false);
    }

    /**
     * 事件入口（`Event::listen` 的回调）
     */
    public function handle(object $event): void
    {
        if (!$this->enabled()) {
            return;
        }

        try {
            $closureEvent = $this->factory()->fromEvent($event, $this->contextFor($event));

            if ($closureEvent === null) {
                return;
            }

            $closureEvent = $this->enrichWithResume($closureEvent);

            $this->dispatcher()->dispatch($closureEvent);
        } catch (\Throwable $e) {
            $this->log('warning', sprintf(
                '[Closure] 处理事件 %s 失败（已兜住，不影响主流程）：%s',
                is_object($event) ? $event::class : gettype($event),
                $e->getMessage()
            ));
        }
    }


    protected function dispatcher(): ClosureDispatcher
    {
        return $this->dispatcher ??= new ClosureDispatcher($this->closureOptions());
    }

    protected function factory(): ClosureEventFactory
    {
        return $this->factory ??= new ClosureEventFactory($this->closureOptions());
    }

    protected function resumer(): ClosurePlanResumer
    {
        return $this->resumer ??= new ClosurePlanResumer($this->closureOptions());
    }

    /**
     * 运行期上下文（事件里有的字段优先）
     *
     * ⚠️ 如实说明：`ToolExecute` / `AgentComplete` **不带登录用户**，且包内没有
     * 「当前 AgentContext」持有者（`AgentContext` 是实例传参，没有 `current()`）——
     * 因此用户取自 {@see CapabilityAuthorizer::currentUserId()}（与工具授权/审批同源），
     * 会话只在 `AgentComplete` 上有。**工具类闭环事件的 `session_id` 会留空**，
     * 其 trace 为生成的 `cl_…`；要让工具事件与会话贯通，需在 `AgentManager` 派发 AFTER 时携带上下文
     * —— 那属于改动既有类，已登记为遗留项＼�。
     *
     * @return array<string, mixed>
     */
    protected function contextFor(object $event): array
    {
        $context = [
            'user_id'   => $this->currentUserId(),
            'user_type' => 'admin',
        ];

        if ($event instanceof ApprovalRequested) {
            $context['user_id']   = $event->requesterId;
            $context['user_type'] = $event->requesterType;
        } elseif ($event instanceof ApprovalDecided) {
            $context['user_id'] = $event->requesterId;
        } elseif ($event instanceof AgentComplete) {
            $context['session_id'] = $event->sessionId;
        }

        return $context;
    }

    protected function currentUserId(): int
    {
        try {
            return (new CapabilityAuthorizer())->currentUserId();
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * 「审批通过」事件：先跑（可选的）自动续跑，把结论写进载荷
     *
     * 写进载荷而不是只记日志，是为了让**同一条通知/审计/webhook** 都带上
     * 「续跑了 / 为什么没续跑」（验收 8）。
     */
    private function enrichWithResume(ClosureEvent $event): ClosureEvent
    {
        if ($event->scene !== ClosureEvent::SCENE_APPROVAL_DECIDED
            || $event->result !== ClosureEvent::RESULT_APPROVED
        ) {
            return $event;
        }

        $outcome = $this->resumer()->maybeResume($event);

        if (!($outcome['handled'] ?? false)) {
            return $event;
        }

        return $event->withPayload(['resume' => $outcome]);
    }

    private function log(string $level, string $message): void
    {
        try {
            ContextualLogger::channel('ai')->{$level}($message);
        } catch (\Throwable) {
        }
    }
}
