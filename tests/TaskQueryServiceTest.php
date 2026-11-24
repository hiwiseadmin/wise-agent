<?php
declare(strict_types=1);

namespace wise\agent\test;

use PHPUnit\Framework\TestCase;
use wise\agent\execution\ExecutionTracker;
use wise\agent\task\TaskQueryService;

/**
 * C1-1：TaskQueryService 读面（过滤/排序/分页/统计/超时候选/详情 + 只读）
 *
 * 通过继承 + override `load*()` 接缝注入内存数组（验收 1、2）。
 */
class TaskQueryServiceTest extends TestCase
{
    /**
     * 构造一个注入内存数据的 TaskQueryService
     */
    private function service(
        array $logs,
        array $plan = [],
        array $closure = [],
        array $pipeline = []
    ): TaskQueryService {
        return new class($logs, $plan, $closure, $pipeline) extends TaskQueryService {
            public function __construct(
                private array $logs,
                private array $plan,
                private array $closure,
                private array $pipeline,
            ) {
            }

            protected function loadLogs(): array
            {
                return $this->logs;
            }

            protected function loadLog(string $trackingId): ?array
            {
                foreach ($this->logs as $log) {
                    if (($log['tracking_id'] ?? '') === $trackingId) {
                        return $log;
                    }
                }
                return null;
            }

            protected function loadPlan(string $trackingId): array
            {
                return $this->plan;
            }

            protected function loadClosure(string $trackingId): array
            {
                return $this->closure;
            }

            protected function loadPipeline(string $sessionId, string $createTime): array
            {
                return $this->pipeline;
            }
        };
    }

    /**
     * 一组典型日志行（覆盖状态、执行体、token、耗时、心跳）
     */
    private function logs(): array
    {
        return [
            [
                'tracking_id' => 'track_1', 'session_id' => 's1', 'user_type' => 'admin', 'user_id' => 7,
                'job_type' => 'agent', 'task' => '查询订单列表', 'status' => ExecutionTracker::STATUS_COMPLETED,
                'total_tokens' => 100, 'duration' => 5.0, 'provider' => 'openai', 'model' => 'gpt-4o',
                'heartbeat' => null, 'create_time' => '2026-10-17 10:00:00',
            ],
            [
                'tracking_id' => 'track_2', 'session_id' => 's2', 'user_type' => 'admin', 'user_id' => 9,
                'job_type' => 'pipeline', 'task' => '导出客户数据', 'status' => ExecutionTracker::STATUS_RUNNING,
                'total_tokens' => 300, 'duration' => 30.0, 'provider' => 'openai', 'model' => 'gpt-4o-mini',
                'heartbeat' => '2000-01-01 00:00:00', 'create_time' => '2026-10-17 09:00:00',
            ],
            [
                'tracking_id' => 'track_3', 'session_id' => 's1', 'user_type' => 'member', 'user_id' => 5,
                'job_type' => 'agent', 'task' => '生成对账单', 'status' => ExecutionTracker::STATUS_FAILED,
                'total_tokens' => 200, 'duration' => 12.5, 'provider' => 'anthropic', 'model' => 'claude-3',
                'heartbeat' => '2026-10-16 22:00:00', 'create_time' => '2026-10-16 21:00:00',
            ],
        ];
    }


    public function testPaginateFiltersByStatus(): void
    {
        $result = $this->service($this->logs())->paginate(
            ['status' => ExecutionTracker::STATUS_COMPLETED],
            1,
            20
        );

        $this->assertSame(1, $result['total']);
        $this->assertSame('track_1', $result['rows'][0]['tracking_id']);
    }

    public function testPaginateFiltersByJobType(): void
    {
        $result = $this->service($this->logs())->paginate(['job_type' => 'pipeline'], 1, 20);

        $this->assertSame(1, $result['total']);
        $this->assertSame('track_2', $result['rows'][0]['tracking_id']);
    }

    public function testPaginateFiltersByUser(): void
    {
        $result = $this->service($this->logs())->paginate(
            ['user_type' => 'member', 'user_id' => 5],
            1,
            20
        );

        $this->assertSame(1, $result['total']);
        $this->assertSame('track_3', $result['rows'][0]['tracking_id']);
    }

    public function testPaginateFiltersByKeyword(): void
    {
        $result = $this->service($this->logs())->paginate(['keyword' => '订单'], 1, 20);

        $this->assertSame(1, $result['total']);
        $this->assertSame('track_1', $result['rows'][0]['tracking_id']);
    }

    public function testPaginateFiltersByTimeRange(): void
    {
        $result = $this->service($this->logs())->paginate(
            ['from' => '2026-10-17 00:00:00', 'to' => '2026-10-17 23:59:59'],
            1,
            20
        );

        $this->assertSame(2, $result['total'], '17 日当天两条');
    }


    public function testPaginateSortsByCreateTimeDescByDefault(): void
    {
        $result = $this->service($this->logs())->paginate([], 1, 20);

        $this->assertSame('track_1', $result['rows'][0]['tracking_id'], '最新在前');
        $this->assertSame('track_3', $result['rows'][2]['tracking_id'], '最旧在后');
    }

    public function testPaginateSortsByDurationDesc(): void
    {
        $result = $this->service($this->logs())->paginate(['sort' => 'duration'], 1, 20);

        $this->assertSame('track_2', $result['rows'][0]['tracking_id'], '30.0 秒排最前');
    }

    public function testPaginateSortsByTokensDesc(): void
    {
        $result = $this->service($this->logs())->paginate(['sort' => 'total_tokens'], 1, 20);

        $this->assertSame('track_2', $result['rows'][0]['tracking_id'], '300 token 排最前');
    }

    public function testPaginateFallsBackToCreateTimeForUnknownSort(): void
    {
        $result = $this->service($this->logs())->paginate(['sort' => 'bogus'], 1, 20);

        $this->assertSame('track_1', $result['rows'][0]['tracking_id']);
    }


    public function testPaginatePaginationAndTotal(): void
    {
        $result = $this->service($this->logs())->paginate([], 2, 1);

        $this->assertSame(3, $result['total'], 'total 是过滤后总数，不受分页影响');
        $this->assertCount(1, $result['rows'], '第二页只有一条');
        $this->assertSame('track_2', $result['rows'][0]['tracking_id']);
    }

    public function testPaginateEnforcesMaxPageSize(): void
    {
        $many = [];
        for ($i = 0; $i < 150; $i++) {
            $many[] = [
                'tracking_id' => 'track_m' . $i, 'session_id' => 's', 'user_type' => 'admin',
                'user_id' => 1, 'job_type' => 'agent', 'task' => 't', 'status' => 2,
                'total_tokens' => 0, 'duration' => 0, 'heartbeat' => null,
                'create_time' => '2026-10-17 10:00:00',
            ];
        }

        $result = $this->service($many)->paginate([], 1, 1000);

        $this->assertSame(150, $result['total']);
        $this->assertCount(TaskQueryService::MAX_PAGE_SIZE, $result['rows'], '单页强制上限 100');
    }


    public function testStatsAggregates(): void
    {
        $stats = $this->service($this->logs())->stats();

        $this->assertSame(1, $stats['by_status'][ExecutionTracker::STATUS_COMPLETED] ?? 0);
        $this->assertSame(1, $stats['by_status'][ExecutionTracker::STATUS_RUNNING] ?? 0);
        $this->assertSame(1, $stats['by_status'][ExecutionTracker::STATUS_FAILED] ?? 0);

        $this->assertSame(2, $stats['by_job_type']['agent'] ?? 0);
        $this->assertSame(1, $stats['by_job_type']['pipeline'] ?? 0);

        $this->assertSame(2, $stats['by_day']['2026-10-17'] ?? 0);
        $this->assertSame(1, $stats['by_day']['2026-10-16'] ?? 0);

        $this->assertSame(400, $stats['tokens_by_day']['2026-10-17'] ?? 0, '17 日 token 合计 100+300');
        $this->assertSame(200, $stats['tokens_by_day']['2026-10-16'] ?? 0);
    }


    public function testStaleRunningSelectsOnlyRunningAndExpired(): void
    {
        $rows = $this->service($this->logs())->staleRunning(60);

        $ids = array_column($rows, 'tracking_id');
        $this->assertNotContains('track_1', $ids, 'completed 不是超时候选');
        $this->assertContains('track_2', $ids, 'running 且心跳已过期 ⇒ 超时候选');
        $this->assertNotContains('track_3', $ids, 'failed 不是超时候选');
    }

    public function testStaleRunningExcludesNullHeartbeat(): void
    {
        $logs = [
            [
                'tracking_id' => 'track_nohb', 'session_id' => 's', 'user_type' => 'admin',
                'user_id' => 1, 'job_type' => 'agent', 'task' => 't', 'status' => ExecutionTracker::STATUS_RUNNING,
                'total_tokens' => 0, 'duration' => 0, 'heartbeat' => null,
                'create_time' => '2026-10-17 09:00:00',
            ],
        ];

        $rows = $this->service($logs)->staleRunning(60);

        $this->assertSame([], $rows, 'NULL 心跳不视为超时');
    }


    public function testDetailAggregatesAttachments(): void
    {
        $plan = ['run' => ['run_id' => 11, 'goal' => 'g'], 'steps' => [['step_no' => 1]], 'available' => true];
        $closure = ['events' => [['event_id' => 1, 'trace_id' => 'trace_x']], 'deliveries' => [], 'available' => true];
        $pipeline = ['runs' => [['pipeline_name' => 'p']], 'available' => true, 'strength' => 'weak'];

        $detail = $this->service($this->logs(), $plan, $closure, $pipeline)->detail('track_1');

        $this->assertSame('track_1', $detail['task']['tracking_id']);
        $this->assertTrue($detail['plan']['available']);
        $this->assertSame('trace_x', $detail['closure']['events'][0]['trace_id']);
        $this->assertSame('weak', $detail['pipeline']['strength']);
        $this->assertTrue($detail['sources']['log']);
    }

    public function testDetailReturnsEmptyForUnknownTrackingId(): void
    {
        $detail = $this->service($this->logs())->detail('track_missing');

        $this->assertNull($detail['task']);
        $this->assertFalse($detail['sources']['log']);
    }


    public function testServiceIsReadOnlyNoWriteCalls(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__) . '/src/task/TaskQueryService.php'
        );

        $code = (string) preg_replace(['~/\*.*?\*/~s', '~//[^\n]*~'], '', $source);

        foreach (['->insert(', '->update(', '->delete(', 'insertGetId('] as $writeCall) {
            $this->assertStringNotContainsString($writeCall, $code, "读面不得出现写调用 {$writeCall}");
        }
    }
}
