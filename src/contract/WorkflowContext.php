<?php
declare(strict_types=1);

namespace wise\agent\contract;

/**
 * 工作流执行上下文
 *
 * 传递给 NodeProviderInterface::run() 的上下文对象，
 * 包含当前工作流执行状态、变量、追踪信息。
 */
class WorkflowContext
{
    protected string $workflowId;
    protected string $executionId;
    protected int $stepIndex;
    protected array $variables;
    protected array $trace;
    protected int $timestamp;
    protected array $extra;

    public function __construct(
        string $workflowId = '',
        string $executionId = '',
        int $stepIndex = 0,
        array $variables = [],
        array $trace = [],
        array $extra = []
    ) {
        $this->workflowId  = $workflowId;
        $this->executionId = $executionId ?: uniqid('exec_', true);
        $this->stepIndex   = $stepIndex;
        $this->variables   = $variables;
        $this->trace       = $trace;
        $this->timestamp   = time();
        $this->extra       = $extra;
    }

    public function getWorkflowId(): string { return $this->workflowId; }
    public function setWorkflowId(string $workflowId): void { $this->workflowId = $workflowId; }

    public function getExecutionId(): string { return $this->executionId; }

    public function getStepIndex(): int { return $this->stepIndex; }
    public function setStepIndex(int $stepIndex): void { $this->stepIndex = $stepIndex; }

    /**
     * 获取变量（支持点号路径，如 'user.name'）
     */
    public function getVariable(string $key, mixed $default = null): mixed
    {
        $keys = explode('.', $key);
        $current = $this->variables;
        foreach ($keys as $k) {
            if (is_array($current) && isset($current[$k])) {
                $current = $current[$k];
            } else {
                return $default;
            }
        }
        return $current;
    }

    /**
     * 设置变量
     */
    public function setVariable(string $key, mixed $value): void
    {
        $this->variables[$key] = $value;
    }

    /**
     * 获取所有变量
     */
    public function getVariables(): array
    {
        return $this->variables;
    }

    /**
     * 设置所有变量
     */
    public function setVariables(array $variables): void
    {
        $this->variables = $variables;
    }

    /**
     * 记录执行追踪
     */
    public function addTrace(string $nodeType, string $status, mixed $output = null): void
    {
        $this->trace[] = [
            'node_type'  => $nodeType,
            'status'     => $status,
            'output'     => $output,
            'step_index' => $this->stepIndex,
            'timestamp'  => microtime(true),
        ];
    }

    /**
     * 获取执行追踪
     */
    public function getTrace(): array
    {
        return $this->trace;
    }

    /**
     * 转换为数组
     */
    public function toArray(): array
    {
        return [
            'workflow_id'  => $this->workflowId,
            'execution_id' => $this->executionId,
            'step_index'   => $this->stepIndex,
            'variables'    => $this->variables,
            'trace'        => $this->trace,
            'timestamp'    => $this->timestamp,
            'extra'        => $this->extra,
        ];
    }
}
