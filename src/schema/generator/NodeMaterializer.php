<?php
declare(strict_types=1);

namespace wise\agent\schema\generator;

use wise\agent\schema\dto\FieldInfo;
use wise\agent\schema\dto\TableInfo;
use wise\agent\schema\discovery\PluginSchemaDiscovery;
use wise\agent\schema\SchemaIntrospector;
use wise\agent\schema\security\SecurityGuard;

/**
 * 工作流节点实体化器
 *
 * 根据模型 Schema 自动生成工作流节点类文件。
 * 与 ToolMaterializer 对称，输出到 plugin/{ident}/ai/node/。
 *
 * 生成节点类型：
 *   - ConditionNode：字段条件判断（如 stock < threshold）
 *   - ActionNode：  记录状态更新（如 order status → approved）
 *   - NotifyNode：  通知触发占位
 *
 * 使用示例：
 *   php think wise:agent:discover --materialize-node=smartstock
 */
class NodeMaterializer
{
    private string $pluginIdent;
    private string $pluginPath;
    private string $nodeDir;
    private ?PluginSchemaDTO $schema = null;
    private ?SecurityGuard $guard = null;

    private array $stats = [
        'files_generated'  => 0,
        'nodes_generated'  => 0,
        'tables_processed' => 0,
        'manifest_updated' => false,
        'errors'           => [],
    ];

    private ?array $cachedAllTools = null;

    public function __construct(string $pluginIdent)
    {
        $this->pluginIdent = $pluginIdent;
        $this->pluginPath  = root_path() . 'plugin' . DIRECTORY_SEPARATOR . $pluginIdent . DIRECTORY_SEPARATOR;
        $this->nodeDir     = $this->pluginPath . 'ai' . DIRECTORY_SEPARATOR . 'node' . DIRECTORY_SEPARATOR;
    }

    public function materialize(bool $force = false): array
    {
        if (!is_dir($this->pluginPath)) {
            $this->stats['errors'][] = "Plugin directory not found: {$this->pluginPath}";
            return $this->stats;
        }

        $this->discoverSchema($force);
        if ($this->schema === null || $this->schema->getTableCount() === 0) {
            $this->stats['errors'][] = 'No tables found.';
            return $this->stats;
        }

        $this->ensureDir($this->nodeDir);

        foreach ($this->schema->tables as $table) {
            if (!($table instanceof TableInfo)) continue;
            if (!$this->guard->isTableAllowed($table->name)) continue;

            $this->generateNodesForTable($table, $force);
            $this->stats['tables_processed']++;
        }

        return $this->stats;
    }

    private function discoverSchema(bool $force): void
    {
        $manifest = $this->loadManifest();
        $introspector = new SchemaIntrospector();
        $discovery = new PluginSchemaDiscovery($introspector);
        $result = $discovery->discover($this->pluginIdent, $manifest, $force);
        $this->schema = $result->schema;
        $aiSchema = $manifest['ai_schema'] ?? [];
        $this->guard = $this->buildGuard($aiSchema);
    }

    private function buildGuard(array $aiSchema): SecurityGuard
    {
        if (!empty($aiSchema['allowed_tables']) || !empty($aiSchema['writable_fields'])) {
            $guard = SecurityGuard::fromManifest($aiSchema);
            if (empty($aiSchema['allowed_tables'])) {
                foreach ($this->schema->tables as $table) {
                    if ($table instanceof TableInfo) $guard->allowTable($table->name);
                }
            }
            return $guard;
        }
        return SecurityGuard::fromSchema($this->schema);
    }

    private function generateNodesForTable(TableInfo $table, bool $force): void
    {
        $numericFields = $table->getNumericFields();
        foreach ($numericFields as $fieldName => $field) {
            $this->generateConditionNode($table, $fieldName, $field, $force);
        }

        $writable = $this->guard->filterWritableFields($table->name, $table->fields);
        if (!empty($writable)) {
            $this->generateActionNode($table, $writable, $force);
        }

        $this->generateNotifyNode($table, $force);
    }

    private function generateConditionNode(TableInfo $table, string $fieldName, FieldInfo $field, bool $force): void
    {
        $tablePascal = $this->snakeToPascal($table->name);
        $fieldPascal = $this->snakeToPascal($fieldName);
        $className = "Check{$tablePascal}{$fieldPascal}Condition";
        $file = $this->nodeDir . $className . '.php';

        if (file_exists($file) && !$force) return;

        $content = $this->renderConditionNode($table, $fieldName, $field, $className);
        file_put_contents($file, $content, LOCK_EX);
        $this->stats['files_generated']++;
        $this->stats['nodes_generated']++;
    }

    private function generateActionNode(TableInfo $table, array $writable, bool $force): void
    {
        $tablePascal = $this->snakeToPascal($table->name);
        $className = "Update{$tablePascal}Action";
        $file = $this->nodeDir . $className . '.php';

        if (file_exists($file) && !$force) return;

        $content = $this->renderActionNode($table, $writable, $className);
        file_put_contents($file, $content, LOCK_EX);
        $this->stats['files_generated']++;
        $this->stats['nodes_generated']++;
    }

    private function generateNotifyNode(TableInfo $table, bool $force): void
    {
        $tablePascal = $this->snakeToPascal($table->name);
        $className = "Notify{$tablePascal}Change";
        $file = $this->nodeDir . $className . '.php';

        if (file_exists($file) && !$force) return;

        $content = $this->renderNotifyNode($table, $className);
        file_put_contents($file, $content, LOCK_EX);
        $this->stats['files_generated']++;
        $this->stats['nodes_generated']++;
    }

    private function renderConditionNode(TableInfo $table, string $fieldName, FieldInfo $field, string $className): string
    {
        $ns = $this->nodeNamespace();
        $label = $table->comment ?: $table->name;
        $fieldLabel = $field->comment ?: $fieldName;
        $operator = $field->isNumeric() ? 'lt' : 'equals';

        return <<<PHP
<?php
declare(strict_types=1);

namespace {$ns};

use wise\agent\tool\BaseConditionNode;

/**
 * 条件判断：{$label} — {$fieldLabel}
 *
 * 检查 {$fieldName} 字段是否满足条件。
 * 默认操作符：{$operator}
 */
class {$className} extends BaseConditionNode
{
    protected string \$nodeName = '{$this->pluginIdent}_check_{$fieldName}';
    protected string \$nodeDescription = '检查 {$label} 的 {$fieldLabel} 字段';
    protected string \$pluginIdent = '{$this->pluginIdent}';
    protected string \$fieldName = '{$fieldName}';
    protected string \$defaultOperator = '{$operator}';
    protected string \$tableName = '{$table->name}';

    public function getConfigSchema(): array
    {
        return [
            'operator' => [
                'type'    => 'string',
                'enum'    => ['gt', 'gte', 'lt', 'lte', 'equals', 'not_empty', 'empty'],
                'default' => '{$operator}',
                'description' => '比较操作符',
            ],
            'threshold' => [
                'type'    => '{$field->type}',
                'description' => '阈值',
            ],
        ];
    }
}

PHP;
    }

    private function renderActionNode(TableInfo $table, array $writable, string $className): string
    {
        $ns = $this->nodeNamespace();
        $label = $table->comment ?: $table->name;
        $fieldList = [];
        foreach ($writable as $f) {
            if ($f instanceof FieldInfo) {
                $fieldList[] = "        '{$f->name}',";
            }
        }
        $fieldsStr = implode("\n", $fieldList);

        return <<<PHP
<?php
declare(strict_types=1);

namespace {$ns};

use wise\agent\tool\BaseActionNode;

/**
 * 操作：更新 {$label}
 *
 * 按主键更新记录的可写字段。
 */
class {$className} extends BaseActionNode
{
    protected string \$nodeName = '{$this->pluginIdent}_update_{$table->name}';
    protected string \$nodeDescription = '更新 {$label} 记录';
    protected string \$pluginIdent = '{$this->pluginIdent}';
    protected string \$tableName = '{$table->name}';
    protected string \$primaryKey = '{$table->primaryKey}';

    protected array \$updatableFields = [
{$fieldsStr}
    ];
}

PHP;
    }

    private function renderNotifyNode(TableInfo $table, string $className): string
    {
        $ns = $this->nodeNamespace();
        $label = $table->comment ?: $table->name;

        return <<<PHP
<?php
declare(strict_types=1);

namespace {$ns};

use wise\agent\tool\BaseNotifyNode;

/**
 * 通知：{$label} 变更通知
 *
 * 当 {$label} 数据发生变更时触发通知。
 * 开发时可重写 doExecute() 实现邮件/企微/钉钉等通知。
 */
class {$className} extends BaseNotifyNode
{
    protected string \$nodeName = '{$this->pluginIdent}_notify_{$table->name}';
    protected string \$nodeDescription = '{$label} 变更通知';
    protected string \$pluginIdent = '{$this->pluginIdent}';
    protected string \$notifyType = 'log';
}

PHP;
    }

    private function nodeNamespace(): string
    {
        return 'plugin\\' . $this->pluginIdent . '\\ai\\node';
    }

    private function snakeToPascal(string $snake): string
    {
        $prefix = $this->pluginIdent . '_';
        if (strpos($snake, $prefix) === 0) {
            $snake = substr($snake, strlen($prefix));
        }
        return str_replace('_', '', ucwords($snake, '_'));
    }

    private function loadManifest(): array
    {
        $file = $this->pluginPath . 'manifest.json';
        if (!file_exists($file)) return [];
        $content = file_get_contents($file);
        return json_decode($content, true) ?: [];
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir)) mkdir($dir, 0755, true);
    }
}
