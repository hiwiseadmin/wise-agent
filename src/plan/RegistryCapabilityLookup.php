<?php
declare(strict_types=1);

namespace wise\agent\plan;

use wise\agent\bridge\NodeRegistry;
use wise\agent\dto\NodeDefinition;
use wise\agent\security\AccessResolver;
use wise\agent\security\CapabilityAccess;
use wise\agent\skill\SkillRegistry;
use wise\agent\tool\ToolRegistry;

/**
 * 能力查询的注册表实现（B1-1）
 *
 * | kind | 存在性 | 参数名 | 是否写类 |
 * |---|---|---|---|
 * | `tool` / `action` | `ToolRegistry::has()` | `getToolSchemas([$name])` 的 `parameters.properties` 键 | `AccessResolver::resolveTool()->access ∈ {write, admin}` |
 * | `skill` | `SkillRegistry::has()` | `Skill::getInputSchema()['properties']` 键（不可得 ⇒ `null`，跳过键校验） | `AccessResolver::resolveSkill()->access ∈ {write, admin}` |
 * | `node` | `NodeRegistry::getNodeDefinitions()` 的 `fullType` | `config_schema.properties` 键 | `metadata['write'] === true`（**默认非写**，见下） |
 *
 * ## 两条必须如实记录的边界
 *
 * 1. **node 的写语义没有公认约定**：`NodeDefinition` 只带 `metadata` 自由字段，
 *    框架未定义节点是否写。因此这里**默认按「非写」处理**，仅当节点自报
 *    `metadata['write'] === true` 才视为写步骤 —— 宁可要求节点作者显式声明，
 *    也不要凭空推断一个不存在的约定。
 * 2. **skill 的 access 常常是默认值**：`BaseSkill` 按 category 推导，多数 Skill 声明为
 *    `execute`；因此「技能步骤是否写」的判定**不如工具可靠**。B1 的写路径主要由
 *    `tool` / `action` 承担（A1 的 `menu.action.*` 有明确 access），这是刻意的。
 *
 * 所有查询都 try/catch：注册表不可用（未装配容器）时返回「不存在 / 未知 / 非写」，
 * 由校验器给出明确错误，而不是抛异常。
 */
class RegistryCapabilityLookup implements PlanCapabilityLookupInterface
{
    public function __construct(private ?AccessResolver $accessResolver = null)
    {
    }

    public function exists(string $kind, string $target): bool
    {
        $target = trim($target);

        if ($target === '') {
            return false;
        }

        try {
            return match ($kind) {
                PlanStep::KIND_SKILL  => $this->skillExists($target),
                PlanStep::KIND_NODE   => $this->nodeDefinition($target) !== null,
                PlanStep::KIND_TOOL,
                PlanStep::KIND_ACTION => $this->tool($target) !== null,
                default               => false,
            };
        } catch (\Throwable) {
            return false;
        }
    }

    public function argumentNames(string $kind, string $target): ?array
    {
        try {
            $schema = match ($kind) {
                PlanStep::KIND_SKILL  => $this->skillSchema($target),
                PlanStep::KIND_NODE   => $this->nodeDefinition($target)?->getConfigSchema() ?? [],
                default               => $this->toolParameters($target),
            };
        } catch (\Throwable) {
            return null;
        }

        if (!is_array($schema)) {
            return null;
        }

        $properties = $schema['properties'] ?? null;

        if (!is_array($properties) || $properties === []) {
            return null;
        }

        return array_map('strval', array_keys($properties));
    }

    public function isWrite(string $kind, string $target): bool
    {
        try {
            if ($kind === PlanStep::KIND_NODE) {
                $metadata = $this->nodeDefinition($target)?->getMetadata() ?? [];

                return ($metadata['write'] ?? false) === true;
            }

            $resolver = $this->resolver();

            if ($kind === PlanStep::KIND_SKILL) {
                $skill = $this->skill($target);

                if ($skill === null) {
                    return false;
                }

                return $this->isWriteAccess($resolver->resolveSkill($skill)->access);
            }

            $tool = $this->tool($target);

            if ($tool === null) {
                return false;
            }

            return $this->isWriteAccess($resolver->resolveTool($tool)->access);
        } catch (\Throwable) {
            return false;
        }
    }


    protected function tool(string $name): ?object
    {
        $tool = ToolRegistry::instance()->normalize($name);

        return is_object($tool) ? $tool : null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function toolParameters(string $name): array
    {
        $schemas = ToolRegistry::instance()->getToolSchemas([$name]);

        return is_array($schemas[0]['function']['parameters'] ?? null)
            ? (array) $schemas[0]['function']['parameters']
            : [];
    }

    protected function skillExists(string $name): bool
    {
        return $this->skillRegistry()->has($name);
    }

    protected function skill(string $name): ?object
    {
        $skill = $this->skillRegistry()->get($name);

        return is_object($skill) ? $skill : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function skillSchema(string $name): ?array
    {
        $schema = $this->skill($name)?->getInputSchema();

        return is_array($schema) ? $schema : null;
    }

    protected function nodeDefinition(string $fullType): ?NodeDefinition
    {
        foreach (NodeRegistry::instance()->getNodeDefinitions() as $definition) {
            if ($definition instanceof NodeDefinition && $definition->getFullType() === $fullType) {
                return $definition;
            }
        }

        return null;
    }

    protected function skillRegistry(): SkillRegistry
    {
        return SkillRegistry::instance();
    }

    protected function resolver(): AccessResolver
    {
        return $this->accessResolver ??= new AccessResolver();
    }

    private function isWriteAccess(string $access): bool
    {
        $normalized = CapabilityAccess::normalize($access);

        return $normalized === CapabilityAccess::WRITE || $normalized === CapabilityAccess::ADMIN;
    }
}
