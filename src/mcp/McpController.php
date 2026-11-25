<?php
declare(strict_types=1);

namespace wise\agent\mcp;

/**
 * MCP HTTP 端点（Streamable HTTP 传输）
 *
 * 接收 JSON-RPC 2.0 消息，支持单条消息与批量消息，
 * 无状态处理（不维护会话）。通过 config('wise-agent.mcp.enable') 开关控制。
 *
 * 建议在生产环境对 /admin/ai/mcp 挂载认证/限流中间件，
 * 因为该端点会暴露所有 AI 工具（含写操作，写工具默认仍需确认）。
 */
class McpController
{
    /**
     * MCP 端点入口
     *
     * @return \think\response\Json|\think\response\Response
     */
    public function endpoint()
    {
        if (config('wise-mcp.enable', false)
            && class_exists(\wise\mcp\controller\McpController::class)
            && class_exists(\wise\mcp\contract\McpServerInterface::class)) {
            try {
                return (new \wise\mcp\controller\McpController())->endpoint();
            } catch (\Throwable $e) {
            }
        }

        if (!config('wise-agent.mcp.enable', false)) {
            return json(['error' => 'MCP server is disabled'], 404);
        }

        $body    = (string) file_get_contents('php://input');
        $message = json_decode($body, true);

        if (!is_array($message)) {
            return json([
                'jsonrpc' => '2.0',
                'id'      => null,
                'error'   => ['code' => -32700, 'message' => 'Parse error'],
            ], 400);
        }

        $server = new McpServer();

        if ($this->isBatch($message)) {
            $responses = [];
            foreach ($message as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $response = $server->handle($item);
                if ($response !== null) {
                    $responses[] = $response;
                }
            }
            return json($responses);
        }

        $response = $server->handle($message);
        if ($response === null) {
            return response('', 202);
        }

        return json($response);
    }

    /**
     * 判断是否为批量请求（JSON 数组，顺序索引）
     */
    private function isBatch(array $message): bool
    {
        if (isset($message['jsonrpc']) || isset($message['method'])) {
            return false;
        }
        return array_keys($message) === array_keys(array_values($message));
    }
}
