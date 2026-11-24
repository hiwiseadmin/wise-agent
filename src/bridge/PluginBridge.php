<?php
declare(strict_types=1);

namespace wise\agent\bridge;

use think\App;
use wise\agent\contract\RequestContext;
use wise\agent\contract\WorkflowContext;

/**
 * AI 网关中间层（PluginBridge）
 *
 * 统一对外暴露 AI 能力调用入口，内部路由到对应的注册表。
 * 提供工具调用、节点执行、适配器查询等能力。
 *
 * 这是插件系统与 wise-agent 核心之间的桥梁，
 * 所有对插件 AI 能力的访问都通过此类进行。
 *
 * 用法：
 *   $bridge = $app->make(PluginBridge::class);
 *   $result = $bridge->callTool('plugin:demo.translate', $params, $context);
 *   $nodes  = $bridge->getWorkflowNodes();
 */
class PluginBridge
{
    protected App $app;
    protected NodeRegistry $nodeRegistry;
    protected AdapterRegistry $adapterRegistry;

    public function __construct(App $app)
    {
        $this->app           = $app;
        $this->nodeRegistry  = NodeRegistry::instance();
        $this->adapterRegistry = AdapterRegistry::instance();
    }

    /**
     * 调用工具（按完整工具名路由）
     *
     * @param string         $fullName 完整工具名（含命名空间），如 'plugin:demo.translate'
     * @param array          $params   调用参数
     * @param RequestContext $context  请求上下文
     * @return mixed 执行结果
     */
    public function callTool(string $fullName, array $params, RequestContext $context): mixed
    {
        $registry = \wise\agent\tool\ToolRegistry::instance();
        $tool     = $registry->get($fullName);

        if ($tool === null) {
            return ['error' => "Tool not found: {$fullName}"];
        }

        $beforeEvent = new \wise\agent\event\ToolExecute(\wise\agent\event\ToolExecute::PHASE_BEFORE, $fullName, $params);
        $this->app->event->trigger($beforeEvent);

        if ($beforeEvent->skip) {
            return $beforeEvent->mockResult ?? json_encode(['error' => 'Tool execution rejected by security policy'], JSON_UNESCAPED_UNICODE);
        }

        return $registry->execute($fullName, $beforeEvent->arguments);
    }

    /**
     * 按命名空间调用工具
     *
     * @param string         $namespace 命名空间（如 'plugin:demo'）
     * @param string         $toolName  工具名（不含命名空间）
     * @param array          $params    调用参数
     * @param RequestContext $context   请求上下文
     * @return mixed
     */
    public function callToolInNamespace(string $namespace, string $toolName, array $params, RequestContext $context): mixed
    {
        $fullName = $namespace . '.' . $toolName;
        return $this->callTool($fullName, $params, $context);
    }

    /**
     * 获取所有可用工具列表（按命名空间分组，供前端展示）
     *
     * @return array{namespace: string, label: string, tools: array}[]
     */
    public function getToolListGrouped(): array
    {
        $registry = \wise\agent\tool\ToolRegistry::instance();
        $result   = [];

        foreach ($registry->getNamespaces() as $ns) {
            $tools = [];
            foreach ($registry->getByNamespace($ns) as $toolName) {
                $tool = $registry->get($toolName);
                if ($tool instanceof \wise\agent\contract\ToolInterface) {
                    $tools[] = [
                        'name'        => $toolName,
                        'description' => $tool->getDescription(),
                        'parameters'  => $tool->getParameters(),
                    ];
                }
            }

            if (!empty($tools)) {
                $result[] = [
                    'namespace' => $ns,
                    'label'     => $this->getNamespaceLabel($ns),
                    'tools'     => $tools,
                ];
            }
        }

        return $result;
    }

    /**
     * 获取所有工作流节点（供前端设计器使用）
     *
     * @return array 节点定义列表
     */
    public function getWorkflowNodes(): array
    {
        $definitions = $this->nodeRegistry->getNodeDefinitions();
        $result      = [];

        foreach ($definitions as $def) {
            $result[] = $def->toArray();
        }

        return $result;
    }

    /**
     * 执行工作流节点
     *
     * @param string          $fullType 完整节点类型（含命名空间）
     * @param array           $config   节点配置
     * @param array           $input    上游节点输出
     * @param WorkflowContext $context  工作流上下文
     * @return array{data: mixed, error: null|string}
     */
    public function runWorkflowNode(string $fullType, array $config, array $input, WorkflowContext $context): array
    {
        return $this->nodeRegistry->runNode($fullType, $config, $input, $context);
    }

    /**
     * 获取所有可用适配器列表（供后台 AI 设置使用）
     *
     * @return array
     */
    public function getAdapters(): array
    {
        return $this->adapterRegistry->getAdapterInfoList();
    }

    /**
     * 使用指定适配器发送聊天请求
     *
     * @param string $adapterId 适配器 ID
     * @param array  $messages  消息列表
     * @param array  $tools     工具定义
     * @param array  $options   额外选项
     * @return array
     */
    public function chatWithAdapter(string $adapterId, array $messages, array $tools = [], array $options = []): array
    {
        return $this->adapterRegistry->chatWithAdapter($adapterId, $messages, $tools, $options);
    }

    /**
     * 获取命名空间展示标签
     */
    protected function getNamespaceLabel(string $namespace): string
    {
        if ($namespace === 'builtin') {
            return '内置工具';
        }

        if (str_starts_with($namespace, 'plugin:')) {
            $ident = substr($namespace, 7);
            return '插件：' . $ident;
        }

        if (str_starts_with($namespace, 'composer:')) {
            return 'Composer：' . substr($namespace, 9);
        }

        return $namespace;
    }
}
