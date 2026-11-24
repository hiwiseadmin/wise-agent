<?php
declare(strict_types=1);

namespace wise\agent\mcp;

/**
 * MCP (Model Context Protocol) 服务端
 *
 * 实现 JSON-RPC 2.0 消息分发，把 wise-agent 的工具集以 MCP 协议暴露，
 * 供外部 MCP 客户端（Claude Desktop、Laravel MCP 客户端等）调用。
 *
 * 支持方法：
 *   - initialize              握手协商
 *   - notifications/initialized  客户端就绪通知（无响应）
 *   - ping                    心跳
 *   - tools/list              列出可用工具
 *   - tools/call              调用工具
 *
 * 协议版本：2025-11-25（「有状态 + initialize 握手」这一代协议族）
 *
 * 本类只实现 initialize 握手与 tools/* 分发，并未实现 MCP 2026-07-28 的无状态化
 * 修订（server/discover、由每请求 _meta 携带版本与客户端能力、移除会话），
 * 因此不得声明 2026-07-28。版本口径与 wise-mcp 的 ProtocolVersion 保持一致。
 */
class McpServer
{
    public const PROTOCOL_VERSION = '2025-11-25';

    protected McpToolBridge $tools;

    public function __construct(?McpToolBridge $tools = null)
    {
        $this->tools = $tools ?? new McpToolBridge();
    }

    /**
     * 处理单个 JSON-RPC 消息
     *
     * @param array $message 已解码的消息数组
     * @return array|null 响应数组；通知返回 null
     */
    public function handle(array $message): ?array
    {
        if (!array_key_exists('id', $message)) {
            $this->handleNotification($message);
            return null;
        }

        $id     = $message['id'];
        $method = (string) ($message['method'] ?? '');

        try {
            $result = $this->dispatch($method, is_array($message['params'] ?? null) ? $message['params'] : []);
            return $this->success($id, $result);
        } catch (\Throwable $e) {
            return $this->error($id, -32603, $e->getMessage());
        }
    }

    /**
     * 分发到具体方法处理器
     */
    protected function dispatch(string $method, array $params): array
    {
        switch ($method) {
            case 'initialize':
                return $this->initialize($params);

            case 'ping':
                return ['resultType' => 'complete'];

            case 'tools/list':
                return [
                    'resultType' => 'complete',
                    'tools'      => $this->tools->listTools(),
                ];

            case 'tools/call':
                $name = (string) ($params['name'] ?? '');
                if ($name === '') {
                    throw new \InvalidArgumentException('tools/call 缺少 name 参数');
                }
                return $this->tools->callTool($name, is_array($params['arguments'] ?? null) ? $params['arguments'] : []);

            case 'resources/list':
                $registry = $this->capabilityRegistry();

                return [
                    'resultType' => 'complete',
                    'resources'  => $registry === null
                        ? []
                        : array_values(array_map(
                            static fn($resource) => $resource->toMcpArray(),
                            $registry->resources()
                        )),
                ];

            case 'resources/read':
                $uri = (string) ($params['uri'] ?? '');
                if ($uri === '') {
                    throw new \InvalidArgumentException('resources/read 缺少 uri 参数');
                }

                $resource = $this->capabilityRegistry()?->getResource($uri);
                if ($resource === null) {
                    throw new \RuntimeException("Resource not found: {$uri}");
                }

                return [
                    'resultType' => 'complete',
                    'contents'   => [$resource->toContentArray()],
                ];

            case 'prompts/list':
                $registry = $this->capabilityRegistry();

                return [
                    'resultType' => 'complete',
                    'prompts'    => $registry === null
                        ? []
                        : array_values(array_map(
                            static fn($prompt) => $prompt->toMcpArray(),
                            $registry->prompts()
                        )),
                ];

            case 'prompts/get':
                $promptName = (string) ($params['name'] ?? '');
                if ($promptName === '') {
                    throw new \InvalidArgumentException('prompts/get 缺少 name 参数');
                }

                $prompt = $this->capabilityRegistry()?->getPrompt($promptName);
                if ($prompt === null) {
                    throw new \RuntimeException("Prompt not found: {$promptName}");
                }

                $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

                return array_merge(
                    ['resultType' => 'complete'],
                    $prompt->render($arguments)
                );

            default:
                throw new \RuntimeException("Method not found: {$method}");
        }
    }

    /**
     * 取 wise-mcp 的能力注册表（未安装时返回 null）
     *
     * 资源 / 提示词的提供者机制只维护一份（在 wise-mcp），本包委托读取，
     * 避免两套注册表各自发散。
     */
    protected function capabilityRegistry(): ?object
    {
        try {
            if (!class_exists(\wise\mcp\registry\CapabilityRegistry::class)) {
                return null;
            }

            return app(\wise\mcp\registry\CapabilityRegistry::class);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * 握手：返回协议版本、服务端能力与服务信息
     */
    protected function initialize(array $params): array
    {
        $registry   = $this->capabilityRegistry();
        $capabilities = [
            'tools' => [
                'listChanged' => false,
            ],
        ];

        if ($registry !== null) {
            if ($registry->resources() !== []) {
                $capabilities['resources'] = ['listChanged' => false, 'subscribe' => false];
            }

            if ($registry->prompts() !== []) {
                $capabilities['prompts'] = ['listChanged' => false];
            }
        }

        return [
            'protocolVersion' => self::PROTOCOL_VERSION,
            'capabilities'    => $capabilities,
            'serverInfo'      => [
                'name'    => McpToolBridge::SERVER_NAME,
                'version' => McpToolBridge::SERVER_VERSION,
            ],
            'instructions'    => 'WiseAdmin AI capability server. Exposes plugin AI tools, schema-auto-generated CRUD tools, and framework menu navigation.',
        ];
    }

    /**
     * 处理通知（无需响应）
     */
    protected function handleNotification(array $message): void
    {
    }

    /**
     * 构建成功响应
     */
    protected function success($id, array $result): array
    {
        return [
            'jsonrpc' => '2.0',
            'id'      => $id,
            'result'  => $result,
        ];
    }

    /**
     * 构建错误响应
     */
    protected function error($id, int $code, string $message, $data = null): array
    {
        $err = [
            'code'    => $code,
            'message' => $message,
        ];
        if ($data !== null) {
            $err['data'] = $data;
        }

        return [
            'jsonrpc' => '2.0',
            'id'      => $id,
            'error'   => $err,
        ];
    }
}
