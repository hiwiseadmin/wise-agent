<?php
declare(strict_types=1);

namespace wise\agent\contract;

/**
 * 工具提供者接口（插件 AI 能力协议）
 *
 * 与 ToolInterface 的关系：
 *   - ToolInterface  = 单个工具的执行合约（已有，不变）
 *   - ToolProviderInterface = 插件"批量提供工具"的入口
 *
 * 插件只需实现一个 ToolProviderInterface，
 * 框架自动将其下所有工具按命名空间注册到 ToolRegistry。
 *
 * 使用示例：
 *   class DemoToolProvider implements ToolProviderInterface
 *   {
 *       public function getNamespace(): string { return 'plugin:demo'; }
 *       public function getTools(): array { return [new TranslateTool(), ...]; }
 *       public function execute(string $toolName, array $params, RequestContext $ctx): mixed { ... }
 *   }
 */
interface ToolProviderInterface
{
    /**
     * 获取提供者命名空间（用于 ToolRegistry 分组，避免名称冲突）
     *
     * 命名空间格式：
     *   - 标准插件：'plugin:{ident}'       例：'plugin:demo'
     *   - Composer 包：'composer:{vendor}/{package}'  例：'composer:my/package'
     *   - 内置（wise-agent 自带）：'builtin'
     *
     * 完整工具名 = namespace + '.' + toolName
     *   例：'plugin:demo.translate'
     */
    public function getNamespace(): string;

    /**
     * 返回该提供者下的所有工具实例
     *
     * @return ToolInterface[] 工具实例列表
     */
    public function getTools(): array;

    /**
     * 执行指定工具
     *
     * @param string         $toolName 工具名称（不含命名空间）
     * @param array          $params   调用参数
     * @param RequestContext $context  请求上下文（含用户/租户信息）
     * @return mixed 执行结果（字符串或数组，最终会被 JSON 编码）
     */
    public function execute(string $toolName, array $params, RequestContext $context): mixed;

    /**
     * 获取提供者元数据（可选，用于前端展示）
     *
     * @return array{label?: string, description?: string, version?: string}
     */
    public function getMetadata(): array;
}
