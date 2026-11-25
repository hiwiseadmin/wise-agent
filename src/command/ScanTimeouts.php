<?php
declare(strict_types=1);

namespace wise\agent\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use wise\agent\execution\ExecutionTracker;

/**
 * 扫描并标记超时的 Agent/Pipeline 执行
 *
 * 查找状态为 running 但心跳超过阈值的任务，自动标记为 timeout。
 * 建议通过 cron 定期执行（例如每 5 分钟）。
 *
 * 用法：
 *   php think wise:agent:scan-timeouts [--timeout=600]
 */
class ScanTimeouts extends Command
{
    protected function configure(): void
    {
        $this->setName('wise:agent:scan-timeouts')
            ->setDescription('Scan for stuck agent/pipeline executions and mark them as timed out')
            ->addOption('timeout', 't', Option::VALUE_OPTIONAL, 'Timeout threshold in seconds (default: 600)', 600);
    }

    protected function execute(Input $input, Output $output): int
    {
        $timeoutSeconds = (int) $input->getOption('timeout');

        if ($timeoutSeconds <= 0) {
            $output->writeln('<error>Timeout must be a positive integer.</error>');
            return 1;
        }

        $output->writeln("<info>Scanning for stuck executions (heartbeat older than {$timeoutSeconds}s)...</info>");

        $count = ExecutionTracker::scanTimeouts($timeoutSeconds);

        if ($count > 0) {
            $output->writeln("<comment>Found {$count} stuck execution(s), marked as timed out.</comment>");
        } else {
            $output->writeln('<info>No stuck executions found.</info>');
        }

        return 0;
    }
}
