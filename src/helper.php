<?php
declare(strict_types=1);

if (!function_exists('wise_agent')) {
    /**
     * 获取 AI 服务实例
     *
     * 用法：
     *   $result = wise_agent()->chat('Hello');
     *   $result = wise_agent()->agent()->withTools(['file_read'])->run('Analyze the code');
     *
     * @return \wise\agent\agent\AgentManager
     */
    function wise_agent(): \wise\agent\agent\AgentManager
    {
        return app()->make(\wise\agent\agent\AgentManager::class);
    }
}

if (!function_exists('wise_agent_chat')) {
    /**
     * 快速与 AI 对话
     *
     * @param string $message  用户消息
     * @param string $provider Provider 名称（可选）
     * @param array  $options  额外选项
     * @return string AI 回复
     */
    function wise_agent_chat(string $message, string $provider = '', array $options = []): string
    {
        return wise_agent()->chat($message, $provider, $options);
    }
}

if (!function_exists('wise_agent_tool')) {
    /**
     * 注册一个自定义工具
     *
     * @param \wise\agent\contract\ToolInterface $tool
     */
    function wise_agent_tool(\wise\agent\contract\ToolInterface $tool): void
    {
        \wise\agent\tool\ToolRegistry::instance()->register($tool);
    }
}

if (!function_exists('wise_agent_memory')) {
    /**
     * 存储一个长期记忆
     *
     * @param string $sessionId 会话 ID
     * @param string $key       记忆键
     * @param mixed  $value     记忆值
     * @param array  $tags      标签
     */
    function wise_agent_memory(string $sessionId, string $key, mixed $value, array $tags = []): void
    {
        $memory = \wise\agent\memory\MemoryFactory::create();
        $memory->store($sessionId, $key, $value, $tags);
    }
}

if (!function_exists('wise_agent_recall')) {
    /**
     * 召回一个长期记忆
     *
     * @param string $sessionId 会话 ID
     * @param string $key       记忆键
     * @param mixed  $default   默认值
     * @return mixed
     */
    function wise_agent_recall(string $sessionId, string $key, mixed $default = null): mixed
    {
        $memory = \wise\agent\memory\MemoryFactory::create();
        return $memory->retrieve($sessionId, $key, $default);
    }
}

if (!function_exists('wise_agent_session')) {
    /**
     * 启动或恢复一个对话会话
     *
     * @param string|null $sessionId 会话 ID（null = 创建新会话）
     * @return \wise\agent\memory\session\Conversation
     */
    function wise_agent_session(?string $sessionId = null): \wise\agent\memory\session\Conversation
    {
        $manager = app()->make(\wise\agent\memory\session\SessionManager::class);
        if ($sessionId !== null) {
            return $manager->resume($sessionId);
        }
        return $manager->create();
    }
}
