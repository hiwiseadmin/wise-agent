<?php
declare(strict_types=1);

namespace wise\agent\event;

use wise\agent\agent\AgentContext;

/**
 * AgentConfigure - AgentContext 创建后、Run Loop 启动前触发
 *
 * 插件可利用此钩子检查/修改配置、
 * 注册额外工具、注入上下文，或在 Agent 开始执行前
 * 调整选项。
 */
class AgentConfigure extends Event
{
    public string $sessionId;
    public string $agentType;
    public string $task;
    public AgentContext $agentContext;
    public array $context;

    public function __construct(
        string $sessionId,
        string $agentType,
        string $task,
        AgentContext $agentContext,
        array $context = []
    ) {
        parent::__construct();
        $this->sessionId    = $sessionId;
        $this->agentType    = $agentType;
        $this->task         = $task;
        $this->agentContext = $agentContext;
        $this->context      = $context;
    }

    public function getEventName(): string
    {
        return 'AgentConfigure';
    }
}
