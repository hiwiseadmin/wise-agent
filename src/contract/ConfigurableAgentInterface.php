<?php
declare(strict_types=1);

namespace wise\agent\contract;

use wise\agent\tool\ToolRegistry;

/**
 * 可配置 Agent 接口（构建器模式）
 *
 * 从 AgentInterface 中提取，以遵循接口隔离原则。
 * 提供构建器风格的方法，用于在执行前配置 Agent。
 * 仅需运行 Agent 的消费者类无需这些方法。
 */
interface ConfigurableAgentInterface
{
    /**
     * 设置系统提示词
     */
    public function setSystemPrompt(string $prompt): self;

    /**
     * 获取可用工具
     */
    public function getTools(): ToolRegistry;

    /**
     * 向当前 Agent 添加工具
     */
    public function addTool(callable|string|array $tool, string $name): self;

    /**
     * 为当前 Agent 设置工具集
     */
    public function withTools(array $toolNames): self;

    /**
     * 启用/禁用记忆功能
     */
    public function withMemory(bool $enabled): self;

    /**
     * 设置客户端 Provider 名称
     */
    public function setProviderName(string $provider): self;
}
