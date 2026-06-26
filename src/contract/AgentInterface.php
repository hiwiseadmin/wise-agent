<?php
declare(strict_types=1);

namespace wise\agent\contract;

use wise\agent\tool\ToolRegistry;

/**
 * Agent 接口（标识 + 执行）
 *
 * 定义 Agent 标识和执行的核心契约。
 * 配置/构建器方法已提取到 ConfigurableAgentInterface
 * （遵循接口隔离原则）。
 *
 * 插件可通过实现此接口创建自定义 Agent 类型。
 * 如需构建器风格的配置，请同时实现 ConfigurableAgentInterface。
 */
interface AgentInterface
{
    /**
     * 获取当前 Agent 运行的会话 ID
     */
    public function getSessionId(): string;

    /**
     * 获取 Agent 类型标识
     */
    public function getType(): string;

    /**
     * 获取当前 Agent 的系统提示词
     */
    public function getSystemPrompt(): string;

    /**
     * 获取客户端 Provider 名称
     */
    public function getProviderName(): string;

    /**
     * 获取可用工具
     */
    public function getTools(): ToolRegistry;

    /**
     * 运行 Agent 执行给定任务
     */
    public function run(string $task, array $context = []): string;

    /**
     * 通过队列异步调度 Agent
     */
    public function dispatchAsync(string $task, array $options = []): string;
}
