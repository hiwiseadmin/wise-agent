<?php
declare(strict_types=1);

namespace wise\agent\contract;

/**
 * AI 客户端接口
 *
 * 定义 LLM Provider 客户端的契约。
 * 新 Provider 可通过实现此接口来添加。
 */
interface AiClientInterface
{
    /**
     * 发送聊天补全请求（非流式）
     *
     * @param array $messages 消息列表 [['role' => 'user', 'content' => '...']]
     * @param array $tools    可用的工具定义（Function Calling 格式）
     * @param array $options  额外参数（temperature、max_tokens 等）
     * @return array ['content' => '...', 'tool_calls' => [...], 'usage' => [...]]
     */
    public function chat(array $messages, array $tools = [], array $options = []): array;

    /**
     * 发送流式聊天补全请求
     *
     * 当 LLM 生成 token 时，逐个产出事件。
     * 事件类型：
     *   - ['type' => 'token', 'content' => '...']         — 单个文本 token
     *   - ['type' => 'tool_call', 'call' => [...]]         — 检测到工具调用（部分）
     *   - ['type' => 'finish', 'reason' => '...']          — 流结束
     *   - ['type' => 'error', 'message' => '...']          — 遇到错误
     *
     * @param array $messages 消息列表
     * @param array $tools    可用的工具定义
     * @param array $options  额外参数（temperature、max_tokens 等）
     * @return \Generator  产出事件数组
     */
    public function chatStream(array $messages, array $tools = [], array $options = []): \Generator;

    /**
     * 获取 Provider 名称
     */
    public function getProviderName(): string;

    /**
     * 获取正在使用的模型名称
     */
    public function getModelName(): string;

    /**
     * 检查 Provider 是否支持某个功能
     */
    public function supports(string $feature): bool;

    /**
     * 列出该 Provider 可用的模型
     *
     * 用途：后台模型下拉框、能力目录、成本预估的前提。
     * 返回**模型标识**（调用时直接填进 `options['model']` 的值），不做联网查询——
     * 各家的 models 接口需要额外权限且未必稳定，这里以配置 + 内置清单为准。
     *
     * @return string[]
     */
    public function listModels(): array;

    /**
     * 估算 token 数
     *
     * 注意：这是**估算**，用于预算与展示，不得用于计费。
     * 真实用量以 `chat()` 返回的 `usage` 为准。
     *
     * @param string|array $input 纯文本，或消息数组
     */
    public function countTokens(string|array $input): int;

    /**
     * 估算单次调用成本
     *
     * 未知模型返回 0.0（表示"未定价"），不编造数字。
     */
    public function estimateCost(int $promptTokens, int $completionTokens): float;
}
