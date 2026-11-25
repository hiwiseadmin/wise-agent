<?php
declare(strict_types=1);

namespace wise\agent\plan;

/**
 * 计划物化外壳＼�
 *
 * ## 为什么需要它
 *
 * `PipelineStep` 只认「skill 名」与「插件节点」（`src/pipeline/PipelineEngine.php:117-122`），
 * 而自治计划里的步骤天然有四种（skill / tool / action / node）。为了让
 * 「一次成功的计划」能转成**可重复触发**的普通 Pipeline，而又**不改动 PipelineEngine**，
 * 就用一个外壳把每个计划步骤包成**一个流水线步骤**。
 *
 * ## ⚠️ 设计更正（B1-4 实测）：外壳是**节点**，不是 skill
 *
 * `SkillManager::execute()` **永远经过 LLM**（`createAgentForSkill()` + `AgentManager::run()`，
 * 见 `src/skill/SkillManager.php:94-102`），而物化外壳必须是**确定性**的
 * ——它只是把入参再分发一次，绝不能在里面再调一次模型。
 *
 * 改用**插件节点**（`NodeProviderInterface`）：
 *
 * - 节点执行是确定性的（`NodeRegistry::runNode()` 直接调 `provider->run()`，无 LLM）；
 * - `PipelineEngine::isPluginNode()` 判定「含 `:` 且命名空间已注册」，
 *   因此物化产物只需把 `skill` 字段填成 `plan:step`，**引擎一行都不用改**；
 * - 执行体仍复用 `PlanExecutor` 的同一条分发逻辑（{@see PlanExecutor::runStep()}），不是第二套实现。
 *
 * ```
 * PlanDefinition ──toPipelineSteps()──▶ [
 *     ['skill' => 'plan:step', 'input_mapping' => ['kind'=>'tool','target'=>'…','input'=>[…]], 'output_key' => 's1'],
 *     …
 * ]
 * ```
 *
 * ## 外壳契约（节点入参）
 *
 * | 键 | 必填 | 说明 |
 * |---|---|---|
 * | `kind` | ✅ | `skill` / `tool` / `action` / `node` |
 * | `target` | ✅ | 被引用能力名（必须真实存在） |
 * | `input` | — | 参数映射；`$input.x` / `$steps.x` 由 `PipelineEngine` 解析后才进到这里 |
 * | `id` / `label` | — | 轨迹与审计用 |
 *
 * ## 运行前提（必须如实告知使用者）
 *
 * 物化产物引用 `plan:step`，因此运行它的进程**必须已注册 `PlanNodeProvider`**
 * ——即 `wise-agent.plan.enabled = true`（见 `AgentServiceProvider`）。
 * 未启用时 `PipelineEngine::isPluginNode()` 返回 false，该步会被当成「skill 名」而找不到 skill
 * ⇒ 报错。`plan-materialize` 命令的输出里会打印这条前提。
 */
final class PlanShell
{
    /**
     * 外壳**节点**类型（`命名空间:类型`）
     *
     * `PipelineStep` 的字段名仍是 `skill`，但值含 `:` 且命名空间 `plan` 已注册，
     * 于是被 `PipelineEngine` 当作插件节点执行（引擎零改动）。
     */
    public const NODE = 'plan:step';

    /** 节点命名空间 */
    public const NAMESPACE = 'plan';

    /** 节点类型（不含命名空间） */
    public const NODE_TYPE = 'step';

    /**
     * 外壳入参键
     *
     * @var string[]
     */
    public const KEYS = ['id', 'kind', 'target', 'label', 'input'];

    /**
     * 该 Pipeline 步骤定义是否是一个「计划外壳步骤」
     *
     * @param array<string, mixed> $definition
     */
    public static function isShellStep(array $definition): bool
    {
        $skill = (string) ($definition['skill'] ?? $definition['skillName'] ?? '');

        return $skill === self::NODE;
    }

    /**
     * 校验外壳入参（物化前后的一致性检查；不执行任何能力）
     *
     * @param array<string, mixed> $input
     * @return string[] 问题清单（空数组 = 合法）
     */
    public static function problemsOf(array $input): array
    {
        $problems = [];

        $kind = strtolower(trim((string) ($input['kind'] ?? '')));

        if (!in_array($kind, PlanStep::KINDS, true)) {
            $problems[] = sprintf('kind 必须是 %s 之一，收到 `%s`', implode('/', PlanStep::KINDS), $kind);
        }

        if (trim((string) ($input['target'] ?? '')) === '') {
            $problems[] = '缺少 target';
        }

        if (isset($input['input']) && !is_array($input['input'])) {
            $problems[] = 'input 必须是对象';
        }

        return $problems;
    }
}
