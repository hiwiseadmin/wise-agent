<?php
declare(strict_types=1);

namespace wise\agent\contract;

/**
 * 工作流节点提供者接口（插件 AI 能力协议）
 *
 * 插件通过实现此接口，向 PipelineEngine 贡献自定义工作流节点。
 * 节点可在工作流设计器中展示，并由 PipelineEngine 动态加载执行。
 *
 * 节点执行契约：
 *   输入 ：$config（节点配置，由设计器保存）+ $input（上游节点输出）
 *   输出 ：{ 'data' => mixed, 'error' => null|string }
 *          统一出口格式，与内置节点无感知差异。
 *
 * 使用示例：
 *   class DemoNodeProvider implements NodeProviderInterface
 *   {
 *       public function getNamespace(): string { return 'plugin:demo'; }
 *       public function getNodes(): array { return [new HttpRequestNode(), ...]; }
 *       public function run(string $nodeType, array $config, array $input, WorkflowContext $ctx): array { ... }
 *   }
 */
interface NodeProviderInterface
{
    /**
     * 获取提供者命名空间（同 ToolProviderInterface 规则）
     */
    public function getNamespace(): string;

    /**
     * 返回节点定义列表（供前端工作流设计器渲染节点面板）
     *
     * 每个定义包含：
     *   - type         : string  节点类型（唯一，含命名空间前缀）
     *   - label        : string  展示名称
     *   - description  : string  描述
     *   - icon         : string  图标 CSS 类名（可选）
     *   - config_schema: array   JSON Schema 格式的配置表单定义
     *   - group        : string  分组名称（可选，用于设计器面板分组）
     *
     * @return array<int, array{type: string, label: string, description: string, icon?: string, config_schema: array, group?: string}>
     */
    public function getNodes(): array;

    /**
     * 执行指定节点
     *
     * @param string          $nodeType 节点类型（不含命名空间，与 getNodes() 中的 type 对应）
     * @param array           $config   节点配置（由工作流设计器保存）
     * @param array           $input    上游节点输出数据
     * @param WorkflowContext $context  工作流执行上下文
     * @return array{data: mixed, error: null|string} 统一出口格式
     */
    public function run(string $nodeType, array $config, array $input, WorkflowContext $context): array;

    /**
     * 获取提供者元数据（可选）
     *
     * @return array{label?: string, description?: string, version?: string}
     */
    public function getMetadata(): array;
}
