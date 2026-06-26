<?php
declare(strict_types=1);

if (!function_exists('wise_agent')) {
    /**
     * Get the AI service instance
     *
     * Usage:
     *   $result = wise_agent()->chat('Hello');
     *   $result = wise_agent()->agent()->withTools(['file_read'])->run('Analyze the code');
     *
     * @return \wise\agent\Agent\AgentManager
     */
    function wise_agent(): \wise\agent\Agent\AgentManager
    {
        return app()->make(\wise\agent\Agent\AgentManager::class);
    }
}

if (!function_exists('wise_agent_chat')) {
    /**
     * Quick chat with AI
     *
     * @param string $message  User message
     * @param string $provider Provider name (optional)
     * @param array  $options  Extra options
     * @return string AI response
     */
    function wise_agent_chat(string $message, string $provider = '', array $options = []): string
    {
        return wise_agent()->chat($message, $provider, $options);
    }
}

if (!function_exists('wise_agent_tool')) {
    /**
     * Register a custom tool
     *
     * @param \wise\agent\Contract\ToolInterface $tool
     */
    function wise_agent_tool(\wise\agent\Contract\ToolInterface $tool): void
    {
        \wise\agent\Tool\ToolRegistry::instance()->register($tool);
    }
}

if (!function_exists('wise_agent_memory')) {
    /**
     * Store a long-term memory
     *
     * @param string $sessionId Session ID
     * @param string $key       Memory key
     * @param mixed  $value     Memory value
     * @param array  $tags      Tags
     */
    function wise_agent_memory(string $sessionId, string $key, mixed $value, array $tags = []): void
    {
        $memory = \wise\agent\Memory\MemoryFactory::create();
        $memory->store($sessionId, $key, $value, $tags);
    }
}

if (!function_exists('wise_agent_recall')) {
    /**
     * Recall a long-term memory
     *
     * @param string $sessionId Session ID
     * @param string $key       Memory key
     * @param mixed  $default   Default value
     * @return mixed
     */
    function wise_agent_recall(string $sessionId, string $key, mixed $default = null): mixed
    {
        $memory = \wise\agent\Memory\MemoryFactory::create();
        return $memory->retrieve($sessionId, $key, $default);
    }
}

if (!function_exists('wise_agent_session')) {
    /**
     * Start or resume a conversation session
     *
     * @param string|null $sessionId Session ID (null = create new)
     * @return \wise\agent\Memory\Session\Conversation
     */
    function wise_agent_session(?string $sessionId = null): \wise\agent\Memory\Session\Conversation
    {
        $manager = app()->make(\wise\agent\Memory\Session\SessionManager::class);
        if ($sessionId !== null) {
            return $manager->resume($sessionId);
        }
        return $manager->create();
    }
}
