<?php
declare(strict_types=1);

namespace wise\agent\Memory;

use think\facade\Cache;
use think\facade\Log;
use wise\agent\Contract\MemoryInterface;
use wise\agent\Exception\AiException;

/**
 * Redis-backed memory store
 *
 * High-performance memory store using Redis.
 * Supports TTL-based expiration.
 *
 * Note: getAll() and forget(null) have limited support in Redis-based stores.
 * Use DatabaseMemory for full get-all/clear-all capability.
 */
class RedisMemory implements MemoryInterface
{
    protected string $prefix;
    protected int $ttl;

    public function __construct(array $config = [])
    {
        $this->prefix = $config['prefix'] ?? 'wai:mem:';
        $this->ttl    = (int) ($config['ttl'] ?? 86400 * 30); // 30 days default
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

        // Store tag index for search
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
        // Redis doesn't easily support listing all keys by pattern efficiently.
        // Unlike DatabaseMemory, there is no native LIST KEYS command suitable
        // for production use (KEYS is O(N) and blocks). Use tags-based retrieval
        // or switch to DatabaseMemory for full session enumeration.
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

        // Clear all session keys — pattern-based deletion depends on Redis driver support.
        // Most ThinkPHP Redis drivers do not support pattern deletion natively.
        // Log a warning that full clear is not performed; data will expire via TTL.
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
