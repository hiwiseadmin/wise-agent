<?php
declare(strict_types=1);

namespace wise\agent\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use wise\agent\security\ToolPermissionRegistrar;

/**
 * AI 能力权限同步命令
 *
 * 用法：
 *   php think wise:agent:permissions              # 收集并写入 wise-auth 权限表
 *   php think wise:agent:permissions --dry-run    # 仅预览，不写库
 *   php think wise:agent:permissions --prune      # 同时清理已废弃的 ai.* 历史权限
 *
 * 写入后可在后台「权限管理 → 权限配置」看到「AI 能力」权限树
 * （按只读 / 写入 / 编排执行 / 管理分类，含细粒度下钻），
 * 并在「角色管理」中按角色勾选授权。
 */
class SyncToolPermissions extends Command
{
    protected function configure()
    {
        $this->setName('wise:agent:permissions')
            ->setDescription('Sync AI capability permissions (tools + skills) into wise-auth permission table')
            ->addOption('dry-run', null, Option::VALUE_NONE, 'Only preview collected permissions, do not write to database')
            ->addOption('prune', null, Option::VALUE_NONE, 'Soft-delete stale ai.* permissions no longer collected');
    }

    protected function execute(Input $input, Output $output): int
    {
        $dryRun    = (bool) $input->getOption('dry-run');
        $prune     = (bool) $input->getOption('prune');
        $registrar = new ToolPermissionRegistrar();

        $output->writeln('');
        $output->writeln('<info>===============================================</info>');
        $output->writeln('<info> wise-agent — AI Capability Permission Sync</info>');
        $output->writeln('<info>===============================================</info>');
        $output->writeln('');

        if (!$registrar->isAvailable()) {
            $output->writeln('<error>wise-auth is not available. Install/enable wise-auth first.</error>');
            $output->writeln('');

            return 1;
        }

        $result = $registrar->sync($dryRun, $prune);

        foreach ($result['items'] as $ident) {
            $output->writeln('  - ' . $ident);
        }
        $output->writeln('');

        if ($dryRun) {
            $output->writeln("<comment>Dry run:</comment> {$result['total']} permission(s) collected, nothing written.");
            $output->writeln('');

            return 0;
        }

        $output->writeln("<info>Total collected:</info> {$result['total']}");
        $output->writeln(
            "<info>Created:</info> {$result['created']}   "
            . "<info>Updated:</info> {$result['updated']}   "
            . "<info>Pruned:</info> {$result['deleted']}"
        );

        if (!empty($result['errors'])) {
            $output->writeln('');
            $output->writeln('<error>Errors:</error>');

            foreach ($result['errors'] as $error) {
                $output->writeln('  <error>- ' . $error . '</error>');
            }
            $output->writeln('');

            return 1;
        }

        $output->writeln('');
        $output->writeln('<info>Done.</info> Grant them in: 后台 → 权限管理 → 角色管理 → 分配权限');
        $output->writeln('    日常只需勾选「全部只读能力 / 全部写入能力」类别通配即可。');
        $output->writeln('');

        return 0;
    }
}
