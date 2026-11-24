<?php
declare(strict_types=1);

namespace wise\agent\contract;

/**
 * 模型适配器提供者接口（插件 AI 能力协议）
 *
 * 扩展 AiClientInterface，新增 adapterInfo() 方法。
 * 插件通过实现此接口，向框架贡献自定义模型适配器，
 * 使框架可对接私有模型或第三方 API。
 *
 * 注册的适配器可在后台 AI 设置中选择作为"模型驱动"。
 */
interface AdapterProviderInterface extends AiClientInterface
{
    /**
     * 获取提供者命名空间（同 ToolProviderInterface 规则）
     *
     * 命名空间格式：
     *   - 标准插件：'plugin:{ident}'       例：'plugin:demo'
     *   - Composer 包：'composer:{vendor}/{package}'  例：'composer:my/package'
     */
    public function getNamespace(): string;

    /**
     * 获取适配器信息（用于后台展示和选择）
     *
     * @return array{
     *     id: string,           // 适配器唯一标识（含命名空间），例：'plugin:demo.custom_model'
     *     label: string,        // 展示名称
     *     description: string,  // 描述
     *     provider: string,     // Provider 名称（同 getProviderName()）
     *     model: string,        // 默认模型名称（同 getModelName()）
     *     supports_stream: bool,// 是否支持流式输出
     *     supports_tools: bool, // 是否支持 Function Calling
     *     config_schema: array, // JSON Schema 格式的配置表单（API Key、Endpoint 等）
     * }
     */
    public function adapterInfo(): array;
}
