<?php
declare(strict_types=1);

namespace wise\agent\bridge;

use think\App;

/**
 * 提供者元数据缓存
 *
 * 将发现的插件 AI 能力元数据序列化后缓存，
 * 避免每次请求都扫描文件系统和实例化提供者。
 *
 * 缓存存储：
 *   - 文件缓存：runtime/wise-agent/provider_cache.php（默认）
 *   - Redis 缓存：wise:agent:provider_cache（可选，需配置）
 *
 * 缓存失效策略：
 *   - PluginInstall/PluginUninstall 事件触发时写失效标记
 *   - 下次请求检测到失效标记时自动重建
 *   - 手动执行 wise:agent:discover 命令时强制重建
 */
class ProviderCache
{
    protected App $app;
    protected string $cacheFile;
    protected ?string $redisKey = null;
    protected int $ttl = 3600;

    public function __construct(App $app)
    {
        $this->app       = $app;
        $this->cacheFile = $app->getRuntimePath() . 'wise-agent' . DIRECTORY_SEPARATOR . 'provider_cache.php';
        $this->redisKey  = $app->config->get('wise-agent.bridge.cache_redis_key', 'wise:agent:provider_cache');
        $this->ttl       = $app->config->get('wise-agent.bridge.cache_ttl', 3600);
    }

    /**
     * 获取缓存的提供者元数据
     *
     * @return array{tools: array, nodes: array, adapters: array, discovered_at: int}|null
     */
    public function get(): ?array
    {
        if ($this->isInvalidated()) {
            return null;
        }

        if ($this->app->config->get('wise-agent.bridge.cache_driver') === 'redis') {
            $data = $this->getFromRedis();
            if ($data !== null) {
                return $data;
            }
        }

        return $this->getFromFile();
    }

    /**
     * 设置缓存
     *
     * @param array{tools: array, nodes: array, adapters: array} $data 元数据
     */
    public function set(array $data): void
    {
        $data['discovered_at'] = time();

        if ($this->app->config->get('wise-agent.bridge.cache_driver') === 'redis') {
            $this->saveToRedis($data);
        }

        $this->saveToFile($data);

        $this->clearInvalidationFlag();
    }

    /**
     * 清除缓存
     */
    public function clear(): void
    {
        if (file_exists($this->cacheFile)) {
            unlink($this->cacheFile);
        }

        if ($this->app->config->get('wise-agent.bridge.cache_driver') === 'redis') {
            try {
                $redis = $this->app->make(\think\cache\driver\Redis::class);
                $redis->delete($this->redisKey);
            } catch (\Throwable $e) {
            }
        }

        $this->invalidate();
    }

    /**
     * 标记缓存失效（供事件监听器调用）
     */
    public function invalidate(): void
    {
        $flagFile = dirname($this->cacheFile) . DIRECTORY_SEPARATOR . 'cache_invalidated.flag';
        $dir = dirname($flagFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($flagFile, (string) time());
    }

    /**
     * 检查缓存是否失效
     */
    public function isInvalidated(): bool
    {
        $flagFile = dirname($this->cacheFile) . DIRECTORY_SEPARATOR . 'cache_invalidated.flag';
        return file_exists($flagFile);
    }

    /**
     * 清除失效标记
     */
    protected function clearInvalidationFlag(): void
    {
        $flagFile = dirname($this->cacheFile) . DIRECTORY_SEPARATOR . 'cache_invalidated.flag';
        if (file_exists($flagFile)) {
            unlink($flagFile);
        }
    }

    /**
     * 从文件读取缓存（使用 JSON 反序列化，避免 var_export/include 的代码注入风险）
     */
    protected function getFromFile(): ?array
    {
        if (!file_exists($this->cacheFile)) {
            return null;
        }

        $content = file_get_contents($this->cacheFile);
        if ($content === false) {
            return null;
        }

        $data = json_decode($content, true);
        if (!is_array($data)) {
            return null;
        }

        $discoveredAt = $data['discovered_at'] ?? 0;
        if (time() - $discoveredAt > $this->ttl) {
            return null;
        }

        return $data;
    }

    /**
     * 写入文件缓存（使用 JSON 序列化，避免 var_export/include 的代码注入风险）
     */
    protected function saveToFile(array $data): void
    {
        $dir = dirname($this->cacheFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $content = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (file_put_contents($this->cacheFile, $content, LOCK_EX) === false) {
            throw new \RuntimeException("Failed to write provider cache to {$this->cacheFile}");
        }
    }

    /**
     * 从 Redis 读取缓存
     */
    protected function getFromRedis(): ?array
    {
        try {
            $redis = $this->app->make(\think\cache\driver\Redis::class);
            $data = $redis->get($this->redisKey);
            return is_array($data) ? $data : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * 写入 Redis 缓存
     */
    protected function saveToRedis(array $data): void
    {
        try {
            $redis = $this->app->make(\think\cache\driver\Redis::class);
            $redis->set($this->redisKey, $data, $this->ttl);
        } catch (\Throwable $e) {
        }
    }
}
