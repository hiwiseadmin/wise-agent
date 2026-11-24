<?php
declare(strict_types=1);

namespace wise\agent\tool;

use think\facade\Db;
use wise\agent\contract\ToolInterface;
use wise\agent\security\CapabilityAccess;
use wise\support\context\ContextualLogger;

/**
 * AI 自动生成工具基类
 *
 * 提供 8 种标准 CRUD 操作的完整执行逻辑。
 * 插件固化的工具类仅需继承此类并定义表配置。
 *
 * 工具类型：
 *   - list:     列表查询（分页 + 排序 + 条件过滤）
 *   - get:      按主键查询单条
 *   - search:   关键词模糊搜索
 *   - stats:    统计聚合（count/summary/group_by）
 *   - create:   创建记录
 *   - update:   更新记录
 *   - delete:   删除记录（支持软删除）
 *   - list_with_relation: 带关联的列表查询
 *
 * 子类需定义：
 *   - $pluginIdent    插件标识
 *   - $tableName      表名（不含前缀）
 *   - $primaryKey     主键字段名
 *   - $toolType       工具类型
 *   - $toolName       工具名称
 *   - $toolDescription 工具描述
 *   - $fieldNames     所有字段名
 *   - $searchableFields 可搜索字段
 *   - $numericFields  数值字段
 *   - $writableFieldNames 可写字段
 *
 * 可选覆盖：
 *   - $hasSoftDelete  是否软删除
 *   - $deleteTimeField 软删除字段名
 *   - $relationName   关联关系名（仅 list_with_relation）
 */
abstract class BaseAutoTool implements ToolInterface
{
    /** @var string 插件标识 */
    protected string $pluginIdent = '';

    /** @var string 表名 */
    protected string $tableName = '';

    /** @var string 主键字段名 */
    protected string $primaryKey = 'id';

    /** @var string 工具类型 */
    protected string $toolType = '';

    /** @var bool 是否软删除 */
    protected bool $hasSoftDelete = false;

    /** @var string|null 软删除字段 */
    protected ?string $deleteTimeField = null;

    /** @var string[] 字段名列表 */
    protected array $fieldNames = [];

    /** @var string[] 可搜索字段名列表 */
    protected array $searchableFields = [];

    /** @var string[] 数值字段名列表 */
    protected array $numericFields = [];

    /** @var string[] 可写字段名列表 */
    protected array $writableFieldNames = [];

    /** @var string 关联关系名（仅 list_with_relation） */
    protected string $relationName = '';

    /** @var string 工具名称 */
    protected string $toolName = '';

    /** @var string 工具描述 */
    protected string $toolDescription = '';


    public function getName(): string
    {
        return $this->toolName;
    }

    public function getDescription(): string
    {
        return $this->toolDescription;
    }

    abstract public function getParameters(): array;

    public function getPermission(): string
    {
        return 'plugin:' . $this->pluginIdent . '.auto.' . $this->toolType . '.' . $this->tableName;
    }

    /**
     * 聚合权限标识（操作类型级，如 plugin:smartstock.auto.delete.*）
     */
    public function getAggregatePermission(): string
    {
        return 'plugin:' . $this->pluginIdent . '.auto.' . $this->toolType . '.*';
    }

    public function requireConfirmation(): bool
    {
        return in_array($this->toolType, CapabilityAccess::WRITE_TOOL_TYPES, true);
    }

    /**
     * @inheritDoc
     */
    public function getAccess(): string
    {
        return CapabilityAccess::forToolType($this->toolType);
    }

    /**
     * @inheritDoc
     */
    public function getRiskLevel(): string
    {
        return CapabilityAccess::riskForToolType($this->toolType);
    }

    /**
     * 获取归属插件标识
     */
    public function getPluginIdent(): string
    {
        return $this->pluginIdent;
    }

    public function execute(array $arguments): string
    {
        try {
            switch ($this->toolType) {
                case 'list':
                    return $this->executeList($arguments);
                case 'get':
                    return $this->executeGet($arguments);
                case 'search':
                    return $this->executeSearch($arguments);
                case 'stats':
                    return $this->executeStats($arguments);
                case 'create':
                    return $this->executeCreate($arguments);
                case 'update':
                    return $this->executeUpdate($arguments);
                case 'delete':
                    return $this->executeDelete($arguments);
                case 'list_with_relation':
                    return $this->executeListWithRelation($arguments);
                default:
                    return json_encode(['error' => 'Unknown tool type: ' . $this->toolType], JSON_UNESCAPED_UNICODE);
            }
        } catch (\Throwable $e) {
            ContextualLogger::error('[BaseAutoTool] execution error: ' . $e->getMessage(), [
                'tool_type' => $this->toolType,
                'exception' => get_class($e),
            ]);
            return json_encode(['error' => 'Execution error', 'message' => '工具执行失败，请检查参数后重试'], JSON_UNESCAPED_UNICODE);
        }
    }


    public function getToolType(): string
    {
        return $this->toolType;
    }

    public function getTableName(): string
    {
        return $this->tableName;
    }

    private function applySoftDeleteFilter($query): void
    {
        if ($this->hasSoftDelete && method_exists($query, 'whereNull')) {
            $query->whereNull($this->deleteTimeField ?: 'delete_time');
        }
    }

    private function applyFieldFilters($query, array $args, array $exclude = []): void
    {
        $reserved = array_merge(['page', 'page_size', 'sort_field', 'sort_order', 'keyword', 'stat_type', 'group_field', 'id'], $exclude);
        foreach ($args as $key => $value) {
            if (in_array($key, $reserved, true) || $value === '' || $value === null) {
                continue;
            }
            if (in_array($key, $this->fieldNames, true) && method_exists($query, 'where')) {
                $query->where($key, $value);
            }
        }
    }

    private function validateSortField(string $field): string
    {
        $allowed = array_merge([$this->primaryKey], $this->fieldNames);
        return in_array($field, $allowed, true) ? $field : $this->primaryKey;
    }

    private function toArray($data): array
    {
        if (is_array($data)) {
            return $data;
        }
        if (is_object($data)) {
            if (method_exists($data, 'toArray')) {
                return $data->toArray();
            }
            return json_decode(json_encode($data), true);
        }
        return (array) $data;
    }


    protected function executeList(array $args): string
    {
        $page = max(1, (int) ($args['page'] ?? 1));
        $pageSize = min(100, max(1, (int) ($args['page_size'] ?? 20)));
        $sortField = $this->validateSortField($args['sort_field'] ?? $this->primaryKey);
        $sortOrder = ($args['sort_order'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        $query = Db::name($this->tableName);
        $this->applyFieldFilters($query, $args);
        $this->applySoftDeleteFilter($query);
        $query->order($sortField, $sortOrder);

        $total = $query->count();
        $list  = $query->page($page, $pageSize)->select();

        return json_encode([
            'total'     => $total,
            'page'      => $page,
            'page_size' => $pageSize,
            'list'      => $this->toArray($list),
        ], JSON_UNESCAPED_UNICODE);
    }


    protected function executeGet(array $args): string
    {
        $id = $args['id'] ?? $args[$this->primaryKey] ?? null;
        if ($id === null) {
            return json_encode(['error' => 'Missing parameter', 'message' => '缺少主键参数 id'], JSON_UNESCAPED_UNICODE);
        }

        $query = Db::name($this->tableName);
        $this->applySoftDeleteFilter($query);

        $record = $query->where($this->primaryKey, $id)->find();
        if ($record === null) {
            return json_encode(['error' => 'Not found', 'message' => '未找到记录'], JSON_UNESCAPED_UNICODE);
        }
        return json_encode($this->toArray($record), JSON_UNESCAPED_UNICODE);
    }


    protected function executeSearch(array $args): string
    {
        $keyword = $args['keyword'] ?? '';
        if ($keyword === '') {
            return json_encode(['error' => 'Missing parameter', 'message' => '缺少搜索关键词'], JSON_UNESCAPED_UNICODE);
        }

        $page = max(1, (int) ($args['page'] ?? 1));
        $pageSize = min(100, max(1, (int) ($args['page_size'] ?? 20)));

        $query = Db::name($this->tableName);
        if (!empty($this->searchableFields)) {
            $query->where(function ($q) use ($keyword) {
                foreach ($this->searchableFields as $field) {
                    $q->whereOr($field, 'like', '%' . $keyword . '%');
                }
            });
        }
        $this->applyFieldFilters($query, $args, ['keyword']);
        $this->applySoftDeleteFilter($query);
        $query->order($this->primaryKey, 'desc');

        $total = $query->count();
        $list = $query->page($page, $pageSize)->select();

        return json_encode([
            'keyword'   => $keyword,
            'total'     => $total,
            'page'      => $page,
            'page_size' => $pageSize,
            'list'      => $this->toArray($list),
        ], JSON_UNESCAPED_UNICODE);
    }


    protected function executeStats(array $args): string
    {
        $statType = $args['stat_type'] ?? 'count';
        $query = Db::name($this->tableName);
        $this->applySoftDeleteFilter($query);
        $this->applyFieldFilters($query, $args, ['stat_type', 'group_field']);

        switch ($statType) {
            case 'count':
                return json_encode(['count' => $query->count()], JSON_UNESCAPED_UNICODE);
            case 'summary':
                $result = ['count' => $query->count()];
                foreach ($this->numericFields as $field) {
                    if (preg_match('/_id$/', $field)) {
                        continue;
                    }
                    $result[$field . '_sum'] = (float) $query->sum($field);
                    $result[$field . '_avg'] = round((float) $query->avg($field), 2);
                    $result[$field . '_max'] = (float) $query->max($field);
                    $result[$field . '_min'] = (float) $query->min($field);
                }
                return json_encode($result, JSON_UNESCAPED_UNICODE);
            case 'group_by':
                $groupField = $args['group_field'] ?? null;
                if ($groupField === null) {
                    return json_encode(['error' => 'Missing group_field'], JSON_UNESCAPED_UNICODE);
                }
                if (!in_array($groupField, $this->fieldNames, true)) {
                    return json_encode(['error' => 'Invalid group_field'], JSON_UNESCAPED_UNICODE);
                }
                $rows = $query->group($groupField)->field("`{$groupField}` as value, COUNT(*) as count")->order('count', 'desc')->select();
                return json_encode($this->toArray($rows), JSON_UNESCAPED_UNICODE);
            default:
                return json_encode(['error' => 'Unknown stat_type: ' . $statType], JSON_UNESCAPED_UNICODE);
        }
    }


    protected function executeCreate(array $args): string
    {
        $data = [];
        foreach ($args as $key => $value) {
            if (in_array($key, $this->writableFieldNames, true)) {
                $data[$key] = $value;
            }
        }
        if (empty($data)) {
            return json_encode(['error' => 'No writable fields'], JSON_UNESCAPED_UNICODE);
        }

        try {
            $id = Db::name($this->tableName)->insertGetId($data);
            return json_encode(['success' => true, 'message' => '创建成功', 'id' => $id], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            ContextualLogger::error('[BaseAutoTool] create error: ' . $e->getMessage(), [
                'tool_type' => $this->toolType,
                'exception' => get_class($e),
            ]);
            return json_encode(['error' => 'Create failed', 'message' => '创建失败，请检查参数后重试'], JSON_UNESCAPED_UNICODE);
        }
    }


    protected function executeUpdate(array $args): string
    {
        $id = $args['id'] ?? null;
        if ($id === null) {
            return json_encode(['error' => 'Missing id'], JSON_UNESCAPED_UNICODE);
        }

        $data = [];
        foreach ($args as $key => $value) {
            if ($key === 'id') {
                continue;
            }
            if (in_array($key, $this->writableFieldNames, true)) {
                $data[$key] = $value;
            }
        }
        if (empty($data)) {
            return json_encode(['error' => 'No writable fields'], JSON_UNESCAPED_UNICODE);
        }
        if (!isset($data['update_time']) && in_array('update_time', $this->fieldNames, true)) {
            $data['update_time'] = date('Y-m-d H:i:s');
        }

        try {
            $query = Db::name($this->tableName);
            $this->applySoftDeleteFilter($query);
            $affected = $query->where($this->primaryKey, $id)->update($data);
            return json_encode(['success' => true, 'message' => '更新成功', 'affected' => $affected], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            ContextualLogger::error('[BaseAutoTool] update error: ' . $e->getMessage(), [
                'tool_type' => $this->toolType,
                'exception' => get_class($e),
            ]);
            return json_encode(['error' => 'Update failed', 'message' => '更新失败，请检查参数后重试'], JSON_UNESCAPED_UNICODE);
        }
    }


    protected function executeDelete(array $args): string
    {
        $id = $args['id'] ?? null;
        if ($id === null) {
            return json_encode(['error' => 'Missing id'], JSON_UNESCAPED_UNICODE);
        }

        try {
            if ($this->hasSoftDelete) {
                $dtField = $this->deleteTimeField ?: 'delete_time';
                $affected = Db::name($this->tableName)
                    ->where($this->primaryKey, $id)
                    ->whereNull($dtField)
                    ->update([$dtField => date('Y-m-d H:i:s')]);
                return json_encode(['success' => true, 'message' => '软删除成功', 'mode' => 'soft_delete', 'affected' => $affected], JSON_UNESCAPED_UNICODE);
            }
            $affected = Db::name($this->tableName)->where($this->primaryKey, $id)->delete();
            return json_encode(['success' => true, 'message' => '删除成功', 'mode' => 'hard_delete', 'affected' => $affected], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            ContextualLogger::error('[BaseAutoTool] delete error: ' . $e->getMessage(), [
                'tool_type' => $this->toolType,
                'exception' => get_class($e),
            ]);
            return json_encode(['error' => 'Delete failed', 'message' => '删除失败，请检查参数后重试'], JSON_UNESCAPED_UNICODE);
        }
    }


    protected function executeListWithRelation(array $args): string
    {
        $page = max(1, (int) ($args['page'] ?? 1));
        $pageSize = min(100, max(1, (int) ($args['page_size'] ?? 20)));
        $sortField = $this->validateSortField($args['sort_field'] ?? $this->primaryKey);
        $sortOrder = ($args['sort_order'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        $query = Db::name($this->tableName);
        $this->applyFieldFilters($query, $args);
        $this->applySoftDeleteFilter($query);
        $query->order($sortField, $sortOrder);

        $total = $query->count();
        $list  = $query->page($page, $pageSize)->select();

        return json_encode([
            'total'     => $total,
            'page'      => $page,
            'page_size' => $pageSize,
            'relation'  => $this->relationName,
            'list'      => $this->toArray($list),
        ], JSON_UNESCAPED_UNICODE);
    }
}
