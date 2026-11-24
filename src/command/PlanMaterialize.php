<?php
declare(strict_types=1);

namespace wise\agent\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use wise\agent\plan\PlanDefinition;
use wise\agent\plan\PlanRun;
use wise\agent\plan\PlanShell;
use wise\agent\plan\store\DbPlanRunStore;

/**
 * `wise:agent:plan-materialize` —— 把一次成功的计划物化成可重复触发的流水线＼�
 *
 * ## 它到底做什么（以及刻意不做什么）
 *
 * - **做**：把一个 `completed` 的计划转成 **Pipeline 定义**（每步都是 `plan:step` 节点，
 *   引用原样保留），并把定义打印出来（`--format=json|php`）；
 * - **不做**：不写文件、不落库、不改 `PipelineEngine`。
 *   `PipelineRegistry` 只有内存/声明式两种来源（`PipelineRegistry::registerDefinition()`），
 *   所以「让流水线长期可用」的正道是**把打印出的定义贴进配置/manifest** ——
 *   本命令的输出就是为这件事准备的（与 `form_generate` 只出草案同理）。
 *
 * ## 运行前提（必须告知使用者）
 *
 * 物化产物引用 `plan:step`，运行它的进程必须**已注册 `PlanNodeProvider`**，
 * 即 `wise-agent.plan.enabled = true`；否则 `PipelineEngine::isPluginNode()` 为 false，
 * 该步会被当成「skill 名」而找不到 skill。命令输出里会带上这条前提。
 *
 * ## 只接受已完成的运行
 *
 * 未完成的计划（`paused`/`aborted`）物化出来的流水线语义不完整，因此默认拒绝；
 * `--force` 可强行物化（会打印明确警告）。
 */
class PlanMaterialize extends Command
{
    protected function configure(): void
    {
        $this->setName('wise:agent:plan-materialize')
            ->addArgument('run_id', \think\console\input\Argument::REQUIRED, '计划运行 id（plan_run 的返回值）')
            ->addOption('name', null, Option::VALUE_OPTIONAL, '流水线名称（默认 plan_{run_id}）')
            ->addOption('format', null, Option::VALUE_OPTIONAL, '输出格式：json | php（默认 json）')
            ->addOption('json', null, Option::VALUE_NONE, '严格 JSON 输出（stdout 只有 JSON，供 AI / 脚本消费）')
            ->addOption('force', null, Option::VALUE_NONE, '允许物化未完成的运行（打印警告）')
            ->setDescription('把一次计划物化为可重复触发的 Pipeline 定义（输出到 stdout，不写文件）');
    }

    protected function execute(Input $input, Output $output): int
    {
        $runId    = (int) $input->getArgument('run_id');
        $force    = (bool) $input->getOption('force');
        $strict   = (bool) $input->getOption('json');
        $format   = $strict ? 'json' : strtolower(trim((string) ($input->getOption('format') ?: 'json')));
        $warnings = [];

        if ($runId <= 0) {
            return $this->fail($output, 'run_id 必须是正整数', $strict);
        }

        $store = new DbPlanRunStore();
        $run   = $store->findRun($runId);

        if ($run === null) {
            return $this->fail($output, sprintf('未找到计划运行 #%d', $runId), $strict);
        }

        if ($run->status !== PlanRun::STATUS_COMPLETED && !$force) {
            return $this->fail(
                $output,
                sprintf('运行 #%d 的状态是 %s，不是 completed；只物化已完成的计划（确需物化请加 --force）', $runId, $run->status),
                $strict,
                ['run_id' => $runId, 'status' => $run->status]
            );
        }

        if ($run->status !== PlanRun::STATUS_COMPLETED) {
            $warnings[] = sprintf('运行 #%d 状态为 %s，物化产物可能不完整（--force）', $runId, $run->status);

            if (!$strict) {
                $output->writeln('<warning>' . end($warnings) . '</warning>');
            }
        }

        $name       = trim((string) ($input->getOption('name') ?: '')) ?: 'plan_' . $runId;
        $definition = self::buildDefinition($run, $name);

        if ($strict) {
            $output->writeln((string) json_encode([
                'ok'            => true,
                'run_id'        => $runId,
                'name'          => $name,
                'status'        => $run->status,
                'definition'    => $definition,
                'warnings'      => $warnings,
                'prerequisites' => [
                    'node'    => PlanShell::NODE,
                    'require' => '运行进程必须已注册 PlanNodeProvider（wise-agent.plan.enabled = true）',
                ],
                'note'          => '本命令只输出定义，不写文件、不落库（PipelineRegistry 无 DB 来源）。',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), Output::OUTPUT_RAW);

            return 0;
        }

        $output->writeln(sprintf('<info>计划 #%d → 流水线「%s」</info>', $runId, $name));
        $output->writeln(sprintf('目标：%s', $run->goal));
        $output->writeln(sprintf('步骤：%d 步（全部为 %s 节点）', count($definition['steps']), PlanShell::NODE));
        $output->writeln('');

        if ($format === 'php') {
            $output->writeln('// 贴进配置/manifest：');
            $output->writeln("PipelineRegistry::registerDefinition('{$name}', " . $this->toPhp($definition) . ');');
        } else {
            $output->writeln((string) json_encode($definition, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        }

        $output->writeln('');
        $output->writeln('<comment>运行前提</comment>：该定义的每个步骤都是 <comment>' . PlanShell::NODE
            . '</comment> 节点，运行它的进程必须已注册 PlanNodeProvider'
            . '（即 wise-agent.plan.enabled = true），否则引擎会把步骤当成 skill 名而找不到。');
        $output->writeln('本命令只输出定义，不写文件、不落库（PipelineRegistry 无 DB 来源）。');

        return 0;
    }

    /**
     * 运行 → Pipeline 定义（**纯计算**，无 IO）
     *
     * 抽成静态方法是为了可测：命令本体依赖 think 的 Input/Output，
     * 而「物化产物长什么样」才是要长期锁定的契约。
     *
     * @return array<string, mixed>
     */
    public static function buildDefinition(PlanRun $run, string $name): array
    {
        return [
            'name'        => $name,
            'description' => sprintf('由计划 #%d 物化：%s', $run->runId, $run->goal),
            'source'      => 'plan:' . $run->runId,
            'triggers'    => ['manual'],
            'steps'       => PlanDefinition::fromArray($run->plan)->toPipelineSteps(),
        ];
    }

    /**
     * 把定义渲染成可粘贴的 PHP 字面量（缩进清晰、字符串转义安全）
     */
    protected function toPhp(mixed $value, int $depth = 0): string
    {
        if (is_array($value)) {
            $isList = array_is_list($value);
            $pad    = str_repeat('    ', $depth + 1);
            $lines  = [];

            foreach ($value as $key => $item) {
                $prefix = $isList ? '' : var_export($key, true) . ' => ';
                $lines[] = $pad . $prefix . $this->toPhp($item, $depth + 1) . ',';
            }

            return "[\n" . implode("\n", $lines) . "\n" . str_repeat('    ', $depth) . ']';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null) {
            return 'null';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return var_export((string) $value, true);
    }

    /**
     * 失败输出：`--json` 下也必须是可解析 JSON，避免 AI 拿到半截文本。
     *
     * @param array<string, mixed> $extra
     */
    protected function fail(Output $output, string $message, bool $strict, array $extra = []): int
    {
        if ($strict) {
            $output->writeln((string) json_encode(
                array_merge(['ok' => false, 'error' => $message], $extra),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ), Output::OUTPUT_RAW);
        } else {
            $output->writeln('<error>' . $message . '</error>');
        }

        return 1;
    }
}
