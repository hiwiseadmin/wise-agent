<?php
declare(strict_types=1);

namespace wise\agent\attribute;

use ReflectionClass;
use ReflectionMethod;

/**
 * `#[AgentAction]` 读取器
 *
 * 职责边界（刻意保持很窄）：
 *   - 只做「读 + 校验 + 归一」，不判权限、不查库、不生成工具；
 *   - 校验失败不抛异常，而是记录到 {@see self::errors()} 并返回 null ——
 *     发现阶段应当「跳过坏注解」，而不是让整个 discover 挂掉；
 *   - 输出结构固定，消费方（`MenuActionIntrospector` / `MenuActionMeta`）不直接碰 Attribute 实例。
 *
 * 校验规则（缺一不可）：
 *   1. 方法必须 public / 非 static / 非 abstract；
 *   2. `label` 非空；
 *   3. `access` ∈ read|write|admin，`risk` ∈ low|medium|high；
 *   4. `required` 必须是 `params` 的子集；
 *   5. 非只读动作必须声明 `params`（否则 LLM 无参可依，调用必失败）。
 */
final class AgentActionReader
{
    /** 允许的访问类型 */
    private const ACCESS = ['read', 'write', 'admin'];

    /** 允许的风险等级 */
    private const RISK = ['low', 'medium', 'high'];

    /** @var array<int, array{method:string, message:string}> */
    private array $errors = [];

    /**
     * 读取类上所有方法级注解
     *
     * @return array<string, array<string, mixed>> method => 归一化元数据
     */
    public function readAll(ReflectionClass $class): array
    {
        $this->errors = [];

        $result = [];
        foreach ($class->getMethods() as $method) {
            $meta = $this->readMethod($method);
            if ($meta !== null) {
                $result[$method->getName()] = $meta;
            }
        }

        return $result;
    }

    /**
     * 读取单个方法
     *
     * @return array<string, mixed>|null
     */
    public function read(ReflectionClass $class, string $method): ?array
    {
        if (!$class->hasMethod($method)) {
            return null;
        }

        return $this->readMethod($class->getMethod($method));
    }

    /**
     * 最近一次读取/校验中的错误（供 discover trace 展示）
     *
     * @return array<int, array{method:string, message:string}>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readMethod(ReflectionMethod $method): ?array
    {
        $attributes = $method->getAttributes(AgentAction::class);
        if ($attributes === []) {
            return null;
        }

        $name = $method->getName();

        if (count($attributes) > 1) {
            $this->fail($name, '同一方法只允许声明一个 #[AgentAction]');

            return null;
        }

        try {
            $attribute = $attributes[0]->newInstance();
        } catch (\Throwable $e) {
            $this->fail($name, '注解实例化失败：' . $e->getMessage());

            return null;
        }

        if (!$method->isPublic() || $method->isStatic() || $method->isAbstract()) {
            $this->fail($name, '被 #[AgentAction] 标注的方法必须是 public 且非 static/abstract');

            return null;
        }

        $label = trim($attribute->label);
        if ($label === '') {
            $this->fail($name, 'label 不能为空');

            return null;
        }

        $access = strtolower(trim($attribute->access));
        if (!in_array($access, self::ACCESS, true)) {
            $this->fail($name, sprintf('access 非法：%s（只允许 %s）', $attribute->access, implode('/', self::ACCESS)));

            return null;
        }

        $risk = strtolower(trim($attribute->risk));
        if (!in_array($risk, self::RISK, true)) {
            $this->fail($name, sprintf('risk 非法：%s（只允许 %s）', $attribute->risk, implode('/', self::RISK)));

            return null;
        }

        $params = $attribute->params;
        foreach ($params as $key => $definition) {
            if (!is_string($key) || $key === '' || !is_array($definition)) {
                $this->fail($name, 'params 必须是「参数名 => JSON Schema 定义」的数组');

                return null;
            }
        }

        $required = [];
        foreach ($attribute->required as $item) {
            if (is_string($item) && trim($item) !== '') {
                $required[] = trim($item);
            }
        }

        $missing = array_values(array_diff($required, array_keys($params)));
        if ($missing !== []) {
            $this->fail($name, 'required 中的参数未在 params 中声明：' . implode(', ', $missing));

            return null;
        }

        if ($params === [] && $access !== 'read') {
            $this->fail($name, '非只读动作必须声明 params（否则 LLM 无法构造有效入参）');

            return null;
        }

        $permission = $attribute->permission !== null ? trim($attribute->permission) : '';

        return [
            'label'       => $label,
            'description' => trim($attribute->description),
            'access'      => $access,
            'risk'        => $risk,
            'confirm'     => $attribute->confirm,
            'permission'  => $permission !== '' ? $permission : null,
            'schema'      => $params === []
                ? []
                : ['type' => 'object', 'properties' => $params, 'required' => $required],
            'required'    => $required,
            'source'      => 'attribute',
            'declared_at' => $method->getFileName() . ':' . $method->getStartLine(),
        ];
    }

    private function fail(string $method, string $message): void
    {
        $this->errors[] = ['method' => $method, 'message' => $message];
    }
}
