<?php
declare(strict_types=1);

namespace wise\agent\mcp;

use wise\agent\tool\ToolRegistry;
use wise\mcp\client\ConnectionManager;
use wise\mcp\client\McpClientFactory;

/**
 * 外部 MCP 工具导入器（Client 侧）
 *
 * 通过 wise-mcp 的客户端工厂发现 config('wise-mcp.clients') 中声明（且通过白名单）
 * 的外部 MCP Server 工具，导入 wise-agent 的 ToolRegistry，命名空间为 'mcp:{server}'。
 * 导入后，LLM 即可像调用内置工具一样调用外部 MCP 能力。
 *
 * 复用 wise-mcp 的连接池与发现缓存，避免重复建连与重复拉取：
 *   - 连接：ConnectionManager（按名称复用，不健康自动重建）
 *   - 工具列表：DiscoveryCache（默认 300s TTL，$refresh 可强制刷新）
 *
 * 注意：该操作会启动/连接外部进程或服务，开销较大，默认在配置开关
 * wise-agent.mcp.import_external 打开时才执行，避免每请求 spawn 子进程。
 * 若把本机 MCP 服务端再喂给自身，可能形成回环；配置时应避免自连。
 */
final class ExternalMcpToolImporter
{
    public function __construct(
        private McpClientFactory $factory,
        private ConnectionManager $manager,
        private ToolRegistry $registry
    ) {
    }

    /**
     * 导入全部配置的外部工具
     *
     * @param bool $refresh 是否强制刷新工具列表缓存
     * @return int 成功导入的工具数量
     */
    public function importAll(bool $refresh = false): int
    {
        $count = 0;

        foreach ($this->factory->discoverTools($refresh) as $server => $tools) {
            $allow = $this->toolAllowlist((string) $server);

            try {
                $client = $this->manager->acquire((string) $server);
            } catch (\Throwable $e) {
                error_log("[wise-agent] 获取外部 MCP 连接 [{$server}] 失败: " . $e->getMessage());
                continue;
            }

            foreach ($tools as $tool) {
                if (!is_array($tool)) {
                    continue;
                }

                $name = (string) ($tool['name'] ?? '');
                if ($name === '') {
                    continue;
                }
                if ($allow !== [] && !in_array($name, $allow, true)) {
                    continue;
                }

                $this->registry->register(
                    new BridgedMcpTool((string) $server, $name, $tool, $client),
                    null,
                    'mcp:' . $server
                );
                $count++;
            }
        }

        return $count;
    }

    /**
     * 单个服务器的工具白名单
     *
     * @return string[]
     */
    private function toolAllowlist(string $server): array
    {
        $configs = (array) (function_exists('config') ? config('wise-mcp.clients', []) : []);
        $tools   = $configs[$server]['tools'] ?? [];

        return is_array($tools) ? array_values(array_map('strval', $tools)) : [];
    }
}
