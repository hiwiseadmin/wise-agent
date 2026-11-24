<?php
declare(strict_types=1);

namespace wise\agent\skill\builtin;

use wise\agent\skill\BaseSkill;
use wise\agent\skill\SkillRegistry;

/**
 * 编排 Agent Skill
 *
 * 接受自然语言复杂任务，自动分析 → 拆解子步骤 → 匹配 Skill → 逐步执行 → 汇总结果。
 *
 * 内部使用 Agent Run Loop + Function Calling 机制：
 * - orchestrate_step 工具可调用任何已注册的 Skill
 * - LLM 自主决定调用顺序和参数
 * - 多轮循环直到任务完成
 *
 * 分类: system
 */
class OrchestrateSkill extends BaseSkill
{
    public function getName(): string
    {
        return 'system.orchestrate';
    }

    public function getDescription(): string
    {
        return '智能任务编排：自动分析复杂任务 → 拆解子步骤 → 匹配并调用 Skill → 汇总结果';
    }

    public function getCategory(): string
    {
        return 'system';
    }

    public function getInputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'task' => [
                    'type'        => 'string',
                    'description' => '复杂任务描述（自然语言），如："写一篇AI趋势文章并做SEO优化"',
                ],
                'constraints' => [
                    'type'        => 'string',
                    'description' => '额外约束条件（可选），如："语气专业、2000字、关键词包含AI和大模型"',
                ],
            ],
            'required' => ['task'],
        ];
    }

    public function getSystemPrompt(): string
    {
        $availableSkills = $this->buildAvailableSkillsList();

        return <<<PROMPT
你是一个智能任务编排专家，擅长将复杂任务拆解为子步骤并依次执行。

## 工作流程
1. **分析任务**：理解用户意图，识别核心需求
2. **拆解步骤**：将复杂任务分解为清晰的子任务序列
3. **匹配 Skill**：为每步选择最合适的可用 Skill
4. **逐步执行**：使用 orchestrate_step 工具依次执行
5. **汇总结果**：整合所有步骤的输出，给用户清晰完整的答复

## 可用 Skill 列表
{$availableSkills}

## 执行原则
- 先充分分析任务，再开始执行
- 按逻辑顺序执行：输入型 Skill 在前（标题→文章），优化型 Skill 在后（去痕→SEO）
- 每步的输出可能是下一步的输入，注意参数传递
- 如果某一步失败，分析原因并尝试调整参数重试，或跳过该步骤
- 执行完所有相关步骤后，用自然语言总结最终结果
- 不要编造 Skill 名称，只使用上面列表中列出的 Skill

## 额外约束
{constraints}

## 用户任务
{task}

现在开始分析并执行这个任务。
PROMPT;
    }

    public function getOutputFormat(): string
    {
        return 'text';
    }

    public function getDefaultTools(): array
    {
        return ['orchestrate_step'];
    }

    public function getDefaultOptions(): array
    {
        return [
            'temperature' => 0.3,
            'max_tokens'  => 4096,
        ];
    }

    public function getSource(): string
    {
        return 'builtin';
    }

    /**
     * 动态构建可用 Skill 列表
     */
    protected function buildAvailableSkillsList(): string
    {
        try {
            $registry = app()->make(SkillRegistry::class);
            $overview = $registry->getOverview();

            $lines = [];
            foreach ($overview as $category => $skills) {
                if ($category === 'system') {
                    continue;
                }

                $lines[] = "### {$this->categoryLabel($category)}";
                foreach ($skills as $skill) {
                    $name = $skill['name'] ?? '';
                    $desc = $skill['description'] ?? '';
                    if ($name !== 'system.orchestrate') {
                        $lines[] = "- **{$name}**: {$desc}";
                    }
                }
            }

            return implode("\n", $lines);
        } catch (\Throwable $e) {
            return '(Skill 列表暂时不可用，请使用已知的通用 Skill)';
        }
    }

    protected function categoryLabel(string $category): string
    {
        return match ($category) {
            'content'  => '内容创作类',
            'data'     => '数据分析类',
            'code'     => '代码类',
            'business' => '业务类',
            default    => $category,
        };
    }

    /**
     * 重写：直接使用 task 作为用户消息
     */
    public function buildUserMessage(array $input): string
    {
        $task = $input['task'] ?? '';
        $constraints = $input['constraints'] ?? '';

        $parts = [];
        if (!empty($constraints)) {
            $parts[] = "约束条件: {$constraints}";
        }
        $parts[] = "任务: {$task}";

        return implode("\n\n", $parts);
    }

    /**
     * 重写：渲染 system prompt 时替换占位符
     */
    public function renderSystemPrompt(array $input = []): string
    {
        $prompt = parent::renderSystemPrompt($input);

        $prompt = str_replace('{constraints}', $input['constraints'] ?? '无', $prompt);
        $prompt = str_replace('{task}', $input['task'] ?? '', $prompt);

        return $prompt;
    }
}
