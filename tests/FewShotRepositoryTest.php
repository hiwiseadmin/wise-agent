<?php
declare(strict_types=1);

namespace wise\agent\test;

use PHPUnit\Framework\TestCase;
use wise\agent\feedback\FewShotRepository;

class FewShotRepositoryTest extends TestCase
{
    public function testShotsOnlySuccessAndLimitByMaxShots(): void
    {
        $rows = [];
        for ($i = 1; $i <= 10; $i++) {
            $rows[] = ['task' => "任务 {$i}", 'provider' => 'openai', 'model' => 'gpt-4o-mini'];
        }

        $repo  = new FewShotRepository(fn () => $rows);
        $shots = $repo->shots();

        $this->assertCount(5, $shots, '数量 ≤ 默认 max_shots(5)');
        $this->assertSame('任务 1', $shots[0]['task']);
        $this->assertSame('openai', $shots[0]['provider']);
    }

    public function testShotsSkipEmptyTask(): void
    {
        $repo = new FewShotRepository(fn () => [
            ['task' => '', 'provider' => 'openai', 'model' => 'x'],
            ['task' => '有效任务', 'provider' => 'openai', 'model' => 'x'],
        ]);

        $shots = $repo->shots();

        $this->assertCount(1, $shots);
        $this->assertSame('有效任务', $shots[0]['task']);
    }

    public function testShotsEmptyWhenLoaderThrows(): void
    {
        $repo = new FewShotRepository(function (): array {
            throw new \RuntimeException('db down');
        });

        $this->assertSame([], $repo->shots(), '加载异常 ⇒ 空（不阻断）');
    }
}
