<?php
declare(strict_types=1);

namespace wise\agent\schema\discovery;

use wise\agent\schema\SchemaIntrospector;
use wise\agent\schema\dto\PluginSchemaDTO;
use wise\agent\schema\dto\TableInfo;
use wise\agent\schema\dto\FieldInfo;
use wise\agent\schema\generator\AiToolGenerator;
use wise\agent\schema\security\SecurityGuard;
use wise\agent\schema\capability\BusinessCapabilityResolver;
use wise\agent\schema\capability\CapabilityResult;
use wise\agent\tool\ToolRegistry;
use wise\agent\pipeline\PipelineRegistry;
use wise\agent\context\ContextManager;
use wise\agent\schema\SchemaContextProvider;

/**
 * 插件 Schema 发现协调器
 *
 * 编排 Schema 子系统的完整发现流水线：
 *   1. SchemaIntrospector → 内省插件数据模型
 *   2. SecurityGuard → 应用安全白名单
 *   3. AiToolGenerator → 自动生成 8 种标准 CRUD Tool
 *   4. BusinessCapabilityResolver → 解析业务能力（领域知识+专用Tool+Skills+Pipelines）
 *   5. SchemaContextProvider → 注入 Agent system prompt
 *   6. ToolRegistry → 注册所有生成的 Tool
 *
 * 由 PluginAiDiscovery::discoverSchema() 调用，
 * 在插件安装后自动触发。
 *
 * 使用示例：
 *   $discovery = new PluginSchemaDiscovery(new SchemaIntrospector());
 *   $result = $discovery->discover('smartstock');
 *   // $result->schema      → PluginSchemaDTO
 *   // $result->toolCount   → 生成的工具数
 *   // $result->capabilities → ['inventory' => CapabilityResult, ...]
 */
class PluginSchemaDiscovery
{
    /** @var SchemaIntrospector */
    private $introspector;

    /** @var array 缓存：已发现的插件 Schema */
    private static $discoveredSchemas = [];

    /**
     * @param SchemaIntrospector $introspector Schema 内省器
     */
    public function __construct(SchemaIntrospector $introspector)
    {
        $this->introspector = $introspector;
    }


    /**
     * 对插件执行完整 Schema 发现
     *
     * 流水线：
     *   1. 内省插件数据模型（或从缓存加载）
     *   2. 读取 manifest.json + 合并 table_descriptions 手动覆盖
     *   3. 构建 SecurityGuard（白名单 + 字段权限）
     *   4. 生成标准 CRUD Tool（AiToolGenerator）
     *   5. 注册到 ToolRegistry
     *   6. 解析业务能力（BusinessCapabilityResolver）
     *   7. 注入 Schema Context（SchemaContextProvider）
     *   8. 持久化缓存到文件
     *
     * @param string $pluginIdent 插件标识（如 smartstock）
     * @param array|null $manifest manifest.json 内容（null 则自动读取）
     * @param bool $forceRefresh 强制重新内省（跳过缓存）
     * @return SchemaDiscoveryResult 发现结果
     */
    public function discover(string $pluginIdent, ?array $manifest = null, bool $forceRefresh = false): SchemaDiscoveryResult
    {
        $result = new SchemaDiscoveryResult($pluginIdent);

        $schema = null;
        if (!$forceRefresh) {
            $schema = $this->loadSchemaFromCache($pluginIdent);
        }

        if ($schema === null) {
            try {
                $schema = $this->introspector->introspect($pluginIdent);
                $this->saveSchemaToCache($pluginIdent, $schema);
            } catch (\Throwable $e) {
                $result->addError('introspect', $e->getMessage());
                return $result;
            }
        }

        $result->schema = $schema;
        $result->tableCount = $schema->getTableCount();

        if ($schema->getTableCount() === 0) {
            return $result;
        }

        if ($manifest === null) {
            $manifest = $this->loadManifest($pluginIdent);
        }

        $aiSchema = $manifest['ai_schema'] ?? [];

        if (!empty($aiSchema['table_descriptions'])) {
            $schema = $this->mergeTableDescriptions($schema, $aiSchema['table_descriptions']);
            $result->schema = $schema;
        }

        $guard = $this->buildSecurityGuard($schema, $aiSchema);

        $namespace = 'plugin:' . $pluginIdent;
        $registry = ToolRegistry::instance();
        try {
            $generator = new AiToolGenerator($schema, $guard);
            $tools = $generator->generate();
            $result->autoToolCount = count($tools);

            $existing = $registry->getByNamespace($namespace);
            if ($existing !== []) {
                \wise\support\context\ContextualLogger::warning(
                    '[ai-schema] 命名空间已有工具，自动生成按低优先级注册（不删除既有工具）',
                    ['namespace' => $namespace, 'existing' => $existing]
                );
            }

            foreach ($tools as $tool) {
                $registry->register($tool, $tool->getName(), $namespace, ToolRegistry::PRIORITY_AUTO);
                $result->toolCount++;
            }

            $result->toolStats = $generator->getStats();
        } catch (\Throwable $e) {
            $result->addError('tool_generation', $e->getMessage());
        }

        $capabilityTags = $aiSchema['business_capabilities'] ?? [];
        if (!empty($capabilityTags)) {
            foreach ($capabilityTags as $tag) {
                try {
                    $capResult = BusinessCapabilityResolver::resolve($tag, $schema);
                    if (!$capResult->isEmpty()) {
                        $capability = BusinessCapabilityResolver::get($tag);
                        if ($capability !== null) {
                            $required = $capability->getRequiredTables();
                            if (!empty($required)) {
                                $this->checkTableMatch($schema, $capResult, $required);
                            }
                        }

                        $result->capabilities[$tag] = $capResult;
                        $result->capabilityToolCount += count($capResult->tools);

                        foreach ($capResult->tools as $tool) {
                            $registry->register($tool, $tool->getName(), 'capability:' . $pluginIdent . ':' . $tag);
                            $result->toolCount++;
                        }
                    }
                } catch (\Throwable $e) {
                    $result->addError('capability:' . $tag, $e->getMessage());
                }
            }
        }

        if (!empty($result->capabilities)) {
            try {
                $factory = new \wise\agent\schema\capability\CapabilitySkillFactory();
                $autoSkills = $factory->generate($schema, $result->capabilities);

                $skillRegistry = \wise\agent\skill\SkillRegistry::instance();
                foreach ($autoSkills as $skill) {
                    if ($skillRegistry->has($skill->getName())) {
                        continue;
                    }
                    $skillRegistry->register($skill);
                    $result->autoSkillCount++;
                }
            } catch (\Throwable $e) {
                $result->addError('auto_skills', $e->getMessage());
            }
        }

        $customTools = $aiSchema['custom_tools'] ?? [];
        if (!empty($customTools)) {
            foreach ($customTools as $toolClass) {
                try {
                    if (class_exists($toolClass)) {
                        $instance = new $toolClass();
                        $registry->register($instance, null, $namespace);
                        $result->customToolCount++;
                        $result->toolCount++;
                    }
                } catch (\Throwable $e) {
                    $result->addError('custom_tool:' . $toolClass, $e->getMessage());
                }
            }
        }

        $pipelineCount = $this->registerCapabilityPipelines($pluginIdent, $result->capabilities);
        $result->pipelineCount = $pipelineCount;

        $this->injectSchemaContext($pluginIdent, $schema, $result->capabilities);

        self::$discoveredSchemas[$pluginIdent] = $result;

        return $result;
    }

    /**
     * 批量发现：自动发现所有已安装插件的 Schema
     *
     * @param bool $force 是否强制重新发现
     * @return SchemaDiscoveryResult[]
     */
    public function discoverAll(bool $force = false): array
    {
        $results = [];

        $pluginDir = root_path() . 'plugin';
        if (!is_dir($pluginDir)) {
            return $results;
        }

        $pluginDirs = glob($pluginDir . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [];
        foreach ($pluginDirs as $dir) {
            $ident = basename($dir);

            if (!$force && isset(self::$discoveredSchemas[$ident])) {
                $results[$ident] = self::$discoveredSchemas[$ident];
                continue;
            }

            $manifestFile = $dir . DIRECTORY_SEPARATOR . 'manifest.json';
            if (!file_exists($manifestFile)) {
                continue;
            }

            $manifest = json_decode(file_get_contents($manifestFile), true);
            if (!is_array($manifest)) {
                continue;
            }

            $aiSchema = $manifest['ai_schema'] ?? [];
            if (empty($aiSchema['auto_introspect'])) {
                continue;
            }

            $results[$ident] = $this->discover($ident, $manifest);
        }

        return $results;
    }


    /**
     * 将 Schema 和业务能力注入到 Agent 上下文
     *
     * @param string $pluginIdent
     * @param PluginSchemaDTO $schema
     * @param array<string, CapabilityResult> $capabilities
     */
    public function injectSchemaContext(string $pluginIdent, PluginSchemaDTO $schema, array $capabilities): void
    {
        $manager = ContextManager::instance();

        $schemaProvider = new SchemaContextProvider($schema, 30);
        $manager->register($schemaProvider);

        $priority = 25;
        foreach ($capabilities as $tag => $capResult) {
            if (!empty($capResult->contextPrompt)) {
                $capProvider = new BusinessCapabilityContextProvider(
                    $capResult,
                    $pluginIdent,
                    $tag,
                    $priority--
                );
                $manager->register($capProvider);
            }
        }
    }


    /**
     * 合并 manifest.json 中的 table_descriptions 到 Schema
     *
     * 支持手动补充表注释和字段注释，覆盖自动内省的结果。
     * 这是 Level 1 接入方式的核心能力。
     *
     * manifest.json 示例：
     * {
     *     "ai_schema": {
     *         "table_descriptions": {
     *             "smartstock_product": {
     *                 "comment": "商品主表，存储所有 SKU 信息",
     *                 "fields": {
     *                     "cost_price": "成本价（元），用于计算毛利",
     *                     "selling_price": "销售价（元），含税"
     *                 }
     *             }
     *         }
     *     }
     * }
     *
     * @param PluginSchemaDTO $schema 自动内省得到的 Schema
     * @param array $descriptions 来自 manifest 的 table_descriptions
     * @return PluginSchemaDTO 合并后的 Schema
     */
    public function mergeTableDescriptions(PluginSchemaDTO $schema, array $descriptions): PluginSchemaDTO
    {
        foreach ($schema->tables as $table) {
            if (!($table instanceof TableInfo)) {
                continue;
            }

            $overrides = $descriptions[$table->name] ?? null;
            if (!is_array($overrides)) {
                continue;
            }

            if (!empty($overrides['comment'])) {
                $table->comment = $overrides['comment'];
            }

            $fieldOverrides = $overrides['fields'] ?? [];
            if (is_array($fieldOverrides) && !empty($fieldOverrides)) {
                foreach ($table->fields as $field) {
                    if ($field instanceof FieldInfo && isset($fieldOverrides[$field->name])) {
                        $field->comment = $fieldOverrides[$field->name];
                    }
                }
            }
        }

        return $schema;
    }


    /**
     * 获取 Schema 缓存目录
     *
     * @return string
     */
    private function getCacheDir(): string
    {
        $runtimePath = defined('RUNTIME_PATH') ? RUNTIME_PATH : sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wiseadmin' . DIRECTORY_SEPARATOR;
        $subDir = 'wise-agent' . DIRECTORY_SEPARATOR . 'schema';
        return $runtimePath . $subDir;
    }

    /**
     * 从文件缓存加载 Schema
     *
     * @param string $pluginIdent
     * @return PluginSchemaDTO|null
     */
    private function loadSchemaFromCache(string $pluginIdent): ?PluginSchemaDTO
    {
        $cacheFile = $this->getCacheDir() . DIRECTORY_SEPARATOR . $pluginIdent . '.json';

        if (!file_exists($cacheFile)) {
            return null;
        }

        $mtime = filemtime($cacheFile);
        $ttl = (int) (function_exists('config') ? config('wise-agent.schema.cache_ttl', 3600) : 3600);
        if (time() - $mtime > $ttl) {
            return null;
        }

        try {
            $content = file_get_contents($cacheFile);
            if ($content === false) {
                return null;
            }
            $data = json_decode($content, true);
            if (!is_array($data) || empty($data['tables'])) {
                return null;
            }
            return PluginSchemaDTO::fromArray($data);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * 将 Schema 持久化到文件缓存
     *
     * @param string $pluginIdent
     * @param PluginSchemaDTO $schema
     */
    private function saveSchemaToCache(string $pluginIdent, PluginSchemaDTO $schema): void
    {
        $dir = $this->getCacheDir();
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $cacheFile = $dir . DIRECTORY_SEPARATOR . $pluginIdent . '.json';
        $data = $schema->toArray();

        $content = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        file_put_contents($cacheFile, $content, LOCK_EX);
    }

    /**
     * 使指定插件的 Schema 缓存失效
     *
     * 在插件卸载、模型变更后调用。
     *
     * @param string $pluginIdent
     */
    public function invalidateSchemaCache(string $pluginIdent): void
    {
        $cacheFile = $this->getCacheDir() . DIRECTORY_SEPARATOR . $pluginIdent . '.json';
        if (file_exists($cacheFile)) {
            unlink($cacheFile);
        }

        unset(self::$discoveredSchemas[$pluginIdent]);
    }

    /**
     * 使所有插件的 Schema 缓存失效
     */
    public function invalidateAllSchemaCache(): void
    {
        $dir = $this->getCacheDir();
        if (is_dir($dir)) {
            $files = glob($dir . DIRECTORY_SEPARATOR . '*.json') ?: [];
            foreach ($files as $file) {
                unlink($file);
            }
        }

        self::$discoveredSchemas = [];
    }


    /**
     * 导出业务能力的上下文提示词到文件
     *
     * 方便开发者手动查看和完善领域知识。
     *
     * @param string $pluginIdent
     * @param string $tag
     * @return string|false 导出的文件路径，失败返回 false
     */
    public function exportCapabilityContext(string $pluginIdent, string $tag)
    {
        $schema = null;
        try {
            $schema = $this->introspector->introspect($pluginIdent);
        } catch (\Throwable $e) {
            return false;
        }

        $capResult = BusinessCapabilityResolver::resolve($tag, $schema);
        if ($capResult->isEmpty()) {
            return false;
        }

        $pluginDir = root_path() . 'plugin' . DIRECTORY_SEPARATOR . $pluginIdent;
        $aiDir = $pluginDir . DIRECTORY_SEPARATOR . '.ai';
        if (!is_dir($aiDir)) {
            mkdir($aiDir, 0755, true);
        }

        $exportFile = $aiDir . DIRECTORY_SEPARATOR . 'capability-' . $tag . '.md';

        $content = "# 业务能力：{$tag}\n\n"
            . "> 插件：{$pluginIdent}\n"
            . "> 导出时间：" . date('Y-m-d H:i:s') . "\n\n"
            . "## 领域知识 Context\n\n"
            . $capResult->contextPrompt . "\n\n"
            . "## 推荐 Skills\n\n"
            . implode("\n", array_map(function ($s) { return "- {$s}"; }, $capResult->skills)) . "\n\n"
            . "## 推荐 Pipelines\n\n";

        foreach ($capResult->pipelines as $name => $pipeline) {
            $content .= "### {$pipeline['name']}\n";
            $content .= "{$pipeline['description']}\n\n";
        }

        file_put_contents($exportFile, $content);
        return $exportFile;
    }

    /**
     * 导出所有已解析能力的上下文到文件
     *
     * @param string $pluginIdent
     * @param array<string, CapabilityResult> $capabilities
     * @return string[] 导出的文件路径列表
     */
    public function exportAllCapabilityContexts(string $pluginIdent, array $capabilities): array
    {
        $files = [];
        foreach ($capabilities as $tag => $capResult) {
            $file = $this->exportCapabilityContext($pluginIdent, $tag);
            if ($file !== false) {
                $files[] = $file;
            }
        }
        return $files;
    }

    /**
     * 导出 Schema 用于手动完善的 JSON 模板
     *
     * 以 manifest.json 中 table_descriptions 的格式输出自动内省结果，
     * 开发者复制到 manifest.json 后修改 comment/fields 即可。
     *
     * 输出示例：
     * {
     *     "smartstock_product": {
     *         "comment": "商品主表",
     *         "fields": {
     *             "name": "商品名称",
     *             "price": "价格"
     *         }
     *     }
     * }
     *
     * @param string $pluginIdent 插件标识
     * @param bool $onlyEssential 仅导出有注释的字段
     * @return array table_descriptions 格式的数据
     */
    public function dumpSchemaForEditing(string $pluginIdent, bool $onlyEssential = false): array
    {
        $schema = $this->loadSchemaFromCache($pluginIdent);

        if ($schema === null) {
            try {
                $schema = $this->introspector->introspect($pluginIdent);
                $this->saveSchemaToCache($pluginIdent, $schema);
            } catch (\Throwable $e) {
                return ['error' => $e->getMessage()];
            }
        }

        $result = [];

        foreach ($schema->tables as $table) {
            if (!($table instanceof TableInfo)) {
                continue;
            }

            $entry = [
                'comment' => $table->comment ?: ($table->name . ' 表'),
                'fields'  => [],
            ];

            $fieldCount = 0;
            foreach ($table->fields as $field) {
                if (!($field instanceof FieldInfo)) {
                    continue;
                }

                if ($onlyEssential && empty($field->comment)) {
                    continue;
                }

                if (in_array($field->name, ['id', 'create_time', 'update_time', 'delete_time', 'created_at', 'updated_at', 'deleted_at'], true)) {
                    continue;
                }

                $entry['fields'][$field->name] = $field->comment ?: ($field->name . '（待补充描述）');
                $fieldCount++;
            }

            if ($onlyEssential && $fieldCount === 0 && empty($table->comment)) {
                continue;
            }

            $result[$table->name] = $entry;
        }

        return $result;
    }

    /**
     * 导出 Schema 为 JSON 字符串
     *
     * @param string $pluginIdent
     * @param bool $onlyEssential
     * @return string
     */
    public function dumpSchemaAsJson(string $pluginIdent, bool $onlyEssential = false): string
    {
        $data = $this->dumpSchemaForEditing($pluginIdent, $onlyEssential);
        if (isset($data['error'])) {
            return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        }
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /**
     * 导出 Schema 为 Markdown 格式
     *
     * @param string $pluginIdent
     * @return string
     */
    public function dumpSchemaAsMarkdown(string $pluginIdent): string
    {
        $data = $this->dumpSchemaForEditing($pluginIdent, false);
        if (isset($data['error'])) {
            return "**Error**: {$data['error']}\n";
        }

        $lines = [];
        $lines[] = "# 插件 {$pluginIdent} — Schema 自动描述\n";
        $lines[] = "> 以下为自动内省生成的表和字段描述。\n";
        $lines[] = "> 请审阅后在「修改为」列填写更准确的描述。\n";
        $lines[] = "> 然后将 JSON 复制到 manifest.json 的 ai_schema.table_descriptions 中。\n\n";
        $lines[] = "```json\n" . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n```\n\n";

        foreach ($data as $tableName => $tableInfo) {
            $lines[] = "## {$tableName} — {$tableInfo['comment']}\n";
            $lines[] = "| 字段 | 当前描述 | 修改为 |";
            $lines[] = "|------|---------|--------|";
            foreach ($tableInfo['fields'] as $fn => $fc) {
                $lines[] = "| `{$fn}` | {$fc} | |";
            }
            $lines[] = '';
        }

        $lines[] = "---\n";
        $lines[] = "**使用方式**:\n";
        $lines[] = "1. 审核上述自动描述，在「修改为」列填写更准确的描述\n";
        $lines[] = "2. 将修改后的 JSON 复制到 manifest.json 的 ai_schema.table_descriptions\n";
        $lines[] = "3. 重新安装/刷新插件即可生效\n";

        return implode("\n", $lines);
    }

    /**
     * 加载插件的 manifest.json
     *
     * @param string $pluginIdent
     * @return array
     */
    private function loadManifest(string $pluginIdent): array
    {
        $manifestFile = root_path() . 'plugin' . DIRECTORY_SEPARATOR . $pluginIdent . DIRECTORY_SEPARATOR . 'manifest.json';
        if (!file_exists($manifestFile)) {
            return [];
        }

        $content = file_get_contents($manifestFile);
        if ($content === false) {
            return [];
        }

        $manifest = json_decode($content, true);
        return is_array($manifest) ? $manifest : [];
    }

    /**
     * 检查能力的核心表匹配情况
     *
     * @param PluginSchemaDTO $schema
     * @param CapabilityResult $result
     * @param array<string, string[]> $required
     */
    private function checkTableMatch(PluginSchemaDTO $schema, CapabilityResult $result, array $required): void
    {
        $unmatched = [];
        foreach ($required as $role => $candidates) {
            $found = false;
            foreach ($candidates as $candidate) {
                foreach ($schema->tables as $table) {
                    if ($table instanceof TableInfo) {
                        if ($table->name === $candidate || false !== strpos($table->name, $candidate)) {
                            $found = true;
                            break 2;
                        }
                    }
                }
            }
            if (!$found) {
                $unmatched[$role] = $candidates;
            }
        }

        if (!empty($unmatched)) {
            $result->setTableMatchResult($unmatched, count($required));
        }
    }

    /**
     * 注册能力推荐的 Pipeline 到 PipelineRegistry
     *
     * 遍历所有已解析的能力，将其推荐的 Pipeline 注册到全局 PipelineRegistry。
     * 注册后 AI 可以通过 wise_agent_pipeline_run() 直接调度。
     *
     * @param string $pluginIdent
     * @param array<string, CapabilityResult> $capabilities
     * @return int 注册的 Pipeline 数量
     */
    public function registerCapabilityPipelines(string $pluginIdent, array $capabilities): int
    {
        $pipelineRegistry = $this->resolvePipelineRegistry();
        $count = 0;

        foreach ($capabilities as $tag => $capResult) {
            if (empty($capResult->pipelines)) {
                continue;
            }

            foreach ($capResult->pipelines as $pipelineName => $pipelineConfig) {
                $definition = $this->normalizePipelineDefinition(
                    $pipelineName,
                    $pipelineConfig,
                    $pluginIdent,
                    $tag
                );

                $pipelineRegistry->registerDefinition($pipelineName, $definition);
                $count++;
            }
        }

        return $count;
    }

    /**
     * 将能力 Pipeline 格式标准化为 PipelineRegistry 格式
     *
     * 输入（BusinessCapability 格式）：
     * {
     *     "name": "库存健康度报告",
     *     "description": "分析库存数据...",
     *     "steps": [
     *         {"skill": "data.analyze", "input_mapping": {...}}
     *     ]
     * }
     *
     * 输出（PipelineRegistry 格式）：
     * {
     *     "description": "库存健康度报告 — 分析库存数据...",
     *     "steps": [...],
     *     "triggers": ["manual"],
     *     "source": "capability:smartstock:inventory"
     * }
     *
     * @param string $name
     * @param array $config
     * @param string $pluginIdent
     * @param string $capabilityTag
     * @return array
     */
    private function normalizePipelineDefinition(string $name, array $config, string $pluginIdent, string $capabilityTag): array
    {
        return [
            'description' => ($config['name'] ?? $name) . ' — ' . ($config['description'] ?? ''),
            'steps'       => $config['steps'] ?? [],
            'triggers'    => $config['triggers'] ?? ['manual'],
            'source'      => $config['source'] ?? ('capability:' . $pluginIdent . ':' . $capabilityTag),
            'meta' => [
                'plugin'      => $pluginIdent,
                'capability'  => $capabilityTag,
                'name'        => $config['name'] ?? $name,
            ],
        ];
    }

    /**
     * 获取 PipelineRegistry 实例
     *
     * 优先级：容器绑定 → 直接构造
     *
     * @return PipelineRegistry
     */
    private function resolvePipelineRegistry(): PipelineRegistry
    {
        if (function_exists('app')) {
            try {
                $registry = app()->make(PipelineRegistry::class);
                if ($registry instanceof PipelineRegistry) {
                    return $registry;
                }
            } catch (\Throwable $e) {
            }
        }

        return new PipelineRegistry();
    }

    /**
     * 从 Schema 和 ai_schema 配置构建 SecurityGuard
     *
     * @param PluginSchemaDTO $schema
     * @param array $aiSchema
     * @return SecurityGuard
     */
    private function buildSecurityGuard(PluginSchemaDTO $schema, array $aiSchema): SecurityGuard
    {
        $hasManifestSecurity = !empty($aiSchema['allowed_tables'])
            || !empty($aiSchema['writable_fields'])
            || !empty($aiSchema['owner_keys'])
            || isset($aiSchema['auto_owner_scope'])
            || isset($aiSchema['auto_detect_owner_key']);

        if ($hasManifestSecurity) {
            $guard = SecurityGuard::fromManifest($aiSchema);

            if (empty($aiSchema['allowed_tables'])) {
                foreach ($schema->tables as $table) {
                    if (method_exists($table, 'getName')) {
                        $guard->allowTable($table->getName());
                    } elseif (isset($table->name)) {
                        $guard->allowTable($table->name);
                    }
                }
            }
        } else {
            $guard = SecurityGuard::fromSchema($schema);
        }

        if (!array_key_exists('auto_owner_scope', $aiSchema)) {
            $defaultScope = function_exists('config')
                ? (bool) config('wise-agent.schema.defaults.auto_owner_scope', true)
                : true;
            $guard->setAutoOwnerScope($defaultScope);
        }
        if (!array_key_exists('auto_detect_owner_key', $aiSchema)) {
            $defaultDetect = function_exists('config')
                ? (bool) config('wise-agent.schema.defaults.auto_detect_owner_key', true)
                : true;
            $guard->setAutoDetectOwnerKey($defaultDetect);
        }

        $guard->detectOwnerKeys($schema);

        return $guard;
    }

    /**
     * 获取已缓存的 Schema 发现结果
     *
     * @param string $pluginIdent
     * @return SchemaDiscoveryResult|null
     */
    public static function getDiscovered(string $pluginIdent): ?SchemaDiscoveryResult
    {
        return self::$discoveredSchemas[$pluginIdent] ?? null;
    }

    /**
     * 清除缓存
     */
    public static function clearCache(): void
    {
        self::$discoveredSchemas = [];
    }
}

