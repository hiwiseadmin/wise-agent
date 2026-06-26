<?php
declare(strict_types=1);

namespace wise\agent\memory;

use think\facade\Config;
use think\facade\Db;
use wise\agent\contract\MemoryInterface;
use wise\agent\contract\UserContextInterface;
use wise\agent\memory\session\SessionUserContext;

/**
 * 数据库支持的记忆存储
 *
 * 将 AI 记忆持久化到数据库以实现长期保留。
 * 使用多态 user_type + user_id 模式（与 auth_user_role 对齐）。
 */
class DatabaseMemory implements MemoryInterface
{
    protected string $table;
    protected UserContextInterface $userContext;

    public function __construct(array $config = [], ?UserContextInterface $userContext = null)
    {
        $this->table = Config::get('wise-agent.tables.memories', 'ai_memories');
        $this->userContext = $userContext ?? new SessionUserContext();
    }

    public function store(string $sessionId, string $key, mixed $value, array $tags = []): void
    {
        $userId = $this->userContext->getUserId();
        $userType = $this->userContext->getUserType();

        // 使用"检查后插入或更新"以保证跨数据库兼容性
        $existing = Db::table($this->table)
            ->where('session_id', $sessionId)
            ->where('key', $key)
            ->find();

        $data = [
            'session_id' => $sessionId,
            'user_type'  => $userType,
            'user_id'    => $userId,
            'key'        => $key,
            'value'      => json_encode($value, JSON_UNESCAPED_UNICODE),
            'tags'       => json_encode($tags, JSON_UNESCAPED_UNICODE),
            'update_time' => date('Y-m-d H:i:s'),
        ];

        if ($existing) {
            Db::table($this->table)
                ->where('session_id', $sessionId)
                ->where('key', $key)
                ->update($data);
        } else {
            $data['create_time'] = date('Y-m-d H:i:s');
            Db::table($this->table)->insert($data);
        }
    }

    public function retrieve(string $sessionId, string $key, mixed $default = null): mixed
    {
        $row = Db::table($this->table)
            ->where('session_id', $sessionId)
            ->where('key', $key)
            ->find();

        if (!$row) {
            return $default;
        }

        return json_decode($row['value'], true) ?? $default;
    }

    public function searchByTags(string $sessionId, array $tags): array
    {
        $rows = Db::table($this->table)
            ->where('session_id', $sessionId)
            ->select()
            ->toArray();

        $results = [];
        foreach ($rows as $row) {
            $rowTags = json_decode($row['tags'] ?? '[]', true) ?? [];
            if (array_intersect($tags, $rowTags)) {
                $results[] = [
                    'key'   => $row['key'],
                    'value' => json_decode($row['value'] ?? 'null', true),
                    'tags'  => $rowTags,
                ];
            }
        }

        return $results;
    }

    public function getAll(string $sessionId): array
    {
        $rows = Db::table($this->table)
            ->where('session_id', $sessionId)
            ->order('update_time', 'desc')
            ->select()
            ->toArray();

        $results = [];
        foreach ($rows as $row) {
            $results[$row['key']] = json_decode($row['value'] ?? 'null', true);
        }

        return $results;
    }

    public function forget(string $sessionId, ?string $key = null): void
    {
        $query = Db::table($this->table)->where('session_id', $sessionId);
        if ($key !== null) {
            $query->where('key', $key);
        }
        $query->delete();
    }

    /**
     * 获取用户上下文（用于测试 / DI）
     */
    public function getUserContext(): UserContextInterface
    {
        return $this->userContext;
    }
}
