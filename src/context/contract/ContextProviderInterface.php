<?php
declare(strict_types=1);

namespace wise\agent\context\contract;

use wise\agent\agent\AgentContext;

/**
 * 上下文注入器接口
 *
 * 插件实现此接口向 AI Agent 注入领域数据。
 * 在 AgentConfigure 事件中，ContextManager 收集所有已注册的 Provider
 * 并将上下文注入到 system prompt 之前。
 */
interface ContextProviderInterface
{
    /**
     * 获取优先级（数值越大，上下文越靠前）
     *
     * @return int 默认 0，最高 100
     */
    public function getPriority(): int;

    /**
     * 获取上下文消息数组
     *
     * 每条消息格式：['role' => 'system', 'content' => '...']
     * AgentManager 会将这些消息注入到 conversation 中。
     *
     * @param AgentContext $agentContext Agent 上下文（含 sessionId/task/agentType 等）
     * @return array 消息数组
     */
    public function getContext(AgentContext $agentContext): array;

    /**
     * 获取上下文来源标识
     *
     * @return string 'builtin' | 'plugin:cms' | 'plugin:smartstock' | ...
     */
    public function getSource(): string;
}
