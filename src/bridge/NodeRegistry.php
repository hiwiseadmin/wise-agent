<?php
declare(strict_types=1);

namespace wise\agent\bridge;

use wise\agent\contract\NodeProviderInterface;
use wise\agent\dto\NodeDefinition;

/**
 * 工作流节点注册表
 *
 * 集中管理工作流节点提供者，按命名空间隔离。
 * 与 ToolRegistry 设计模式一致，支持懒加载和缓存。
 *
 * 用法：
 *   NodeRegistry::instance()->registerProvider($myNodeProvider);
 *   $nodes = NodeRegistry::instance()->getNodeDefinitions();
 */
class NodeRegistry
{
    protected static ?NodeRegistry $instance = null;

    /** @var array<string, NodeProviderInterface> 命名空间 → 提供者实例 */
    protected array $providers = [];

    /** @var array<string, NodeDefinition[]> 命名空间 → 节点定义列表（缓存） */
    protected array $definitionCache = [];

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
     * 注册一个节点提供者
     */
    public function registerProvider(NodeProviderInterface $provider): void
    {
        $namespace = $provider->getNamespace();
        $this->providers[$namespace] = $provider;
        unset($this->definitionCache[$namespace]);
    }

    /**
     * 按命名空间获取提供者
     */
    public function getProvider(string $namespace): ?NodeProviderInterface
    {
        return $this->providers[$namespace] ?? null;
    }

    /**
     * 检查命名空间是否已注册
     */
    public function hasProvider(string $namespace): bool
    {
        return isset($this->providers[$namespace]);
    }

    /**
     * 移除指定命名空间的提供者
     */
    public function removeProvider(string $namespace): void
    {
        unset($this->providers[$namespace]);
        unset($this->definitionCache[$namespace]);
    }

    /**
     * 获取所有已注册的命名空间
     *
     * @return string[]
     */
    public function getNamespaces(): array
    {
        return array_keys($this->providers);
    }

    /**
     * 获取所有节点定义（供前端工作流设计器使用）
     *
     * @return NodeDefinition[]
     */
    public function getNodeDefinitions(): array
    {
        $definitions = [];

        foreach ($this->providers as $namespace => $provider) {
            if (!isset($this->definitionCache[$namespace])) {
                $nodes = $provider->getNodes();
                $cached = [];
                foreach ($nodes as $nodeDef) {
                    $nodeDef['namespace'] = $nodeDef['namespace'] ?? $namespace;
                    $cached[] = NodeDefinition::fromArray($nodeDef);
                }
                $this->definitionCache[$namespace] = $cached;
            }
            $definitions = array_merge($definitions, $this->definitionCache[$namespace]);
        }

        return $definitions;
    }

    /**
     * 按命名空间获取节点定义
     *
     * @return NodeDefinition[]
     */
    public function getNodeDefinitionsByNamespace(string $namespace): array
    {
        $provider = $this->getProvider($namespace);
        if (!$provider) {
            return [];
        }

        if (!isset($this->definitionCache[$namespace])) {
            $nodes = $provider->getNodes();
            $cached = [];
            foreach ($nodes as $nodeDef) {
                $nodeDef['namespace'] = $nodeDef['namespace'] ?? $namespace;
                $cached[] = NodeDefinition::fromArray($nodeDef);
            }
            $this->definitionCache[$namespace] = $cached;
        }

        return $this->definitionCache[$namespace];
    }

    /**
     * 执行指定节点
     *
     * @param string          $fullType 完整节点类型（含命名空间），如 'plugin:demo:http_request'
     * @param array           $config   节点配置
     * @param array           $input    上游节点输出
     * @param \wise\agent\contract\WorkflowContext $context  工作流上下文
     * @return array{data: mixed, error: null|string}
     */
    public function runNode(string $fullType, array $config, array $input, \wise\agent\contract\WorkflowContext $context): array
    {
        $parts = explode(':', $fullType, 2);
        if (count($parts) !== 2) {
            return ['data' => null, 'error' => "Invalid node type format: {$fullType}"];
        }

        $namespace = $parts[0];
        $nodeType = $parts[1];

        $provider = $this->getProvider($namespace);
        if (!$provider) {
            return ['data' => null, 'error' => "Node provider not found: {$namespace}"];
        }

        try {
            return $provider->run($nodeType, $config, $input, $context);
        } catch (\Throwable $e) {
            return ['data' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * 获取提供者数量
     */
    public function count(): int
    {
        return count($this->providers);
    }

    /**
     * 重置单例（用于测试）
     */
    public static function reset(): void
    {
        self::$instance = null;
    }
}
