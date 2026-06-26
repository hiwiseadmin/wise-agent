<?php
declare(strict_types=1);

namespace wise\agent\Agent;

/**
 * Simple agent
 *
 * The default general-purpose agent implementation.
 * No specialized behavior — relies entirely on the base agent's Run Loop.
 *
 * Usage:
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
