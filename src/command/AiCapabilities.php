<?php
declare(strict_types=1);

namespace wise\agent\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use wise\agent\capability\CapabilityCatalog;

/**
 * 导出 AI 能力目录（B1-4）
 *
 * 用途：交付前回答"这套系统上 AI 到底能做哪些事"，并可作为合同附件。
 *
 * 用法：
 *   php think ai:capabilities                # 人类可读列表
 *   php think ai:capabilities --json         # 机器可读（给 CI / 交付脚本）
 *   php think ai:capabilities --source=plugin
 *   php think ai:capabilities --disabled     # 只看默认关闭的高危能力
 */
class AiCapabilities extends Command
{
    protected function configure(): void
    {
        $this->setName('ai:capabilities')
            ->setDescription('导出当前环境的 AI 能力目录（能力标识 / 权限 / 风险 / 来源）')
            ->addOption('json', 'j', Option::VALUE_NONE, '以 JSON 输出（便于 CI / 交付脚本消费）')
            ->addOption('source', 's', Option::VALUE_REQUIRED, '仅显示指定来源（builtin / plugin / menu_action）')
            ->addOption('disabled', 'd', Option::VALUE_NONE, '仅显示默认关闭的高危能力（admin / high / 外部接入）');
    }

    protected function execute(Input $input, Output $output): int
    {
        $items = CapabilityCatalog::build();

        $source = trim((string) ($input->getOption('source') ?? ''));
        if ($source !== '') {
            $items = array_values(array_filter(
                $items,
                static fn(array $item) => (string) ($item['source'] ?? '') === $source
            ));
        }

        if ($input->getOption('disabled')) {
            $items = array_values(array_filter(
                $items,
                static fn(array $item) => empty($item['default_enabled'])
            ));
        }

        if ($input->getOption('json')) {
            $output->writeln((string) json_encode([
                'generated_at' => date('c'),
                'summary'      => CapabilityCatalog::summary($items),
                'items'        => $items,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

            return 0;
        }

        if ($items === []) {
            $output->writeln('<comment>没有匹配的能力。</comment>');
            return 0;
        }

        $summary = CapabilityCatalog::summary($items);

        $output->writeln('<info>AI 能力目录</info>');
        $output->writeln(sprintf(
            '共 %d 项，其中默认关闭（高危/外部） %d 项',
            $summary['total'],
            $summary['default_disabled']
        ));
        $output->writeln('');

        foreach ($items as $item) {
            $flag = $item['default_enabled'] ? ' ' : '!';

            $output->writeln(sprintf(
                '%s %-48s %-8s %-6s %-12s %s',
                $flag,
                $item['name'],
                $item['access'],
                $item['risk'],
                $item['source'],
                $item['external'] ? 'external' : ''
            ));
        }

        $output->writeln('');
        $output->writeln('<comment>! = 默认关闭。access/risk 取值与权限树一致（read/write/execute/admin）。</comment>');

        return 0;
    }
}
