<?php
declare(strict_types=1);

namespace wise\agent\contract;

/**
 * 记忆接口
 *
 * 定义 AI 记忆存储后端的契约。
 * 支持短期（会话作用域）和长期（持久化）记忆。
 */
interface MemoryInterface
{
    /**
     * 存储一条记忆条目
     *
     * @param string $sessionId 会话标识（全局记忆使用空字符串）
     * @param string $key       记忆键
     * @param mixed  $value     记忆值
     * @param array  $tags      用于分类和检索的标签
     */
    public function store(string $sessionId, string $key, mixed $value, array $tags = []): void;

    /**
     * 检索一条记忆条目
     *
     * @param string $sessionId 会话标识
     * @param string $key       记忆键
     * @param mixed  $default   未找到时的默认值
     * @return mixed
     */
    public function retrieve(string $sessionId, string $key, mixed $default = null): mixed;

    /**
     * 按标签搜索记忆条目
     *
     * @param string $sessionId 会话标识
     * @param array  $tags      要搜索的标签
     * @return array
     */
    public function searchByTags(string $sessionId, array $tags): array;

    /**
     * 获取会话的所有记忆条目
     *
     * @param string $sessionId 会话标识
     * @return array
     */
    public function getAll(string $sessionId): array;

    /**
     * 遗忘（删除）记忆条目
     *
     * @param string      $sessionId 会话标识
     * @param string|null $key       要遗忘的特定键，或 null 表示清空全部
     */
    public function forget(string $sessionId, ?string $key = null): void;
}
