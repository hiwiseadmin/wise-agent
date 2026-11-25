<?php
declare(strict_types=1);

namespace wise\agent\dto;

/**
 * 工作流节点定义 DTO
 *
 * 封装工作流节点的完整定义，包含 JSON Schema 配置表单，
 * 供 NodeRegistry 序列化和前端工作流设计器渲染节点面板。
 */
class NodeDefinition
{
    protected string $type;
    protected string $namespace;
    protected string $fullType;
    protected string $label;
    protected string $description;
    protected string $icon;
    protected array $configSchema;
    protected string $group;
    protected array $metadata;

    public function __construct(
        string $type,
        string $namespace,
        string $label = '',
        string $description = '',
        string $icon = '',
        array $configSchema = [],
        string $group = 'default',
        array $metadata = []
    ) {
        $this->type         = $type;
        $this->namespace    = $namespace;
        $this->fullType     = $namespace . ':' . $type;
        $this->label        = $label;
        $this->description  = $description;
        $this->icon         = $icon;
        $this->configSchema = $configSchema;
        $this->group        = $group;
        $this->metadata     = $metadata;
    }

    public function getType(): string { return $this->type; }
    public function getNamespace(): string { return $this->namespace; }
    public function getFullType(): string { return $this->fullType; }
    public function getLabel(): string { return $this->label; }
    public function getDescription(): string { return $this->description; }
    public function getIcon(): string { return $this->icon; }
    public function getConfigSchema(): array { return $this->configSchema; }
    public function getGroup(): string { return $this->group; }
    public function getMetadata(): array { return $this->metadata; }

    /**
     * 转换为数组（供 API 返回和前端设计器使用）
     */
    public function toArray(): array
    {
        return [
            'type'          => $this->fullType,
            'label'         => $this->label,
            'description'   => $this->description,
            'icon'          => $this->icon,
            'config_schema' => $this->configSchema,
            'group'         => $this->group,
            'namespace'     => $this->namespace,
            'metadata'      => $this->metadata,
        ];
    }

    /**
     * 从数组还原
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['type'] ?? '',
            $data['namespace'] ?? '',
            $data['label'] ?? '',
            $data['description'] ?? '',
            $data['icon'] ?? '',
            $data['config_schema'] ?? [],
            $data['group'] ?? 'default',
            $data['metadata'] ?? []
        );
    }
}
