<?php
declare(strict_types=1);

namespace wise\agent\contract;

/**
 * 会话接口
 *
 * 定义会话消息管理的契约。
 * 实现类可提供不同的后端（数据库、内存等）。
 */
interface ConversationInterface
{
    /**
     * 获取会话标识
     */
    public function getSessionId(): string;

    /**
     * 获取系统提示词
     */
    public function getSystemPrompt(): string;

    /**
     * 设置系统提示词
     */
    public function setSystemPrompt(string $prompt): self;

    /**
     * 向会话中添加一条消息
     *
     * @param string      $role        消息角色（user、assistant、system、tool）
     * @param string|null $content     消息内容
     * @param array|null  $toolCalls   助手发起的工具调用
     * @param string|null $toolCallId  工具调用 ID（用于工具结果）
     * @param string|null $toolName    工具名称（用于工具结果）
     */
    public function addMessage(string $role, ?string $content, ?array $toolCalls = null, ?string $toolCallId = null, ?string $toolName = null): self;

    /**
     * 获取所有消息（含系统提示词）
     *
     * @return array 消息数组列表
     */
    public function getMessages(): array;

    /**
     * 仅获取面向用户的消息（已过滤）
     *
     * @return array user 角色消息数组列表
     */
    public function getUserMessages(): array;

    /**
     * 获取消息总数（不含系统提示词）
     */
    public function count(): int;

    /**
     * 清空所有消息
     */
    public function clear(): self;
}
