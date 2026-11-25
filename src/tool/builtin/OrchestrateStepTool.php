<?php
declare(strict_types=1);

namespace wise\agent\tool\builtin;

use wise\agent\security\CapabilityAccess;
use wise\agent\skill\SkillManager;
use wise\agent\skill\SkillRegistry;
use wise\agent\tool\BaseTool;

/**
 * 编排步骤工具
 *
 * 允许 Orchestrator Agent 在 Run Loop 中动态调用已注册的 Skill。
 * 每个 Skill 作为一个"虚拟工具"暴露给 LLM，LLM 可以依次调用来完成复杂任务。
 *
 * 权限: ai.tool.orchestrate_step
 * 确认: 否（编排 Agent 内部使用，不面向最终用户）
 */
class OrchestrateStepTool extends BaseTool
{
    protected ?SkillRegistry $registry = null;
    protected ?SkillManager $manager = null;

    public function getName(): string
    {
        return 'orchestrate_step';
    }

    public function getDescription(): string
    {
        return "执行一个已注册的 AI Skill。你需要按顺序调用不同的 Skill 来完成任务。"
            . "先分析任务需求，再选择合适的 Skill，逐步执行。"
            . "每步完成后根据结果决定下一步。"
            . "所有步骤完成后总结最终结果。";
    }

    public function getParameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'skill_name' => [
                    'type'        => 'string',
                    'description' => '要执行的 Skill 名称（如 content.title_generate、content.article_generate 等）。可用 Skill 列表见系统提示。',
                ],
                'input' => [
                    'type'        => 'object',
                    'description' => 'Skill 的输入参数。每个 Skill 有不同的必填参数，请根据 Skill 描述提供正确的参数。',
                ],
                'reason' => [
                    'type'        => 'string',
                    'description' => '为什么选择这个 Skill？简要说明当前步骤的目的。',
                ],
            ],
            'required' => ['skill_name', 'input'],
        ];
    }

    public function execute(array $arguments): string
    {
        $skillName = $arguments['skill_name'] ?? '';
        $input = $arguments['input'] ?? [];
        $reason = $arguments['reason'] ?? '';

        if (empty($skillName)) {
            return json_encode(['error' => '缺少 skill_name 参数'], JSON_UNESCAPED_UNICODE);
        }

        if ($this->manager === null) {
            try {
                $this->manager = app()->make(SkillManager::class);
            } catch (\Throwable $e) {
                return json_encode(['error' => 'SkillManager 不可用: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
            }
        }

        try {
            $result = $this->manager->execute($skillName, $input);

            $response = [
                'skill'    => $skillName,
                'output'   => $result->getOutput(),
                'duration' => $result->getDuration(),
                'step_reason' => $reason,
            ];

            return json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        } catch (\Throwable $e) {
            return json_encode([
                'skill'  => $skillName,
                'error'  => $e->getMessage(),
                'hint'   => 'Skill 执行失败，请检查参数或选择其他 Skill',
            ], JSON_UNESCAPED_UNICODE);
        }
    }

    public function getPermission(): string
    {
        return 'ai.tool.orchestrate_step';
    }

    public function requireConfirmation(): bool
    {
        return false;
    }

    public function getAccess(): string
    {
        return CapabilityAccess::EXECUTE;
    }

    public function getRiskLevel(): string
    {
        return CapabilityAccess::RISK_MEDIUM;
    }
}
