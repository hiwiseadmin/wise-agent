<?php
declare(strict_types=1);

namespace wise\agent\pipeline;

use wise\agent\pipeline\contract\PipelineInterface;

/**
 * 流水线注册表
 */
class PipelineRegistry
{
    /** @var array<string, PipelineInterface> */
    protected array $pipelines = [];

    /** @var array<string, array> 声明式定义缓存 */
    protected array $definitions = [];

    /**
     * 注册流水线
     */
    public function register(PipelineInterface $pipeline): void
    {
        $this->pipelines[$pipeline->getName()] = $pipeline;
    }

    /**
     * 注册声明式流水线定义（从 manifest.json 或配置加载）
     *
     * @param string $name   流水线名称
     * @param array  $config ['steps' => [...], 'description' => '...', 'triggers' => [...]]
     */
    public function registerDefinition(string $name, array $config): void
    {
        $this->definitions[$name] = $config;
    }

    /**
     * 获取流水线
     */
    public function get(string $name): ?PipelineInterface
    {
        if (isset($this->pipelines[$name])) {
            return $this->pipelines[$name];
        }

        if (isset($this->definitions[$name])) {
            return $this->buildFromDefinition($name, $this->definitions[$name]);
        }

        return null;
    }

    /**
     * 检查是否存在
     */
    public function has(string $name): bool
    {
        return isset($this->pipelines[$name]) || isset($this->definitions[$name]);
    }

    /**
     * 列出所有名称
     */
    public function list(): array
    {
        return array_unique(array_merge(
            array_keys($this->pipelines),
            array_keys($this->definitions)
        ));
    }

    /**
     * 获取流水线定义（含步骤详情）
     */
    public function getDefinition(string $name): ?array
    {
        if (isset($this->definitions[$name])) {
            return $this->definitions[$name];
        }
        return null;
    }

    /**
     * 从声明式定义构建流水线实例
     */
    protected function buildFromDefinition(string $name, array $config): PipelineInterface
    {
        $steps = [];
        foreach ($config['steps'] ?? [] as $stepData) {
            $steps[] = PipelineStep::fromArray($stepData);
        }

        return new class($name, $steps, $config) implements PipelineInterface {
            public function __construct(
                private string $name,
                private array $steps,
                private array $config
            ) {}

            public function getName(): string { return $this->name; }
            public function getDescription(): string { return $this->config['description'] ?? ''; }
            public function getSteps(): array { return $this->steps; }
            public function getTriggers(): array { return $this->config['triggers'] ?? ['manual']; }
            public function getSource(): string { return $this->config['source'] ?? 'builtin'; }
        };
    }

    public function count(): int
    {
        return count($this->pipelines) + count($this->definitions);
    }
}
