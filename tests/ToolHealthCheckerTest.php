<?php
declare(strict_types=1);

namespace wise\agent\test;

use PHPUnit\Framework\TestCase;
use wise\agent\feedback\ToolHealthChecker;

class ToolHealthCheckerTest extends TestCase
{
    public function testWarnWhenFailureRateExceedsThreshold(): void
    {
        $checker = new ToolHealthChecker(fn () => [
            ['tool' => 'A', 'calls' => 10, 'failure' => 8, 'failure_rate' => 0.8, 'top_failure_reason' => 'x'],
        ]);

        $result = $checker->check();

        $this->assertFalse($result->passed, '失败率超阈值 ⇒ 预警');
        $this->assertStringContainsString('A', $result->message);
    }

    public function testOkWhenAllHealthy(): void
    {
        $checker = new ToolHealthChecker(fn () => [
            ['tool' => 'A', 'calls' => 10, 'failure' => 1, 'failure_rate' => 0.1, 'top_failure_reason' => ''],
        ]);

        $result = $checker->check();

        $this->assertTrue($result->passed, '失败率低于阈值 ⇒ 正常');
    }

    public function testOkWhenNoTools(): void
    {
        $checker = new ToolHealthChecker(fn () => []);

        $result = $checker->check();

        $this->assertTrue($result->passed, '无工具调用 ⇒ 正常');
    }

    public function testFailOnStatsException(): void
    {
        $checker = new ToolHealthChecker(function (): array {
            throw new \RuntimeException('db down');
        });

        $result = $checker->check();

        $this->assertFalse($result->passed, '统计异常 ⇒ 告警（不静默吞掉）');
    }
}
