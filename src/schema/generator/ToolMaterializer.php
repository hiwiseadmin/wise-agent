<?php
declare(strict_types=1);

namespace wise\agent\schema\generator;

use wise\agent\schema\dto\FieldInfo;
use wise\agent\schema\dto\TableInfo;
use wise\agent\schema\dto\PluginSchemaDTO;
use wise\agent\schema\discovery\PluginSchemaDiscovery;
use wise\agent\schema\SchemaIntrospector;
use wise\agent\schema\security\SecurityGuard;

/**
 * 工具实体化器
 *
 * 将自动生成的 AI Tool 从运行时缓存固化为插件目录下的 PHP 类文件。
 * 适用于开发完成后不再频繁变更的稳定插件。
 *
 * 生成目录结构：
 *   plugin/{ident}/ai/
 *   └── tool/
 *       ├── BaseAutoTool.php              # 共享执行基类
 *       ├── List{Table}.php               # 列表查询
 *       ├── Get{Table}.php                # 单条查询
 *       ├── Search{Table}.php             # 关键词搜索
 *       ├── Stats{Table}.php              # 统计聚合
 *       ├── Create{Table}.php             # 创建记录
 *       ├── Update{Table}.php             # 更新记录
 *       ├── Delete{Table}.php             # 删除记录
 *       ├── List{Table}With{Relation}.php # 关联查询
 *       └── {Ident}SchemaToolProvider.php # 统一 Provider
 *
 * 使用示例：
 *   php think wise:agent:discover --materialize=smartstock
 */
class ToolMaterializer
{
    /** @var string 插件标识 */
    private string $pluginIdent;

    /** @var string 插件根目录 */
    private string $pluginPath;

    /** @var PluginSchemaDTO|null */
    private ?PluginSchemaDTO $schema = null;

    /** @var SecurityGuard|null */
    private ?SecurityGuard $guard = null;

    /** @var string 工具输出目录 */
    private string $toolDir;

    /** @var array 生成统计 */
    private array $stats = [
        'files_generated'  => 0,
        'tools_generated'  => 0,
        'tables_processed' => 0,
        'base_class'       => false,
        'provider'         => false,
        'manifest_updated' => false,
        'errors'           => [],
    ];

    /**
     * @param string $pluginIdent 插件标识
     */
    public function __construct(string $pluginIdent)
    {
        if ($pluginIdent === ''
            || $pluginIdent === '.'
            || $pluginIdent === '..'
            || str_contains($pluginIdent, '/')
            || str_contains($pluginIdent, '\\')
            || str_contains($pluginIdent, '..')) {
            throw new \InvalidArgumentException('非法的插件标识: ' . $pluginIdent);
        }
        $this->pluginIdent = $pluginIdent;
        $this->pluginPath  = root_path() . 'plugin' . DIRECTORY_SEPARATOR . $pluginIdent . DIRECTORY_SEPARATOR;
        $this->toolDir     = $this->pluginPath . 'ai' . DIRECTORY_SEPARATOR . 'tool' . DIRECTORY_SEPARATOR;
    }

    /**
     * 执行实体化
     *
     * @param bool $force 是否强制覆盖已有文件
     * @return array 生成统计
     */
    public function materialize(bool $force = false): array
    {
        if (!is_dir($this->pluginPath)) {
            $this->stats['errors'][] = "Plugin directory not found: {$this->pluginPath}";
            return $this->stats;
        }

        $this->discoverSchema($force);

        if ($this->schema === null || $this->schema->getTableCount() === 0) {
            $this->stats['errors'][] = 'No tables found in plugin schema.';
            return $this->stats;
        }

        $this->ensureDir($this->toolDir);

        foreach ($this->schema->tables as $table) {
            if (!($table instanceof TableInfo)) {
                continue;
            }

            if (!$this->guard->isTableAllowed($table->name)) {
                continue;
            }

            $this->generateToolsForTable($table, $force);
            $this->stats['tables_processed']++;
        }

        $this->generateProvider($force);

        $this->updateManifest();

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
                    if ($table instanceof TableInfo) {
                        $guard->allowTable($table->name);
                    }
                }
            }
            return $guard;
        }
        return SecurityGuard::fromSchema($this->schema);
    }


    private function generateBaseClass(bool $force): void
    {
        $file = $this->toolDir . 'BaseAutoTool.php';

        if (file_exists($file) && !$force) {
            $this->stats['base_class'] = true;
            return;
        }

        $content = $this->renderBaseClassTemplate();
        file_put_contents($file, $content, LOCK_EX);
        $this->stats['base_class'] = true;
        $this->stats['files_generated']++;
    }

    private function renderBaseClassTemplate(): string
    {
        $namespace = $this->toolNamespace();
        $lines = [];

        $lines[] = '<?php';
        $lines[] = 'declare(strict_types=1);';
        $lines[] = '';
        $lines[] = "namespace {$namespace};";
        $lines[] = '';
        $lines[] = 'use think\facade\Db;';
        $lines[] = 'use wise\agent\contract\ToolInterface;';
        $lines[] = 'use wise\agent\security\CapabilityAccess;';
        $lines[] = '';
        $lines[] = '/**';
        $lines[] = ' * 自动生成 AI Tool 基类';
        $lines[] = ' *';
        $lines[] = ' * 为插件 ' . $this->pluginIdent . ' 自动生成，';
        $lines[] = ' * 提供 8 种标准 CRUD 操作的执行逻辑。';
        $lines[] = ' * 所有具体工具类继承此类，仅需定义配置。';
        $lines[] = ' *';
        $lines[] = ' * 生成时间：' . date('Y-m-d H:i:s');
        $lines[] = ' * 生成命令：php think wise:agent:discover --materialize=' . $this->pluginIdent;
        $lines[] = ' *';
        $lines[] = ' * 如需自定义某个工具的行为，重写对应方法即可。';
        $lines[] = ' */';
        $lines[] = 'abstract class BaseAutoTool implements ToolInterface';
        $lines[] = '{';
        $lines[] = '    /** @var string 表名 */';
        $lines[] = '    protected string $tableName = \'\';';
        $lines[] = '';
        $lines[] = '    /** @var string 主键字段名 */';
        $lines[] = '    protected string $primaryKey = \'id\';';
        $lines[] = '';
        $lines[] = '    /** @var string 工具类型 */';
        $lines[] = '    protected string $toolType = \'\';';
        $lines[] = '';
        $lines[] = '    /** @var bool 是否软删除 */';
        $lines[] = '    protected bool $hasSoftDelete = false;';
        $lines[] = '';
        $lines[] = '    /** @var string|null 软删除字段 */';
        $lines[] = '    protected ?string $deleteTimeField = null;';
        $lines[] = '';
        $lines[] = '    /** @var string[] 字段名列表 */';
        $lines[] = '    protected array $fieldNames = [];';
        $lines[] = '';
        $lines[] = '    /** @var string[] 可搜索字段名列表 */';
        $lines[] = '    protected array $searchableFields = [];';
        $lines[] = '';
        $lines[] = '    /** @var string[] 数值字段名列表 */';
        $lines[] = '    protected array $numericFields = [];';
        $lines[] = '';
        $lines[] = '    /** @var string[] 可写字段名列表 */';
        $lines[] = '    protected array $writableFieldNames = [];';
        $lines[] = '';
        $lines[] = '    /** @var string 关联关系名（仅 list_with_relation） */';
        $lines[] = '    protected string $relationName = \'\';';
        $lines[] = '';
        $lines[] = '    /** @var string 工具名称 */';
        $lines[] = '    protected string $toolName = \'\';';
        $lines[] = '';
        $lines[] = '    /** @var string 工具描述 */';
        $lines[] = '    protected string $toolDescription = \'\';';
        $lines[] = '';
        $lines[] = '    // ──────────────────────────────────────';
        $lines[] = '    //  ToolInterface';
        $lines[] = '    // ──────────────────────────────────────';
        $lines[] = '';
        $lines[] = '    public function getName(): string';
        $lines[] = '    {';
        $lines[] = '        return $this->toolName;';
        $lines[] = '    }';
        $lines[] = '';
        $lines[] = '    public function getDescription(): string';
        $lines[] = '    {';
        $lines[] = '        return $this->toolDescription;';
        $lines[] = '    }';
        $lines[] = '';
        $lines[] = '    abstract public function getParameters(): array;';
        $lines[] = '';
        $lines[] = '    public function getPermission(): string';
        $lines[] = '    {';
        $lines[] = '        return \'plugin:' . $this->pluginIdent . '.auto.\' . $this->toolType . \'.\' . $this->tableName;';
        $lines[] = '    }';
        $lines[] = '';
        $lines[] = '    /**';
        $lines[] = '     * 聚合权限标识（操作类型级，如 plugin:' . $this->pluginIdent . '.auto.delete.*）';
        $lines[] = '     */';
        $lines[] = '    public function getAggregatePermission(): string';
        $lines[] = '    {';
        $lines[] = '        return \'plugin:' . $this->pluginIdent . '.auto.\' . $this->toolType . \'.*\';';
        $lines[] = '    }';
        $lines[] = '';
        $lines[] = '    public function requireConfirmation(): bool';
        $lines[] = '    {';
        $lines[] = '        return in_array($this->toolType, CapabilityAccess::WRITE_TOOL_TYPES, true);';
        $lines[] = '    }';
        $lines[] = '';
        $lines[] = '    /**';
        $lines[] = '     * 访问类型（只读 / 写入），用于按读/写分类授权';
        $lines[] = '     */';
        $lines[] = '    public function getAccess(): string';
        $lines[] = '    {';
        $lines[] = '        return CapabilityAccess::forToolType($this->toolType);';
        $lines[] = '    }';
        $lines[] = '';
        $lines[] = '    /**';
        $lines[] = '     * 风险等级（delete 高危 / create,update 中危 / 只读低危）';
        $lines[] = '     */';
        $lines[] = '    public function getRiskLevel(): string';
        $lines[] = '    {';
        $lines[] = '        return CapabilityAccess::riskForToolType($this->toolType);';
        $lines[] = '    }';
        $lines[] = '';
        $lines[] = '    /**';
        $lines[] = '     * 归属插件标识';
        $lines[] = '     */';
        $lines[] = '    public function getPluginIdent(): string';
        $lines[] = '    {';
        $lines[] = '        return \'' . $this->pluginIdent . '\';';
        $lines[] = '    }';
        $lines[] = '';
        $lines[] = '    public function execute(array $arguments): string';
        $lines[] = '    {';
        $lines[] = '        try {';
        $lines[] = '            switch ($this->toolType) {';
        $lines[] = '                case \'list\':';
        $lines[] = '                    return $this->executeList($arguments);';
        $lines[] = '                case \'get\':';
        $lines[] = '                    return $this->executeGet($arguments);';
        $lines[] = '                case \'search\':';
        $lines[] = '                    return $this->executeSearch($arguments);';
        $lines[] = '                case \'stats\':';
        $lines[] = '                    return $this->executeStats($arguments);';
        $lines[] = '                case \'create\':';
        $lines[] = '                    return $this->executeCreate($arguments);';
        $lines[] = '                case \'update\':';
        $lines[] = '                    return $this->executeUpdate($arguments);';
        $lines[] = '                case \'delete\':';
        $lines[] = '                    return $this->executeDelete($arguments);';
        $lines[] = '                case \'list_with_relation\':';
        $lines[] = '                    return $this->executeListWithRelation($arguments);';
        $lines[] = '                default:';
        $lines[] = '                    return json_encode([\'error\' => \'Unknown tool type: \' . $this->toolType], JSON_UNESCAPED_UNICODE);';
        $lines[] = '            }';
        $lines[] = '        } catch (\Throwable $e) {';
        $lines[] = '            return json_encode([\'error\' => \'Execution error\', \'message\' => \'An internal error occurred while processing your request.\'], JSON_UNESCAPED_UNICODE);';
        $lines[] = '        }';
        $lines[] = '    }';
        $lines[] = '';
        $lines[] = '    // ──────────────────────────────────────';
        $lines[] = '    //  查询辅助';
        $lines[] = '    // ──────────────────────────────────────';
        $lines[] = '';
        $lines[] = '    public function getToolType(): string';
        $lines[] = '    {';
        $lines[] = '        return $this->toolType;';
        $lines[] = '    }';
        $lines[] = '';
        $lines[] = '    public function getTableName(): string';
        $lines[] = '    {';
        $lines[] = '        return $this->tableName;';
        $lines[] = '    }';
        $lines[] = '';
        $lines[] = '    private function applySoftDeleteFilter($query): void';
        $lines[] = '    {';
        $lines[] = '        if ($this->hasSoftDelete && method_exists($query, \'whereNull\')) {';
        $lines[] = '            $query->whereNull($this->deleteTimeField ?: \'delete_time\');';
        $lines[] = '        }';
        $lines[] = '    }';
        $lines[] = '';
        $lines[] = '    private function applyFieldFilters($query, array $args, array $exclude = []): void';
        $lines[] = '    {';
        $lines[] = '        $reserved = array_merge([\'page\', \'page_size\', \'sort_field\', \'sort_order\', \'keyword\', \'stat_type\', \'group_field\', \'id\'], $exclude);';
        $lines[] = '        foreach ($args as $key => $value) {';
        $lines[] = '            if (in_array($key, $reserved, true) || $value === \'\' || $value === null) continue;';
        $lines[] = '            if (in_array($key, $this->fieldNames, true) && method_exists($query, \'where\')) {';
        $lines[] = '                $query->where($key, $value);';
        $lines[] = '            }';
        $lines[] = '        }';
        $lines[] = '    }';
        $lines[] = '';
        $lines[] = '    private function validateSortField(string $field): string';
        $lines[] = '    {';
        $lines[] = '        $allowed = array_merge([$this->primaryKey], $this->fieldNames);';
        $lines[] = '        return in_array($field, $allowed, true) ? $field : $this->primaryKey;';
        $lines[] = '    }';
        $lines[] = '';
        $lines[] = '    private function toArray($data): array';
        $lines[] = '    {';
        $lines[] = '        if (is_array($data)) return $data;';
        $lines[] = '        if (is_object($data)) {';
        $lines[] = '            if (method_exists($data, \'toArray\')) return $data->toArray();';
        $lines[] = '            return json_decode(json_encode($data), true);';
        $lines[] = '        }';
        $lines[] = '        return (array)$data;';
        $lines[] = '    }';
        $lines[] = '';
        $lines[] = '    // ──────────────────────────────────────';
        $lines[] = '    //  list 列表查询';
        $lines[] = '    // ──────────────────────────────────────';
        $lines[] = '';
        $lines[] = '    protected function executeList(array $args): string';
        $lines[] = '    {';
        $lines[] = '        $page = max(1, (int)($args[\'page\'] ?? 1));';
        $lines[] = '        $pageSize = min(100, max(1, (int)($args[\'page_size\'] ?? 20)));';
        $lines[] = '        $sortField = $this->validateSortField($args[\'sort_field\'] ?? $this->primaryKey);';
        $lines[] = '        $sortOrder = ($args[\'sort_order\'] ?? \'desc\') === \'asc\' ? \'asc\' : \'desc\';';
        $lines[] = '';
        $lines[] = '        $query = Db::name($this->tableName);';
        $lines[] = '        $this->applyFieldFilters($query, $args);';
        $lines[] = '        $this->applySoftDeleteFilter($query);';
        $lines[] = '        $query->order($sortField, $sortOrder);';
        $lines[] = '';
        $lines[] = '        $total = $query->count();';
        $lines[] = '        $list  = $query->page($page, $pageSize)->select();';
        $lines[] = '';
        $lines[] = '        return json_encode([';
        $lines[] = '            \'total\'     => $total,';
        $lines[] = '            \'page\'      => $page,';
        $lines[] = '            \'page_size\' => $pageSize,';
        $lines[] = '            \'list\'      => $this->toArray($list),';
        $lines[] = '        ], JSON_UNESCAPED_UNICODE);';
        $lines[] = '    }';
        $lines[] = '';
        $lines[] = '    // ──────────────────────────────────────';
        $lines[] = '    //  get 单条查询';
        $lines[] = '    // ──────────────────────────────────────';
        $lines[] = '';
        $lines[] = '    protected function executeGet(array $args): string';
        $lines[] = '    {';
        $lines[] = '        $id = $args[\'id\'] ?? $args[$this->primaryKey] ?? null;';
        $lines[] = '        if ($id === null) {';
        $lines[] = '            return json_encode([\'error\' => \'Missing parameter\', \'message\' => \'缺少主键参数 id\'], JSON_UNESCAPED_UNICODE);';
        $lines[] = '        }';
        $lines[] = '';
        $lines[] = '        $query = Db::name($this->tableName);';
        $lines[] = '        $this->applySoftDeleteFilter($query);';
        $lines[] = '';
        $lines[] = '        $record = $query->where($this->primaryKey, $id)->find();';
        $lines[] = '        if ($record === null) {';
        $lines[] = '            return json_encode([\'error\' => \'Not found\', \'message\' => \'未找到记录\'], JSON_UNESCAPED_UNICODE);';
        $lines[] = '        }';
        $lines[] = '        return json_encode($this->toArray($record), JSON_UNESCAPED_UNICODE);';
        $lines[] = '    }';
        $lines[] = '';
        $lines[] = '    // ──────────────────────────────────────';
        $lines[] = '    //  search 关键词搜索';
        $lines[] = '    // ──────────────────────────────────────';
        $lines[] = '';
        $lines[] = '    protected function executeSearch(array $args): string';
        $lines[] = '    {';
        $lines[] = '        $keyword = $args[\'keyword\'] ?? \'\';';
        $lines[] = '        if ($keyword === \'\') {';
        $lines[] = '            return json_encode([\'error\' => \'Missing parameter\', \'message\' => \'缺少搜索关键词\'], JSON_UNESCAPED_UNICODE);';
        $lines[] = '        }';
        $lines[] = '';
        $lines[] = '        $page = max(1, (int)($args[\'page\'] ?? 1));';
        $lines[] = '        $pageSize = min(100, max(1, (int)($args[\'page_size\'] ?? 20)));';
        $lines[] = '';
        $lines[] = '        $query = Db::name($this->tableName);';
        $lines[] = '        if (!empty($this->searchableFields)) {';
        $lines[] = '            $query->where(function ($q) use ($keyword) {';
        $lines[] = '                foreach ($this->searchableFields as $field) {';
        $lines[] = '                    $q->whereOr($field, \'like\', \'%\' . $keyword . \'%\');';
        $lines[] = '                }';
        $lines[] = '            });';
        $lines[] = '        }';
        $lines[] = '        $this->applyFieldFilters($query, $args, [\'keyword\']);';
        $lines[] = '        $this->applySoftDeleteFilter($query);';
        $lines[] = '        $query->order($this->primaryKey, \'desc\');';
        $lines[] = '';
        $lines[] = '        $total = $query->count();';
        $lines[] = '        $list = $query->page($page, $pageSize)->select();';
        $lines[] = '';
        $lines[] = '        return json_encode([';
        $lines[] = '            \'keyword\'   => $keyword,';
        $lines[] = '            \'total\'     => $total,';
        $lines[] = '            \'page\'      => $page,';
        $lines[] = '            \'page_size\' => $pageSize,';
        $lines[] = '            \'list\'      => $this->toArray($list),';
        $lines[] = '        ], JSON_UNESCAPED_UNICODE);';
        $lines[] = '    }';
        $lines[] = '';
        $lines[] = '    // ──────────────────────────────────────';
        $lines[] = '    //  stats 统计';
        $lines[] = '    // ──────────────────────────────────────';
        $lines[] = '';
        $lines[] = '    protected function executeStats(array $args): string';
        $lines[] = '    {';
        $lines[] = '        $statType = $args[\'stat_type\'] ?? \'count\';';
        $lines[] = '        $query = Db::name($this->tableName);';
        $lines[] = '        $this->applySoftDeleteFilter($query);';
        $lines[] = '        $this->applyFieldFilters($query, $args, [\'stat_type\', \'group_field\']);';
        $lines[] = '';
        $lines[] = '        switch ($statType) {';
        $lines[] = '            case \'count\':';
        $lines[] = '                return json_encode([\'count\' => $query->count()], JSON_UNESCAPED_UNICODE);';
        $lines[] = '            case \'summary\':';
        $lines[] = '                $result = [\'count\' => $query->count()];';
        $lines[] = '                foreach ($this->numericFields as $field) {';
        $lines[] = '                    if (preg_match(\'/_id$/\', $field)) continue;';
        $lines[] = '                    $result[$field . \'_sum\'] = (float)$query->sum($field);';
        $lines[] = '                    $result[$field . \'_avg\'] = round((float)$query->avg($field), 2);';
        $lines[] = '                    $result[$field . \'_max\'] = (float)$query->max($field);';
        $lines[] = '                    $result[$field . \'_min\'] = (float)$query->min($field);';
        $lines[] = '                }';
        $lines[] = '                return json_encode($result, JSON_UNESCAPED_UNICODE);';
        $lines[] = '            case \'group_by\':';
        $lines[] = '                $groupField = $args[\'group_field\'] ?? null;';
        $lines[] = '                if ($groupField === null) {';
        $lines[] = '                    return json_encode([\'error\' => \'Missing group_field\'], JSON_UNESCAPED_UNICODE);';
        $lines[] = '                }';
        $lines[] = '                $rows = $query->group($groupField)->field("{$groupField} as value, COUNT(*) as count")->order(\'count\', \'desc\')->select();';
        $lines[] = '                return json_encode($this->toArray($rows), JSON_UNESCAPED_UNICODE);';
        $lines[] = '            default:';
        $lines[] = '                return json_encode([\'error\' => \'Unknown stat_type: \' . $statType], JSON_UNESCAPED_UNICODE);';
        $lines[] = '        }';
        $lines[] = '    }';
        $lines[] = '';
        $lines[] = '    // ──────────────────────────────────────';
        $lines[] = '    //  create 创建';
        $lines[] = '    // ──────────────────────────────────────';
        $lines[] = '';
        $lines[] = '    protected function executeCreate(array $args): string';
        $lines[] = '    {';
        $lines[] = '        $data = [];';
        $lines[] = '        foreach ($args as $key => $value) {';
        $lines[] = '            if (in_array($key, $this->writableFieldNames, true)) {';
        $lines[] = '                $data[$key] = $value;';
        $lines[] = '            }';
        $lines[] = '        }';
        $lines[] = '        if (empty($data)) {';
        $lines[] = '            return json_encode([\'error\' => \'No writable fields\'], JSON_UNESCAPED_UNICODE);';
        $lines[] = '        }';
        $lines[] = '';
        $lines[] = '        try {';
        $lines[] = '            $id = Db::name($this->tableName)->insertGetId($data);';
        $lines[] = '            return json_encode([\'success\' => true, \'message\' => \'创建成功\', \'id\' => $id], JSON_UNESCAPED_UNICODE);';
        $lines[] = '        } catch (\Throwable $e) {';
        $lines[] = '            return json_encode([\'error\' => \'Create failed\', \'message\' => $e->getMessage()], JSON_UNESCAPED_UNICODE);';
        $lines[] = '        }';
        $lines[] = '    }';
        $lines[] = '';
        $lines[] = '    // ──────────────────────────────────────';
        $lines[] = '    //  update 更新';
        $lines[] = '    // ──────────────────────────────────────';
        $lines[] = '';
        $lines[] = '    protected function executeUpdate(array $args): string';
        $lines[] = '    {';
        $lines[] = '        $id = $args[\'id\'] ?? null;';
        $lines[] = '        if ($id === null) {';
        $lines[] = '            return json_encode([\'error\' => \'Missing id\'], JSON_UNESCAPED_UNICODE);';
        $lines[] = '        }';
        $lines[] = '';
        $lines[] = '        $data = [];';
        $lines[] = '        foreach ($args as $key => $value) {';
        $lines[] = '            if ($key === \'id\') continue;';
        $lines[] = '            if (in_array($key, $this->writableFieldNames, true)) {';
        $lines[] = '                $data[$key] = $value;';
        $lines[] = '            }';
        $lines[] = '        }';
        $lines[] = '        if (empty($data)) {';
        $lines[] = '            return json_encode([\'error\' => \'No writable fields\'], JSON_UNESCAPED_UNICODE);';
        $lines[] = '        }';
        $lines[] = '        if (!isset($data[\'update_time\']) && in_array(\'update_time\', $this->fieldNames, true)) {';
        $lines[] = '            $data[\'update_time\'] = date(\'Y-m-d H:i:s\');';
        $lines[] = '        }';
        $lines[] = '';
        $lines[] = '        try {';
        $lines[] = '            $query = Db::name($this->tableName);';
        $lines[] = '            $this->applySoftDeleteFilter($query);';
        $lines[] = '            $affected = $query->where($this->primaryKey, $id)->update($data);';
        $lines[] = '            return json_encode([\'success\' => true, \'message\' => \'更新成功\', \'affected\' => $affected], JSON_UNESCAPED_UNICODE);';
        $lines[] = '        } catch (\Throwable $e) {';
        $lines[] = '            return json_encode([\'error\' => \'Update failed\', \'message\' => $e->getMessage()], JSON_UNESCAPED_UNICODE);';
        $lines[] = '        }';
        $lines[] = '    }';
        $lines[] = '';
        $lines[] = '    // ──────────────────────────────────────';
        $lines[] = '    //  delete 删除';
        $lines[] = '    // ──────────────────────────────────────';
        $lines[] = '';
        $lines[] = '    protected function executeDelete(array $args): string';
        $lines[] = '    {';
        $lines[] = '        $id = $args[\'id\'] ?? null;';
        $lines[] = '        if ($id === null) {';
        $lines[] = '            return json_encode([\'error\' => \'Missing id\'], JSON_UNESCAPED_UNICODE);';
        $lines[] = '        }';
        $lines[] = '';
        $lines[] = '        try {';
        $lines[] = '            if ($this->hasSoftDelete) {';
        $lines[] = '                $dtField = $this->deleteTimeField ?: \'delete_time\';';
        $lines[] = '                $affected = Db::name($this->tableName)';
        $lines[] = '                    ->where($this->primaryKey, $id)';
        $lines[] = '                    ->whereNull($dtField)';
        $lines[] = '                    ->update([$dtField => date(\'Y-m-d H:i:s\')]);';
        $lines[] = '                return json_encode([\'success\' => true, \'message\' => \'软删除成功\', \'mode\' => \'soft_delete\', \'affected\' => $affected], JSON_UNESCAPED_UNICODE);';
        $lines[] = '            }';
        $lines[] = '            $affected = Db::name($this->tableName)->where($this->primaryKey, $id)->delete();';
        $lines[] = '            return json_encode([\'success\' => true, \'message\' => \'删除成功\', \'mode\' => \'hard_delete\', \'affected\' => $affected], JSON_UNESCAPED_UNICODE);';
        $lines[] = '        } catch (\Throwable $e) {';
        $lines[] = '            return json_encode([\'error\' => \'Delete failed\', \'message\' => $e->getMessage()], JSON_UNESCAPED_UNICODE);';
        $lines[] = '        }';
        $lines[] = '    }';
        $lines[] = '';
        $lines[] = '    // ──────────────────────────────────────';
        $lines[] = '    //  list_with_relation 关联查询';
        $lines[] = '    // ──────────────────────────────────────';
        $lines[] = '';
        $lines[] = '    protected function executeListWithRelation(array $args): string';
        $lines[] = '    {';
        $lines[] = '        $page = max(1, (int)($args[\'page\'] ?? 1));';
        $lines[] = '        $pageSize = min(100, max(1, (int)($args[\'page_size\'] ?? 20)));';
        $lines[] = '        $sortField = $this->validateSortField($args[\'sort_field\'] ?? $this->primaryKey);';
        $lines[] = '        $sortOrder = ($args[\'sort_order\'] ?? \'desc\') === \'asc\' ? \'asc\' : \'desc\';';
        $lines[] = '';
        $lines[] = '        $query = Db::name($this->tableName);';
        $lines[] = '        $this->applyFieldFilters($query, $args);';
        $lines[] = '        $this->applySoftDeleteFilter($query);';
        $lines[] = '        $query->order($sortField, $sortOrder);';
        $lines[] = '';
        $lines[] = '        $total = $query->count();';
        $lines[] = '        $list  = $query->page($page, $pageSize)->select();';
        $lines[] = '';
        $lines[] = '        return json_encode([';
        $lines[] = '            \'total\'     => $total,';
        $lines[] = '            \'page\'      => $page,';
        $lines[] = '            \'page_size\' => $pageSize,';
        $lines[] = '            \'relation\'  => $this->relationName,';
        $lines[] = '            \'list\'      => $this->toArray($list),';
        $lines[] = '        ], JSON_UNESCAPED_UNICODE);';
        $lines[] = '    }';
        $lines[] = '}';
        $lines[] = '';

        return implode("\n", $lines);
    }


    private function generateToolsForTable(TableInfo $table, bool $force): void
    {
        if (!isset($this->cachedAllTools)) {
            $generator = new AiToolGenerator($this->schema, $this->guard);
            $this->cachedAllTools = $generator->generate();
        }

        $tools = [];
        foreach ($this->cachedAllTools as $tool) {
            if ($tool instanceof AutoGeneratedTool && $tool->getTableName() === $table->name) {
                $tools[] = $tool;
            }
        }

        if (empty($tools)) {
            return;
        }

        foreach ($tools as $tool) {
            $className = $this->toolClassName($tool);
            $file = $this->toolDir . $className . '.php';

            if (file_exists($file) && !$force) {
                continue;
            }

            $content = $this->renderToolClass($tool, $table);
            file_put_contents($file, $content, LOCK_EX);
            $this->stats['files_generated']++;
            $this->stats['tools_generated']++;
        }
    }

    /** @var AutoGeneratedTool[]|null 缓存生成结果 */
    private ?array $cachedAllTools = null;

    /**
     * 工具类名 → PascalCase 文件名
     */
    private function toolClassName(AutoGeneratedTool $tool): string
    {
        $type = $tool->getToolType();
        $tableName = $this->tableNameToPascal($tool->getTableName());

        switch ($type) {
            case 'list':    return 'List' . $tableName;
            case 'get':     return 'Get' . $tableName;
            case 'search':  return 'Search' . $tableName;
            case 'stats':   return 'Stats' . $tableName;
            case 'create':  return 'Create' . $tableName;
            case 'update':  return 'Update' . $tableName;
            case 'delete':  return 'Delete' . $tableName;
            case 'list_with_relation':
                $relName = $this->relationNameToPascal($tool);
                return 'List' . $tableName . 'With' . $relName;
            default:
                return ucfirst($type) . $tableName;
        }
    }

    private function relationNameToPascal(AutoGeneratedTool $tool): string
    {
        $schema = $tool->getTableSchema();
        if (!empty($schema->relations)) {
            foreach ($schema->relations as $rel) {
                if (isset($rel->name)) {
                    $relName = $rel->name;
                } elseif (method_exists($rel, 'getName')) {
                    $relName = $rel->getName();
                } else {
                    continue;
                }
                $toolName = $tool->getName();
                if (strpos($toolName, '_with_' . $relName) !== false) {
                    return $this->snakeToPascal($relName);
                }
            }
        }
        return 'Relation';
    }

    private function tableNameToPascal(string $tableName): string
    {
        $prefix = $this->pluginIdent . '_';
        if (strpos($tableName, $prefix) === 0) {
            $tableName = substr($tableName, strlen($prefix));
        }
        return $this->snakeToPascal($tableName);
    }

    private function snakeToPascal(string $snake): string
    {
        return str_replace('_', '', ucwords($snake, '_'));
    }

    /**
     * 渲染单个工具 PHP 类文件
     */
    private function renderToolClass(AutoGeneratedTool $tool, TableInfo $table): string
    {
        $className = $this->toolClassName($tool);
        $namespace = $this->toolNamespace();
        $params = $this->renderParametersArray($tool);
        $toolName = $tool->getName();
        $toolType = $tool->getToolType();
        $description = $tool->getDescription();
        $fields = $this->renderFieldsArray($table);
        $searchable = $this->renderSearchableArray($table);
        $numeric = $this->renderNumericArray($table);
        $writable = $this->renderWritableArray($table);
        $relationName = $toolType === 'list_with_relation' ? $this->extractRelationName($tool) : '';

        $this->assertSafeNamespace($namespace);
        $this->assertSafeIdentifier($className, 'className');
        $identifierFields = [
            'pluginIdent' => (string) $this->pluginIdent,
            'tableName'   => (string) $table->name,
            'primaryKey'  => (string) $table->primaryKey,
            'toolType'    => (string) $toolType,
            'toolName'    => (string) $toolName,
        ];
        foreach ($identifierFields as $label => $value) {
            $this->assertSafeIdentifier($value, $label);
        }
        if ($relationName !== '') {
            $this->assertSafeIdentifier($relationName, 'relationName');
        }
        $description   = $this->sanitizeDocblock((string) $description);
        $tableComment  = $this->sanitizeDocblock((string) ($table->comment ?? ''));
        $tableNameSafe = $this->sanitizeDocblock((string) $table->name);
        $primaryKeySafe = $this->sanitizeDocblock((string) $table->primaryKey);
        $modelClassSafe = $this->sanitizeDocblock((string) ($table->modelClass ?: '-'));

        $lines = [];
        $lines[] = '<?php';
        $lines[] = 'declare(strict_types=1);';
        $lines[] = '';
        $lines[] = "namespace {$namespace};";
        $lines[] = '';
        $lines[] = 'use wise\agent\tool\BaseAutoTool;';
        $lines[] = '';
        $lines[] = '/**';
        $lines[] = ' * ' . $description;
        $lines[] = ' *';
        $lines[] = ' * 表：' . $tableNameSafe . ($tableComment !== '' ? '（' . $tableComment . '）' : '');
        $lines[] = ' * 主键：' . $primaryKeySafe;
        $lines[] = ' * 模型：' . $modelClassSafe;
        $lines[] = ' * 软删除：' . ($table->hasSoftDelete ? '是（' . $this->sanitizeDocblock((string) ($table->deleteTimeField ?: 'delete_time')) . '）' : '否');
        $lines[] = ' *';
        $lines[] = ' * 自动生成，可自由修改执行逻辑。';
        $lines[] = ' */';
        $lines[] = "class {$className} extends BaseAutoTool";
        $lines[] = '{';
        $lines[] = '    protected string $pluginIdent = ' . var_export($this->pluginIdent, true) . ';';
        $lines[] = '    protected string $tableName = ' . var_export($table->name, true) . ';';
        $lines[] = '    protected string $primaryKey = ' . var_export($table->primaryKey, true) . ';';
        $lines[] = '    protected string $toolType = ' . var_export($toolType, true) . ';';
        $lines[] = '    protected string $toolName = ' . var_export($toolName, true) . ';';
        $lines[] = '    protected string $toolDescription = ' . var_export($description, true) . ';';
        $lines[] = '    protected bool $hasSoftDelete = ' . var_export($table->hasSoftDelete, true) . ';';
        if ($table->deleteTimeField) {
            $lines[] = '    protected ?string $deleteTimeField = ' . var_export($table->deleteTimeField, true) . ';';
        }
        $lines[] = '';
        $lines[] = '    protected array $fieldNames = ' . $fields . ';';
        $lines[] = '    protected array $searchableFields = ' . $searchable . ';';
        $lines[] = '    protected array $numericFields = ' . $numeric . ';';
        $lines[] = '    protected array $writableFieldNames = ' . $writable . ';';
        if ($relationName) {
            $lines[] = '    protected string $relationName = ' . var_export($relationName, true) . ';';
        }
        $lines[] = '';
        $lines[] = '    public function getParameters(): array';
        $lines[] = '    {';
        $lines[] = '        return ' . $params . ';';
        $lines[] = '    }';
        $lines[] = '}';
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * 校验简单 PHP 标识符（类名 / 表名 / 字段名等），不合法则拒绝生成
     */
    private function assertSafeIdentifier(string $value, string $label): void
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $value)) {
            throw new \RuntimeException(sprintf('%s 含非法字符，禁止用于代码生成: %s', $label, $value));
        }
    }

    /**
     * 校验命名空间（允许反斜杠分段）
     */
    private function assertSafeNamespace(string $value): void
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*$/', $value)) {
            throw new \RuntimeException('命名空间含非法字符，禁止用于代码生成: ' . $value);
        }
    }

    /**
     * 转义进入 docblock 注释的自由文本，防止星号斜杠提前闭合注释注入代码
     */
    private function sanitizeDocblock(string $text): string
    {
        return str_replace(['*/', "\r", "\n"], ['* /', ' ', ' '], $text);
    }

    private function renderParametersArray(AutoGeneratedTool $tool): string
    {
        $params = $tool->getParameters();
        return $this->arrayToPhpExport($params);
    }

    private function renderFieldsArray(TableInfo $table): string
    {
        $names = [];
        foreach ($table->fields as $f) {
            if ($f instanceof FieldInfo) {
                $names[] = $f->name;
            }
        }
        return $this->arrayToPhpExport($names);
    }

    private function renderSearchableArray(TableInfo $table): string
    {
        $names = [];
        foreach ($table->getSearchableFields() as $name => $f) {
            $names[] = $name;
        }
        return $this->arrayToPhpExport($names);
    }

    private function renderNumericArray(TableInfo $table): string
    {
        $names = [];
        foreach ($table->getNumericFields() as $name => $f) {
            $names[] = $name;
        }
        return $this->arrayToPhpExport($names);
    }

    private function renderWritableArray(TableInfo $table): string
    {
        $names = [];
        if ($this->guard) {
            $writable = $this->guard->filterWritableFields($table->name, $table->fields);
            foreach ($writable as $f) {
                if ($f instanceof FieldInfo) {
                    if (in_array($f->name, SecurityGuard::SYSTEM_FIELDS, true)) {
                        continue;
                    }
                    $names[] = $f->name;
                }
            }
        }
        return $this->arrayToPhpExport($names);
    }

    private function extractRelationName(AutoGeneratedTool $tool): string
    {
        $toolName = $tool->getName();
        if (preg_match('/_with_(\w+)$/', $toolName, $m)) {
            return $m[1];
        }
        return '';
    }

    /**
     * 将 PHP 数组转为可嵌入的 PHP 短数组语法
     */
    private function arrayToPhpExport(array $data): string
    {
        $parts = [];
        $isList = array_keys($data) === range(0, count($data) - 1);

        foreach ($data as $key => $value) {
            $k = $isList ? '' : var_export($key, true) . ' => ';
            if (is_array($value)) {
                $parts[] = $k . $this->arrayToPhpExport($value);
            } elseif (is_string($value)) {
                $parts[] = $k . var_export($value, true);
            } elseif (is_bool($value)) {
                $parts[] = $k . ($value ? 'true' : 'false');
            } elseif (is_null($value)) {
                $parts[] = $k . 'null';
            } elseif (is_int($value) || is_float($value)) {
                $parts[] = $k . (string)$value;
            } else {
                $parts[] = $k . var_export($value, true);
            }
        }

        $inner = implode(', ', $parts);
        return '[' . $inner . ']';
    }


    private function generateProvider(bool $force): void
    {
        $providerName = $this->providerClassName();
        $file = $this->toolDir . $providerName . '.php';

        if (file_exists($file) && !$force) {
            $this->stats['provider'] = true;
            return;
        }

        $content = $this->renderProviderClass();
        file_put_contents($file, $content, LOCK_EX);
        $this->stats['provider'] = true;
        $this->stats['files_generated']++;
    }

    private function providerClassName(): string
    {
        return $this->snakeToPascal($this->pluginIdent) . 'SchemaToolProvider';
    }

    private function renderProviderClass(): string
    {
        $namespace = $this->toolNamespace();
        $className = $this->providerClassName();

        $this->assertSafeNamespace($namespace);
        $this->assertSafeIdentifier($className, 'provider className');
        $this->assertSafeIdentifier((string) $this->pluginIdent, 'pluginIdent');

        $toolInstances = [];
        if ($this->cachedAllTools === null) {
            $generator = new AiToolGenerator($this->schema, $this->guard);
            $this->cachedAllTools = $generator->generate();
        }

        foreach ($this->cachedAllTools as $tool) {
            if ($tool instanceof AutoGeneratedTool) {
                $tcName = $this->toolClassName($tool);
                $toolInstances[] = "            new {$tcName}(),";
            }
        }

        $instancesStr = implode("\n", $toolInstances);

        $lines = [];
        $lines[] = '<?php';
        $lines[] = 'declare(strict_types=1);';
        $lines[] = '';
        $lines[] = "namespace {$namespace};";
        $lines[] = '';
        $lines[] = 'use wise\agent\contract\RequestContext;';
        $lines[] = 'use wise\agent\contract\ToolInterface;';
        $lines[] = 'use wise\agent\contract\ToolProviderInterface;';
        $lines[] = '';
        $lines[] = '/**';
        $lines[] = ' * ' . $this->pluginIdent . ' 插件 Schema 工具提供者';
        $lines[] = ' *';
        $lines[] = ' * 统一提供所有自动生成的 CRUD 工具，供 wise-agent 发现和注册。';
        $lines[] = ' * 通过 manifest.json 的 ai_providers.tool 注册。';
        $lines[] = ' *';
        $lines[] = ' * 生成时间：' . date('Y-m-d H:i:s');
        $lines[] = ' */';
        $lines[] = "class {$className} implements ToolProviderInterface";
        $lines[] = '{';
        $lines[] = '    public function getNamespace(): string';
        $lines[] = '    {';
        $lines[] = '        return \'plugin:' . $this->pluginIdent . '\';';
        $lines[] = '    }';
        $lines[] = '';
        $lines[] = '    /**';
        $lines[] = '     * @return ToolInterface[]';
        $lines[] = '     */';
        $lines[] = '    public function getTools(): array';
        $lines[] = '    {';
        $lines[] = '        return [';
        $lines[] = $instancesStr;
        $lines[] = '        ];';
        $lines[] = '    }';
        $lines[] = '';
        $lines[] = '    public function execute(string $toolName, array $params, RequestContext $context): mixed';
        $lines[] = '    {';
        $lines[] = '        foreach ($this->getTools() as $tool) {';
        $lines[] = '            if ($tool->getName() === $toolName) {';
        $lines[] = '                return json_decode($tool->execute($params), true);';
        $lines[] = '            }';
        $lines[] = '        }';
        $lines[] = '        throw new \RuntimeException("Unknown tool: {$toolName}");';
        $lines[] = '    }';
        $lines[] = '';
        $lines[] = '    public function getMetadata(): array';
        $lines[] = '    {';
        $lines[] = '        return [';
        $lines[] = '            \'label\'       => \'' . $this->pluginIdent . ' Schema 工具\',';
        $lines[] = '            \'description\' => \'' . $this->pluginIdent . ' 插件自动生成的 CRUD 工具集\',';
        $lines[] = '            \'version\'     => \'1.0.0\',';
        $lines[] = '        ];';
        $lines[] = '    }';
        $lines[] = '}';
        $lines[] = '';

        return implode("\n", $lines);
    }


    private function updateManifest(): void
    {
        $manifestFile = $this->pluginPath . 'manifest.json';
        if (!file_exists($manifestFile)) {
            $this->stats['errors'][] = 'manifest.json not found';
            return;
        }

        $backup = $manifestFile . '.bak.' . date('Ymd');
        copy($manifestFile, $backup);

        $content = file_get_contents($manifestFile);
        $manifest = json_decode($content, true);
        if (!is_array($manifest)) {
            $this->stats['errors'][] = 'Invalid manifest.json';
            return;
        }

        $providerClass = $this->toolNamespace() . '\\' . $this->providerClassName();
        if (!isset($manifest['ai_providers'])) {
            $manifest['ai_providers'] = [];
        }
        $manifest['ai_providers']['tool'] = $providerClass;

        if (!isset($manifest['ai_schema'])) {
            $manifest['ai_schema'] = [];
        }
        $manifest['ai_schema']['auto_introspect'] = false;

        $json = json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        file_put_contents($manifestFile, $json);

        $this->stats['manifest_updated'] = true;
        $this->stats['files_generated']++;
    }


    private function toolNamespace(): string
    {
        return 'plugin\\' . $this->pluginIdent . '\\ai\\tool';
    }

    private function loadManifest(): array
    {
        $file = $this->pluginPath . 'manifest.json';
        if (!file_exists($file)) {
            return [];
        }
        $content = file_get_contents($file);
        return json_decode($content, true) ?: [];
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }
}
