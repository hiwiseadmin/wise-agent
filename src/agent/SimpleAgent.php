<?php
declare(strict_types=1);

namespace wise\agent\agent;

/**
 * 简单 Agent
 *
 * 默认的通用 Agent 实现。
 * 无特殊行为 — 完全依赖基类的 Run Loop。
 *
 * 用法：
 *   $agent = new SimpleAgent();
 *   $result = $agent->withTools(['file_read', 'db_query'])->run('Analyze the data');
 */
class SimpleAgent extends BaseAgent
{
    public function getType(): string
    {
        return 'simple';
    }

    public function getSystemPrompt(): string
    {
        return config('wise-agent.agent.system_prompt', 'You are the WiseAdmin AI assistant. You have access to tools to accomplish tasks. Always respond in the same language as the user.');
    }
}
