<?php
declare(strict_types=1);

namespace wise\agent\skill\contract;

/**
 * AI 技能接口
 *
 * 每个 Skill 定义了一个标准化的 AI 任务模板，封装了 system prompt、
 * 输入参数 schema、输出格式和关联工具，供插件和 Pipeline 复用。
 *
 * 与 Tool 的区别：
 * - Tool 是给 LLM 用的原子操作（Function Calling）
 * - Skill 是封装了 prompt + tools + 参数的完整 AI 任务模板
 */
interface SkillInterface
{
    /**
     * 获取技能唯一标识
     *
     * @return string 如 'content.article_generate'
     */
    public function getName(): string;

    /**
     * 获取技能描述（用于 UI 展示和 Skill 列表）
     */
    public function getDescription(): string;

    /**
     * 获取技能分类
     *
     * @return string 'content' | 'data' | 'code' | 'business'
     */
    public function getCategory(): string;

    /**
     * 获取输入参数 JSON Schema
     *
     * 定义技能所需的输入参数结构，遵循 JSON Schema 规范。
     * 参数值在 execute 时通过 $input 传入，并替换 system prompt 中的占位符。
     *
     * @return array JSON Schema
     */
    public function getInputSchema(): array;

    /**
     * 获取系统提示词
     *
     * 支持占位符变量：{param_name}，执行时从 $input 中替换。
     *
     * @return string
     */
    public function getSystemPrompt(): string;

    /**
     * 获取输出格式
     *
     * @return string 'text' | 'json' | 'markdown'
     */
    public function getOutputFormat(): string;

    /**
     * 获取默认关联工具名称列表
     *
     * @return string[]
     */
    public function getDefaultTools(): array;

    /**
     * 获取默认执行选项
     *
     * @return array ['temperature' => 0.7, 'max_tokens' => 4096, ...]
     */
    public function getDefaultOptions(): array;

    /**
     * 获取技能来源标识
     *
     * @return string 'builtin' | 'plugin:cms' | 'plugin:smartstock' | ...
     */
    public function getSource(): string;

    /**
     * 渲染 system prompt（替换占位符变量）
     *
     * @param array $input 输入参数
     * @return string 渲染后的 system prompt
     */
    public function renderSystemPrompt(array $input = []): string;

    /**
     * 构建用户消息（将 input 转为发送给 LLM 的消息文本）
     *
     * @param array $input 输入参数
     * @return string
     */
    public function buildUserMessage(array $input): string;

    /**
     * 校验输入参数是否符合 InputSchema
     *
     * @param array $input
     * @return array ['valid' => bool, 'errors' => string[]]
     */
    public function validateInput(array $input): array;

    /**
     * 获取权限标识
     *
     * 约定为 `ai.skill.{name}`（如 ai.skill.content.translate）。
     * Skill 是编排入口，必须与工具同等受控——否则「能用 orchestrate_step
     * 就能执行任意 Skill」会成为权限绕过通道。
     *
     * @see \wise\agent\security\CapabilityAccess::SKILL_PREFIX
     */
    public function getPermission(): string;

    /**
     * 访问类型（权限分类：read | write | execute | admin）
     *
     * 决定权限树中的归类与「类别通配」授权（如「全部只读能力」ai.access.read.*）。
     * 只产出文本、不改数据的能力用 read；改写文件 / 数据的用 write；
     * 编排类或语义不明确的保守返回 execute。
     *
     * @see \wise\agent\security\CapabilityAccess
     */
    public function getAccess(): string;

    /**
     * 风险等级（low | medium | high）
     *
     * 与访问类型正交，用于审批策略：高危能力可被策略要求额外确认或走审批流。
     *
     * @see \wise\agent\security\ApprovalPolicy
     */
    public function getRiskLevel(): string;
}
