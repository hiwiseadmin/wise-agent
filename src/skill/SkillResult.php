<?php
declare(strict_types=1);

namespace wise\agent\skill;

/**
 * Skill 执行结果
 *
 * 封装一次 Skill 执行后的完整结果，包含输出内容、token 用量、
 * 耗时和元数据。
 */
class SkillResult
{
    protected string $skillName;
    protected string $output;
    protected array $usage;
    protected float $duration;
    protected int $steps;
    protected array $metadata;

    public function __construct(
        string $skillName,
        string $output,
        array $usage = [],
        float $duration = 0.0,
        int $steps = 1,
        array $metadata = []
    ) {
        $this->skillName = $skillName;
        $this->output = $output;
        $this->usage = $usage;
        $this->duration = $duration;
        $this->steps = $steps;
        $this->metadata = $metadata;
    }

    public function getSkillName(): string
    {
        return $this->skillName;
    }

    public function getOutput(): string
    {
        return $this->output;
    }

    public function getUsage(): array
    {
        return $this->usage;
    }

    /** 总 token 数 */
    public function getTotalTokens(): int
    {
        return $this->usage['total_tokens'] ?? 0;
    }

    /** 耗时（秒） */
    public function getDuration(): float
    {
        return $this->duration;
    }

    /** 执行步数 */
    public function getSteps(): int
    {
        return $this->steps;
    }

    /** 元数据 */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function get(string $key, $default = null)
    {
        return $this->metadata[$key] ?? $default;
    }

    public function toArray(): array
    {
        return [
            'skill_name'  => $this->skillName,
            'output'      => $this->output,
            'usage'       => $this->usage,
            'duration'    => round($this->duration, 3),
            'steps'       => $this->steps,
            'metadata'    => $this->metadata,
        ];
    }
}
