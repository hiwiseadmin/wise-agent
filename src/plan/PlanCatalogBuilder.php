<?php
declare(strict_types=1);

namespace wise\agent\plan;

use wise\agent\bridge\NodeRegistry;
use wise\agent\dto\NodeDefinition;
use wise\agent\skill\SkillRegistry;
use wise\agent\tool\ToolRegistry;

/**
 * 能力目录构建器＼�
 *
 * 从**既有注册表**汇总「能规划什么」，并按策略裁剪：
 *
 * | 来源 | 汇总方式 | kind |
 * |---|---|---|
 * | `ToolRegistry` | `list()` + `normalize()` | `ActionTool` ⇒ `action`，其余 ⇒ `tool` |
 * | `SkillRegistry` | `getOverview()`（按 category 分组） | `skill` |
 * | `NodeRegistry` | `getNodeDefinitions()` | `node` |
 *
 * 三条裁剪规则：
 *
 * 1. **`deny_capabilities` 的能力不进目录**（黑名单在前置生效）；
 *    被隐藏的数量随目录回传（诚实标注，而不是让 LLM 以为「框架没有这个能力」）；
 * 2. **`allowed_kinds` 之外不汇总**；
 * 3. **条目数上限** `max_catalog_entries`（默认 200，防「插件装多了 Prompt 爆炸」）——
 *    超限时按 kind 优先级（tool → action → skill → node）**截断并记数**。
 *
 * 目录项**只带参数名清单**，不带完整 JSON Schema —— 那是第 ② 层按需拉取的事。
 */
class PlanCatalogBuilder
{
    use PlanOptions;

    /**
     * 默认条目上限（`plan.max_catalog_entries`）
     */
    public const DEFAULT_MAX_ENTRIES = 200;

    /**
     * @param array<string, mixed> $options `plan.*` 覆盖项
     */
    public function __construct(
        array $options = [],
        private ?PlanCapabilityLookupInterface $lookup = null
    ) {
        $this->setPlanOptions($options);
    }

    public function build(): PlanCatalog
    {
        $allowed = $this->allowedKinds();
        $deny    = $this->denyPatterns();
        $max     = max(1, (int) $this->planOpt('max_catalog_entries', self::DEFAULT_MAX_ENTRIES));

        $entries = [];
        $hidden  = 0;

        if (in_array(PlanStep::KIND_TOOL, $allowed, true) || in_array(PlanStep::KIND_ACTION, $allowed, true)) {
            foreach ($this->toolEntries($deny, $hidden) as $entry) {
                if (!in_array($entry->kind, $allowed, true)) {
                    continue;
                }

                $entries[] = $entry;
            }
        }

        if (in_array(PlanStep::KIND_SKILL, $allowed, true)) {
            foreach ($this->skillEntries($deny, $hidden) as $entry) {
                $entries[] = $entry;
            }
        }

        if (in_array(PlanStep::KIND_NODE, $allowed, true)) {
            foreach ($this->nodeEntries($deny, $hidden) as $entry) {
                $entries[] = $entry;
            }
        }

        if (count($entries) > $max) {
            $hidden += count($entries) - $max;
            $entries = array_slice($entries, 0, $max);
        }

        return PlanCatalog::of($entries, $hidden);
    }


    /**
     * @param string[] $deny
     * @return PlanCatalogEntry[]
     */
    protected function toolEntries(array $deny, int &$hidden): array
    {
        $entries = [];

        foreach ($this->toolNames() as $name) {
            $kind = $this->kindOfTool($name);

            if ($this->denied($name, $deny)) {
                $hidden++;
                continue;
            }

            $entries[] = new PlanCatalogEntry(
                kind: $kind,
                target: $name,
                description: $this->toolDescription($name),
                args: $this->lookup()->argumentNames($kind, $name),
                write: $this->lookup()->isWrite($kind, $name),
            );
        }

        return $entries;
    }

    /**
     * @param string[] $deny
     * @return PlanCatalogEntry[]
     */
    protected function skillEntries(array $deny, int &$hidden): array
    {
        $entries = [];

        foreach ($this->skillOverview() as $skill) {
            $name = (string) ($skill['name'] ?? '');

            if ($name === '') {
                continue;
            }

            if ($this->denied($name, $deny)) {
                $hidden++;
                continue;
            }

            $entries[] = new PlanCatalogEntry(
                kind: PlanStep::KIND_SKILL,
                target: $name,
                description: (string) ($skill['description'] ?? ''),
                args: $this->lookup()->argumentNames(PlanStep::KIND_SKILL, $name),
                write: $this->lookup()->isWrite(PlanStep::KIND_SKILL, $name),
            );
        }

        return $entries;
    }

    /**
     * @param string[] $deny
     * @return PlanCatalogEntry[]
     */
    protected function nodeEntries(array $deny, int &$hidden): array
    {
        $entries = [];

        foreach ($this->nodeDefinitions() as $definition) {
            $target = $definition->getFullType();

            if ($this->denied($target, $deny)) {
                $hidden++;
                continue;
            }

            $entries[] = new PlanCatalogEntry(
                kind: PlanStep::KIND_NODE,
                target: $target,
                description: $definition->getDescription(),
                args: $this->lookup()->argumentNames(PlanStep::KIND_NODE, $target),
                write: $this->lookup()->isWrite(PlanStep::KIND_NODE, $target),
                label: $definition->getLabel(),
            );
        }

        return $entries;
    }


    /**
     * @return string[]
     */
    protected function toolNames(): array
    {
        $names = ToolRegistry::instance()->list();
        sort($names);

        return array_values(array_map('strval', $names));
    }

    protected function kindOfTool(string $name): string
    {
        $tool = ToolRegistry::instance()->normalize($name);

        if ($tool !== null && class_exists(\wise\agent\action\ActionTool::class)
            && $tool instanceof \wise\agent\action\ActionTool
        ) {
            return PlanStep::KIND_ACTION;
        }

        return PlanStep::KIND_TOOL;
    }

    protected function toolDescription(string $name): string
    {
        try {
            return (string) (ToolRegistry::instance()->normalize($name)?->getDescription() ?? '');
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * 展平 `getOverview()` 的分类结构
     *
     * @return array<int, array<string, mixed>>
     */
    protected function skillOverview(): array
    {
        $flat = [];

        foreach (SkillRegistry::instance()->getOverview() as $skills) {
            foreach ((array) $skills as $skill) {
                if (is_array($skill)) {
                    $flat[] = $skill;
                }
            }
        }

        usort($flat, static fn(array $a, array $b): int => strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')));

        return $flat;
    }

    /**
     * @return NodeDefinition[]
     */
    protected function nodeDefinitions(): array
    {
        $definitions = NodeRegistry::instance()->getNodeDefinitions();

        usort(
            $definitions,
            static fn(NodeDefinition $a, NodeDefinition $b): int => strcmp($a->getFullType(), $b->getFullType())
        );

        return $definitions;
    }

    protected function lookup(): PlanCapabilityLookupInterface
    {
        return $this->lookup ??= new RegistryCapabilityLookup();
    }


    /**
     * @return string[]
     */
    protected function allowedKinds(): array
    {
        $configured = $this->planOpt('allowed_kinds', PlanStep::KINDS);

        if (!is_array($configured)) {
            return PlanStep::KINDS;
        }

        $allowed = array_values(array_intersect(array_map('strval', $configured), PlanStep::KINDS));

        return $allowed === [] ? PlanStep::KINDS : $allowed;
    }

    /**
     * @return string[]
     */
    protected function denyPatterns(): array
    {
        $configured = $this->planOpt('deny_capabilities', []);

        if (!is_array($configured)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn(mixed $pattern): string => trim((string) $pattern),
            $configured
        ), static fn(string $pattern): bool => $pattern !== ''));
    }

    /**
     * @param string[] $patterns
     */
    protected function denied(string $target, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if ($pattern === $target) {
                return true;
            }

            if (str_contains($pattern, '*')
                && preg_match('/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/', $target) === 1
            ) {
                return true;
            }
        }

        return false;
    }

}
