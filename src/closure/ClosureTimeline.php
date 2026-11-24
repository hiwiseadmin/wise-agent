<?php
declare(strict_types=1);

namespace wise\agent\closure;

use think\facade\Db;
use wise\agent\closure\store\ClosureTraceStoreInterface;
use wise\agent\closure\store\DbClosureTraceStore;

/**
 * 闭环回放：按 `trace_id` 聚合一条时间线＼�验收 2）
 *
 * ## 它能回答什么（以及**不能**回答什么）
 *
 * **能**：谁申请 → 谁批 → 跑了哪几步 → 通知/webhook 投了几次、结果如何 → 审计落在哪
 * （六个来源：`ai_closure_*` / `ai_approvals` / `ai_agent_logs` / `ai_plan_runs`+`ai_plan_steps` /
 * `notification_histories` / `webhook_sends`）。
 *
 * **不能**：业务表本身的历史值（如"库存改之前是多少"）。B2 只在载荷里保留**调用参数摘要**，
 * 「时光倒流」需要业务侧版本化，不属于闭环范围。
 *
 * ## ⚠️ 关联强度是**分级的**，界面/报告里必须如实呈现（`matched_by` 字段就是为此存在的）
 *
 * | 来源 | 关联方式 | 强度 |
 * |---|---|---|
 * | 闭环事件与投递 | `trace_id` 精确 | 强 |
 * | 审批单 | `trace_id = approval:{ident}` ⇒ 用 `ident` 精确查 | 强 |
 * | AI 执行记录 | 事件行里的 `tracking_id` 精确查 | 强 |
 * | 计划运行/步骤 | 事件行里的 `plan_run_id` 精确查 | 强 |
 * | 通知历史 | `message_payload LIKE %trace_id%` | **弱（尽力匹配）** |
 * | webhook 投递 | `payload LIKE %trace_id%`（信封里必带 trace_id） | 中（同源 JSON 匹配） |
 *
 * 弱匹配可能**漏**（对方把 trace 放在别处）也可能**多**（trace 串出现在别处）—— 因此本类
 * 只把结果作为"线索"，并逐条标出 `matched_by`，不宣称它是完备的。
 *
 * ## 健壮性
 *
 * 每个来源独立 try/catch：**缺包/缺表不会让回放整体失败**（例如没装 wise-webhook 的实例，
 * `webhook` 来源标 `unavailable` + 原因，其余照常返回）。
 */
class ClosureTimeline
{
    use ClosureOptions;

    public const SOURCE_CLOSURE      = 'closure';
    public const SOURCE_APPROVAL     = 'approval';
    public const SOURCE_EXECUTION    = 'execution';
    public const SOURCE_PLAN         = 'plan';
    public const SOURCE_NOTIFICATION = 'notification';
    public const SOURCE_WEBHOOK      = 'webhook';

    /** 每个来源的行数上限（防一条 trace 拉出几万行） */
    public const MAX_ROWS = 200;

    private ?ClosureTraceStoreInterface $store = null;

    /**
     * @param array<string, mixed> $options `closure.*` 覆盖项
     */
    public function __construct(array $options = [], ?ClosureTraceStoreInterface $store = null)
    {
        $this->setClosureOptions($options);
        $this->store = $store;
    }

    /**
     * 构建时间线
     *
     * @return array{trace_id:string, sources:array<string,array<string,mixed>>, timeline:array<int,array<string,mixed>>, source_count:int, missing:array<int,string>, note:string}
     */
    public function build(string $traceId): array
    {
        $traceId = trim($traceId);

        if ($traceId === '') {
            return [
                'trace_id'     => '',
                'sources'      => [],
                'timeline'     => [],
                'source_count' => 0,
                'missing'      => [],
                'note'         => 'trace_id 为空',
            ];
        }

        $sources = [];
        $missing = [];

        $closure = $this->load(fn(): array => $this->closureRows($traceId));

        $sources[self::SOURCE_CLOSURE] = $this->sectionOf(
            self::SOURCE_CLOSURE,
            'B2 闭环事件与投递',
            'trace_id=' . $traceId . '（精确）',
            $closure
        );

        $keys = $this->keysOf($closure['rows']);

        $sources[self::SOURCE_APPROVAL] = $this->sectionOf(
            self::SOURCE_APPROVAL,
            '审批单（ws_ai_approvals）',
            $keys['ident'] !== '' ? 'ident=' . $keys['ident'] . '（精确；来自 trace 前缀 approval:）' : '（trace 不是 approval:* ⇒ 无法关联）',
            $keys['ident'] !== '' ? $this->load(fn(): array => $this->approvalRows($keys['ident'])) : self::emptyLoad()
        );

        $sources[self::SOURCE_EXECUTION] = $this->sectionOf(
            self::SOURCE_EXECUTION,
            'AI 执行记录（ai_agent_logs）',
            $keys['tracking_id'] !== '' ? 'tracking_id=' . $keys['tracking_id'] . '（精确）' : '（事件未携带 tracking_id）',
            $keys['tracking_id'] !== '' ? $this->load(fn(): array => $this->executionRows($keys['tracking_id'])) : self::emptyLoad()
        );

        $sources[self::SOURCE_PLAN] = $this->sectionOf(
            self::SOURCE_PLAN,
            '计划运行与步骤（ai_plan_runs / ai_plan_steps）',
            $keys['plan_run_id'] > 0 ? 'plan_run_id=' . $keys['plan_run_id'] . '（精确）' : '（事件未携带 plan_run_id）',
            $keys['plan_run_id'] > 0 ? $this->load(fn(): array => $this->planRows($keys['plan_run_id'])) : self::emptyLoad()
        );

        $sources[self::SOURCE_NOTIFICATION] = $this->sectionOf(
            self::SOURCE_NOTIFICATION,
            '通知投递（notification_histories）',
            'message_payload LIKE %' . $traceId . '%（**弱匹配**：尽力而为）',
            $this->load(fn(): array => $this->notificationRows($traceId))
        );

        $sources[self::SOURCE_WEBHOOK] = $this->sectionOf(
            self::SOURCE_WEBHOOK,
            'webhook 投递（webhook_sends）',
            'payload LIKE %' . $traceId . '%（信封里必带 trace_id）',
            $this->load(fn(): array => $this->webhookRows($traceId))
        );

        $count = 0;

        foreach ($sources as $name => $section) {
            if (($section['count'] ?? 0) > 0) {
                $count++;
            }

            if (($section['unavailable'] ?? false) === true) {
                $missing[] = $name . '：' . (string) ($section['error'] ?? '不可用');
            }
        }

        return [
            'trace_id'     => $traceId,
            'sources'      => $sources,
            'timeline'     => $this->flatten($sources),
            'source_count' => $count,
            'missing'      => $missing,
            'note'         => $count >= 4
                ? '已取回 ≥4 类记录（验收 2 的"可回放"口径）'
                : '记录类别少于 4；若确认该 trace 确实走完闭环，请检查缺失来源（见 missing）',
        ];
    }


    /**
     * 闭环自身：事件行 + 投递行
     *
     * @return array<int, array<string, mixed>>
     */
    protected function closureRows(string $traceId): array
    {
        $rows = [];

        foreach ($this->store()->findEventsByTrace($traceId, self::MAX_ROWS) as $event) {
            $rows[] = $event + ['_kind' => 'event'];
        }

        foreach ($this->store()->findDeliveries($traceId, null, self::MAX_ROWS) as $delivery) {
            $rows[] = $delivery + ['_kind' => 'delivery'];
        }

        return $rows;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function approvalRows(string $ident): array
    {
        return Db::name('ai_approvals')
            ->where('ident', $ident)
            ->whereNull('delete_time')
            ->limit(self::MAX_ROWS)
            ->select()
            ->toArray();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function executionRows(string $trackingId): array
    {
        return Db::name('ai_agent_logs')
            ->where('tracking_id', $trackingId)
            ->limit(self::MAX_ROWS)
            ->select()
            ->toArray();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function planRows(int $runId): array
    {
        $rows = [];

        $run = Db::name('ai_plan_runs')->where('run_id', $runId)->find();

        if (is_array($run)) {
            $rows[] = $run + ['_kind' => 'run'];
        }

        foreach (Db::name('ai_plan_steps')->where('run_id', $runId)->order('step_no', 'asc')->limit(self::MAX_ROWS)->select()->toArray() as $step) {
            $rows[] = $step + ['_kind' => 'step'];
        }

        return $rows;
    }

    /**
     * ⚠️ 弱匹配：只按 JSON 文本包含 trace
     *
     * @return array<int, array<string, mixed>>
     */
    protected function notificationRows(string $traceId): array
    {
        $escaped = addcslashes($traceId, '%_\\');

        return Db::name('notification_histories')
            ->whereLike('message_payload', '%' . $escaped . '%')
            ->order('id', 'asc')
            ->limit(self::MAX_ROWS)
            ->select()
            ->toArray();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function webhookRows(string $traceId): array
    {
        $escaped = addcslashes($traceId, '%_\\');

        return Db::name('webhook_sends')
            ->whereLike('payload', '%' . $escaped . '%')
            ->order('id', 'asc')
            ->limit(self::MAX_ROWS)
            ->select()
            ->toArray();
    }

    protected function store(): ClosureTraceStoreInterface
    {
        return $this->store ??= new DbClosureTraceStore($this->closureOptions());
    }


    /**
     * 从闭环行里提取关联键（**其它来源怎么查，取决于这里**）
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array{ident:string, tracking_id:string, plan_run_id:int}
     */
    private function keysOf(array $rows): array
    {
        $ident      = '';
        $trackingId = '';
        $planRunId  = 0;

        foreach ($rows as $row) {
            $trace = (string) ($row['trace_id'] ?? '');

            if ($ident === '' && str_starts_with($trace, 'approval:')) {
                $ident = substr($trace, strlen('approval:'));
            }

            if ($trackingId === '' && ($row['tracking_id'] ?? '') !== '') {
                $trackingId = (string) $row['tracking_id'];
            }

            if ($planRunId === 0 && (int) ($row['plan_run_id'] ?? 0) > 0) {
                $planRunId = (int) $row['plan_run_id'];
            }
        }

        return ['ident' => $ident, 'tracking_id' => $trackingId, 'plan_run_id' => $planRunId];
    }

    /**
     * @param array{rows:array<int, array<string, mixed>>, error:string} $loaded
     * @return array<string, mixed>
     */
    private function sectionOf(string $source, string $label, string $matchedBy, array $loaded): array
    {
        return [
            'source'      => $source,
            'label'       => $label,
            'matched_by'  => $matchedBy,
            'count'       => count($loaded['rows']),
            'rows'        => $loaded['rows'],
            'unavailable' => $loaded['error'] !== '',
            'error'       => $loaded['error'],
        ];
    }

    /**
     * 单个来源的查询兜底：**缺表/缺包不能让整体失败**
     *
     * @param callable():array<int, array<string, mixed>> $loader
     * @return array{rows:array<int, array<string, mixed>>, error:string}
     */
    private function load(callable $loader): array
    {
        try {
            return ['rows' => $loader(), 'error' => ''];
        } catch (\Throwable $e) {
            return ['rows' => [], 'error' => $e->getMessage()];
        }
    }

    /**
     * @return array{rows:array<int, array<string, mixed>>, error:string}
     */
    private static function emptyLoad(): array
    {
        return ['rows' => [], 'error' => ''];
    }

    /**
     * 扁平化成人类可读的时间线
     *
     * @param array<string, array<string, mixed>> $sources
     * @return array<int, array<string, mixed>>
     */
    private function flatten(array $sources): array
    {
        $items = [];

        foreach ($sources as $name => $section) {
            foreach ((array) ($section['rows'] ?? []) as $row) {
                if (($row['_unavailable'] ?? false) === true) {
                    continue;
                }

                $items[] = [
                    'at'      => $this->timeOf($row),
                    'source'  => $name,
                    'summary' => $this->summaryOf($name, $row),
                    'detail'  => $row,
                ];
            }
        }

        usort($items, static function (array $a, array $b): int {
            return strcmp((string) $a['at'], (string) $b['at']);
        });

        return $items;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function timeOf(array $row): string
    {
        foreach (['occurred_at', 'create_time', 'decided_at', 'update_time'] as $key) {
            if (!empty($row[$key])) {
                return (string) $row[$key];
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $row
     */
    private function summaryOf(string $source, array $row): string
    {
        return match ($source) {
            self::SOURCE_CLOSURE => ($row['_kind'] ?? '') === 'event'
                ? sprintf('闭环事件 %s → %s（%s）', (string) ($row['scene'] ?? ''), (string) ($row['result'] ?? ''), (string) ($row['capability_name'] ?? ''))
                : sprintf('投递 %s：%s%s', (string) ($row['connector'] ?? ''), (string) ($row['status'] ?? ''), ($row['error'] ?? '') !== '' ? '（' . mb_substr((string) $row['error'], 0, 60, 'UTF-8') . '）' : ''),
            self::SOURCE_APPROVAL => sprintf('审批单 %s：%s（申请人 #%s，决策人 #%s）', (string) ($row['ident'] ?? ''), (string) ($row['status'] ?? ''), (string) ($row['requester_id'] ?? ''), (string) ($row['decider_id'] ?? '')),
            self::SOURCE_EXECUTION => sprintf('AI 执行 %s：%s（%s 步 / %s tokens）', (string) ($row['agent_type'] ?? ''), (string) ($row['status'] ?? ''), (string) ($row['steps'] ?? ''), (string) ($row['total_tokens'] ?? '')),
            self::SOURCE_PLAN => ($row['_kind'] ?? '') === 'run'
                ? sprintf('计划运行 #%s：%s（%s/%s 步）', (string) ($row['run_id'] ?? ''), (string) ($row['status'] ?? ''), (string) ($row['current_step'] ?? ''), (string) ($row['total_steps'] ?? ''))
                : sprintf('计划步骤 %s：%s（%s）', (string) ($row['step_id'] ?? ''), (string) ($row['status'] ?? ''), (string) ($row['target'] ?? '')),
            self::SOURCE_NOTIFICATION => sprintf('通知 %s：%s（收件人 %s#%s）', (string) ($row['channel'] ?? ''), (string) ($row['status'] ?? ''), (string) ($row['notifiable_type'] ?? ''), (string) ($row['notifiable_id'] ?? '')),
            self::SOURCE_WEBHOOK => sprintf('webhook %s：%s（HTTP %s）', (string) ($row['url'] ?? ''), (string) ($row['status'] ?? ''), (string) ($row['response_code'] ?? '')),
            default => '记录',
        };
    }
}
