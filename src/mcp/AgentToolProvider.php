<?php
declare(strict_types=1);

namespace wise\agent\mcp;

use wise\agent\contract\ToolInterface;
use wise\agent\tool\ToolRegistry;
use wise\mcp\contract\ToolProviderInterface;
use wise\mcp\dto\ToolDefinition;

/**
 * wise-agent 的 MCP 工具提供者
 *
 * 把 ToolRegistry 中已注册的全部工具（内置 / 插件 schema 自动生成 / ai_actions /
 * capability 专用工具 / 框架菜单工具）适配为 MCP 工具定义，并回接执行。
 *
 * 这是 L3「领域映射层」：wise-mcp 只认 ToolProviderInterface，
 * 由本适配器承担 ToolInterface → ToolDefinition 的转换，二者互不依赖。
 *
 * 注册方式（AgentServiceProvider::boot()）：
 *   ExtPoint::register(ToolProviderInterface::EXT_POINT, 'agent', AgentToolProvider::class);
 *
 * 命名约定：注册键中的 ":" 替换为 "."，以符合 MCP 工具名字符集。
 */
final class AgentToolProvider implements ToolProviderInterface
{
    private ToolRegistry $registry;

    public function __construct(?ToolRegistry $registry = null)
    {
        $this->registry = $registry ?? ToolRegistry::instance();
    }

    public function getNamespace(): string
    {
        return 'agent';
    }

    /**
     * @return ToolDefinition[]
     */
    public function listTools(): array
    {
        $tools = [];

        foreach ($this->registry->all() as $key => $tool) {
            if (!$tool instanceof ToolInterface) {
                continue;
            }

            $registryKey = (string) $key;
            $tools[] = new ToolDefinition(
                name: str_replace(':', '.', $registryKey),
                description: $tool->getDescription(),
                inputSchema: $tool->getParameters(),
                handler: fn (array $arguments): string => $this->registry->execute($registryKey, $arguments),
                meta: [
                    'permission'            => $tool->getPermission(),
                    'requires_confirmation' => $tool->requireConfirmation(),
                    'source'                => 'wise-agent',
                ],
            );
        }

        return $tools;
    }
}
