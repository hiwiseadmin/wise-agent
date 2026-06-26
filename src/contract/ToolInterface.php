<?php
declare(strict_types=1);

namespace wise\agent\contract;

/**
 * 工具接口
 *
 * 定义 Agent 工具（Function Calling）的契约。
 * 插件通过实现此接口来添加自定义工具。
 */
interface ToolInterface
{
    /**
     * 获取工具名称（唯一标识）
     */
    public function getName(): string;

    /**
     * 获取工具描述（展示给 LLM）
     */
    public function getDescription(): string;

    /**
     * 获取参数 schema（JSON Schema 格式，展示给 LLM）
     */
    public function getParameters(): array;

    /**
     * 执行工具
     *
     * @param array $arguments 来自 LLM 的参数
     * @return string 结果文本
     */
    public function execute(array $arguments): string;

    /**
     * 获取权限标识（空字符串表示无限制）
     */
    public function getPermission(): string;

    /**
     * 工具执行前是否需要确认
     */
    public function requireConfirmation(): bool;
}
