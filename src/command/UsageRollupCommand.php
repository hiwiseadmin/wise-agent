<?php
declare(strict_types=1);

namespace wise\agent\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use think\facade\Db;
use wise\agent\cost\UsageRollup;

/**
 * 用量日汇总命令＼�
 *
 * 把 `ai_agent_logs` 某日的调用聚合进 `ai_usage_daily`（小表），供仪表盘读。
 * **幂等**：先删该日该维度再插入（验收 8），因此可重跑、可补历史。
 *
 * 用法：
 *   php think wise:agent:usage-rollup --day=YYYY-MM-DD   # 默认昨天
 */
class UsageRollupCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('wise:agent:usage-rollup')
            ->setDescription('Roll up a day of AI usage into ai_usage_daily (idempotent)')
            ->addOption('day', 'd', Option::VALUE_OPTIONAL, 'Target day (YYYY-MM-DD), default yesterday', '');
    }

    protected function execute(Input $input, Output $output): int
    {
        $day = trim((string) $input->getOption('day'));
        if ($day === '') {
            $day = date('Y-m-d', strtotime('-1 day'));
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
            $output->writeln('<error>Invalid day format, expected YYYY-MM-DD.</error>');
            return 1;
        }

        $dayStart = $day . ' 00:00:00';
        $dayEnd   = $day . ' 23:59:59';

        try {
            $rows = Db::name('ai_agent_logs')
                ->whereBetween('create_time', [$dayStart, $dayEnd])
                ->select()
                ->toArray();
            $rows = is_array($rows) ? $rows : [];

            $buckets = (new UsageRollup())->aggregate($rows);
            $insert  = (new UsageRollup())->buildRows($buckets, $day);

            $deleted = Db::name('ai_usage_daily')->where('day', $day)->delete();

            $inserted = 0;
            if ($insert !== []) {
                $inserted = Db::name('ai_usage_daily')->insertAll($insert);
            }

            $output->writeln(sprintf(
                '<info>usage-rollup %s: scanned=%d buckets=%d deleted=%d inserted=%d</info>',
                $day,
                count($rows),
                count($buckets),
                $deleted,
                $inserted
            ));

            return 0;
        } catch (\Throwable $e) {
            $output->writeln("<error>usage-rollup failed: {$e->getMessage()}</error>");
            return 1;
        }
    }
}
