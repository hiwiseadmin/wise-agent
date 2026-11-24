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

if (!function_exists('wise_agent_skill')) {
    /**
     * 获取 Skill 管理器实例
     *
     * @return \wise\agent\skill\SkillManager
     */
    function wise_agent_skill(): \wise\agent\skill\SkillManager
    {
        return app()->make(\wise\agent\skill\SkillManager::class);
    }
}

if (!function_exists('wise_agent_skill_execute')) {
    /**
     * 快速执行一个 Skill
     *
     * @param string $skillName Skill 名称
     * @param array  $input     输入参数
     * @param array  $options   执行选项
     * @return \wise\agent\skill\SkillResult
     * @throws \wise\agent\skill\exception\SkillException
     */
    function wise_agent_skill_execute(string $skillName, array $input = [], array $options = []): \wise\agent\skill\SkillResult
    {
        return wise_agent_skill()->execute($skillName, $input, $options);
    }
}

if (!function_exists('wise_agent_skills')) {
    /**
     * 获取所有可用 Skill 的概览
     *
     * @return array
     */
    function wise_agent_skills(): array
    {
        return wise_agent_skill()->getAvailableSkills();
    }
}

if (!function_exists('wise_agent_pipeline')) {
    /**
     * 获取 Pipeline 引擎实例
     *
     * @return \wise\agent\pipeline\PipelineEngine
     */
    function wise_agent_pipeline(): \wise\agent\pipeline\PipelineEngine
    {
        return app()->make(\wise\agent\pipeline\PipelineEngine::class);
    }
}

if (!function_exists('wise_agent_pipeline_run')) {
    /**
     * 快速执行一个流水线
     *
     * @param string $pipelineName 流水线名称
     * @param array  $input        输入参数
     * @param callable|null $onProgress 进度回调
     * @return \wise\agent\pipeline\PipelineResult
     */
    function wise_agent_pipeline_run(string $pipelineName, array $input = [], ?callable $onProgress = null): \wise\agent\pipeline\PipelineResult
    {
        return wise_agent_pipeline()->run($pipelineName, $input, $onProgress);
    }
}

if (!function_exists('wise_agent_feedback')) {
    /**
     * 收集用户反馈
     *
     * @param string $skillName Skill 名称
     * @param int    $rating    评分 1-5
     * @param string|null $comment    评语
     * @param string|null $correction 纠正内容
     * @return bool
     */
    function wise_agent_feedback(string $skillName, int $rating, ?string $comment = null, ?string $correction = null): bool
    {
        $collector = app()->make(\wise\agent\feedback\FeedbackCollector::class);
        return $collector->collect(new \wise\agent\feedback\Feedback($skillName, $rating, $comment, $correction));
    }
}

if (!function_exists('wise_agent_schedule')) {
    /**
     * 获取调度器实例
     *
     * @return \wise\agent\schedule\AiTaskScheduler
     */
    function wise_agent_schedule(): \wise\agent\schedule\AiTaskScheduler
    {
        return app()->make(\wise\agent\schedule\AiTaskScheduler::class);
    }
}

if (!function_exists('wise_agent_schema_discover')) {
    /**
     * 对指定插件执行 Schema 发现
     *
     * 流水线：内省数据模型 → 生成 Tool → 解析业务能力 → 注入 Context
     *
     * @param string $pluginIdent 插件标识（如 smartstock）
     * @return \wise\agent\schema\discovery\SchemaDiscoveryResult
     */
    function wise_agent_schema_discover(string $pluginIdent): \wise\agent\schema\discovery\SchemaDiscoveryResult
    {
        $discovery = app()->make(\wise\agent\schema\discovery\PluginSchemaDiscovery::class);
        return $discovery->discover($pluginIdent);
    }
}

if (!function_exists('wise_agent_schema_discover_all')) {
    /**
     * 批量发现所有已安装插件的 Schema
     *
     * @param bool $force 是否强制重新发现
     * @return \wise\agent\schema\discovery\SchemaDiscoveryResult[]
     */
    function wise_agent_schema_discover_all(bool $force = false): array
    {
        $discovery = app()->make(\wise\agent\schema\discovery\PluginSchemaDiscovery::class);
        return $discovery->discoverAll($force);
    }
}

if (!function_exists('wise_agent_schema_get')) {
    /**
     * 获取指定插件的 Schema 发现结果（缓存）
     *
     * @param string $pluginIdent
     * @return \wise\agent\schema\discovery\SchemaDiscoveryResult|null
     */
    function wise_agent_schema_get(string $pluginIdent): ?\wise\agent\schema\discovery\SchemaDiscoveryResult
    {
        return \wise\agent\schema\discovery\PluginSchemaDiscovery::getDiscovered($pluginIdent);
    }
}

if (!function_exists('wise_agent_schema_dump')) {
    /**
     * 导出插件的自动 Schema 描述，供手动完善
     *
     * 用法：
     *   $json = wise_agent_schema_dump('smartstock', 'json');
     *   $md   = wise_agent_schema_dump('smartstock', 'markdown');
     *   $arr  = wise_agent_schema_dump('smartstock', 'array');
     *
     * @param string $pluginIdent 插件标识
     * @param string $format 输出格式：json | markdown | array
     * @param bool $onlyEssential 是否仅导出有描述的字段
     * @return mixed
     */
    function wise_agent_schema_dump(string $pluginIdent, string $format = 'json', bool $onlyEssential = false)
    {
        $discovery = app()->make(\wise\agent\schema\discovery\PluginSchemaDiscovery::class);

        switch ($format) {
            case 'markdown':
            case 'md':
                return $discovery->dumpSchemaAsMarkdown($pluginIdent);
            case 'array':
                return $discovery->dumpSchemaForEditing($pluginIdent, $onlyEssential);
            case 'json':
            default:
                return $discovery->dumpSchemaAsJson($pluginIdent, $onlyEssential);
        }
    }
}

if (!function_exists('wise_agent_frontend_entry')) {
    /**
     * 获取 AI 助手前端入口 URL（带版本号缓存失效）
     *
     * 由框架模板注入 window.__WS_AI_ENTRY__，前端 wise-bootstrap 据此
     * 动态加载 AI 模块。AI 前端代码随 wise-agent 包发布，版本号用于缓存失效。
     *
     * @return string
     */
    function wise_agent_frontend_entry(): string
    {
        $version = '1.0.0';
        try {
            if (function_exists('config')) {
                $v = config('wise-agent.version', '');
                if (is_string($v) && $v !== '') {
                    $version = $v;
                }
            }
        } catch (\Throwable $e) {
        }

        return '/statics/plugins/wise-agent/js/agent-chat.js?v=' . rawurlencode($version);
    }
}
