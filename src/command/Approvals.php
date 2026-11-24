<?php
declare(strict_types=1);

namespace wise\agent\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use wise\agent\security\ApprovalManager;

/**
 * AI 能力审批单管理命令
 *
 * 用法：
 *   php think wise:agent:approvals                          # 列出待审批
 *   php think wise:agent:approvals --status=all --limit=50  # 列出全部（分页上限）
 *   php think wise:agent:approvals --approve=12 --note=同意  # 批准
 *   php think wise:agent:approvals --reject=12 --note=风险过大 # 驳回
 *   php think wise:agent:approvals --cancel=12              # 撤回
 *   php think wise:agent:approvals --expire                 # 清理过期
 *
 * 说明：批准后不重放历史调用，申请人需重新发起同一调用，
 * 校验时命中可用审批单并原子消费一次。
 */
class Approvals extends Command
{
    protected function configure()
    {
        $this->setName('wise:agent:approvals')
            ->setDescription('Manage AI capability approvals (list / approve / reject / cancel / expire)')
            ->addOption('list', null, Option::VALUE_NONE, 'List approvals (default action)')
            ->addOption('status', null, Option::VALUE_REQUIRED, 'Filter by status: pending|approved|consumed|rejected|expired|cancelled|all', 'pending')
            ->addOption('limit', null, Option::VALUE_REQUIRED, 'Max rows to display', '20')
            ->addOption('approve', null, Option::VALUE_REQUIRED, 'Approve approval by id')
            ->addOption('reject', null, Option::VALUE_REQUIRED, 'Reject approval by id')
            ->addOption('cancel', null, Option::VALUE_REQUIRED, 'Cancel approval by id')
            ->addOption('note', null, Option::VALUE_REQUIRED, 'Decision note', '')
            ->addOption('operator', null, Option::VALUE_REQUIRED, 'Operator admin id', '0')
            ->addOption('expire', null, Option::VALUE_NONE, 'Mark expired approvals');
    }

    protected function execute(Input $input, Output $output): int
    {
        $manager  = new ApprovalManager();
        $operator = (int) $input->getOption('operator');
        $note     = (string) $input->getOption('note');

        if ($input->getOption('expire')) {
            $count = $manager->expireStale();
            $output->writeln("<info>已失效 {$count} 条审批单</info>");

            return 0;
        }

        foreach (['approve' => '批准', 'reject' => '驳回', 'cancel' => '撤回'] as $action => $label) {
            $id = $input->getOption($action);

            if ($id === null) {
                continue;
            }

            $result = $manager->{$action}((int) $id, $operator, $note);

            if (!$result['ok']) {
                $output->writeln('<error>' . $result['message'] . '</error>');

                return 1;
            }

            $output->writeln("<info>{$label}成功：{$result['data']['ident']}</info>");
        }

        return $this->listApprovals($manager, $input, $output);
    }

    /**
     * 列出审批单
     */
    protected function listApprovals(ApprovalManager $manager, Input $input, Output $output): int
    {
        $status = (string) $input->getOption('status');
        $limit  = max(1, (int) $input->getOption('limit'));

        $result = $manager->paginate(
            ['status' => $status === 'all' ? '' : $status],
            1,
            $limit
        );

        $output->writeln('');
        $output->writeln(sprintf('  %-6s %-22s %-10s %-28s %-10s %-6s', 'ID', '单号', '状态', '能力', '申请人', '剩余'));
        $output->writeln('  ' . str_repeat('-', 92));

        foreach ($result['rows'] as $row) {
            $output->writeln(sprintf(
                '  %-6s %-22s %-10s %-28s %-10s %-6s',
                $row['id'],
                $row['ident'],
                $row['status_label'],
                mb_substr((string) $row['capability_name'] . ' (' . $row['permission'] . ')', 0, 26),
                $row['requester_name'],
                $row['uses_left'] . '/' . $row['uses_total']
            ));
        }

        $output->writeln('');
        $output->writeln('<comment>Total:</comment> ' . $result['total']);
        $output->writeln('');

        return 0;
    }
}
