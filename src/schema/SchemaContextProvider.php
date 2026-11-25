<?php
declare(strict_types=1);

namespace wise\agent\schema;

use wise\agent\agent\AgentContext;
use wise\agent\context\contract\ContextProviderInterface;
use wise\agent\schema\dto\PluginSchemaDTO;

/**
 * Schema 上下文注入器
 *
 * 实现 ContextProviderInterface，将插件数据模型的完整表结构
 * 以 Markdown 格式注入到 AI Agent 的 system prompt 中。
 *
 * 每次 Agent 运行时，ContextManager 会自动收集此 Provider 的消息，
 * 使 AI 在对话中完整理解插件的数据库结构。
 *
 * 使用示例：
 *   // 在插件发现流程中
 *   $schema = (new SchemaIntrospector())->introspect('smartstock');
 *   $provider = new SchemaContextProvider($schema);
 *   ContextManager::register($provider);
 */
class SchemaContextProvider implements ContextProviderInterface
{
    /** @var PluginSchemaDTO Schema 数据 */
    private $schema;

    /** @var int 优先级（数值越大越靠前） */
    private $priority;

    /** @var string 来源标识 */
    private $source;

    /**
     * @param PluginSchemaDTO $schema 插件 Schema
     * @param int $priority 优先级（默认 60，高于 SystemProvider 的 0，低于 UserProvider 的 50 的场景）
     */
    public function __construct(PluginSchemaDTO $schema, int $priority = 30)
    {
        $this->schema   = $schema;
        $this->priority = $priority;
        $this->source   = 'plugin:' . $schema->pluginIdent . ':schema';
    }

    /**
     * 获取优先级
     *
     * @return int
     */
    public function getPriority(): int
    {
        return $this->priority;
    }

    /**
     * 获取上下文消息数组
     *
     * 将完整的表结构描述以 system 消息形式注入。
     * 消息会被置于 system prompt 和 conversation history 之间，
     * 帮助 AI 理解可用的数据结构和查询方式。
     *
     * @param AgentContext $agentContext Agent 上下文
     * @return array 消息数组 [['role' => 'system', 'content' => '...']]
     */
    public function getContext(AgentContext $agentContext): array
    {
        $prompt = $this->schema->toContextPrompt();

        if (empty($prompt)) {
            return [];
        }

        return [
            [
                'role'    => 'system',
                'content' => $prompt,
            ],
        ];
    }

    /**
     * 获取上下文来源标识
     *
     * 格式：plugin:{pluginIdent}:schema
     *
     * @return string
     */
    public function getSource(): string
    {
        return $this->source;
    }

    /**
     * 获取关联的插件标识
     *
     * @return string
     */
    public function getPluginIdent(): string
    {
        return $this->schema->pluginIdent;
    }

    /**
     * 刷新 Schema 数据（当插件内省结果更新时使用）
     *
     * @param PluginSchemaDTO $schema
     */
    public function refresh(PluginSchemaDTO $schema): void
    {
        $this->schema = $schema;
        $this->source = 'plugin:' . $schema->pluginIdent . ':schema';
    }
}
