<?php
declare(strict_types=1);

namespace wise\agent\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use wise\agent\console\ConsoleCommandGuard;
use wise\agent\console\ConsoleCommandIntrospector;
use wise\agent\console\JsonOutputTrait;

/**
 * 框架命令内省导出命令＼�
 *
 * 用法：
 *   php think wise:agent:console-dump [--json] [--force] [--no-cache] [--allowed-only]
 *
 *   --json         以 JSON 格式输出（HTTP 侧内省入口，**纯 JSON，便于 json_decode**）
 *   --force        强制重建内省缓存
 *   --no-cache     仅导出，不写内省缓存
 *   --allowed-only 仅导出通过白名单的命令（默认导出全部，便于审计）
 *
 * 为什么需要它：
 *   插件命令由 `PluginServiceProvider` 在 `Console::starting()` 阶段注册，
 *   HTTP 进程不会创建 Console 实例，因此**拿不到插件命令**。
 *   HTTP 侧通过本命令的子进程产物回填缓存（见 ConsoleCommandIntrospector::introspectViaCli）。
 *
 * ⚠️ 不要解析 `php think list` 的格式化文本（ANSI + 分组 + 对齐，脆弱），
 *    本命令直接序列化 `Console::all()` 的结构化元数据。
 */
class ConsoleDump extends Command
{
    use JsonOutputTrait;

    protected function configure(): void
    {
        $this->setName('wise:agent:console-dump')
            ->setDescription('Introspect framework / package / plugin think commands as structured metadata (pure JSON with --json)')
            ->addOption('json', null, Option::VALUE_NONE, 'Output as JSON (machine readable)')
            ->addOption('force', 'f', Option::VALUE_NONE, 'Force rebuild introspection cache')
            ->addOption('no-cache', null, Option::VALUE_NONE, 'Do not write the introspection cache')
            ->addOption('allowed-only', null, Option::VALUE_NONE, 'Only export commands passing the allowlist');
    }

    protected function execute(Input $input, Output $output): int
    {
        $isJson      = $this->isJsonMode($input);
        $force       = (bool) $input->getOption('force');
        $noCache     = (bool) $input->getOption('no-cache');
        $allowedOnly = (bool) $input->getOption('allowed-only');

        try {
            $introspector = new ConsoleCommandIntrospector();

            $metas = $noCache ? $introspector->introspect() : $introspector->resolve(true);
        } catch (\Throwable $e) {
            return $this->fail($output, $isJson, 'Introspection failed: ' . $e->getMessage());
        }

        if ($metas === []) {
            return $this->fail($output, $isJson, 'No console command registered in the current CLI process.');
        }

        $guard = new ConsoleCommandGuard();
        foreach ($metas as $meta) {
            $guard->annotate($meta);
        }

        $all     = array_values(array_map(static fn($m) => $m->toArray(), $metas));
        $allowed = array_values(array_filter($all, static fn(array $m) => !empty($m['allowed'])));
        $list    = $allowedOnly ? $allowed : $all;

        if ($isJson) {
            $this->outputJson($output, 0, 'ok', [
                'total'   => count($all),
                'allowed' => count($allowed),
                'listed'  => count($list),
                'commands' => $list,
            ]);

            return 0;
        }

        $output->writeln('');
        $output->writeln('<info>===============================================</info>');
        $output->writeln('<info> wise-agent — Console Command Introspection</info>');
        $output->writeln('<info>===============================================</info>');
        $output->writeln('');
        $output->writeln(sprintf(
            '  Total: %d   Allowed: %d   Listed: %d',
            count($all),
            count($allowed),
            count($list)
        ));
        $output->writeln('');

        foreach ($list as $item) {
            $flag = !empty($item['allowed']) ? '<info>ALLOW</info>' : '<error>DENY </error>';
            $json = !empty($item['supports_json']) ? ' [--json]' : '';
            $output->writeln(sprintf(
                '  %s  %-40s <comment>%s</comment> <comment>(%s)</comment>%s',
                $flag,
                $item['name'],
                $item['risk'],
                $item['source'],
                $json
            ));
        }

        $output->writeln('');
        $output->writeln('  <comment>Tip:</comment> php think wise:agent:console-dump --json  → machine readable');
        $output->writeln('');

        return 0;
    }

    private function fail(Output $output, bool $isJson, string $message): int
    {
        if ($isJson) {
            $this->outputJson($output, 1, $message);
        } else {
            $output->writeln('<error>' . $message . '</error>');
        }

        return 1;
    }
}
