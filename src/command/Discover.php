<?php
declare(strict_types=1);

namespace wise\agent\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use wise\agent\bridge\PluginAiDiscovery;
use wise\agent\schema\SchemaIntrospector;
use wise\agent\schema\discovery\PluginSchemaDiscovery;
use wise\agent\schema\capability\BusinessCapabilityResolver;
use wise\agent\schema\capability\CapabilityResult;
use wise\agent\schema\generator\ToolMaterializer;
use wise\agent\schema\generator\NodeMaterializer;

/**
 * wise-agent AI 能力发现命令
 *
 * 用法：
 *   php think wise:agent:discover [--force] [--schema-only] [-v] [--dump=plugin] [--bootstrap=plugin] [--materialize=plugin]
 *
 *   --force           强制重建缓存
 *   --schema-only     仅执行 Schema 发现
 *   -v, --verbose     详细输出（每表 Tool 生成详情 + 每能力匹配详情）
 *   --dump=plugin     导出自动 Schema 为 JSON（复制到 manifest.json）
 *   --dump-md=plugin  导出为 Markdown
 *   --bootstrap=plugin 自动补全 manifest.json 的 ai_schema 段
 *   --materialize=plugin 固化自动生成的 Tool 为 PHP 类文件到插件目录
 */
class Discover extends Command
{
    protected function configure()
    {
        $this->setName('wise:agent:discover')
            ->setDescription('Discover AI capability providers and auto-introspect plugin schemas')
            ->addOption('force', 'f', Option::VALUE_NONE, 'Force rebuild cache')
            ->addOption('schema-only', null, Option::VALUE_NONE, 'Only run Schema discovery')
            ->addOption('dump', null, Option::VALUE_REQUIRED, 'Export schema as JSON (e.g. --dump=smartstock)')
            ->addOption('dump-md', null, Option::VALUE_REQUIRED, 'Export schema as Markdown')
            ->addOption('bootstrap', null, Option::VALUE_REQUIRED, 'Auto-fill ai_schema in manifest.json (e.g. --bootstrap=smartstock)')
            ->addOption('materialize', null, Option::VALUE_REQUIRED, 'Materialize auto-generated tools to plugin PHP files (e.g. --materialize=smartstock)')
            ->addOption('materialize-node', null, Option::VALUE_REQUIRED, 'Materialize auto-generated workflow nodes (e.g. --materialize-node=smartstock)');
    }

    protected function execute(Input $input, Output $output): int
    {
        $force          = $input->getOption('force');
        $schemaOnly     = $input->getOption('schema-only');
        $verbose        = $input->getOption('verbose');
        $dumpPlugin     = $input->getOption('dump');
        $dumpMd         = $input->getOption('dump-md');
        $bootstrap      = $input->getOption('bootstrap');
        $materialize    = $input->getOption('materialize');
        $materializeNode= $input->getOption('materialize-node');

        if ($dumpPlugin || $dumpMd) {
            return $this->executeDump($input, $output);
        }

        if ($materialize) {
            return $this->executeMaterialize($input, $output);
        }

        if ($materializeNode) {
            return $this->executeMaterializeNode($input, $output);
        }

        if ($bootstrap) {
            return $this->executeBootstrap($input, $output);
        }

        $output->writeln('');
        $output->writeln('<info>===============================================</info>');
        $output->writeln('<info> wise-agent — AI Capability Discovery</info>');
        $output->writeln('<info>===============================================</info>');
        $output->writeln('');

        if ($force && $schemaOnly) {
            $output->writeln('<comment>Force schema-only mode: rebuilding schema cache...</comment>');
        } elseif ($force) {
            $output->writeln('<comment>Force mode: rebuilding all caches...</comment>');
        } elseif ($schemaOnly) {
            $output->writeln('<comment>Schema-only mode: discovering plugin schemas...</comment>');
        }
        $output->writeln('');

        $discovery = new PluginAiDiscovery($this->app);
        $discovery->setSchemaIntrospector(new SchemaIntrospector());

        if ($schemaOnly) {
            $discovery->discoverSchema();
            $stats = $discovery->getStats();
            $this->discoveryInstance = $discovery;
            $this->outputSchemaStats($output, $stats, $verbose);
            return 0;
        }

        $stats = $discovery->discover($force);
        $this->discoveryInstance = $discovery;

        $output->writeln('<info>Discovery completed!</info>');
        $output->writeln('');
        $this->outputProviderStats($output, $stats);

        if (($stats['schema_plugins'] ?? 0) > 0) {
            $this->outputSchemaStats($output, $stats, $verbose);
        }

        $this->outputErrors($output, $stats);

        $this->refreshConsoleTools($output);

        $this->refreshMenuActions($output);

        $this->refreshListQueryTargets($output);

        $this->refreshFormTargets($output);

        $this->refreshPlanCatalog($output);

        $this->refreshClosure($output);

        $output->writeln('<info>Cache updated. AI capabilities are now available.</info>');
        $output->writeln('');

        return 0;
    }

    /**
     * A4：刷新控制台命令内省缓存并注册命令工具
     *
     * 复用 PluginAiDiscovery 的发现流程：发现完成后顺带重建 console_commands 缓存，
     * 使 `php think wise:agent:discover --force` 一次操作覆盖全部 AI 能力来源。
     * 该步骤完全隔离（try-catch），失败不影响既有发现结果。
     */
    protected function refreshConsoleTools(Output $output): void
    {
        if (!(bool) config('wise-agent.console.enabled', false)) {
            return;
        }

        try {
            $metas = (new \wise\agent\console\ConsoleCommandIntrospector())->refresh();
            $count = (new \wise\agent\console\ConsoleCommandToolProvider())->registerTo();

            $output->writeln('  <comment>Console tools:</comment> '
                . "{$count} tool(s) registered from " . count($metas) . ' introspected command(s)');
            $output->writeln('');
        } catch (\Throwable $e) {
            $output->writeln('  <warning>Console tool refresh failed: ' . $e->getMessage() . '</warning>');
            $output->writeln('');
        }
    }

    /**
     * A1：刷新「后台菜单动作内省」缓存并注册动作工具
     *
     * 与 A4 的 `refreshConsoleTools()` 并列：发现完成后顺带重建 `menu_actions.json`
     * 缓存（菜单/控制器/RBAC 锚点可能随插件安装而变化）。
     * 该步骤完全隔离（try-catch），失败不影响既有发现结果。
     */
    protected function refreshMenuActions(Output $output): void
    {
        if (!(bool) config('wise-agent.menu_actions.enabled', false)) {
            return;
        }

        try {
            $introspector = new \wise\agent\menu\MenuActionIntrospector();
            $metas        = $introspector->refresh();
            $provider     = new \wise\agent\menu\MenuActionToolProvider($introspector);
            $tools        = $provider->getTools();
            $count        = $provider->registerTo();

            $output->writeln('  <comment>Menu actions:</comment> '
                . "{$count} tool(s) registered from " . count($metas) . ' introspected action(s)'
                . '（权限标识来源：' . $introspector->identSource() . '）');

            try {
                $checker = new \wise\agent\menu\MenuActionParityChecker();
                $report  = $checker->check($metas, $tools);

                $output->writeln('  <comment>Parity:</comment> ' . $checker->summary($report));

                if (!$report['ok']) {
                    $output->writeln('  <error>Parity drift: AI 的访问面可能大于人工，请检查菜单/权限点与 pipeline 配置</error>');
                }
            } catch (\Throwable $e) {
                $output->writeln('  <warning>Parity check skipped: ' . $e->getMessage() . '</warning>');
            }

            $output->writeln('');
        } catch (\Throwable $e) {
            $output->writeln('  <warning>Menu action refresh failed: ' . $e->getMessage() . '</warning>');
            $output->writeln('');
        }
    }

    /**
     * A3：输出「自然语言查询目标」清单
     *
     * 目标来源与 A1 的内省结果同源（A3-3 的 `ListQueryTargetResolver`），这里把每个目标
     * **实际会走哪条执行路径、筛选能力如何**打印出来，供运维确认「哪些列表可被 AI 查询、
     * 走 A1 还是独立执行、能不能下筛选条件」。只读、无副作用；失败不影响发现结果。
     */
    protected function refreshListQueryTargets(Output $output): void
    {
        if (!(bool) config('wise-agent.list_query.enabled', false)) {
            return;
        }

        try {
            \wise\agent\query\ListQueryTargetResolver::resetCache();

            $resolver = new \wise\agent\query\ListQueryTargetResolver();
            $executor = new \wise\agent\query\executor\ListQueryExecutorResolver();
            $targets  = $resolver->resolve(true);

            $output->writeln('  <comment>List query targets:</comment> '
                . count($targets) . ' target(s)（执行路径 / 筛选能力）');

            foreach ($targets as $target) {
                $output->writeln(sprintf(
                    '    - %-24s [%s] filter=%s%s',
                    $target->name,
                    $executor->pathOf($target),
                    $target->filterSupport,
                    $target->title !== '' ? '  ' . $target->title : ''
                ));
            }

            foreach ($resolver->trace() as $step) {
                $output->writeln('      · ' . $step['message']);
            }

            $output->writeln('');
        } catch (\Throwable $e) {
            $output->writeln('  <warning>List query target refresh failed: ' . $e->getMessage() . '</warning>');
            $output->writeln('');
        }
    }

    /**
     * A2：输出「表单目标」清单
     *
     * 目标来源与 A1 的菜单动作内省同源（有 `save`/`update` 动作的控制器 ⇒ 有表单），
     * 这里把每个目标**目录的来源与字段数**打印出来，供运维确认「AI 能解释哪些表单、
     * 字段语义是不是真的（defineForm vs 表结构推断）」。只读、无副作用；失败不影响发现结果。
     */
    protected function refreshFormTargets(Output $output): void
    {
        if (!(bool) config('wise-agent.form_schema.enabled', false)) {
            return;
        }

        try {
            \wise\agent\form\FormSchemaIntrospector::resetCache();

            $introspector = new \wise\agent\form\FormSchemaIntrospector();
            $targets      = $introspector->targets(true);

            $output->writeln('  <comment>Form targets:</comment> '
                . count($targets) . ' target(s)（source / 字段数 / 可填数）');

            foreach ($targets as $name => $controller) {
                try {
                    $schema = $introspector->forController($controller);
                } catch (\Throwable $e) {
                    $output->writeln(sprintf('    - %-24s <warning>内省失败：%s</warning>', $name, $e->getMessage()));

                    continue;
                }

                $output->writeln(sprintf(
                    '    - %-24s source=%-11s fields=%-3d fillable=%d%s',
                    $name,
                    $schema->source,
                    count($schema->all()),
                    count($schema->fillableKeys()),
                    $schema->hasNativeSchema() ? '' : '（注意：非表单声明，字段语义有限）'
                ));
            }

            foreach ($introspector->trace() as $step) {
                $output->writeln('      · ' . $step['message']);
            }

            $output->writeln('');
        } catch (\Throwable $e) {
            $output->writeln('  <warning>Form target refresh failed: ' . $e->getMessage() . '</warning>');
            $output->writeln('');
        }
    }

    /**
     * B1：输出「可规划能力目录」概览
     *
     * 规划器能引用什么，完全取决于目录（`PlanCatalogBuilder` 从 Tool/Skill/Node 注册表汇总，
     * 再按 `allowed_kinds` / `deny_capabilities` / `max_catalog_entries` 三重裁剪）。
     * 因此这里打印**各类能力的条目数与隐藏数**，供运维回答两个问题：
     * 「AI 到底能规划哪些能力」与「某项能力为什么不在目录里」（被种类过滤还是被黑名单挡了）。
     * 只读、无副作用；失败不影响发现结果。
     */
    protected function refreshPlanCatalog(Output $output): void
    {
        if (!(bool) config('wise-agent.plan.enabled', false)) {
            return;
        }

        try {
            $catalog = (new \wise\agent\plan\PlanCatalogBuilder())->build();
            $byKind  = [];

            foreach ($catalog->all() as $entry) {
                $byKind[$entry->kind] = ($byKind[$entry->kind] ?? 0) + 1;
            }

            $output->writeln(sprintf(
                '  <comment>Plan catalog:</comment> %d capability(ies)，隐藏 %d（黑名单/条目上限）',
                $catalog->size(),
                $catalog->hiddenCount()
            ));

            foreach (\wise\agent\plan\PlanStep::KINDS as $kind) {
                $count = $byKind[$kind] ?? 0;

                $output->writeln(sprintf(
                    '    - %-7s %d%s',
                    $kind,
                    $count,
                    $count === 0 ? '（该种类无可规划能力，或被 allowed_kinds 过滤）' : ''
                ));
            }

            $writes = array_filter($catalog->all(), static fn($e): bool => $e->write === true);

            $output->writeln(sprintf('    写能力 %d 个；上限：步数 %d / 写步骤 %d / 计划时长 %ds',
                count($writes),
                (int) config('wise-agent.plan.max_steps', 12),
                (int) config('wise-agent.plan.max_write_steps', 5),
                (int) config('wise-agent.plan.max_plan_duration', 180)
            ));
            $output->writeln(sprintf(
                '    写步骤需登录用户上下文：%s（plan.require_user_context_for_writes）',
                (bool) config('wise-agent.plan.require_user_context_for_writes', true) ? '是（默认，fail-closed）' : '否（会显式标注）'
            ));
            $output->writeln('');
        } catch (\Throwable $e) {
            $output->writeln('  <warning>Plan catalog refresh failed: ' . $e->getMessage() . '</warning>');
            $output->writeln('');
        }
    }

    /**
     * B2：输出「业务闭环」概览
     *
     * 闭环最容易「配了却不生效」，因此这里把三件事一次讲清：
     * ① 订阅了哪几个事件；② 配置要求了哪些连接器、**实际装上**了哪些（以及为什么没装上）；
     * ③ 场景路由表（哪个场景会投给谁）。只读、无副作用；失败不影响发现结果。
     */
    protected function refreshClosure(Output $output): void
    {
        if (!(bool) config('wise-agent.closure.enabled', false)) {
            return;
        }

        try {
            $options = (array) config('wise-agent.closure', []);
            $factory = new \wise\agent\closure\ClosureConnectorFactory($options);

            $output->writeln('  <comment>Closure:</comment> 订阅事件 '
                . count(\wise\agent\closure\ClosureListener::events()) . ' 个；已装配连接器 '
                . count($factory->names()) . ' 个');

            $output->writeln('    事件：' . implode('、', array_map(
                static fn(string $c): string => substr($c, strrpos($c, '\\') + 1),
                \wise\agent\closure\ClosureListener::events()
            )));

            $output->writeln('    连接器：' . ($factory->names() === [] ? '（无）' : implode('、', $factory->names())));

            foreach ($factory->unavailable() as $item) {
                $output->writeln(sprintf('      <warning>%s 未装配：%s</warning>', $item['connector'], $item['reason']));
            }

            $router = new \wise\agent\closure\ClosureRouter($options);

            foreach (\wise\agent\closure\ClosureEvent::SCENES as $scene) {
                $probe = new \wise\agent\closure\ClosureEvent(
                    traceId: 'discover',
                    scene: $scene,
                    capabilityKind: $scene === \wise\agent\closure\ClosureEvent::SCENE_AGENT_COMPLETED ? 'agent' : 'tool',
                    capabilityName: 'probe',
                    access: 'write',
                    risk: 'high',
                    write: true
                );

                $explain = $router->explain($probe);

                $output->writeln(sprintf(
                    '    %-19s → %s%s',
                    $scene,
                    $explain['kept'] === [] ? '（不触发）' : implode(' → ', $explain['kept']),
                    $explain['reason'] !== '' ? '（' . $explain['reason'] . '）' : ''
                ));
            }

            $output->writeln(sprintf(
                '    自动续跑计划：%s；轨迹保留参考：%d 天（用 php think wise:agent:closure show <trace_id> 回放）',
                (bool) ($options['auto_resume_plans'] ?? false) ? '开（批准后自动 resume）' : '关（默认，需人工 plan_resume）',
                (int) ($options['retain_days'] ?? 180)
            ));
            $output->writeln('');
        } catch (\Throwable $e) {
            $output->writeln('  <warning>Closure overview failed: ' . $e->getMessage() . '</warning>');
            $output->writeln('');
        }
    }


    protected function outputProviderStats(Output $output, array $stats): void
    {
        $output->writeln('  <comment>Providers:</comment>');
        $output->writeln('    Tool providers:    ' . ($stats['tools'] ?? 0));
        $output->writeln('    Node providers:    ' . ($stats['nodes'] ?? 0));
        $output->writeln('    Adapter providers: ' . ($stats['adapters'] ?? 0));
        $output->writeln('');
    }


    protected function outputSchemaStats(Output $output, array $stats, bool $verbose = false): void
    {
        $schemaPlugins = $stats['schema_plugins'] ?? 0;

        if ($schemaPlugins === 0) {
            $output->writeln('  <comment>Schema Discovery:</comment>');
            $output->writeln('    No plugins with auto_introspect enabled were found.');
            $output->writeln('    Add "auto_introspect": true to your plugin manifest.json.');
            $output->writeln('');
            return;
        }

        $output->writeln('  <comment>Schema Discovery:</comment>');
        $output->writeln("    Plugins scanned:   {$schemaPlugins} plugin(s)");

        $discoveredNamespaces = $this->getDiscovery() ? $this->getDiscovery()->getDiscoveredNamespaces() : [];
        $schemaNamespaces = array_filter($discoveredNamespaces, fn($key) => strpos($key, 'schema:') === 0, ARRAY_FILTER_USE_KEY);

        foreach ($schemaNamespaces as $ns => $info) {
            $pluginIdent = substr($ns, 7);
            $result = PluginSchemaDiscovery::getDiscovered($pluginIdent);
            if ($result === null) continue;

            $summary = $result->getSummary();
            $capTags = implode(', ', $summary['capabilities']);
            $capInfo = $capTags ? " (capabilities: {$capTags})" : '';
            $prefix = '    ├─';

            $output->writeln("{$prefix} <info>{$pluginIdent}</info>");
            $output->writeln("    │  Tables: {$summary['tables']}, Tools: {$summary['total_tools']}" .
                " (auto: {$summary['auto_tools']}, capability: {$summary['capability_tools']})" .
                " | Pipelines: " . ($summary['pipelines'] ?? 0) . $capInfo);

            if ($verbose && !empty($result->toolStats['by_type'])) {
                $output->writeln("    │  <comment>Tool types:</comment>");
                foreach (($result->toolStats['by_type'] ?? []) as $type => $count) {
                    $output->writeln("    │    {$type}: {$count}");
                }
            }

            if ($verbose && !empty($result->capabilities)) {
                $output->writeln("    │  <comment>Capabilities:</comment>");
                foreach ($result->capabilities as $tag => $capResult) {
                    $this->outputCapabilityVerbose($output, $tag, $capResult, "    │   ");
                }
            }

            foreach (($result->errors ?? []) as $error) {
                $output->writeln("    │  <warning>⚠ {$error}</warning>");
            }
        }

        $output->writeln('');
        $output->writeln('  <info>Tip:</info> <comment>php think wise:agent:discover --bootstrap={plugin}</comment>');
        $output->writeln('        to auto-fill descriptions into manifest.json.');
        $output->writeln('  <info>Tip:</info> <comment>php think wise:agent:discover -v</comment> for verbose details.');
        $output->writeln('');
    }

    /**
     * Verbose 输出单个能力的匹配详情
     */
    private function outputCapabilityVerbose(Output $output, string $tag, CapabilityResult $capResult, string $indent): void
    {
        $matchRate = $capResult->getMatchRate();
        $icon = $capResult->hasUnmatchedTables() ? '⚠' : '✓';

        $output->writeln("{$indent}{$icon} {$tag} (match: {$matchRate}, tools: " . count($capResult->tools) . 
            ", pipelines: " . count($capResult->pipelines) . ")");

        foreach ($capResult->unmatchedTables as $role => $candidates) {
            $candidatesStr = implode('/', $candidates);
            $output->writeln("{$indent}   <warning>Missing: {$role} (expected: {$candidatesStr})</warning>");
        }
    }


    protected function outputErrors(Output $output, array $stats): void
    {
        if (empty($stats['errors'])) return;

        $output->writeln('<error>Errors:</error>');
        foreach ($stats['errors'] as $error) {
            $output->writeln('  <error>- ' . $error . '</error>');
        }
        $output->writeln('');
    }


    protected function executeDump(Input $input, Output $output): int
    {
        $dumpPlugin = $input->getOption('dump');
        $dumpMd     = $input->getOption('dump-md');
        $plugin     = $dumpPlugin ?: $dumpMd;

        if (empty($plugin)) {
            $output->writeln('<error>Please specify a plugin ident: --dump=smartstock</error>');
            return 1;
        }

        $output->writeln('');
        $output->writeln("<info>Exporting schema for plugin: {$plugin}</info>");
        $output->writeln('');

        try {
            $schemaDiscovery = new PluginSchemaDiscovery(new SchemaIntrospector());

            if ($dumpMd) {
                $content = $schemaDiscovery->dumpSchemaAsMarkdown($plugin);
                $output->writeln($content);
            } else {
                $json = $schemaDiscovery->dumpSchemaAsJson($plugin, false);
                $data = json_decode($json, true);
                if (!is_array($data) || isset($data['error'])) {
                    $output->writeln("<error>Error:</error> {$data['error']}");
                    return 1;
                }
                $output->writeln('<comment># Copy into manifest.json → ai_schema.table_descriptions:</comment>');
                $output->writeln('');
                $output->writeln($json);
                $output->writeln('');
            }

            $output->writeln('<info>Export complete.</info>');
            $output->writeln('');
            $output->writeln('Next steps:');
            $output->writeln('  1. Review and edit comments');
            $output->writeln('  2. Copy to manifest.json → ai_schema.table_descriptions');
            $output->writeln('  3. Run <comment>php think wise:agent:discover --force</comment>');
            $output->writeln('');
        } catch (\Throwable $e) {
            $output->writeln('<error>Failed:</error> ' . $e->getMessage());
            return 1;
        }
        return 0;
    }


    protected function executeBootstrap(Input $input, Output $output): int
    {
        $plugin = $input->getOption('bootstrap');

        if (empty($plugin)) {
            $output->writeln('<error>Please specify a plugin ident: --bootstrap=smartstock</error>');
            return 1;
        }

        $output->writeln('');
        $output->writeln("<info>Bootstrapping ai_schema for plugin: {$plugin}</info>");
        $output->writeln('');

        try {
            $schemaDiscovery = new PluginSchemaDiscovery(new SchemaIntrospector());

            $manifestFile = $this->getPluginManifestPath($plugin);

            $manifest = [];
            if (file_exists($manifestFile)) {
                $content = file_get_contents($manifestFile);
                $manifest = json_decode($content, true) ?: [];
            }

            $aiSchema = $this->generateAiSchemaSuggestion($schemaDiscovery, $plugin, $manifest);

            $existingAiSchema = $manifest['ai_schema'] ?? [];
            $manifest['ai_schema'] = array_merge($existingAiSchema, $aiSchema);

            $backup = $manifestFile . '.bak.' . date('Ymd');
            copy($manifestFile, $backup);
            
            $json = json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            file_put_contents($manifestFile, $json);

            $output->writeln("<info>✓ manifest.json updated</info> (backup: {$backup})");
            $output->writeln('');

            $output->writeln('<comment>Written ai_schema:</comment>');
            $output->writeln(json_encode($aiSchema, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            $output->writeln('');
            $output->writeln('Next steps:');
            $output->writeln('  1. Edit manifest.json — refine descriptions, uncomment writable_fields');
            $output->writeln('  2. Run <comment>php think wise:agent:discover --force</comment>');
            $output->writeln('');
        } catch (\Throwable $e) {
            $output->writeln('<error>Bootstrap failed:</error> ' . $e->getMessage());
            return 1;
        }
        return 0;
    }


    protected function executeMaterialize(Input $input, Output $output): int
    {
        $plugin = $input->getOption('materialize');
        $force  = $input->getOption('force');

        if (empty($plugin)) {
            $output->writeln('<error>Please specify a plugin ident: --materialize=smartstock</error>');
            return 1;
        }

        $output->writeln('');
        $output->writeln("<info>Materializing AI tools for plugin: {$plugin}</info>");
        $output->writeln('');

        try {
            $materializer = new ToolMaterializer($plugin);
            $stats = $materializer->materialize($force);

            if (!empty($stats['errors'])) {
                $output->writeln('<error>Errors:</error>');
                foreach ($stats['errors'] as $error) {
                    $output->writeln("  <error>- {$error}</error>");
                }
                return 1;
            }

            $output->writeln('<info>Materialization completed!</info>');
            $output->writeln('');
            $output->writeln("  <comment>Files generated:</comment> {$stats['files_generated']}");
            $output->writeln("  <comment>Tools materialized:</comment> {$stats['tools_generated']}");
            $output->writeln("  <comment>Tables processed:</comment> {$stats['tables_processed']}");
            $output->writeln("  <comment>SchemaToolProvider:</comment> " . ($stats['provider'] ? 'yes' : 'no'));
            $output->writeln("  <comment>Manifest updated:</comment> " . ($stats['manifest_updated'] ? 'yes' : 'no'));
            $output->writeln('');

            $output->writeln('<info>Generated files in:</info>');
            $output->writeln('  plugin/' . $plugin . '/ai/tool/');
            $output->writeln('');
            $output->writeln('<comment>Next steps:</comment>');
            $output->writeln('  1. Review generated tool classes in plugin/' . $plugin . '/ai/tool/');
            $output->writeln('  2. Customize tool descriptions and execution logic as needed');
            $output->writeln('  3. Run <comment>php think wise:agent:discover --force</comment> to re-register');
            $output->writeln('  4. The plugin now loads tools from materialized files, no runtime introspection needed.');
            $output->writeln('');
        } catch (\Throwable $e) {
            $output->writeln('<error>Materialization failed:</error> ' . $e->getMessage());
            return 1;
        }
        return 0;
    }


    protected function executeMaterializeNode(Input $input, Output $output): int
    {
        $plugin = $input->getOption('materialize-node');
        $force  = $input->getOption('force');

        if (empty($plugin)) {
            $output->writeln('<error>Please specify a plugin ident: --materialize-node=smartstock</error>');
            return 1;
        }

        $output->writeln('');
        $output->writeln("<info>Materializing workflow nodes for plugin: {$plugin}</info>");
        $output->writeln('');

        try {
            $materializer = new NodeMaterializer($plugin);
            $stats = $materializer->materialize($force);

            if (!empty($stats['errors'])) {
                $output->writeln('<error>Errors:</error>');
                foreach ($stats['errors'] as $error) {
                    $output->writeln("  <error>- {$error}</error>");
                }
                return 1;
            }

            $output->writeln('<info>Node materialization completed!</info>');
            $output->writeln('');
            $output->writeln("  <comment>Nodes generated:</comment> {$stats['nodes_generated']}");
            $output->writeln("  <comment>Tables processed:</comment> {$stats['tables_processed']}");
            $output->writeln('');
            $output->writeln('<info>Generated files in:</info>');
            $output->writeln('  plugin/' . $plugin . '/ai/node/');
            $output->writeln('');
            $output->writeln('<comment>Next steps:</comment>');
            $output->writeln('  1. Review generated node classes in plugin/' . $plugin . '/ai/node/');
            $output->writeln('  2. Register nodes in manifest.json ai_providers.node');
            $output->writeln('  3. Nodes are now available in workflow designer.');
            $output->writeln('');
        } catch (\Throwable $e) {
            $output->writeln('<error>Node materialization failed:</error> ' . $e->getMessage());
            return 1;
        }
        return 0;
    }

    /**
     * 获取插件 manifest.json 路径
     */
    private function getPluginManifestPath(string $pluginIdent): string
    {
        return $this->app->getRootPath() . 'plugin' . DIRECTORY_SEPARATOR . $pluginIdent . DIRECTORY_SEPARATOR . 'manifest.json';
    }

    /**
     * 生成 ai_schema 建议配置
     */
    private function generateAiSchemaSuggestion(PluginSchemaDiscovery $discovery, string $plugin, array $manifest): array
    {
        $schema_ = [];

        $existingAi = $manifest['ai_schema'] ?? [];
        $schema_['auto_introspect'] = $existingAi['auto_introspect'] ?? true;

        $dumped = $discovery->dumpSchemaForEditing($plugin, false);
        if (!isset($dumped['error']) && !empty($dumped)) {
            $schema_['table_descriptions'] = $dumped;
        }

        $matchedCaps = $this->suggestCapabilities($plugin);
        if (!empty($matchedCaps)) {
            $schema_['business_capabilities'] = $matchedCaps;
        }

        $writableFields = $this->suggestWritableFields($discovery, $plugin);
        if (!empty($writableFields)) {
            $schema_['_writable_fields_suggestion'] = [
                '__comment' => 'Review and uncomment writable fields, then rename key to writable_fields',
            ];
            foreach ($writableFields as $table => $fields) {
                $schema_['_writable_fields_suggestion'][$table] = $fields;
            }
        }

        return $schema_;
    }

    /**
     * 建议匹配的业务能力标签
     */
    private function suggestCapabilities(string $pluginIdent): array
    {
        $discovery = new PluginSchemaDiscovery(new SchemaIntrospector());
        $schema = null;

        try {
            $schema = $discovery->dumpSchemaForEditing($pluginIdent, true);
        } catch (\Throwable $e) {
            return [];
        }

        if (isset($schema['error']) || empty($schema)) {
            return [];
        }

        $tableNames = array_keys($schema);
        $suggested = [];

        if ($this->tableSetContains($tableNames, ['product', 'stock'])) {
            $suggested[] = 'inventory';
        }

        if ($this->tableSetContains($tableNames, ['order']) || $this->tableSetContains($tableNames, ['sale'])) {
            $suggested[] = 'sales';
        }

        if ($this->tableSetContains($tableNames, ['customer', 'user', 'member', 'client'])) {
            $suggested[] = 'crm';
        }

        if ($this->tableSetContains($tableNames, ['article', 'post', 'news', 'content'])) {
            $suggested[] = 'content';
        }

        return $suggested;
    }

    /**
     * 检查表名集合是否包含任一候选
     */
    private function tableSetContains(array $tableNames, array $candidates): bool
    {
        foreach ($tableNames as $name) {
            foreach ($candidates as $c) {
                if (false !== strpos($name, $c)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * 生成 writable_fields 建议（所有非系统字段）
     */
    private function suggestWritableFields(PluginSchemaDiscovery $discovery, string $plugin): array
    {
        $dumped = $discovery->dumpSchemaForEditing($plugin, false);
        if (isset($dumped['error']) || empty($dumped)) {
            return [];
        }

        $writable = [];
        $systemFields = ['id', 'create_time', 'update_time', 'delete_time', 'created_at', 'updated_at', 'deleted_at'];

        foreach ($dumped as $tableName => $info) {
            $fields = array_keys($info['fields'] ?? []);
            $fields = array_diff($fields, $systemFields);
            if (!empty($fields)) {
                $writable[$tableName] = $fields;
            }
        }

        return $writable;
    }


    protected $discoveryInstance;

    protected function setDiscovery(PluginAiDiscovery $discovery): void { $this->discoveryInstance = $discovery; }
    protected function getDiscovery(): ?PluginAiDiscovery { return $this->discoveryInstance; }
}
