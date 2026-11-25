<?php
declare(strict_types=1);

namespace wise\agent\mcp;

use think\facade\Event;
use wise\agent\contract\ToolInterface;
use wise\agent\event\ToolExecute;
use wise\agent\tool\ToolRegistry;

/**
 * MCP 工具桥接器
 *
 * 将 ToolRegistry 中已注册的所有工具（内置 / 插件 schema 自动生成 /
 * ai_actions 声明式动作 / capability 专用工具 / 框架菜单工具）
 * 转换为 MCP 工具定义，并把 MCP tools/call 分发回 ToolRegistry 执行。
 *
 * 命名约定：MCP 工具名使用注册键的 ":" 替换为 "."，
 * 以符合 MCP 工具名字符集（字母/数字/_/-/.），同时保持全局唯一。
 */
class McpToolBridge
{
    public const SERVER_NAME = 'wise-agent';
    public const SERVER_VERSION = '1.0.0';

    protected ToolRegistry $registry;

    /** @var array<string, string>|null MCP 名 → 注册键 映射缓存 */
    protected ?array $nameMap = null;

    public function __construct(?ToolRegistry $registry = null)
    {
        $this->registry = $registry ?? ToolRegistry::instance();
    }

    /**
     * 列出所有工具（MCP 格式）
     *
     * @return array<int, array>
     */
    public function listTools(): array
    {
        $tools = [];
        foreach ($this->buildNameMap() as $mcpName => $registryKey) {
            $tool = $this->registry->get($registryKey);
            if (!$tool instanceof ToolInterface) {
                continue;
            }
            $tools[] = $this->toMcpTool($mcpName, $tool);
        }
        return $tools;
    }

    /**
     * 调用工具（MCP 格式）
     *
     * @param string $name      MCP 工具名
     * @param array  $arguments 调用参数
     * @return array{resultType: string, content: array, isError: bool}
     */
    public function callTool(string $name, array $arguments): array
    {
        $map = $this->buildNameMap();
        $registryKey = $map[$name] ?? $name;

        $before = new ToolExecute(ToolExecute::PHASE_BEFORE, $registryKey, $arguments);
        Event::trigger($before);

        if ($before->skip) {
            return [
                'resultType' => 'complete',
                'content'    => [[
                    'type' => 'text',
                    'text' => (string) json_encode(['error' => '该操作未通过安全校验'], JSON_UNESCAPED_UNICODE),
                ]],
                'isError'    => true,
            ];
        }

        $result = $this->registry->execute($registryKey, $arguments);

        $after = new ToolExecute(ToolExecute::PHASE_AFTER, $registryKey, $arguments, $result);
        Event::trigger($after);
        if ($after->result !== null) {
            $result = (string) $after->result;
        }

        $isError = false;
        $decoded = json_decode($result, true);
        if (is_array($decoded) && array_key_exists('error', $decoded)) {
            $isError = !in_array($decoded['error'], [null, '', [], false], true);
        }

        return [
            'resultType' => 'complete',
            'content'    => [
                ['type' => 'text', 'text' => $result],
            ],
            'isError'    => $isError,
        ];
    }

    /**
     * 将单个工具转换为 MCP 工具定义
     */
    private function toMcpTool(string $name, ToolInterface $tool): array
    {
        return [
            'name'        => $name,
            'title'       => $name,
            'description' => $tool->getDescription(),
            'inputSchema' => $this->normalizeSchema($tool->getParameters()),
        ];
    }

    /**
     * 规范化参数为合法 JSON Schema
     */
    private function normalizeSchema(array $parameters): array
    {
        if (empty($parameters) || !isset($parameters['type'])) {
            return [
                'type'                 => 'object',
                'properties'           => new \stdClass(),
                'required'             => [],
                'additionalProperties' => false,
            ];
        }

        if (!isset($parameters['required'])) {
            $parameters['required'] = [];
        }

        return $parameters;
    }

    /**
     * 构建 MCP 名 → 注册键 映射
     *
     * @return array<string, string>
     */
    protected function buildNameMap(): array
    {
        if ($this->nameMap !== null) {
            return $this->nameMap;
        }

        $map = [];
        foreach ($this->registry->all() as $key => $tool) {
            if ($tool instanceof ToolInterface) {
                $map[$this->toMcpName((string) $key)] = (string) $key;
            }
        }

        return $this->nameMap = $map;
    }

    /**
     * 注册键 → MCP 名（"plugin:smartstock.xxx" → "plugin.smartstock.xxx"）
     */
    private function toMcpName(string $registryKey): string
    {
        return str_replace(':', '.', $registryKey);
    }
}
