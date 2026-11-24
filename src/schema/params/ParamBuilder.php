<?php
declare(strict_types=1);

namespace wise\agent\schema\params;

use wise\agent\schema\dto\FieldInfo;
use wise\agent\schema\dto\TableInfo;

/**
 * 参数构建器（流式 API）
 *
 * 根据 TableInfo 自动生成 AI Tool 的 JSON Schema 参数定义。
 * 支持链式调用，每种工具类型有对应的构建方法。
 *
 * 使用示例：
 *   $builder = new ParamBuilder('smartstock', $tableInfo);
 *   $params = $builder->forList()
 *       ->addPagination()
 *       ->addFieldFilters(['status', 'category_id'])
 *       ->addSorting(['id', 'name', 'selling_price'])
 *       ->build();
 */
class ParamBuilder
{
    /** @var string 插件标识 */
    private $pluginIdent;

    /** @var TableInfo 目标表信息 */
    private $tableInfo;

    /** @var ToolParams 正在构建的参数对象 */
    private $params;

    /** @var string 插件短名（从 ident 提取，用于工具命名前缀） */
    private $pluginPrefix;

    /**
     * @param string $pluginIdent 插件标识（如 smartstock）
     * @param TableInfo $tableInfo 表信息
     */
    public function __construct(string $pluginIdent, TableInfo $tableInfo)
    {
        $this->pluginIdent  = $pluginIdent;
        $this->tableInfo    = $tableInfo;
        $this->pluginPrefix = $this->extractPluginPrefix($pluginIdent);
    }


    /**
     * 开始构建列表查询工具参数
     *
     * @return $this
     */
    public function forList(): self
    {
        $toolName = $this->pluginPrefix . '_list_' . $this->tableInfo->name;
        $this->params = new ToolParams($toolName, 'list', $this->tableInfo->name, $this->pluginIdent);
        return $this;
    }

    /**
     * 开始构建单条查询工具参数
     *
     * @return $this
     */
    public function forGet(): self
    {
        $toolName = $this->pluginPrefix . '_get_' . $this->tableInfo->name;
        $this->params = new ToolParams($toolName, 'get', $this->tableInfo->name, $this->pluginIdent);
        return $this;
    }

    /**
     * 开始构建搜索工具参数
     *
     * @return $this
     */
    public function forSearch(): self
    {
        $toolName = $this->pluginPrefix . '_search_' . $this->tableInfo->name;
        $this->params = new ToolParams($toolName, 'search', $this->tableInfo->name, $this->pluginIdent);
        return $this;
    }

    /**
     * 开始构建统计工具参数
     *
     * @return $this
     */
    public function forStats(): self
    {
        $toolName = $this->pluginPrefix . '_stats_' . $this->tableInfo->name;
        $this->params = new ToolParams($toolName, 'stats', $this->tableInfo->name, $this->pluginIdent);
        return $this;
    }

    /**
     * 开始构建创建工具参数
     *
     * @return $this
     */
    public function forCreate(): self
    {
        $toolName = $this->pluginPrefix . '_create_' . $this->tableInfo->name;
        $this->params = new ToolParams($toolName, 'create', $this->tableInfo->name, $this->pluginIdent);
        return $this;
    }

    /**
     * 开始构建更新工具参数
     *
     * @return $this
     */
    public function forUpdate(): self
    {
        $toolName = $this->pluginPrefix . '_update_' . $this->tableInfo->name;
        $this->params = new ToolParams($toolName, 'update', $this->tableInfo->name, $this->pluginIdent);
        return $this;
    }

    /**
     * 开始构建删除工具参数
     *
     * @return $this
     */
    public function forDelete(): self
    {
        $toolName = $this->pluginPrefix . '_delete_' . $this->tableInfo->name;
        $this->params = new ToolParams($toolName, 'delete', $this->tableInfo->name, $this->pluginIdent);
        return $this;
    }

    /**
     * 开始构建关联查询工具参数
     *
     * @param string $relationName 关系方法名
     * @return $this
     */
    public function forListWithRelation(string $relationName): self
    {
        $toolName = $this->pluginPrefix . '_list_' . $this->tableInfo->name . '_with_' . $relationName;
        $this->params = new ToolParams($toolName, 'list_with_relation', $this->tableInfo->name, $this->pluginIdent);
        $this->params->relationName = $relationName;
        return $this;
    }


    /**
     * 添加分页参数（page + page_size）
     *
     * @return $this
     */
    public function addPagination(): self
    {
        $this->params->addParam('page', [
            'type'        => 'integer',
            'description' => '页码，从 1 开始，默认 1',
            'default'     => 1,
        ]);

        $this->params->addParam('page_size', [
            'type'        => 'integer',
            'description' => '每页数量，默认 20，最大 100',
            'default'     => 20,
        ]);

        return $this;
    }

    /**
     * 添加排序参数
     *
     * @param string[] $sortableFields 可排序字段列表
     * @return $this
     */
    public function addSorting(array $sortableFields = []): self
    {
        if (empty($sortableFields)) {
            $sortableFields = array_merge(
                [$this->tableInfo->primaryKey],
                $this->tableInfo->getFieldNames()
            );
            $sortableFields = array_unique($sortableFields);
        }

        $sortableFields = array_values(array_filter($sortableFields, function ($f) {
            return !in_array($f, ['password', 'token', 'content', 'description', 'remark'], true);
        }));

        $this->params->addParam('sort_field', [
            'type'        => 'string',
            'enum'        => $sortableFields,
            'description' => '排序字段',
            'default'     => $this->tableInfo->primaryKey,
        ]);

        $this->params->addParam('sort_order', [
            'type'        => 'string',
            'enum'        => ['asc', 'desc'],
            'description' => '排序方向：asc=升序, desc=降序',
            'default'     => 'desc',
        ]);

        return $this;
    }

    /**
     * 添加字段过滤参数
     *
     * 根据指定字段名自动生成对应的过滤参数（type + description）。
     *
     * @param string[] $fieldNames 可过滤的字段名列表
     * @return $this
     */
    public function addFieldFilters(array $fieldNames): self
    {
        foreach ($fieldNames as $fieldName) {
            $field = $this->tableInfo->getField($fieldName);
            if ($field === null) {
                continue;
            }

            $schema = $this->fieldToJsonSchema($field);
            if ($schema !== null) {
                $this->params->addParam($fieldName, $schema);
            }
        }

        return $this;
    }

    /**
     * 添加关键词搜索参数
     *
     * @return $this
     */
    public function addSearchKeyword(): self
    {
        $searchableFields = $this->tableInfo->getSearchableFields();

        $fieldNames = array_keys($searchableFields);
        $fieldList  = implode('、', $fieldNames);

        $description = '搜索关键词';
        if (!empty($fieldList)) {
            $description .= '，会在 ' . $fieldList . ' 中模糊搜索';
        }

        $this->params->addParam('keyword', [
            'type'        => 'string',
            'description' => $description,
        ], true);

        return $this;
    }

    /**
     * 添加统计类型参数
     *
     * @param bool $hasNumeric 是否有数值字段（影响 stat_type 的 enum）
     * @return $this
     */
    public function addStatType(bool $hasNumeric = true): self
    {
        $statTypes = ['count'];

        if ($hasNumeric) {
            $numericFields = $this->tableInfo->getNumericFields();
            if (!empty($numericFields)) {
                $statTypes[] = 'summary';
            }
        }

        $groupFields = $this->getGroupableFields();
        if (!empty($groupFields)) {
            $statTypes[] = 'group_by';
        }

        $this->params->addParam('stat_type', [
            'type'        => 'string',
            'enum'        => $statTypes,
            'description' => '统计类型：count=计数, summary=数值汇总（总和/均值/最大/最小）, group_by=分组统计',
        ], true);

        if (!empty($groupFields)) {
            $this->params->addParam('group_field', [
                'type'        => 'string',
                'enum'        => $groupFields,
                'description' => '分组统计的字段名（仅 stat_type=group_by 时有效）',
            ]);
        }

        return $this;
    }

    /**
     * 添加写入字段参数（用于 create/update）
     *
     * @param FieldInfo[] $writableFields 可写字段列表
     * @param bool $isCreate 是否为创建操作（创建时某些字段必填）
     * @return $this
     */
    public function addWritableFields(array $writableFields, bool $isCreate = false): self
    {
        foreach ($writableFields as $field) {
            if (!($field instanceof FieldInfo)) {
                continue;
            }

            if ($field->isPrimary && $field->isAutoIncrement) {
                continue;
            }

            $schema = $this->fieldToJsonSchema($field, true);

            if ($schema !== null) {
                $required = $isCreate && !$field->isNullable && $field->defaultValue === null;
                $this->params->addParam($field->name, $schema, $required);
            }
        }

        return $this;
    }

    /**
     * 添加主键参数（用于 get/update/delete）
     *
     * @return $this
     */
    public function addPrimaryKey(): self
    {
        $pkField = $this->tableInfo->getField($this->tableInfo->primaryKey);

        $description = '记录 ID（主键）';
        if ($pkField !== null && !empty($pkField->comment)) {
            $description = $pkField->comment;
        }

        $this->params->addParam('id', [
            'type'        => $this->getJsonType($pkField),
            'description' => $description,
        ], true);

        return $this;
    }


    /**
     * 构建并返回 ToolParams
     *
     * @return ToolParams
     */
    public function build(): ToolParams
    {
        return $this->params;
    }

    /**
     * 构建并返回 JSON Schema 数组（兼容 ToolInterface::getParameters()）
     *
     * @return array
     */
    public function buildJsonSchema(): array
    {
        return $this->params->toJsonSchema();
    }


    /**
     * 从插件标识提取短名作为工具前缀
     *
     * @param string $pluginIdent
     * @return string
     */
    private function extractPluginPrefix(string $pluginIdent): string
    {
        $parts = explode('/', $pluginIdent);
        return end($parts);
    }

    /**
     * 将 FieldInfo 转为 JSON Schema 属性定义
     *
     * @param FieldInfo $field 字段信息
     * @param bool $forWrite 是否为写入场景
     * @return array|null
     */
    private function fieldToJsonSchema(FieldInfo $field, bool $forWrite = false): ?array
    {
        $type = $this->getJsonType($field);

        if ($type === null) {
            return null;
        }

        $schema = [
            'type'        => $type,
            'description' => $this->buildFieldDescription($field, $forWrite),
        ];

        $enum = $this->extractEnumFromComment($field->comment);
        if (!empty($enum)) {
            if ($type === 'string') {
                $schema['type'] = 'string';
            }
        }

        return $schema;
    }

    /**
     * 获取字段的 JSON Schema 类型
     *
     * @param FieldInfo $field
     * @return string|null
     */
    private function getJsonType(?FieldInfo $field): ?string
    {
        if ($field === null) {
            return 'integer';
        }

        $typeMap = [
            'int'      => 'integer',
            'float'    => 'number',
            'string'   => 'string',
            'text'     => 'string',
            'datetime' => 'string',
            'date'     => 'string',
            'json'     => 'string',
        ];

        return isset($typeMap[$field->type]) ? $typeMap[$field->type] : 'string';
    }

    /**
     * 构建字段描述文本
     *
     * @param FieldInfo $field
     * @param bool $forWrite 是否为写入场景
     * @return string
     */
    private function buildFieldDescription(FieldInfo $field, bool $forWrite): string
    {
        $desc = $field->comment;

        if (empty($desc)) {
            $desc = $field->name;
        }

        if ($forWrite && $field->isNullable) {
            $desc .= '（可选）';
        }

        return $desc;
    }

    /**
     * 从注释中提取枚举值
     *
     * 支持格式：状态：0=下架, 1=在售, 2=预售
     *
     * @param string $comment
     * @return array
     */
    private function extractEnumFromComment(string $comment): array
    {
        if (empty($comment)) {
            return [];
        }

        if (preg_match_all('/(\d+)=([^,，]+)/u', $comment, $matches, PREG_SET_ORDER)) {
            return array_map(function ($m) {
                return intval($m[1]);
            }, $matches);
        }

        return [];
    }

    /**
     * 获取适合做分组统计的字段
     *
     * @return string[]
     */
    private function getGroupableFields(): array
    {
        $groupable = [];

        foreach ($this->tableInfo->fields as $field) {
            if (!($field instanceof FieldInfo)) {
                continue;
            }

            if ($field->isPrimary) {
                continue;
            }

            if ($field->type === 'int') {
                if (preg_match('/_id$|^status$|^type$|^state$|^category/', $field->name)) {
                    $groupable[] = $field->name;
                }
            }
        }

        return $groupable;
    }
}
