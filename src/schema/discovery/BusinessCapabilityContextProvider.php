<?php
declare(strict_types=1);

namespace wise\agent\schema\discovery;

use wise\agent\agent\AgentContext;
use wise\agent\context\contract\ContextProviderInterface;
use wise\agent\schema\capability\CapabilityResult;

/**
 * 业务能力上下文注入器
 *
 * 将 BusinessCapability 的领域知识以 System 消息注入到 Agent 对话上下文。
 * 由 PluginSchemaDiscovery 在 Schema 发现过程中创建并注册到 ContextManager。
 *
 * @internal 仅供 PluginSchemaDiscovery 内部使用
 */
class BusinessCapabilityContextProvider implements ContextProviderInterface
{
    /** @var CapabilityResult */
    private $result;
    /** @var string */
    private $pluginIdent;
    /** @var string */
    private $tag;
    /** @var int */
    private $priority;

    /**
     * @param CapabilityResult $result
     * @param string $pluginIdent
     * @param string $tag
     * @param int $priority
     */
    public function __construct(CapabilityResult $result, string $pluginIdent, string $tag, int $priority = 25)
    {
        $this->result      = $result;
        $this->pluginIdent = $pluginIdent;
        $this->tag         = $tag;
        $this->priority    = $priority;
    }

    public function getPriority(): int
    {
        return $this->priority;
    }

    public function getSource(): string
    {
        return 'capability:' . $this->pluginIdent . ':' . $this->tag;
    }

    public function getContext(?AgentContext $context): array
    {
        if (empty($this->result->contextPrompt)) {
            return [];
        }

        return [
            [
                'role'    => 'system',
                'content' => $this->result->contextPrompt,
            ],
        ];
    }

    public function getPluginIdent(): string
    {
        return $this->pluginIdent;
    }

    public function getCapabilityTag(): string
    {
        return $this->tag;
    }
}
