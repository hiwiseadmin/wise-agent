<?php
declare(strict_types=1);

namespace wise\agent\closure;

/**
 * 闭环场景路由＼�
 *
 * 回答一个问题：**这个事件该投给哪些连接器**。规则全部配置驱动，且**只有这一处判定**
 * （连接器的 `supports()` 只判"这个事件我认不认"，不参与读写/风险过滤，避免两处判定漂移）。
 *
 * ## 过滤规则（顺序即优先级）
 *
 * 1. **场景未配置**（`closure.scenes` 里没有该 scene）⇒ **一个都不触发**
 *    （刻意不做"默认全触发"：宁可让人显式配置，也不要默默把通知发给所有人）；
 * 2. **审计连接器**（`audit`）走**独立**规则：scene ∈ `audit_scenes`；
 *    再按 `audit_write_only`（默认 true）过滤 —— 审计既不该被"读操作不打扰"完全关掉，
 *    也不该为每次列表查询写一行（两个极端都是错的，所以给一个显式开关）；
 * 3. **其它连接器**：受 `only_write_capabilities`（默认 true）与 `risk_levels` 过滤。
 *
 * ## 两处刻意的"保守纳入"（宁可多记，不可漏记）
 *
 * - `only_write_capabilities=true` 时，**`access` 未知**（能力未注册 / 描述不可得）的事件
 *   **仍然触发**：漏掉一个未知写工具的通知/审计，代价高于多一条记录；
 * - `risk_levels` 过滤时，**`risk` 未知**不构成丢弃理由（同样理由）。
 *
 * 两种"未知"都会在 {@see self::explain()} 的 `notes` 里被标注，回放时看得见。
 */
class ClosureRouter
{
    use ClosureOptions;

    /** 审计连接器名 */
    public const AUDIT = 'audit';

    /**
     * 默认场景路由
     *
     * @var array<string, string[]>
     */
    public const DEFAULT_SCENES = [
        ClosureEvent::SCENE_APPROVAL_REQUESTED => ['notification', 'webhook'],
        ClosureEvent::SCENE_APPROVAL_DECIDED   => ['notification', 'webhook', 'audit'],
        ClosureEvent::SCENE_TOOL_EXECUTED      => ['audit'],
        ClosureEvent::SCENE_AGENT_COMPLETED    => ['notification'],
        ClosureEvent::SCENE_TASK_FAILED        => ['notification', 'audit'],
    ];

    /**
     * 默认产生审计的场景
     *
     * @var string[]
     */
    public const DEFAULT_AUDIT_SCENES = [
        ClosureEvent::SCENE_APPROVAL_DECIDED,
        ClosureEvent::SCENE_TOOL_EXECUTED,
        ClosureEvent::SCENE_TASK_FAILED,
    ];

    /**
     * @param array<string, mixed> $options `closure.*` 覆盖项
     */
    public function __construct(array $options = [])
    {
        $this->setClosureOptions($options);
    }

    /**
     * 该事件要投递的连接器（有序）
     *
     * @return string[]
     */
    public function connectorsFor(ClosureEvent $event): array
    {
        return $this->explain($event)['kept'];
    }

    /**
     * 路由决策的全貌（`kept` / `dropped` / `reason` / `notes`）—— 回放与排障要看的就是它
     *
     * @return array{kept:string[], dropped:array<int, array{connector:string, reason:string}>, reason:string, notes:array<int, array{code:string, message:string}>}
     */
    public function explain(ClosureEvent $event): array
    {
        $scenes     = $this->closureArray('scenes', self::DEFAULT_SCENES);
        $configured = $scenes[$event->scene] ?? null;

        if (!is_array($configured)) {
            return [
                'kept'    => [],
                'dropped' => [],
                'reason'  => 'scene_not_configured',
                'notes'   => [[
                    'code'    => 'closure.scene_not_configured',
                    'message' => sprintf('场景 `%s` 未在 closure.scenes 中配置，闭环未触发', $event->scene),
                ]],
            ];
        }

        $kept    = [];
        $dropped = [];
        $notes   = [];

        foreach (array_values(array_unique(array_map('strval', $configured))) as $name) {
            $reason = $this->dropReason($name, $event, $notes);

            if ($reason === null) {
                $kept[] = $name;

                continue;
            }

            $dropped[] = ['connector' => $name, 'reason' => $reason];
        }

        if ($event->access === '' && $event->capabilityName !== '' && $event->capabilityKind !== 'agent') {
            $notes[] = [
                'code'    => 'closure.access_unknown',
                'message' => sprintf('能力 `%s` 的访问类型不可得（未注册或描述不可得），按"保守纳入"处理', $event->capability()),
            ];
        }

        if ($event->risk === '' && $event->capabilityKind !== 'agent') {
            $notes[] = [
                'code'    => 'closure.risk_unknown',
                'message' => '风险等级不可得，不因风险过滤而丢弃',
            ];
        }

        return ['kept' => $kept, 'dropped' => $dropped, 'reason' => '', 'notes' => $notes];
    }


    /**
     * @param array<int, array{code:string, message:string}> $notes
     * @return string|null `null` = 保留；字符串 = 丢弃原因
     */
    private function dropReason(string $connector, ClosureEvent $event, array &$notes): ?string
    {
        if ($connector === self::AUDIT) {
            if (!in_array($event->scene, $this->auditScenes(), true)) {
                return 'scene_not_in_audit_scenes';
            }

            if ($this->closureBool('audit_write_only', true) && !$this->isConsequential($event)) {
                return 'not_consequential';
            }

            return null;
        }

        if ($this->closureBool('only_write_capabilities', true) && !$this->isConsequential($event)) {
            return 'not_consequential';
        }

        return $this->riskAllowed($event) ? null : 'risk_filtered';
    }

    /**
     * 是否"值得惊动别人"（写类 / 能力类未知 / 非能力类场景如对话结束）
     */
    private function isConsequential(ClosureEvent $event): bool
    {
        if ($event->capabilityKind === 'agent') {
            return true;
        }

        if ($event->write) {
            return true;
        }

        return $event->access === '';
    }

    private function riskAllowed(ClosureEvent $event): bool
    {
        $levels = array_map('strval', $this->closureArray('risk_levels', ['medium', 'high']));

        if (in_array('*', $levels, true)) {
            return true;
        }

        return $event->risk === '' || in_array($event->risk, $levels, true);
    }

    /**
     * @return string[]
     */
    private function auditScenes(): array
    {
        return array_map('strval', $this->closureArray('audit_scenes', self::DEFAULT_AUDIT_SCENES));
    }
}
