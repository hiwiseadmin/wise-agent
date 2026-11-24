<?php
declare(strict_types=1);

namespace wise\agent\schema\capability;

use wise\agent\contract\ToolInterface;

/**
 * 业务能力解析结果 DTO
 *
 * 封装 BusinessCapability::apply() 的产出物，
 * 包含领域知识上下文、专用工具、推荐技能和流水线。
 */
class CapabilityResult implements \JsonSerializable
{
    /** @var string 领域知识上下文提示词 */
    public $contextPrompt = '';

    /** @var ToolInterface[] 专用 AI Tools */
    public $tools = [];

    /** @var string[] 推荐技能名称列表 */
    public $skills = [];

    /** @var array 推荐 Pipeline 定义 */
    public $pipelines = [];

    /** @var string 能力标签 */
    public $tag = '';

    /**
     * 缺失的核心表（角色 → 候选表名列表）
     * 如：['stock_out' => ['stock_out', 'sale', 'delivery']]
     * 空数组表示所有角色都匹配到了对应的表。
     *
     * @var array<string, string[]>
     */
    public $unmatchedTables = [];

    /**
     * 匹配的核心表数量 / 总需求数量
     * 如：3/5 表示 5 个角色中匹配了 3 个
     *
     * @var string|null
     */
    public $matchRate = null;

    /**
     * @param string $contextPrompt 上下文提示词
     * @param ToolInterface[] $tools 专用工具
     * @param string[] $skills 推荐技能
     * @param array $pipelines 推荐流水线
     * @param string $tag 能力标签
     */
    public function __construct(
        string $contextPrompt = '',
        array $tools = [],
        array $skills = [],
        array $pipelines = [],
        string $tag = ''
    ) {
        $this->contextPrompt = $contextPrompt;
        $this->tools         = $tools;
        $this->skills        = $skills;
        $this->pipelines     = $pipelines;
        $this->tag           = $tag;
    }

    /**
     * 记录表匹配结果
     *
     * @param array<string, string[]> $unmatched 未匹配的角色 → 候选表名
     * @param int $total 总需求角色数
     */
    public function setTableMatchResult(array $unmatched, int $total): void
    {
        $matched = $total - count($unmatched);
        $this->unmatchedTables = $unmatched;
        $this->matchRate = "{$matched}/{$total}";
    }

    /**
     * 获取匹配率（如 "3/5"）
     *
     * @return string
     */
    public function getMatchRate(): string
    {
        return $this->matchRate ?? '?/?';
    }

    /**
     * 是否有缺失表
     *
     * @return bool
     */
    public function hasUnmatchedTables(): bool
    {
        return !empty($this->unmatchedTables);
    }

    /**
     * 结果是否为空（无可用的能力内容）
     *
     * @return bool
     */
    public function isEmpty(): bool
    {
        return empty($this->contextPrompt)
            && empty($this->tools)
            && empty($this->skills)
            && empty($this->pipelines);
    }

    /**
     * 转为数组
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'tag'              => $this->tag,
            'context_prompt'   => $this->contextPrompt,
            'tool_count'       => count($this->tools),
            'skills'           => $this->skills,
            'pipelines'        => $this->pipelines,
            'match_rate'       => $this->matchRate,
            'unmatched_tables' => $this->unmatchedTables,
        ];
    }

    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        return $this->toArray();
    }
}
