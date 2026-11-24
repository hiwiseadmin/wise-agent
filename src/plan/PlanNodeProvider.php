<?php
declare(strict_types=1);

namespace wise\agent\plan;

use wise\agent\contract\NodeProviderInterface;
use wise\agent\contract\WorkflowContext;

/**
 * 计划物化外壳的节点提供者＼�
 *
 * 物化产物里的每个步骤都是 `plan:step` 节点，由本类承接；它**不重写分发逻辑**，
 * 而是调 {@see PlanExecutor::runStep()}（同一条实现，见 `PlanShell` 的设计更正说明）。
 *
 * ## 入参
 *
 * `$input` 即 `PlanShell::KEYS`：`id / kind / target / label / input`
 * （`$input.x` / `$steps.x` 已由 `PipelineEngine::resolveInputMapping()` 解析完毕）。
 *
 * ## 输出
 *
 * 统一出口 `['data' => mixed, 'error' => null|string]`：
 * - 入参非法 ⇒ `error` 非空（**不抛异常**，让流水线按 `on_error` 策略处置）；
 * - 执行失败 ⇒ 同样转成 `error`（节点契约不允许抛）。
 *
 * ## 运行前提
 *
 * 只有 `wise-agent.plan.enabled = true`（即本 Provider 被注册）时，
 * `PipelineEngine::isPluginNode('plan:step')` 才为 true；否则该步骤会被当成「skill 名」而找不到 skill。
 */
class PlanNodeProvider implements NodeProviderInterface
{
    private ?PlanExecutor $executor = null;

    public function __construct(?PlanExecutor $executor = null)
    {
        $this->executor = $executor;
    }

    public function getNamespace(): string
    {
        return PlanShell::NAMESPACE;
    }

    /**
     * 节点定义（供工作流设计器展示；这里只暴露一个步骤外壳）
     *
     * @return array<int, array<string, mixed>>
     */
    public function getNodes(): array
    {
        return [[
            'type'          => PlanShell::NODE_TYPE,
            'label'         => '执行计划步骤',
            'description'   => '按 kind/target 调用既有能力（skill / tool / action / node）——由 plan-materialize 生成，通常无需手工配置',
            'group'         => 'ai',
            'config_schema' => [
                'type'       => 'object',
                'properties' => [
                    'kind'   => ['type' => 'string', 'enum' => PlanStep::KINDS, 'description' => '步骤种类'],
                    'target' => ['type' => 'string', 'description' => '被引用能力名'],
                    'input'  => ['type' => 'object', 'description' => '参数映射（引用由 PipelineEngine 解析）'],
                ],
                'required'   => ['kind', 'target'],
            ],
        ]];
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $input
     */
    public function run(string $nodeType, array $config, array $input, WorkflowContext $context): array
    {
        if ($nodeType !== PlanShell::NODE_TYPE) {
            return ['data' => null, 'error' => '不支持的节点类型：' . $nodeType];
        }

        $problems = PlanShell::problemsOf($input);

        if ($problems !== []) {
            return ['data' => null, 'error' => '计划步骤入参非法：' . implode('；', $problems)];
        }

        $step = PlanStep::fromShellInput($input);

        $stepsOutput = $context->getVariable('steps', []);

        try {
            $data = $this->executor()->runStep(
                $step,
                is_array($input['input'] ?? null) ? $input['input'] : [],
                is_array($stepsOutput) ? $stepsOutput : [],
                $context->getStepIndex()
            );
        } catch (\Throwable $e) {
            return ['data' => null, 'error' => $e->getMessage()];
        }

        return ['data' => $data, 'error' => null];
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return [
            'label'       => 'AI 计划步骤',
            'description' => '把一次 AI 计划物化为可重复触发的流水线（步骤外壳）',
            'version'     => '1.0.0',
        ];
    }

    protected function executor(): PlanExecutor
    {
        return $this->executor ??= new PlanExecutor();
    }
}
