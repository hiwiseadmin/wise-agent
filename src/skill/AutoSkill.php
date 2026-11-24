<?php
declare(strict_types=1);

namespace wise\agent\skill;

use wise\agent\security\CapabilityAccess;

/**
 * 自动生成的 Skill
 *
 * 由 CapabilitySkillFactory 根据插件 Schema 与业务能力自动生成，
 * 插件开发者无需编写任何 prompt，即可获得开箱即用的智能能力
 * （如经营洞察、记录解读、表单填充）。
 *
 * 所有字段在构造时确定，继承 BaseSkill 获得 renderSystemPrompt /
 * buildUserMessage / validateInput 等通用能力。
 */
class AutoSkill extends BaseSkill
{
    private string $name;
    private string $description;
    private string $category;
    private string $systemPrompt;
    private array $inputSchema;
    private string $outputFormat;
    private array $defaultTools;
    private string $source;

    /**
     * @param string $name         技能唯一标识
     * @param string $description  技能描述
     * @param string $category     分类
     * @param string $systemPrompt 系统提示词
     * @param array  $inputSchema  输入参数 JSON Schema
     * @param string $outputFormat 输出格式
     * @param array  $defaultTools 默认关联工具名列表
     * @param string $source       来源标识
     */
    public function __construct(
        string $name,
        string $description,
        string $category,
        string $systemPrompt,
        array $inputSchema = [],
        string $outputFormat = 'markdown',
        array $defaultTools = [],
        string $source = 'auto'
    ) {
        $this->name         = $name;
        $this->description  = $description;
        $this->category     = $category;
        $this->systemPrompt = $systemPrompt;
        $this->inputSchema  = $inputSchema;
        $this->outputFormat = $outputFormat;
        $this->defaultTools = $defaultTools;
        $this->source       = $source;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getCategory(): string
    {
        return $this->category;
    }

    public function getInputSchema(): array
    {
        return $this->inputSchema;
    }

    public function getSystemPrompt(): string
    {
        return $this->systemPrompt;
    }

    public function getOutputFormat(): string
    {
        return $this->outputFormat;
    }

    public function getDefaultTools(): array
    {
        return $this->defaultTools;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    /**
     * @inheritDoc
     *
     * 自动生成的三个技能中，`{plugin}.auto_fill` 产出「可写入字段值」，
     * 语义上属写入能力（保守归类）；insight / explain_record 只产出分析文本，
     * 继承基类按分类推断出的 read。
     */
    public function getAccess(): string
    {
        return str_ends_with($this->name, '.auto_fill')
            ? CapabilityAccess::WRITE
            : parent::getAccess();
    }

    /**
     * @inheritDoc
     */
    public function getRiskLevel(): string
    {
        return str_ends_with($this->name, '.auto_fill')
            ? CapabilityAccess::RISK_MEDIUM
            : parent::getRiskLevel();
    }
}
