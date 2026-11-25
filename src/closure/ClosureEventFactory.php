<?php
declare(strict_types=1);

namespace wise\agent\closure;

use wise\agent\event\AgentComplete;
use wise\agent\event\ApprovalDecided;
use wise\agent\event\ApprovalRequested;
use wise\agent\event\ToolExecute;
use wise\agent\plan\PlanApprovalSignal;
use wise\agent\security\AccessResolver;
use wise\agent\security\CapabilityAccess;
use wise\agent\security\CapabilityDescriptor;
use wise\agent\skill\SkillRegistry;
use wise\agent\tool\ToolRegistry;

/**
 * 闭环事件归一化工厂＼�
 *
 * 四类既有事件 → {@see ClosureEvent}：**归一化只有这一处实现**，
 * 于是路由、连接器、落库、回放命令都只需要认识一种形状。
 *
 * ## 字段从哪里来（`write` / `access` / `risk` 的权威来源）
 *
 * `access` / `risk` / 是否写，全部来自 **`AccessResolver`**（`CapabilityDescriptor`）——
 * 与 B1 的 `RegistryCapabilityLookup`、「能力授权」「审批策略」**同一个来源**，
 * 不新增第二套「这个能力算不算写」的判定。
 *
 * ⚠️ 能力**未注册或描述不可得**时：`access`/`risk` 留空、`write=false`，
 * 由 {@see ClosureRouter} 按「未知 ⇒ 保守纳入」处理（漏审计的代价高于多一条记录）。
 *
 * ## ⚠️ 复用了 `plan\PlanApprovalSignal`（命名债，如实登记）
 *
 * `ToolExecute(AFTER)` 的输出可能是 `ToolSecurityCheck` 的**拦截载荷**
 * （`required_action` / `approval_id` / `Permission denied: …`，见 `ToolSecurityCheck.php:141-186`）。
 * 该载荷的解析**已经在 B1-4 实现过一份**（`PlanApprovalSignal`），因此这里**复用**而不是再抄一份
 * —— 载荷契约若变，只需改一处。
 * 代价是 `closure` 命名空间引用了 `plan` 命名空间的类（它本该叫 `CapabilityBlockSignal` 之类
 * 并放在共享命名空间）。这条已登记为命名债，将来提取时两处引用需同步更新。
 *
 * ## `traceId` 策略（回放的主键，必须稳定）
 *
 * | 来源 | trace id | 理由 |
 * |---|---|---|
 * | 审批申请 / 决策 | `approval:{ident}` | **同一次审批的申请与决策共用一个 trace**，回放时能串成一条线 |
 * | 对话结束 | `agent:{session_id}` | 一次会话一个 trace |
 * | 工具执行 | 由调用方从上下文注入（`trace_id` / `tracking_id`）；都没有才生成 | `ToolExecute` 事件**不带会话字段**，只能靠上下文（B2-4 的监听器负责注入 AgentContext 里的 tracking） |
 * | 兜底 | `cl_<16位hex>` | 随机但可识别 |
 */
final class ClosureEventFactory
{
    use ClosureOptions;

    /** 兜底 trace 前缀 */
    public const TRACE_PREFIX = 'cl_';

    /** 事件行 payload 的默认长度上限 */
    public const DEFAULT_MAX_PAYLOAD_CHARS = 2000;

    private ?AccessResolver $resolver = null;

    private ?PayloadMasker $masker = null;

    /**
     * @param array<string, mixed> $options `closure.*` 覆盖项
     */
    public function __construct(
        array $options = [],
        ?AccessResolver $resolver = null,
        ?PayloadMasker $masker = null
    ) {
        $this->setClosureOptions($options);
        $this->resolver = $resolver;
        $this->masker   = $masker;
    }

    /**
     * 通用入口：按事件类型归一
     *
     * **未知事件返回 `null`（不抛异常）** —— B2 是"额外挂上"的监听器，
     * 遇到不认识的事件应当安静跳过，而不是打断 AI 主链路。
     *
     * ⚠️ `ToolExecute` 的 **BEFORE 阶段返回 `null`**：执行前的拦截归 `ToolSecurityCheck` 管，
     * B2 只在**执行后**（AFTER）做闭环＼�。
     *
     * @param array<string, mixed> $context 上下文（`trace_id` / `tracking_id` / `agent_type` …）
     */
    public function fromEvent(object $event, array $context = []): ?ClosureEvent
    {
        if ($event instanceof ApprovalRequested) {
            return $this->fromApprovalRequested($event, $context);
        }

        if ($event instanceof ApprovalDecided) {
            return $this->fromApprovalDecided($event, $context);
        }

        if ($event instanceof ToolExecute) {
            return $event->phase === ToolExecute::PHASE_AFTER
                ? $this->fromToolExecute($event, $context)
                : null;
        }

        if ($event instanceof AgentComplete) {
            return $this->fromAgentComplete($event, $context);
        }

        return null;
    }

    /**
     * 审批申请 → `approval_requested`
     *
     * @param array<string, mixed> $context
     */
    public function fromApprovalRequested(ApprovalRequested $event, array $context = []): ClosureEvent
    {
        return new ClosureEvent(
            traceId: $this->traceFor('approval:' . $event->ident, $context),
            scene: ClosureEvent::SCENE_APPROVAL_REQUESTED,
            capabilityKind: $event->capabilityKind,
            capabilityName: $event->capabilityName,
            access: $event->access,
            risk: $event->risk,
            write: $this->isWriteAccess($event->access),
            result: ClosureEvent::RESULT_PENDING,
            reason: $event->reason,
            payload: $this->payload([
                'permission'     => $event->permission,
                'mode'           => $event->mode,
                'rule'           => $event->rule,
                'requester_type' => $event->requesterType,
            ]),
            approvalId: $event->approvalId,
            actorId: $event->requesterId,
            actorType: $event->requesterType,
            timestamp: microtime(true),
        );
    }

    /**
     * 审批决策 → `approval_decided`
     *
     * ⚠️ 该事件**不带** `access` / `risk`（只有 `permission`）—— 按"拿不到就留空"处理，
     * `access` 留空 ⇒ 路由侧不会因"非写"而丢弃（见 {@see ClosureRouter}）。
     *
     * @param array<string, mixed> $context
     */
    public function fromApprovalDecided(ApprovalDecided $event, array $context = []): ClosureEvent
    {
        $decision = strtolower(trim((string) $event->decision));

        return new ClosureEvent(
            traceId: $this->traceFor('approval:' . $event->ident, $context),
            scene: ClosureEvent::SCENE_APPROVAL_DECIDED,
            capabilityKind: 'tool',
            capabilityName: $event->capabilityName,
            access: '',
            risk: '',
            write: false,
            result: in_array($decision, ClosureEvent::DECISION_RESULTS, true)
                ? $decision
                : ClosureEvent::RESULT_UNKNOWN,
            reason: $event->note,
            payload: $this->payload([
                'permission' => $event->permission,
                'decision'   => $decision,
                'decider_id' => $event->deciderId,
                'note'       => $event->note,
            ]),
            approvalId: $event->approvalId,
            actorId: $event->requesterId,
            timestamp: microtime(true),
        );
    }

    /**
     * 工具执行后 → `tool_executed`
     *
     * @param array<string, mixed> $context
     */
    public function fromToolExecute(ToolExecute $event, array $context = []): ClosureEvent
    {
        $toolName   = (string) $event->toolName;
        $descriptor = $this->descriptorOfTool($toolName);
        $outcome    = $this->outcomeOf($event->result);

        return new ClosureEvent(
            traceId: $this->traceFor('', $context),
            scene: ClosureEvent::SCENE_TOOL_EXECUTED,
            capabilityKind: 'tool',
            capabilityName: $toolName,
            access: $descriptor?->access ?? '',
            risk: $descriptor?->risk ?? '',
            write: $this->isWriteAccess($descriptor?->access ?? ''),
            result: $outcome['result'],
            reason: $outcome['reason'],
            payload: $this->payload([
                'arguments' => $event->arguments,
                'output'    => $this->summarize($event->result),
                'skipped'   => (bool) $event->skip,
            ]),
            approvalId: $outcome['approval_id'],
            sessionId: (string) ($context['session_id'] ?? ''),
            trackingId: (string) ($context['tracking_id'] ?? ''),
            actorId: (int) ($context['user_id'] ?? 0),
            actorType: (string) ($context['user_type'] ?? 'admin'),
            timestamp: microtime(true),
        );
    }

    /**
     * 对话结束 → `agent_completed`
     *
     * @param array<string, mixed> $context
     */
    public function fromAgentComplete(AgentComplete $event, array $context = []): ClosureEvent
    {
        $sessionId = (string) $event->sessionId;
        $usage     = is_array($event->usage ?? null) ? $event->usage : [];

        return new ClosureEvent(
            traceId: $sessionId !== '' ? 'agent:' . $sessionId : $this->traceFor('', $context),
            scene: ClosureEvent::SCENE_AGENT_COMPLETED,
            capabilityKind: 'agent',
            capabilityName: (string) ($context['agent_type'] ?? (property_exists($event, 'agentType') ? (string) $event->agentType : '')),
            access: '',
            risk: '',
            write: false,
            result: ClosureEvent::RESULT_SUCCESS,
            reason: '',
            payload: $this->payload([
                'steps'        => (int) ($event->totalSteps ?? 0),
                'total_tokens' => (int) ($usage['total_tokens'] ?? 0),
                'duration'     => round((float) ($event->duration ?? 0), 3),
            ]),
            sessionId: $sessionId,
            trackingId: (string) ($context['tracking_id'] ?? ''),
            actorId: (int) ($context['user_id'] ?? 0),
            actorType: (string) ($context['user_type'] ?? 'admin'),
            timestamp: microtime(true),
        );
    }

    /**
     * 任务死信 → `task_failed`＼�
     *
     * 由三个异步 job（AiAgentJob / PipelineJob / PlanJob）的**超限分支**直接调用，
     * **不经事件系统**（死信没有对应的业务事件），因此单独一个工厂方法。
     *
     * `capabilityKind` 复用 `agent`（与 {@see self::fromAgentComplete()} 一致）：表示这是
     * 「任务级」场景而非「tool/skill 能力」场景，从而被 {@see ClosureRouter} 视为 consequential
     * （不受 `only_write_capabilities` 读写过滤），也不会触发 access/risk 不可得的误导 note。
     *
     * 收件人（通知连接器）留空 ⇒ 发给 `actorId`（任务发起人），
     * 与 `NotificationConnector::recipientsFor()` 的默认语义一致。
     *
     * @param array<string, mixed> $meta 死信上下文：tracking_id / session_id / user_id / user_type /
     *                                    job_type / attempts / last_error / reason
     */
    public function fromTaskFailed(array $meta): ClosureEvent
    {
        return new ClosureEvent(
            traceId: $this->traceFor('', $meta),
            scene: ClosureEvent::SCENE_TASK_FAILED,
            capabilityKind: 'agent',
            capabilityName: (string) ($meta['job_type'] ?? ''),
            access: '',
            risk: '',
            write: false,
            result: ClosureEvent::RESULT_FAILURE,
            reason: (string) ($meta['reason'] ?? ''),
            payload: $this->payload([
                'job_type'   => (string) ($meta['job_type'] ?? ''),
                'attempts'   => (int) ($meta['attempts'] ?? 0),
                'last_error' => (string) ($meta['last_error'] ?? ''),
            ]),
            sessionId: (string) ($meta['session_id'] ?? ''),
            trackingId: (string) ($meta['tracking_id'] ?? ''),
            actorId: (int) ($meta['user_id'] ?? 0),
            actorType: (string) ($meta['user_type'] ?? 'admin'),
            timestamp: microtime(true),
        );
    }


    /**
     * 工具输出的结论（含"被拦截"的三种载荷）
     *
     * 拦截载荷的解析复用 B1-4 的 {@see PlanApprovalSignal}（见类注释的命名债说明）：
     *
     * | 载荷 | 结论 |
     * |---|---|
     * | `required_action=confirm/workflow` | `pending`（在等审批，**不是失败**） |
     * | `required_action=rejected` | `rejected` |
     * | `error` 以 `Permission denied:` 开头 | `failure` |
     * | 同核契约 `code=0` / `code≠0` | `success` / `failure` |
     * | 其它（纯文本、空） | `unknown`（**不猜**） |
     *
     * @return array{result:string, reason:string, approval_id:int}
     */
    public function outcomeOf(mixed $output): array
    {
        $signal = PlanApprovalSignal::of($output);

        if ($signal['kind'] === PlanApprovalSignal::KIND_PENDING) {
            return [
                'result'      => ClosureEvent::RESULT_PENDING,
                'reason'      => (string) $signal['reason'],
                'approval_id' => (int) ($signal['approval_id'] ?? 0),
            ];
        }

        if ($signal['kind'] === PlanApprovalSignal::KIND_REJECTED) {
            return [
                'result'      => ClosureEvent::RESULT_REJECTED,
                'reason'      => (string) $signal['reason'],
                'approval_id' => (int) ($signal['approval_id'] ?? 0),
            ];
        }

        if ($signal['kind'] === PlanApprovalSignal::KIND_PERMISSION_DENIED) {
            return ['result' => ClosureEvent::RESULT_FAILURE, 'reason' => (string) $signal['reason'], 'approval_id' => 0];
        }

        $decoded = $this->decode($output);

        if (!is_array($decoded)) {
            return ['result' => ClosureEvent::RESULT_UNKNOWN, 'reason' => '', 'approval_id' => 0];
        }

        if (($decoded['required_action'] ?? '') === 'sandbox' || !empty($decoded['sandbox'])) {
            return [
                'result'      => ClosureEvent::RESULT_PREVIEWED,
                'reason'      => (string) ($decoded['error'] ?? ''),
                'approval_id' => 0,
            ];
        }

        if (array_key_exists('code', $decoded)) {
            $code = (int) $decoded['code'];

            return $code === 0
                ? ['result' => ClosureEvent::RESULT_SUCCESS, 'reason' => '', 'approval_id' => 0]
                : [
                    'result'      => ClosureEvent::RESULT_FAILURE,
                    'reason'      => (string) ($decoded['msg'] ?? ('code=' . $code)),
                    'approval_id' => 0,
                ];
        }

        $error = (string) ($decoded['error'] ?? '');

        return $error !== ''
            ? ['result' => ClosureEvent::RESULT_FAILURE, 'reason' => $error, 'approval_id' => 0]
            : ['result' => ClosureEvent::RESULT_UNKNOWN, 'reason' => '', 'approval_id' => 0];
    }


    /**
     * 能力描述（`access` / `risk` 的权威来源）
     */
    protected function descriptorOfTool(string $toolName): ?CapabilityDescriptor
    {
        try {
            $tool = ToolRegistry::instance()->normalize($toolName);

            if (!is_object($tool)) {
                return null;
            }

            return $this->resolver()->resolveTool($tool, $toolName);
        } catch (\Throwable) {
            return null;
        }
    }

    protected function resolver(): AccessResolver
    {
        return $this->resolver ??= new AccessResolver();
    }

    protected function masker(): PayloadMasker
    {
        return $this->masker ??= PayloadMasker::fromOptions($this->closureOptions());
    }

    /**
     * skill 能力描述（B2-1 暂未从事件侧产生 skill 场景，保留接缝供后续使用）
     */
    protected function descriptorOfSkill(string $skillName): ?CapabilityDescriptor
    {
        try {
            $skill = SkillRegistry::instance()->get($skillName);

            return is_object($skill) ? $this->resolver()->resolveSkill($skill) : null;
        } catch (\Throwable) {
            return null;
        }
    }


    /**
     * trace id：优先 `$preferred`（如 `approval:{ident}`），其次上下文，最后随机
     *
     * @param array<string, mixed> $context
     */
    private function traceFor(string $preferred, array $context): string
    {
        if ($preferred !== '' && $preferred !== 'approval:') {
            return $preferred;
        }

        $explicit = trim((string) ($context['trace_id'] ?? ''));

        if ($explicit !== '') {
            return $explicit;
        }

        $tracking = trim((string) ($context['tracking_id'] ?? ''));

        if ($tracking !== '') {
            return 'track:' . $tracking;
        }

        return self::TRACE_PREFIX . bin2hex(random_bytes(8));
    }

    /**
     * payload 组装：脱敏 → 截断（**先脱敏再截断**，避免截断把已脱敏的键名切掉）
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function payload(array $payload): array
    {
        $masked = $this->masker()->mask($payload);

        return is_array($masked) ? $masked : [];
    }

    /**
     * 输出摘要（长文本截断，避免把整个响应塞进事件行）
     */
    private function summarize(mixed $output): string
    {
        if (is_string($output)) {
            return $this->truncate($output);
        }

        if ($output === null) {
            return '';
        }

        $encoded = json_encode($output, JSON_UNESCAPED_UNICODE);

        return $this->truncate($encoded === false ? '[unencodable]' : $encoded);
    }

    private function truncate(string $text): string
    {
        $max = max(64, (int) $this->closureOpt('max_payload_chars', self::DEFAULT_MAX_PAYLOAD_CHARS));

        return mb_strlen($text, 'UTF-8') <= $max
            ? $text
            : mb_substr($text, 0, $max, 'UTF-8') . '…(truncated)';
    }

    private function decode(mixed $output): mixed
    {
        if (!is_string($output)) {
            return $output;
        }

        $decoded = json_decode($output, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $output;
    }

    private function isWriteAccess(string $access): bool
    {
        $normalized = CapabilityAccess::normalize($access);

        return $normalized === CapabilityAccess::WRITE || $normalized === CapabilityAccess::ADMIN;
    }
}
