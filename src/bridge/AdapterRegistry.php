<?php
declare(strict_types=1);

namespace wise\agent\bridge;

use wise\agent\contract\AdapterProviderInterface;

/**
 * 模型适配器注册表
 *
 * 集中管理模型适配器提供者，按命名空间隔离。
 * 注册的适配器可在后台 AI 设置中选择作为"模型驱动"。
 *
 * 用法：
 *   AdapterRegistry::instance()->registerAdapter($myAdapterProvider);
 *   $adapters = AdapterRegistry::instance()->getAdapterInfoList();
 */
class AdapterRegistry
{
    protected static ?AdapterRegistry $instance = null;

    /** @var array<string, AdapterProviderInterface> 命名空间 → 适配器实例 */
    protected array $adapters = [];

    /**
     * 获取单例实例
     */
    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * 注册一个模型适配器
     */
    public function registerAdapter(AdapterProviderInterface $adapter): void
    {
        $namespace = $adapter->getNamespace();
        $this->adapters[$namespace] = $adapter;
    }

    /**
     * 按命名空间获取适配器
     */
    public function getAdapter(string $namespace): ?AdapterProviderInterface
    {
        return $this->adapters[$namespace] ?? null;
    }

    /**
     * 按适配器 ID 获取适配器（adapterInfo()['id']）
     */
    public function getAdapterById(string $adapterId): ?AdapterProviderInterface
    {
        foreach ($this->adapters as $adapter) {
            $info = $adapter->adapterInfo();
            if ($info['id'] === $adapterId) {
                return $adapter;
            }
        }
        return null;
    }

    /**
     * 检查适配器是否已注册
     */
    public function hasAdapter(string $namespace): bool
    {
        return isset($this->adapters[$namespace]);
    }

    /**
     * 移除指定命名空间的适配器
     */
    public function removeAdapter(string $namespace): void
    {
        unset($this->adapters[$namespace]);
    }

    /**
     * 获取所有已注册的命名空间
     *
     * @return string[]
     */
    public function getNamespaces(): array
    {
        return array_keys($this->adapters);
    }

    /**
     * 获取所有适配器信息列表（供后台 AI 设置页面使用）
     *
     * @return array<int, array> adapterInfo() 列表
     */
    public function getAdapterInfoList(): array
    {
        $list = [];
        foreach ($this->adapters as $adapter) {
            $list[] = $adapter->adapterInfo();
        }
        return $list;
    }

    /**
     * 使用指定适配器发送聊天请求
     *
     * @param string $adapterId 适配器 ID（adapterInfo()['id']）
     * @param array  $messages  消息列表
     * @param array  $tools     工具定义
     * @param array  $options   额外选项
     * @return array 统一响应格式（同 AiClientInterface::chat()）
     */
    public function chatWithAdapter(string $adapterId, array $messages, array $tools = [], array $options = []): array
    {
        $adapter = $this->getAdapterById($adapterId);
        if (!$adapter) {
            return [
                'content'      => '',
                'tool_calls'   => [],
                'usage'        => [],
                'finish_reason' => 'error',
                'error'        => "Adapter not found: {$adapterId}",
            ];
        }

        return $adapter->chat($messages, $tools, $options);
    }

    /**
     * 使用指定适配器发送流式聊天请求
     *
     * @return \Generator 事件流
     */
    public function chatStreamWithAdapter(string $adapterId, array $messages, array $tools = [], array $options = []): \Generator
    {
        $adapter = $this->getAdapterById($adapterId);
        if (!$adapter) {
            yield ['type' => 'error', 'message' => "Adapter not found: {$adapterId}"];
            return;
        }

        yield from $adapter->chatStream($messages, $tools, $options);
    }

    /**
     * 获取适配器数量
     */
    public function count(): int
    {
        return count($this->adapters);
    }

    /**
     * 重置单例（用于测试）
     */
    public static function reset(): void
    {
        self::$instance = null;
    }
}
