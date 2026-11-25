<?php
declare(strict_types=1);

namespace wise\agent\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\facade\Cache;
use wise\agent\schedule\AiTaskScheduler;

/**
 * 执行到期的定时 AI 任务＼�
 *
 * 之前 `AiTaskScheduler::runDueTasks()` 是「孤儿」：没有命令驱动它，
 * 定时任务从未真正被调度。本命令把它接到 cron/计划任务上。
 *
 * 用法：
 *   php think wise:agent:run-due-tasks
 *   （建议 cron 每 1 分钟执行一次）
 *
 * 单机锁：用 `Cache::add` 的原子性防并发（TTL 60s，锁故障时 fail-open 继续执行，
 * 避免「锁挂了就把调度一起挂掉」）。
 */
class RunDueTasks extends Command
{
    private const LOCK_KEY = 'wise_agent:run_due_tasks:lock';
    private const LOCK_TTL = 60;

    protected function configure(): void
    {
        $this->setName('wise:agent:run-due-tasks')
            ->setDescription('Execute due scheduled AI tasks (single-instance locked)');
    }

    protected function execute(Input $input, Output $output): int
    {
        if (!$this->acquireLock()) {
            $output->writeln('<comment>Another run-due-tasks is in progress, skip.</comment>');
            return 0;
        }

        try {
            $scheduler = app(AiTaskScheduler::class);
            $results   = $scheduler->runDueTasks();

            if ($results === []) {
                $output->writeln('<info>No due tasks.</info>');
                return 0;
            }

            foreach ($results as $id => $result) {
                if (is_array($result) && isset($result['error'])) {
                    $output->writeln("<error>Task [{$id}] failed: {$result['error']}</error>");
                } else {
                    $output->writeln("<info>Task [{$id}] executed.</info>");
                }
            }

            return 0;
        } finally {
            $this->releaseLock();
        }
    }

    private function acquireLock(): bool
    {
        try {
            return (bool) Cache::add(self::LOCK_KEY, 1, self::LOCK_TTL);
        } catch (\Throwable) {
            return true;
        }
    }

    private function releaseLock(): void
    {
        try {
            Cache::delete(self::LOCK_KEY);
        } catch (\Throwable) {
        }
    }
}
