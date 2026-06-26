<?php
declare(strict_types=1);

namespace wise\agent\event;

/**
 * ToolExecute - 工具执行前后触发
 *
 * 插件可拦截工具以进行安全检查、日志记录或结果修改。
 *
 * 阶段常量：
 *   BEFORE = 'before' - 允许修改，支持跳过
 *   AFTER  = 'after'  - 允许修改结果
 */
class ToolExecute extends Event
{
    public const PHASE_BEFORE = 'before';
    public const PHASE_AFTER  = 'after';

    public string $phase;
    public string $toolName;
    public array $arguments;
    public mixed $result;
    public bool $skip = false;
    public mixed $mockResult = null;

    public function __construct(string $phase, string $toolName, array $arguments = [], mixed $result = null)
    {
        parent::__construct();
        $this->phase     = $phase;
        $this->toolName  = $toolName;
        $this->arguments = $arguments;
        $this->result    = $result;
    }

    public function getEventName(): string
    {
        return 'ToolExecute';
    }
}
