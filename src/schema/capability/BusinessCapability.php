<?php
declare(strict_types=1);

namespace wise\agent\schema\capability;

use wise\agent\contract\ToolInterface;
use wise\agent\schema\dto\PluginSchemaDTO;
use wise\agent\schema\dto\TableInfo;

/**
 * 业务能力抽象基类
 *
 * 一个业务能力标签对应一套预定义的：
 *   - Context 提示词：告诉 AI 该领域的业务知识
 *   - 专用 Tools：入库、出库、盘点等业务操作
 *   - 推荐 Skills：关联的内置数据分析 Skill
 *   - 推荐 Pipelines：开箱即用的工作流模板
 *
 * 子类实现示例：
 *   class InventoryCapability extends BusinessCapability
 *   {
 *       public function getContextPrompt(PluginSchemaDTO $schema): string { ... }
 *       public function getTools(PluginSchemaDTO $schema): array { ... }
 *       public function getRecommendedSkills(): array { ... }
 *       public function getRecommendedPipelines(PluginSchemaDTO $schema): array { ... }
 *   }
 */
abstract class BusinessCapability
{
    /**
     * 获取能力标签标识
     *
     * @return string
     */
    abstract public function getTag(): string;

    /**
     * 获取注入到 Agent Context 的领域知识提示词
     *
     * @param PluginSchemaDTO $schema 插件 Schema
     * @return string
     */
    abstract public function getContextPrompt(PluginSchemaDTO $schema): string;

    /**
     * 获取该能力提供的专用 AI Tools
     *
     * @param PluginSchemaDTO $schema 插件 Schema
     * @return ToolInterface[]
     */
    abstract public function getTools(PluginSchemaDTO $schema): array;

    /**
     * 获取推荐的 Skill 名称列表
     *
     * @return string[]
     */
    abstract public function getRecommendedSkills(): array;

    /**
     * 获取推荐的 Pipeline 定义
     *
     * @param PluginSchemaDTO $schema 插件 Schema
     * @return array
     */
    abstract public function getRecommendedPipelines(PluginSchemaDTO $schema): array;

    /**
     * 获取能力需要的核心表候选名（用于缺失检测）
     *
     * 返回格式：
     * [
     *     '角色名' => ['候选表名1', '候选表名2', ...],
     * ]
     *
     * 解析器会自动检测哪些角色没有匹配到，并在结果中标记缺失。
     * 默认返回空数组（无强制要求）。
     *
     * @return array<string, string[]>
     */
    public function getRequiredTables(): array
    {
        return [];
    }

    /**
     * 执行能力解析，返回 CapabilityResult
     *
     * @param PluginSchemaDTO $schema
     * @return CapabilityResult
     */
    public function apply(PluginSchemaDTO $schema): CapabilityResult
    {
        return new CapabilityResult(
            $this->getContextPrompt($schema),
            $this->getTools($schema),
            $this->getRecommendedSkills(),
            $this->getRecommendedPipelines($schema),
            $this->getTag()
        );
    }


    /**
     * 从 Schema 中按候选名查找表
     *
     * 按优先级依次匹配候选表名（取第一个匹配到的）。
     *
     * @param PluginSchemaDTO $schema
     * @param string[] $candidates 候选表名列表（按优先级排序）
     * @return TableInfo|null
     */
    public function findTable(PluginSchemaDTO $schema, array $candidates): ?TableInfo
    {
        foreach ($candidates as $candidate) {
            foreach ($schema->tables as $table) {
                if (!($table instanceof TableInfo)) {
                    continue;
                }
                if ($table->name === $candidate) {
                    return $table;
                }
                if (false !== strpos($table->name, $candidate)) {
                    return $table;
                }
                $shortName = $this->extractShortName($table->name);
                if ($shortName === $candidate) {
                    return $table;
                }
            }
        }

        return null;
    }

    /**
     * 从 Schema 中按候选名查找多张表
     *
     * @param PluginSchemaDTO $schema
     * @param string[] $candidates
     * @return TableInfo[]
     */
    protected function findTables(PluginSchemaDTO $schema, array $candidates): array
    {
        $result = [];
        foreach ($candidates as $candidate) {
            $table = $this->findTable($schema, [$candidate]);
            if ($table !== null) {
                $result[$candidate] = $table;
            }
        }
        return $result;
    }

    /**
     * 从完整表名中提取短名
     *
     * 如 "smartstock_product" → "product"
     * 如 "demo_users" → "users"
     *
     * @param string $fullName
     * @return string
     */
    protected function extractShortName(string $fullName): string
    {
        $pos = strrpos($fullName, '_');
        if ($pos !== false) {
            $short = substr($fullName, $pos + 1);
            if (!empty($short) && !is_numeric($short)) {
                return $short;
            }
        }
        return $fullName;
    }
}
