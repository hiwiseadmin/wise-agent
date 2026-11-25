<?php
declare(strict_types=1);

namespace wise\agent\contract;

/**
 * 用户上下文接口
 *
 * 为 AI 操作抽象用户身份识别（会话追踪、记忆存储、日志记录）。
 * 将 Agent 与硬编码的会话键（admin_id、user_id、user_type）解耦。
 *
 * 实现类可从 session、JWT、API token 或任意自定义
 * 认证机制中读取用户信息。
 */
interface UserContextInterface
{
    /**
     * 获取当前用户 ID
     *
     * @return int 用户 ID，未认证则为 0
     */
    public function getUserId(): int;

    /**
     * 获取当前用户类型
     *
     * @return string 用户类型标识（如 'admin'、'user'、'api'）
     */
    public function getUserType(): string;
}
