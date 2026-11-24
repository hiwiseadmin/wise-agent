<?php
declare(strict_types=1);

namespace wise\agent\mcp;

use wise\agent\contract\ToolInterface;
use wise\agent\security\CapabilityAccess;
use wise\mcp\client\McpClient;

/**
 * 外部 MCP 工具的 wise-agent 适配器
 *
 * 把某个外部 MCP Server 上的工具包装成 wise-agent 的 ToolInterface，
 * 使 LLM 可通过 Function Calling 调用外部 MCP 能力。
 */
final class BridgedMcpTool implements ToolInterface
{
    /**
     * @param string    $serverName 外部服务器名（用于权限标识与日志）
     * @param string    $remoteName MCP 工具名（在外部服务器上的原名）
     * @param array     $schema     原始 MCP 工具定义（含 description / inputSchema）
     * @param McpClient $client     所属客户端连接
     */
    public function __construct(
        private string $serverName,
        private string $remoteName,
        private array $schema,
        private McpClient $client
    ) {
    }

    public function getName(): string
    {
        return $this->remoteName;
    }

    public function getDescription(): string
    {
        return (string) ($this->schema['description'] ?? '');
    }

    public function getParameters(): array
    {
        $schema = $this->schema['inputSchema'] ?? ['type' => 'object', 'properties' => []];

        return is_array($schema) ? $schema : ['type' => 'object', 'properties' => []];
    }

    /**
     * 调用外部 MCP 工具，返回文本结果
     */
    public function execute(array $arguments): string
    {
        try {
            $result = $this->client->callTool($this->remoteName, $arguments);
        } catch (\Throwable $e) {
            return (string) json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }

        $text = $this->extractText($result);
        $isError = (bool) ($result['isError'] ?? false);

        return $isError
            ? (string) json_encode(['error' => $text], JSON_UNESCAPED_UNICODE)
            : $text;
    }

    public function getPermission(): string
    {
        return 'mcp:' . $this->serverName;
    }

    /**
     * 调用外部系统具副作用，默认需要确认
     */
    public function requireConfirmation(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     *
     * 外部 MCP 工具行为未知，保守归为写入 + 高危：
     * 需角色显式授权，且默认需要确认。
     */
    public function getAccess(): string
    {
        return CapabilityAccess::WRITE;
    }

    /**
     * @inheritDoc
     */
    public function getRiskLevel(): string
    {
        return CapabilityAccess::RISK_HIGH;
    }

    /**
     * 从 MCP 结果中提取文本内容
     */
    private function extractText(array $result): string
    {
        $parts  = [];
        $content = $result['content'] ?? [];

        if (is_array($content)) {
            foreach ($content as $item) {
                if (is_array($item) && ($item['type'] ?? '') === 'text' && isset($item['text'])) {
                    $parts[] = (string) $item['text'];
                }
            }
        }

        if ($parts !== []) {
            return implode("\n", $parts);
        }

        return (string) json_encode($result, JSON_UNESCAPED_UNICODE);
    }
}
