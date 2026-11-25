<?php
declare(strict_types=1);

namespace wise\agent\task;

use think\facade\Db;
use wise\agent\execution\ExecutionTracker;

/**
 * 统一任务事实层（C1 任务中心的唯一读面）
 *
 * 只读聚合：把分散在 `ai_agent_logs`（主）+ `ai_plan_runs/steps`（B1 附）+
 * `ai_closure_events/deliveries`（B2 附）+ `ai_pipeline_runs`（弱附）的执行事实，
 * 收敛成一套「过滤 / 排序 / 分页 / 统计 / 详情」查询。
 *
 * 设计约束＼�：
 *  - **只读**：本类不写任何表。置 `timeout` 是 {@see ExecutionTracker::scanTimeouts()} 的职责，
 *    本类只负责「找出超时候选」；
 *  - **状态用常量**：一律引用 {@see ExecutionTracker} 的 STATUS_*，禁止裸数字；
 *  - **接缝可测**：所有 DB 访问收敛到 `load*()` 保护方法，单测继承本类、注入内存数组即可
 *    离线验证过滤/排序/分页/统计（与 B1-3 `PlanRunStoreInterface` 同一思路）。
 *
 * @package wise\agent
 */
class TaskQueryService
{
    /** 单页上限（风险表「强制分页上限」，防止大表分页拖垮主库） */
    public const MAX_PAGE_SIZE = 100;

    /** 允许的排序字段（默认 create_time；其余一律回落 create_time） */
    private const SORTABLE = ['create_time', 'duration', 'total_tokens'];


    /**
     * 分页列表（过滤 + 排序 + 分页）
     *
     * @param array<string, mixed> $filter 过滤维度：status/job_type/user_type/user_id/session_id/
     *                                     provider/model/from/to/keyword
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    public function paginate(array $filter, int $page, int $limit): array
    {
        $page  = max(1, $page);
        $limit = min(max(1, $limit), self::MAX_PAGE_SIZE);

        $rows     = $this->filterRows($this->loadLogs(), $filter);
        $sorted   = $this->sortRows($rows, (string) ($filter['sort'] ?? 'create_time'));
        $total    = count($sorted);
        $offset   = ($page - 1) * $limit;
        $pageRows = array_slice($sorted, $offset, $limit);

        return [
            'rows'  => array_values(array_map([$this, 'normalizeLog'], $pageRows)),
            'total' => $total,
        ];
    }

    /**
     * 单任务详情：事实层 + 三附（各带可用性标注）
     *
     * @return array{task: ?array, plan: array, closure: array, pipeline: array, sources: array}
     */
    public function detail(string $trackingId): array
    {
        if ($trackingId === '') {
            return $this->emptyDetail();
        }

        $task = $this->loadLog($trackingId);
        if ($task === null) {
            return $this->emptyDetail();
        }

        $plan     = $this->loadPlan($trackingId);
        $closure  = $this->loadClosure($trackingId);
        $pipeline = $this->loadPipeline(
            (string) ($task['session_id'] ?? ''),
            (string) ($task['create_time'] ?? '')
        );

        return [
            'task'     => $this->normalizeLog($task),
            'plan'     => $plan,
            'closure'  => $closure,
            'pipeline' => $pipeline,
            'sources'  => [
                'log'      => true,
                'plan'     => $plan['available'] ?? false,
                'closure'  => $closure['available'] ?? false,
                'pipeline' => $pipeline['available'] ?? false,
            ],
        ];
    }

    /**
     * 统计：按状态 / 执行体 / 天 分组计数（仪表盘与健康检查共用）
     *
     * @return array{by_status: array<int, int>, by_job_type: array<string, int>,
     *               by_day: array<string, int>, tokens_by_day: array<string, int>}
     */
    public function stats(array $filter = []): array
    {
        $rows = $this->filterRows($this->loadLogs(), $filter);

        $byStatus    = [];
        $byJobType   = [];
        $byDay       = [];
        $tokensByDay = [];

        foreach ($rows as $row) {
            $status = (int) ($row['status'] ?? 0);
            $byStatus[$status] = ($byStatus[$status] ?? 0) + 1;

            $jobType = (string) ($row['job_type'] ?? '');
            $byJobType[$jobType] = ($byJobType[$jobType] ?? 0) + 1;

            $day = substr((string) ($row['create_time'] ?? ''), 0, 10);
            if ($day !== '') {
                $byDay[$day] = ($byDay[$day] ?? 0) + 1;
                $tokensByDay[$day] = ($tokensByDay[$day] ?? 0) + (int) ($row['total_tokens'] ?? 0);
            }
        }

        return [
            'by_status'     => $byStatus,
            'by_job_type'   => $byJobType,
            'by_day'        => $byDay,
            'tokens_by_day' => $tokensByDay,
        ];
    }

    /**
     * 超时候选（只读；实际置 timeout 仍由 ExecutionTracker::scanTimeouts 做）
     *
     * 判定与 {@see ExecutionTracker::scanTimeouts()} 一致：
     * `status = running` 且 `heartbeat < now - $timeoutSeconds`；心跳为 NULL/空 不视为超时
     * （与 SQL `heartbeat < cutoff` 对 NULL 不匹配的语义一致）。
     *
     * @return array<int, array<string, mixed>>
     */
    public function staleRunning(int $timeoutSeconds): array
    {
        $cutoff = date('Y-m-d H:i:s', time() - max(1, $timeoutSeconds));

        $rows = array_filter($this->loadLogs(), static function (array $row) use ($cutoff): bool {
            if ((int) ($row['status'] ?? 0) !== ExecutionTracker::STATUS_RUNNING) {
                return false;
            }
            $hb = $row['heartbeat'] ?? null;
            return is_string($hb) && $hb !== '' && $hb < $cutoff;
        });

        return array_values(array_map([$this, 'normalizeLog'], $rows));
    }

    /**
     * 死信计数（最近 $sinceSeconds 内）
     *
     * 死信特征 = 三个 job 超限分支的统一落库标记：`status=failed` 且
     * `error` 以 `Max retries exceeded` 开头（AiAgentJob/PipelineJob 经
     * {@see ExecutionTracker::finish()} 落 ai_agent_logs；PlanJob 在 trackingId 非空时同样落
     * ai_agent_logs）。故只扫主表即可覆盖三类死信。
     *
     * @param int $sinceSeconds 时间窗（秒），默认 24h
     */
    public function deadLetterCount(int $sinceSeconds = 86400): int
    {
        $cutoff = date('Y-m-d H:i:s', time() - max(1, $sinceSeconds));

        $rows = array_filter($this->loadLogs(), static function (array $row) use ($cutoff): bool {
            if ((int) ($row['status'] ?? 0) !== ExecutionTracker::STATUS_FAILED) {
                return false;
            }
            if ((string) ($row['create_time'] ?? '') < $cutoff) {
                return false;
            }
            return str_starts_with((string) ($row['error'] ?? ''), 'Max retries exceeded');
        });

        return count($rows);
    }


    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, mixed>             $filter
     * @return array<int, array<string, mixed>>
     */
    protected function filterRows(array $rows, array $filter): array
    {
        return array_values(array_filter($rows, static function (array $row) use ($filter): bool {
            if (self::has($filter, 'status')) {
                $statuses = is_array($filter['status'])
                    ? array_map(static fn ($v) => (int) $v, $filter['status'])
                    : [(int) $filter['status']];
                if (!in_array((int) $row['status'], $statuses, true)) {
                    return false;
                }
            }
            if (self::has($filter, 'job_type') && (string) ($row['job_type'] ?? '') !== (string) $filter['job_type']) {
                return false;
            }
            if (self::has($filter, 'user_type') && (string) ($row['user_type'] ?? '') !== (string) $filter['user_type']) {
                return false;
            }
            if (self::has($filter, 'user_id') && (int) ($row['user_id'] ?? 0) !== (int) $filter['user_id']) {
                return false;
            }
            if (self::has($filter, 'session_id') && (string) ($row['session_id'] ?? '') !== (string) $filter['session_id']) {
                return false;
            }
            if (self::has($filter, 'provider') && (string) ($row['provider'] ?? '') !== (string) $filter['provider']) {
                return false;
            }
            if (self::has($filter, 'model') && (string) ($row['model'] ?? '') !== (string) $filter['model']) {
                return false;
            }
            if (self::has($filter, 'from') && (string) ($row['create_time'] ?? '') < (string) $filter['from']) {
                return false;
            }
            if (self::has($filter, 'to') && (string) ($row['create_time'] ?? '') > (string) $filter['to']) {
                return false;
            }
            if (self::has($filter, 'keyword') && !str_contains((string) ($row['task'] ?? ''), (string) $filter['keyword'])) {
                return false;
            }
            return true;
        }));
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    protected function sortRows(array $rows, string $sort): array
    {
        $sort = in_array($sort, self::SORTABLE, true) ? $sort : 'create_time';

        usort($rows, static function (array $a, array $b) use ($sort): int {
            return match ($sort) {
                'duration'     => (float) ($b['duration'] ?? 0) <=> (float) ($a['duration'] ?? 0),
                'total_tokens' => (int) ($b['total_tokens'] ?? 0) <=> (int) ($a['total_tokens'] ?? 0),
                default        => strcmp((string) ($b['create_time'] ?? ''), (string) ($a['create_time'] ?? '')),
            };
        });

        return $rows;
    }

    /**
     * 行归一化：状态补文本、数值列转强类型（供前端 / 测试稳定断言）
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    protected function normalizeLog(array $row): array
    {
        $status = (int) ($row['status'] ?? 0);

        $row['status']       = $status;
        $row['status_text']  = ExecutionTracker::statusText($status);
        $row['steps']        = (int) ($row['steps'] ?? 0);
        $row['total_tokens'] = (int) ($row['total_tokens'] ?? 0);
        $row['duration']     = (float) ($row['duration'] ?? 0);
        $row['user_id']      = (int) ($row['user_id'] ?? 0);

        return $row;
    }


    /**
     * @return array<int, array<string, mixed>> `ai_agent_logs` 原始行
     */
    protected function loadLogs(): array
    {
        try {
            $rows = Db::name('ai_agent_logs')->select()->toArray();
            return is_array($rows) ? $rows : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function loadLog(string $trackingId): ?array
    {
        try {
            $row = Db::name('ai_agent_logs')->where('tracking_id', $trackingId)->find();
            return is_array($row) ? $row : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array{run: ?array, steps: array, available: bool}
     */
    protected function loadPlan(string $trackingId): array
    {
        try {
            $run = Db::name('ai_plan_runs')->where('tracking_id', $trackingId)->find();
            $steps = [];
            if (is_array($run) && !empty($run['run_id'])) {
                $steps = Db::name('ai_plan_steps')
                    ->where('run_id', (int) $run['run_id'])
                    ->order('step_no', 'asc')
                    ->select()
                    ->toArray();
            }
            return [
                'run'       => is_array($run) ? $run : null,
                'steps'     => is_array($steps) ? $steps : [],
                'available' => is_array($run),
            ];
        } catch (\Throwable) {
            return ['run' => null, 'steps' => [], 'available' => false];
        }
    }

    /**
     * @return array{events: array, deliveries: array, available: bool}
     */
    protected function loadClosure(string $trackingId): array
    {
        try {
            $events = Db::name('ai_closure_events')
                ->where('tracking_id', $trackingId)
                ->order('occurred_at', 'asc')
                ->select()
                ->toArray();
            $events = is_array($events) ? $events : [];

            $deliveries = [];
            $eventIds = array_values(array_filter(array_map(
                static fn (array $e): int => (int) ($e['event_id'] ?? 0),
                $events
            )));
            if ($eventIds !== []) {
                $deliveries = Db::name('ai_closure_deliveries')
                    ->whereIn('event_id', $eventIds)
                    ->select()
                    ->toArray();
            }

            return [
                'events'     => $events,
                'deliveries' => is_array($deliveries) ? $deliveries : [],
                'available'  => $events !== [],
            ];
        } catch (\Throwable) {
            return ['events' => [], 'deliveries' => [], 'available' => false];
        }
    }

    /**
     * 弱关联：按 `session_id + 时间窗`（±1 天）尽力匹配 `ai_pipeline_runs`（无 tracking_id）
     *
     * @return array{runs: array, available: bool, strength: string}
     */
    protected function loadPipeline(string $sessionId, string $createTime): array
    {
        if ($sessionId === '') {
            return ['runs' => [], 'available' => false, 'strength' => 'weak'];
        }

        $from = $createTime !== '' ? date('Y-m-d H:i:s', strtotime($createTime) - 86400) : null;
        $to   = $createTime !== '' ? date('Y-m-d H:i:s', strtotime($createTime) + 86400) : null;

        try {
            $query = Db::name('ai_pipeline_runs')->where('session_id', $sessionId);
            if ($from !== null && $to !== null) {
                $query->whereBetween('create_time', [$from, $to]);
            }
            $runs = $query->order('create_time', 'desc')->select()->toArray();

            return [
                'runs'      => is_array($runs) ? $runs : [],
                'available' => !empty($runs),
                'strength'  => 'weak',
            ];
        } catch (\Throwable) {
            return ['runs' => [], 'available' => false, 'strength' => 'weak'];
        }
    }


    /**
     * 过滤条件是否「提供了值」（区分「未传」与「传了空串/0」）
     */
    private static function has(array $filter, string $key): bool
    {
        return array_key_exists($key, $filter)
            && $filter[$key] !== null
            && $filter[$key] !== '';
    }

    /**
     * @return array{task: ?array, plan: array, closure: array, pipeline: array, sources: array}
     */
    private function emptyDetail(): array
    {
        return [
            'task'     => null,
            'plan'     => ['run' => null, 'steps' => [], 'available' => false],
            'closure'  => ['events' => [], 'deliveries' => [], 'available' => false],
            'pipeline' => ['runs' => [], 'available' => false, 'strength' => 'weak'],
            'sources'  => ['log' => false, 'plan' => false, 'closure' => false, 'pipeline' => false],
        ];
    }
}
