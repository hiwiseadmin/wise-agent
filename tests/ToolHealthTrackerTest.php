<?php
declare(strict_types=1);

namespace wise\agent\test;

use PHPUnit\Framework\TestCase;
use wise\agent\feedback\ToolHealthTracker;

class ToolHealthTrackerTest extends TestCase
{
    public function testAggregatePlanStepsOnlyToolAndAction(): void
    {
        $steps = [
            ['kind' => 'tool', 'target' => 'db_query', 'status' => 'completed', 'duration' => 1.5, 'error' => null],
            ['kind' => 'action', 'target' => 'db_query', 'status' => 'failed', 'duration' => 2.0, 'error' => 'timeout'],
            ['kind' => 'skill', 'target' => 'db_query', 'status' => 'failed', 'duration' => 9.9, 'error' => 'x'],
            ['kind' => 'tool', 'target' => 'db_query', 'status' => 'running', 'duration' => 9.9, 'error' => null],
        ];

        $stats = (new ToolHealthTracker())->aggregate($steps, []);

        $this->assertCount(1, $stats);
        $this->assertSame('db_query', $stats[0]['tool']);
        $this->assertSame(2, $stats[0]['calls']);
        $this->assertSame(1, $stats[0]['success']);
        $this->assertSame(1, $stats[0]['failure']);
    }

    public function testAggregateClosureEventsOnlyToolExecuted(): void
    {
        $events = [
            ['scene' => 'tool_executed', 'capability_name' => 'form_fill', 'result' => 'success', 'duration_ms' => 2000],
            ['scene' => 'tool_executed', 'capability_name' => 'form_fill', 'result' => 'failure', 'duration_ms' => 1000, 'reason' => 'bad input'],
            ['scene' => 'agent_completed', 'capability_name' => 'form_fill', 'result' => 'failure', 'duration_ms' => 9999],
        ];

        $stats = (new ToolHealthTracker())->aggregate([], $events);

        $this->assertCount(1, $stats);
        $this->assertSame('form_fill', $stats[0]['tool']);
        $this->assertSame(2, $stats[0]['calls']);
        $this->assertSame(1, $stats[0]['success']);
        $this->assertSame(1, $stats[0]['failure']);
    }

    public function testAggregateMergesBothSourcesIntoOneBucket(): void
    {
        $steps = [
            ['kind' => 'tool', 'target' => 'db_query', 'status' => 'completed', 'duration' => 1.0, 'error' => null],
        ];
        $events = [
            ['scene' => 'tool_executed', 'capability_name' => 'db_query', 'result' => 'failure', 'duration_ms' => 2000, 'reason' => 'boom'],
        ];

        $stats = (new ToolHealthTracker())->aggregate($steps, $events);

        $this->assertCount(1, $stats, '两来源同一工具合并到同一 bucket');
        $this->assertSame(2, $stats[0]['calls']);
        $this->assertSame(1, $stats[0]['success']);
        $this->assertSame(1, $stats[0]['failure']);
    }

    public function testAvgDurationUnifiedToSeconds(): void
    {
        $events = [
            ['scene' => 'tool_executed', 'capability_name' => 't', 'result' => 'success', 'duration_ms' => 1000],
            ['scene' => 'tool_executed', 'capability_name' => 't', 'result' => 'success', 'duration_ms' => 3000],
        ];

        $stats = (new ToolHealthTracker())->aggregate([], $events);

        $this->assertSame(2.0, $stats[0]['avg_duration'], '毫秒统一换算为秒');
    }

    public function testFailureRate(): void
    {
        $events = [
            ['scene' => 'tool_executed', 'capability_name' => 't', 'result' => 'success', 'duration_ms' => 0],
            ['scene' => 'tool_executed', 'capability_name' => 't', 'result' => 'failure', 'duration_ms' => 0, 'reason' => 'x'],
            ['scene' => 'tool_executed', 'capability_name' => 't', 'result' => 'failure', 'duration_ms' => 0, 'reason' => 'y'],
        ];

        $stats = (new ToolHealthTracker())->aggregate([], $events);

        $this->assertSame(round(2 / 3, 4), $stats[0]['failure_rate']);
    }

    public function testTopFailureReason(): void
    {
        $events = [
            ['scene' => 'tool_executed', 'capability_name' => 't', 'result' => 'failure', 'duration_ms' => 0, 'reason' => 'timeout'],
            ['scene' => 'tool_executed', 'capability_name' => 't', 'result' => 'failure', 'duration_ms' => 0, 'reason' => 'timeout'],
            ['scene' => 'tool_executed', 'capability_name' => 't', 'result' => 'failure', 'duration_ms' => 0, 'reason' => 'bad input'],
        ];

        $stats = (new ToolHealthTracker())->aggregate([], $events);

        $this->assertSame('timeout', $stats[0]['top_failure_reason'], '出现次数最多的失败原因');
    }

    public function testGroupByEquivalence(): void
    {
        $steps = [
            ['kind' => 'tool', 'target' => 'A', 'status' => 'completed', 'duration' => 1.0, 'error' => null],
            ['kind' => 'tool', 'target' => 'A', 'status' => 'completed', 'duration' => 2.0, 'error' => null],
            ['kind' => 'tool', 'target' => 'A', 'status' => 'completed', 'duration' => 3.0, 'error' => null],
            ['kind' => 'tool', 'target' => 'A', 'status' => 'failed', 'duration' => 1.0, 'error' => 'e'],
            ['kind' => 'tool', 'target' => 'B', 'status' => 'failed', 'duration' => 1.0, 'error' => 'e'],
            ['kind' => 'tool', 'target' => 'B', 'status' => 'failed', 'duration' => 2.0, 'error' => 'e'],
        ];

        $stats = (new ToolHealthTracker())->aggregate($steps, []);
        $byTool = [];
        foreach ($stats as $s) {
            $byTool[$s['tool']] = $s;
        }

        $this->assertSame(4, $byTool['A']['calls']);
        $this->assertSame(3, $byTool['A']['success']);
        $this->assertSame(1, $byTool['A']['failure']);
        $this->assertSame(1.75, $byTool['A']['avg_duration']);

        $this->assertSame(2, $byTool['B']['calls']);
        $this->assertSame(0, $byTool['B']['success']);
        $this->assertSame(2, $byTool['B']['failure']);
        $this->assertSame(1.0, $byTool['B']['failure_rate']);
    }
}
