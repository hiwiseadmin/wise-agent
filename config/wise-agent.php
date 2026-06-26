<?php
declare(strict_types=1);

return [
    // =========================================================================
    // 默认 Provider
    // =========================================================================
    'default' => env('AI_DEFAULT_PROVIDER', 'openai'),

    // =========================================================================
    // LLM Provider 配置
    // =========================================================================
    'providers' => [
        'openai' => [
            'class'      => \wise\agent\client\OpenAiClient::class,
            'api_key'    => env('OPENAI_API_KEY', ''),
            'base_url'   => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
            'model'      => env('OPENAI_MODEL', 'gpt-4o'),
            'timeout'    => 60,
            'max_retry'  => 2,
        ],
        'deepseek' => [
            'class'      => \wise\agent\client\DeepSeekClient::class,
            'api_key'    => env('DEEPSEEK_API_KEY', ''),
            'base_url'   => env('DEEPSEEK_BASE_URL', 'https://api.deepseek.com/v1'),
            'model'      => env('DEEPSEEK_MODEL', 'deepseek-chat'),
            'timeout'    => 60,
            'max_retry'  => 2,
        ],
    ],

    // =========================================================================
    // SSE (Server-Sent Events) 配置 — TODO: 待 SSE 功能实现后启用以下配置
    // =========================================================================
    // 'sse' => [
    //     'timeout'          => 300,
    //     'heartbeat_interval' => 15,
    //     'retry'            => 3000,
    //     'stream_tool_calls' => true,
    //     'buffer_size'      => 1,
    // ],

    // =========================================================================
    // Agent 默认配置
    // =========================================================================
    'agent' => [
        'max_steps'           => 10,
        'max_tokens_per_step' => 4096,
        'temperature'         => 0.7,
        'default_type'        => 'simple',
        'system_prompt'       => 'You are the WiseAdmin AI assistant. You have access to tools to accomplish tasks. Always respond in the same language as the user. When you have enough information, provide the final answer directly.',
        'enable_memory'       => true,
        'session_ttl'         => 86400,
    ],

    // =========================================================================
    // 记忆配置
    // =========================================================================
    'memory' => [
        'default' => 'database',
        'stores'  => [
            'database' => [
                'class' => \wise\agent\memory\DatabaseMemory::class,
            ],
            'redis' => [
                'class'  => \wise\agent\memory\RedisMemory::class,
                'prefix' => 'wai:mem:',
            ],
        ],
    ],

    // =========================================================================
    // 内置工具配置
    // =========================================================================
    'tools' => [
        'file_read'    => ['class' => \wise\agent\tool\builtin\FileReadTool::class,    'enabled' => false],
        'file_write'   => ['class' => \wise\agent\tool\builtin\FileWriteTool::class,   'enabled' => false],
        'db_query'     => ['class' => \wise\agent\tool\builtin\DatabaseQueryTool::class, 'enabled' => false],
        'http_request' => ['class' => \wise\agent\tool\builtin\HttpRequestTool::class,  'enabled' => false],
    ],

    // =========================================================================
    // 日志配置
    // =========================================================================
    'log' => [
        'enabled' => true,
        'channel' => 'ai',
    ],

    // =========================================================================
    // 队列配置（异步 Agent 使用）
    // =========================================================================
    'queue' => [
        'connection' => 'database',
        'job'        => \wise\agent\job\AiAgentJob::class,
    ],

    // =========================================================================
    // 安全配置
    // =========================================================================
    'security' => [
        'max_tool_executions_per_step' => 5,
        'max_agent_duration'          => 300,
    ],

    // =========================================================================
    // 数据表配置
    // =========================================================================
    'tables' => [
        'sessions'    => 'ai_sessions',
        'messages'    => 'ai_messages',
        'memories'    => 'ai_memories',
        'tools'       => 'ai_tools',
        'agent_logs'  => 'ai_agent_logs',
        'ai_config'   => 'ws_ai_config',
    ],
];
