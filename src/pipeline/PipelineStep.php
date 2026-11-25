<?php
declare(strict_types=1);

namespace wise\agent\pipeline;

/**
 * 流水线步骤定义
 *
 * 每个步骤指定一个 Skill 名称 + 输入映射 + 可选的条件/重试/后处理。
 *
 * 输入映射语法：
 *   $input.xxx        → 从流水线初始输入取值
 *   $steps.{key}.xxx  → 从上一步输出取值
 *   "literal string"  → 字面量
 */
class PipelineStep
{
    /** Skill 名称 */
    public string $skillName;

    /** 输入参数映射 */
    public array $inputMapping = [];

    /** 输出键名（供后续步骤引用） */
    public string $outputKey = '';

    /** 条件执行表达式（PHP 表达式字符串，如: $steps.titles != null） */
    public ?string $condition = null;

    /** 失败重试次数 */
    public int $retries = 0;

    /** 超时秒数 */
    public ?int $timeout = null;

    /** 错误处理策略: fail | skip | retry */
    public string $onError = 'fail';

    /**
     *  是否并行执行
     *
     * 语义：**连续的** `parallel=true` 步骤组成一个批次，批内步骤互相看不到
     * 对方输出（各自只读取批次开始前的上下文）。这是"并行"的契约本身——
     * 如果后一步要读前一步的输出，它就不是并行的。
     *
     * 实现说明：无协程运行时（FPM）下批内退化为**串行执行**，但上下文隔离
     * 与失败聚合仍按并行语义处理；这样同一份配置在 FPM 与 Swoole 下行为一致，
     * 不会出现"本地能跑、上 Swoole 结果不同"的漂移。
     */
    public bool $parallel = false;

    public function __construct(
        string $skillName,
        array $inputMapping = [],
        string $outputKey = '',
        ?string $condition = null,
        int $retries = 0,
        ?int $timeout = null,
        string $onError = 'fail',
        bool $parallel = false
    ) {
        $this->skillName    = $skillName;
        $this->inputMapping = $inputMapping;
        $this->outputKey    = $outputKey;
        $this->condition    = $condition;
        $this->retries      = $retries;
        $this->timeout      = $timeout;
        $this->onError      = $onError;
        $this->parallel     = $parallel;
    }

    /**
     * 从数组创建步骤
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['skill']        ?? $data['skillName'] ?? '',
            $data['input_mapping'] ?? $data['inputMapping'] ?? [],
            $data['output_key']    ?? $data['outputKey'] ?? '',
            $data['condition']     ?? null,
            $data['retries']       ?? 0,
            $data['timeout']       ?? null,
            $data['on_error']      ?? $data['onError'] ?? 'fail',
            (bool) ($data['parallel'] ?? false)
        );
    }

    public function toArray(): array
    {
        return [
            'skill'         => $this->skillName,
            'input_mapping' => $this->inputMapping,
            'output_key'    => $this->outputKey,
            'condition'     => $this->condition,
            'retries'       => $this->retries,
            'timeout'       => $this->timeout,
            'on_error'      => $this->onError,
            'parallel'      => $this->parallel,
        ];
    }
}
