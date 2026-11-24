<?php
declare(strict_types=1);

namespace wise\agent\mcp;

use think\console\Command;
use think\console\Input;
use think\console\Output;

/**
 * MCP stdio 传输命令
 *
 * 以 newline-delimited JSON-RPC 方式在标准输入/输出上运行 MCP 服务端，
 * 供 Claude Desktop 等以 stdio 方式连接 MCP 的客户端使用。
 *
 * 配置示例（Claude Desktop claude_desktop_config.json）：
 * {
 *     "mcpServers": {
 *         "wise-agent": {
 *             "command": "php",
 *             "args": ["think", "wise:agent:mcp"]
 *         }
 *     }
 * }
 *
 * 用法：php think wise:agent:mcp
 */
class McpServeCommand extends Command
{
    protected function configure()
    {
        $this->setName('wise:agent:mcp')
            ->setDescription('Start wise-agent MCP server over stdio (newline-delimited JSON-RPC)');
    }

    protected function execute(Input $input, Output $output): int
    {
        if (class_exists(\wise\mcp\contract\McpServerInterface::class)
            && class_exists(\wise\mcp\transport\StdioTransport::class)) {
            try {
                (new \wise\mcp\transport\StdioTransport())
                    ->serve(app()->make(\wise\mcp\contract\McpServerInterface::class));
                return 0;
            } catch (\Throwable $e) {
            }
        }

        $server = new McpServer();

        $stdin  = defined('STDIN') ? STDIN : fopen('php://stdin', 'r');
        $stdout = defined('STDOUT') ? STDOUT : fopen('php://stdout', 'w');

        while (($line = fgets($stdin)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $message = json_decode($line, true);
            if (!is_array($message)) {
                continue;
            }

            $response = $server->handle($message);
            if ($response !== null) {
                fwrite($stdout, json_encode($response, JSON_UNESCAPED_UNICODE) . "\n");
                fflush($stdout);
            }
        }

        return 0;
    }
}
