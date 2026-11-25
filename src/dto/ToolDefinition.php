<?php
declare(strict_types=1);

namespace wise\agent\dto;

/**
 * 工具定义 DTO
 *
 * 封装工具的完整定义，包含 JSON Schema 参数描述，
 * 供 ToolRegistry 序列化和前端工作流设计器使用。
 */
class ToolDefinition
{
    protected string $name;
    protected string $namespace;
    protected string $fullName;
    protected string $description;
    protected array $parameters;
    protected string $permission;
    protected bool $requireConfirmation;
    protected array $metadata;

    public function __construct(
        string $name,
        string $namespace,
        string $description = '',
        array $parameters = [],
        string $permission = '',
        bool $requireConfirmation = false,
        array $metadata = []
    ) {
        $this->name               = $name;
        $this->namespace          = $namespace;
        $this->fullName           = $namespace . '.' . $name;
        $this->description        = $description;
        $this->parameters         = $parameters;
        $this->permission         = $permission;
        $this->requireConfirmation = $requireConfirmation;
        $this->metadata           = $metadata;
    }

    public function getName(): string { return $this->name; }
    public function getNamespace(): string { return $this->namespace; }
    public function getFullName(): string { return $this->fullName; }
    public function getDescription(): string { return $this->description; }
    public function getParameters(): array { return $this->parameters; }
    public function getPermission(): string { return $this->permission; }
    public function requireConfirmation(): bool { return $this->requireConfirmation; }
    public function getMetadata(): array { return $this->metadata; }

    /**
     * 转换为 Function Calling 格式（供 LLM 使用）
     */
    public function toFunctionSchema(): array
    {
        return [
            'type'     => 'function',
            'function' => [
                'name'        => $this->fullName,
                'description' => $this->description,
                'parameters'  => $this->parameters,
            ],
        ];
    }

    /**
     * 转换为数组（供序列化/缓存）
     */
    public function toArray(): array
    {
        return [
            'name'                => $this->name,
            'namespace'           => $this->namespace,
            'full_name'           => $this->fullName,
            'description'         => $this->description,
            'parameters'          => $this->parameters,
            'permission'          => $this->permission,
            'require_confirmation' => $this->requireConfirmation,
            'metadata'            => $this->metadata,
        ];
    }

    /**
     * 从数组还原
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['name'] ?? '',
            $data['namespace'] ?? '',
            $data['description'] ?? '',
            $data['parameters'] ?? [],
            $data['permission'] ?? '',
            $data['require_confirmation'] ?? false,
            $data['metadata'] ?? []
        );
    }
}
