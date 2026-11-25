<?php
declare(strict_types=1);

namespace wise\agent\action;

use wise\agent\contract\ToolInterface;
use wise\agent\security\CapabilityAccess;

/**
 * 声明式业务动作工具
 *
 * 将插件 manifest.json 中声明的 ai_actions 包装为可被 LLM 调用的工具，
 * 执行时通过容器解析目标方法（复用插件已有的 service 方法），
 * 插件无需编写任何 Tool 类。
 *
 * manifest 示例：
 * {
 *     "ai_actions": {
 *         "stock.shelf_product": {
 *             "description": "上架指定商品",
 *             "method": "plugin\\smartstock\\service\\ProductService@shelf",
 *             "params": {
 *                 "product_id": {"type": "int", "required": true, "desc": "商品ID"}
 *             },
 *             "confirm": true,
 *             "permission": "plugin:smartstock.shelf"
 *         }
 *     }
 * }
 *
 * 目标方法约定：public function xxx(array $arguments): mixed
 * 方法接收完整参数关联数组，返回值会被规范化（字符串原样返回，数组/对象 JSON 化）。
 */
class ActionTool implements ToolInterface
{
    /** @var string 动作名称（如 stock.shelf_product） */
    private string $name;

    /** @var string 动作描述（展示给 LLM） */
    private string $description;

    /** @var string 目标方法引用（"Class@method" 或 "Class::method"） */
    private string $method;

    /** @var array JSON Schema 参数定义 */
    private array $parameters;

    /** @var bool 执行前是否需要用户确认 */
    private bool $confirm;

    /** @var string 权限标识 */
    private string $permission;

    /** @var string 访问类型 */
    private string $access;

    /** @var string 风险等级 */
    private string $risk;

    /**
     * @param string $name        动作名称
     * @param string $description 动作描述
     * @param string $method      目标方法引用
     * @param array  $params      参数声明 [name => ['type'=>, 'required'=>, 'desc'=>, 'enum'=>]]
     * @param bool   $confirm     是否需要确认（默认 true，安全优先）
     * @param string $permission  权限标识
     * @param string $access      访问类型（read|write|execute|admin，默认 write——写动作是主流）
     * @param string $risk        风险等级（low|medium|high，默认 medium）
     */
    public function __construct(
        string $name,
        string $description,
        string $method,
        array $params = [],
        bool $confirm = true,
        string $permission = '',
        string $access = CapabilityAccess::WRITE,
        string $risk = CapabilityAccess::RISK_MEDIUM
    ) {
        $this->name        = $name;
        $this->description = $description;
        $this->method      = $method;
        $this->confirm     = $confirm;
        $this->permission  = $permission;
        $this->access      = CapabilityAccess::normalize($access);
        $this->risk        = $risk;
        $this->parameters  = $this->buildParameters($params);
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return $this->description;
    }

    /**
     * @inheritDoc
     */
    public function getParameters(): array
    {
        return $this->parameters;
    }

    /**
     * @inheritDoc
     */
    public function getPermission(): string
    {
        return $this->permission;
    }

    /**
     * @inheritDoc
     */
    public function requireConfirmation(): bool
    {
        return $this->confirm;
    }

    /**
     * @inheritDoc
     */
    public function getAccess(): string
    {
        return $this->access;
    }

    /**
     * @inheritDoc
     */
    public function getRiskLevel(): string
    {
        return $this->risk;
    }

    /**
     * @inheritDoc
     */
    public function execute(array $arguments): string
    {
        try {
            $callable = $this->resolveCallable();
            if ($callable === null) {
                return $this->jsonSafe([
                    'error'   => 'Action not found',
                    'message' => '无法解析动作方法：' . $this->method,
                ]);
            }

            $result = call_user_func($callable, $arguments);

            return $this->normalizeResult($result);
        } catch (\Throwable $e) {
            return $this->jsonSafe([
                'error'   => 'Action error',
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 解析目标方法为可调用数组 [instance, methodName]
     *
     * @return array{0: object, 1: string}|null
     */
    private function resolveCallable(): ?array
    {
        $method = $this->method;

        if (str_contains($method, '@')) {
            [$class, $methodName] = explode('@', $method, 2);
        } elseif (str_contains($method, '::')) {
            [$class, $methodName] = explode('::', $method, 2);
        } else {
            return null;
        }

        $class    = trim($class);
        $methodName = trim($methodName);

        if (!class_exists($class)) {
            return null;
        }

        $instance = null;
        try {
            $instance = app()->make($class);
        } catch (\Throwable $e) {
        }

        if (!is_object($instance)) {
            try {
                $instance = new $class();
            } catch (\Throwable $e) {
                return null;
            }
        }

        if (!is_callable([$instance, $methodName])) {
            return null;
        }

        return [$instance, $methodName];
    }

    /**
     * 将参数声明转换为 JSON Schema
     *
     * @param array $params
     * @return array
     */
    private function buildParameters(array $params): array
    {
        $properties = [];
        $required   = [];

        foreach ($params as $name => $spec) {
            if (!is_array($spec)) {
                $spec = ['type' => 'string', 'desc' => (string) $spec];
            }

            $property = [
                'type'        => $this->mapType((string) ($spec['type'] ?? 'string')),
                'description' => (string) ($spec['desc'] ?? ($spec['description'] ?? $name)),
            ];

            if (isset($spec['enum']) && is_array($spec['enum'])) {
                $property['enum'] = $spec['enum'];
            }

            $properties[$name] = $property;

            if (!empty($spec['required'])) {
                $required[] = $name;
            }
        }

        return [
            'type'       => 'object',
            'properties' => $properties,
            'required'   => $required,
        ];
    }

    /**
     * 映射声明类型到 JSON Schema 类型
     *
     * @param string $type
     * @return string
     */
    private function mapType(string $type): string
    {
        return match ($type) {
            'int', 'integer'            => 'integer',
            'float', 'number', 'double', 'decimal' => 'number',
            'bool', 'boolean'           => 'boolean',
            'array', 'list'             => 'array',
            'object', 'json'            => 'object',
            default                     => 'string',
        };
    }

    /**
     * 规范化方法返回值
     *
     * @param mixed $result
     * @return string
     */
    private function normalizeResult($result): string
    {
        if (is_string($result)) {
            return $result;
        }

        if (is_scalar($result) || $result === null) {
            return (string) $result;
        }

        return $this->jsonSafe($result);
    }

    /**
     * 安全 JSON 编码
     *
     * @param mixed $data
     * @return string
     */
    private function jsonSafe($data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return '{"error":"Encoding error"}';
        }
        return $json;
    }
}
