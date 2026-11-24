<?php
declare(strict_types=1);

namespace wise\agent\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Argument;
use think\console\input\Option;
use think\console\Output;
use wise\agent\closure\ClosureConnectorFactory;
use wise\agent\closure\ClosureDispatcher;
use wise\agent\closure\ClosureEvent;
use wise\agent\closure\ClosureRouter;
use wise\agent\closure\ClosureTimeline;
use wise\agent\closure\store\DbClosureTraceStore;

/**
 * `wise:agent:closure` —— 闭环轨迹的查看、回放与补投＼�
 *
 * | 子命令 | 作用 |
 * |---|---|
 * | `list` | 列出闭环事件（可按场景/状态/trace 过滤） |
 * | `show <trace_id>` | **回放**：按时间线聚合六个来源（含每个来源的关联方式与缺失原因） |
 * | `retry` | 补投失败的投递（`--failed` 批量 / `--id=N` 单条） |
 * | `prune` | 清理历史（`--before=` 或 `--days=`） |
 *
 * ## 两条安全边界（写进命令里，避免误用）
 *
 * 1. **`retry` 只补投"连接器投递"**（通知 / webhook / 审计），**绝不重放业务写操作** ——
 *    写操作的重放只能走「审批 + `plan_resume`」；命令头部会打印这句提醒。
 * 2. **`prune` 必须显式给时间**（`--before` 或 `--days`），不给就拒绝执行 ——
 *    防止"手滑清空闭环轨迹"，而轨迹正是审计要用的东西。
 */
class Closure extends Command
{
    protected function configure(): void
    {
        $this->setName('wise:agent:closure')
            ->addArgument('action', Argument::OPTIONAL, 'list | show | retry | prune（默认 list）', 'list')
            ->addArgument('trace_id', Argument::OPTIONAL, 'show 用：闭环 trace id', '')
            ->addOption('scene', null, Option::VALUE_OPTIONAL, 'list 用：场景过滤', '')
            ->addOption('status', null, Option::VALUE_OPTIONAL, 'list 用：状态过滤（ok/partial/failed/skipped）', '')
            ->addOption('trace', null, Option::VALUE_OPTIONAL, 'list 用：trace 过滤', '')
            ->addOption('limit', null, Option::VALUE_OPTIONAL, 'limit', '20')
            ->addOption('failed', null, Option::VALUE_NONE, 'retry 用：批量补投所有失败投递')
            ->addOption('id', null, Option::VALUE_OPTIONAL, 'retry 用：补投指定 delivery_id', '0')
            ->addOption('before', null, Option::VALUE_OPTIONAL, 'prune 用：早于该时间（Y-m-d 或 Y-m-d H:i:s）', '')
            ->addOption('days', null, Option::VALUE_OPTIONAL, 'prune 用：保留最近 N 天', '0')
            ->setDescription('查看 / 回放 / 补投 AI 业务闭环轨迹（审批→执行→通知→审计）');
    }

    protected function execute(Input $input, Output $output): int
    {
        $action = strtolower(trim((string) $input->getArgument('action')));

        return match ($action) {
            'show'  => $this->showTimeline($input, $output),
            'retry' => $this->retryDeliveries($input, $output),
            'prune' => $this->pruneHistory($input, $output),
            'list'  => $this->listEvents($input, $output),
            default => $this->unknown($action, $output),
        };
    }


    /**
     * 注意：方法名刻意避开 `list`（PHP 的保留字，虽然 8.x 允许作方法名，但读起来容易误会）
     */
    protected function listEvents(Input $input, Output $output): int
    {
        $rows = $this->store()->listEvents([
            'scene'    => (string) $input->getOption('scene'),
            'status'   => (string) $input->getOption('status'),
            'trace_id' => (string) $input->getOption('trace'),
        ], max(1, (int) $input->getOption('limit')));

        if ($rows === []) {
            $output->writeln('<comment>没有匹配的闭环事件。</comment>');
            $output->writeln('提示：闭环需要 wise-agent.closure.enabled=true，且事件命中 closure.scenes 路由。');

            return 0;
        }

        $output->writeln(sprintf('<info>闭环事件（%d 条）</info>', count($rows)));

        foreach ($rows as $row) {
            $output->writeln(sprintf(
                '  #%-6s %-19s %-11s %-8s %-34s %s',
                (string) ($row['event_id'] ?? ''),
                (string) ($row['scene'] ?? ''),
                (string) ($row['status'] ?? ''),
                (string) ($row['result'] ?? ''),
                mb_substr((string) ($row['trace_id'] ?? ''), 0, 32, 'UTF-8'),
                mb_substr((string) ($row['capability_name'] ?? ''), 0, 30, 'UTF-8')
            ));
        }

        $output->writeln('');
        $output->writeln('用 <comment>php think wise:agent:closure show <trace_id></comment> 回放某一条的完整时间线。');

        return 0;
    }

    protected function showTimeline(Input $input, Output $output): int
    {
        $traceId = trim((string) $input->getArgument('trace_id'));

        if ($traceId === '') {
            $output->writeln('<error>用法：php think wise:agent:closure show <trace_id></error>');
            $output->writeln('（trace 形如 approval:AP2026… / agent:<session> / cl_<hex>，可从 closure list 里取）');

            return 1;
        }

        $timeline = (new ClosureTimeline())->build($traceId);

        $output->writeln(sprintf('<info>闭环回放：%s</info>', $traceId));
        $output->writeln(sprintf('来源类别命中：%d（%s）', $timeline['source_count'], (string) $timeline['note']));
        $output->writeln('');

        foreach ($timeline['sources'] as $name => $section) {
            $output->writeln(sprintf(
                '  <comment>%s</comment>：%d 行%s',
                (string) $section['label'],
                (int) $section['count'],
                ($section['unavailable'] ?? false) ? ' <warning>[不可用]</warning>' : ''
            ));
            $output->writeln(sprintf('      关联方式：%s', (string) $section['matched_by']));

            if (($section['unavailable'] ?? false) === true) {
                $output->writeln(sprintf('      <warning>原因：%s</warning>', (string) $section['error']));
            }
        }

        $output->writeln('');
        $output->writeln('<comment>时间线</comment>');

        foreach ($timeline['timeline'] as $item) {
            $output->writeln(sprintf('  %-19s [%-12s] %s', (string) $item['at'], (string) $item['source'], (string) $item['summary']));
        }

        if ($timeline['timeline'] === []) {
            $output->writeln('  <comment>（没有可展示的记录）</comment>');
        }

        if ($timeline['missing'] !== []) {
            $output->writeln('');
            $output->writeln('<warning>缺失来源（缺包/缺表时属正常，其余来源仍可用）：</warning>');

            foreach ($timeline['missing'] as $line) {
                $output->writeln('  · ' . $line);
            }
        }

        return 0;
    }

    protected function retryDeliveries(Input $input, Output $output): int
    {
        $output->writeln('<comment>注意：retry 只补投"连接器投递"（通知/webhook/审计），不会重放业务写操作。</comment>');
        $output->writeln('     业务写操作的重放只能走「审批 + plan_resume」。');
        $output->writeln('');

        $limit    = max(1, (int) $input->getOption('limit'));
        $singleId = (int) $input->getOption('id');

        $rows = $singleId > 0
            ? array_filter([$this->store()->findDelivery($singleId)])
            : ($input->getOption('failed') ? $this->store()->findFailedDeliveries($limit) : []);

        if ($rows === []) {
            $output->writeln($singleId > 0
                ? sprintf('<error>未找到 delivery #%d</error>', $singleId)
                : '没有失败投递可补投（用 --failed 批量，或 --id=N 指定单条）。');

            return $singleId > 0 ? 1 : 0;
        }

        $dispatcher = $this->dispatcher();
        $ok = 0;
        $failed = 0;

        foreach ($rows as $row) {
            $deliveryId = (int) ($row['delivery_id'] ?? 0);
            $connector  = (string) ($row['connector'] ?? '');
            $eventId    = (int) ($row['event_id'] ?? 0);
            $eventRow   = $eventId > 0 ? $this->store()->findEvent($eventId) : null;

            if (!is_array($eventRow)) {
                $output->writeln(sprintf('  <warning>#%d 跳过：找不到原事件（event_id=%d），无法重建载荷</warning>', $deliveryId, $eventId));
                $failed++;

                continue;
            }

            $result = $dispatcher->retryDelivery($connector, ClosureEvent::fromArray($eventRow), $eventId);

            $output->writeln(sprintf(
                '  #%d %s → %s%s',
                $deliveryId,
                $connector,
                $result->status,
                $result->isOk() ? '' : '（' . mb_substr($result->error, 0, 80, 'UTF-8') . '）'
            ));

            $result->isOk() ? $ok++ : $failed++;
        }

        $output->writeln('');
        $output->writeln(sprintf('<info>补投完成：成功 %d / 失败 %d</info>', $ok, $failed));

        return $failed > 0 ? 1 : 0;
    }

    protected function pruneHistory(Input $input, Output $output): int
    {
        $before = trim((string) $input->getOption('before'));
        $days   = (int) $input->getOption('days');

        if ($before === '' && $days <= 0) {
            $output->writeln('<error>必须显式指定 --before=YYYY-MM-DD 或 --days=N</error>');
            $output->writeln('（闭环轨迹是审计素材，不做"无参数默认清理"）');

            return 1;
        }

        if ($before === '') {
            $before = date('Y-m-d H:i:s', time() - $days * 86400);
        } else {
            $before = strlen($before) <= 10 ? $before . ' 00:00:00' : $before;
        }

        $deleted = $this->store()->pruneBefore($before);

        $output->writeln(sprintf(
            '<info>已清理 %s 之前的轨迹：事件 %d 行 / 投递 %d 行</info>',
            $before,
            $deleted['events'],
            $deleted['deliveries']
        ));

        return 0;
    }

    protected function unknown(string $action, Output $output): int
    {
        $output->writeln(sprintf('<error>未知子命令：%s</error>', $action));
        $output->writeln('可用：list | show <trace_id> | retry [--failed|--id=N] | prune --before=…|--days=N');

        return 1;
    }


    protected function store(): DbClosureTraceStore
    {
        return new DbClosureTraceStore();
    }

    /**
     * 补投用的派发器：连接器按配置装配（缺包/未实现的名字不会装上，补投时会如实报 skipped）
     */
    protected function dispatcher(): ClosureDispatcher
    {
        $options = (array) config('wise-agent.closure', []);

        return new ClosureDispatcher(
            $options,
            (new ClosureConnectorFactory($options))->registry(),
            new ClosureRouter($options)
        );
    }
}
