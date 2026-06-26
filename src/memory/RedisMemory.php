<?php
declare(strict_types=1);

namespace wise\agent\memory;

use think\facade\Cache;
use think\facade\Log;
use wise\agent\contract\MemoryInterface;
use wise\agent\exception\AiException;

/**
 * Redis 支持的记忆存储
 *
 * 使用 Redis 的高性能记忆存储。
 * 支持基于 TTL 的过期机制。
 *
 * 注意：基于 Redis 的存储对 getAll() 和 forget(null) 的支持有限。
 * 如需完整的获取全部/清空全部能力，请使用 DatabaseMemory。
 */
class RedisMemory implements MemoryInterface
{
    protected string $prefix;
    protected int $ttl;

    public function __construct(array $config = [])
    {
        $this->prefix = $config['prefix'] ?? 'wai:mem:';
        $this->ttl    = (int) ($config['ttl'] ?? 86400 * 30); // 默认 30 天
    }

    public function store(string $sessionId, string $key, mixed $value, array $tags = []): void
    {
        $cacheKey = $this->buildKey($sessionId, $key);
        $data = json_encode([
            'value' => $value,
            'tags'  => $tags,
            'time'  => time(),
        ], JSON_UNESCAPED_UNICODE);

        Cache::store('redis')->set($cacheKey, $data, $this->ttl);

        // 存储标签索引以便搜索
        foreach ($tags as $tag) {
            $tagKey = $this->buildTagKey($sessionId, $tag);
            $existing = Cache::store('redis')->get($tagKey, []);
            if (!is_array($existing)) {
                $existing = [];
            }
            $existing[] = $key;
            $existing = array_unique($existing);
            Cache::store('redis')->set($tagKey, json_encode($existing), $this->ttl);
        }
    }

    public function retrieve(string $sessionId, string $key, mixed $default = null): mixed
    {
        $cacheKey = $this->buildKey($sessionId, $key);
        $data = Cache::store('redis')->get($cacheKey);

        if ($data === null) {
            return $default;
        }

        $decoded = json_decode($data, true);
        return $decoded['value'] ?? $default;
    }

    public function searchByTags(string $sessionId, array $tags): array
    {
        $results = [];
        $seenKeys = [];

        foreach ($tags as $tag) {
            $tagKey = $this->buildTagKey($sessionId, $tag);
            $keysData = Cache::store('redis')->get($tagKey);
            $keys = json_decode($keysData ?? '[]', true) ?? [];

            foreach ($keys as $key) {
                if (in_array($key, $seenKeys, true)) {
                    continue;
                }
                $seenKeys[] = $key;

                $value = $this->retrieve($sessionId, $key);
                if ($value !== null) {
                    $results[] = [
                        'key'   => $key,
                        'value' => $value,
                        'tags'  => $tags,
                    ];
                }
            }
        }

        return $results;
    }

    public function getAll(string $sessionId): array
    {
        // Redis 不支持高效地按模式列出所有键。
        // 与 DatabaseMemory 不同，没有原生 LIST KEYS 命令适合
        // 生产环境使用（KEYS 是 O(N) 且会阻塞）。请使用基于标签的检索，
        // 或切换到 DatabaseMemory 以进行完整的会话枚举。
        throw new AiException(
            'RedisMemory::getAll() is not supported. Use searchByTags() for specific queries, '
            . 'or switch to DatabaseMemory for full session memory enumeration.'
        );
    }

    public function forget(string $sessionId, ?string $key = null): void
    {
        if ($key !== null) {
            Cache::store('redis')->delete($this->buildKey($sessionId, $key));
            return;
        }

        // 清除所有会话键 — 基于模式的删除取决于 Redis 驱动支持。
        // 大多数 ThinkPHP Redis 驱动原生不支持模式删除。
        // 记录警告：未执行完整清除；数据将通过 TTL 过期。
        Log::warning("[RedisMemory] forget(null) called for session {$sessionId}: full clear is not supported. "
            . "Session data will expire via TTL ({$this->ttl}s).");
    }

    protected function buildKey(string $sessionId, string $key): string
    {
        return $this->prefix . $sessionId . ':' . $key;
    }

    protected function buildTagKey(string $sessionId, string $tag): string
    {
        return $this->prefix . 'tag:' . $sessionId . ':' . $tag;
    }
}
