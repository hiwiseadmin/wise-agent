<?php
declare(strict_types=1);

namespace wise\agent\schema\dto;

/**
 * 插件完整 Schema 描述
 *
 * 包含一个插件所有数据表的完整结构信息。
 * 核心职责：
 *   - 存储内省结果
 *   - toContextPrompt() 生成 AI 可读的 Markdown 格式表结构描述
 *   - 支持 JSON 序列化用于缓存
 */
class PluginSchemaDTO implements \JsonSerializable
{
    /** @var string 插件标识 */
    public $pluginIdent = '';

    /** @var TableInfo[] 表信息列表 */
    public $tables = [];

    /** @var \DateTime 内省时间 */
    public $introspectedAt;

    /**
     * @param string $pluginIdent 插件标识
     * @param TableInfo[] $tables 表信息列表
     */
    public function __construct(string $pluginIdent, array $tables = [])
    {
        $this->pluginIdent    = $pluginIdent;
        $this->tables         = $tables;
        $this->introspectedAt = new \DateTime();
    }

    /**
     * @param array $data 从数组构造（用于缓存反序列化）
     */
    public static function fromArray(array $data): self
    {
        $tables = [];
        if (isset($data['tables']) && is_array($data['tables'])) {
            foreach ($data['tables'] as $tableData) {
                if (is_array($tableData)) {
                    $tables[] = TableInfo::fromArray($tableData);
                }
            }
        }

        $schema = new self($data['plugin_ident'] ?? '', $tables);

        if (isset($data['introspected_at'])) {
            try {
                $schema->introspectedAt = new \DateTime($data['introspected_at']);
            } catch (\Exception $e) {
            }
        }

        return $schema;
    }

    /**
     * 获取所有表名
     *
     * @return string[]
     */
    public function getTableNames(): array
    {
        return array_map(function (TableInfo $t) {
            return $t->name;
        }, $this->tables);
    }

    /**
     * 获取单张表信息
     *
     * @param string $name 表名（不含前缀）
     * @return TableInfo|null
     */
    public function getTable(string $name): ?TableInfo
    {
        foreach ($this->tables as $table) {
            if ($table->name === $name) {
                return $table;
            }
        }
        return null;
    }

    /**
     * 获取表数量
     *
     * @return int
     */
    public function getTableCount(): int
    {
        return count($this->tables);
    }

    /**
     * 获取字段总数
     *
     * @return int
     */
    public function getFieldCount(): int
    {
        $count = 0;
        foreach ($this->tables as $table) {
            $count += count($table->fields);
        }
        return $count;
    }

    /**
     * 获取关系总数
     *
     * @return int
     */
    public function getRelationCount(): int
    {
        $count = 0;
        foreach ($this->tables as $table) {
            $count += count($table->relations);
        }
        return $count;
    }

    /**
     * 生成 AI Context 描述文本
     *
     * 输出 Markdown 格式的完整表结构描述，可直接注入到 Agent system prompt。
     * 包含：表列表（字段/类型/注释）、表关系图、软删除提醒。
     *
     * @return string Markdown 格式文本
     */
    public function toContextPrompt(): string
    {
        $lines = [];
        $lines[] = '## 插件 [' . $this->pluginIdent . '] 数据模型';
        $lines[] = '';
        $lines[] = '> 内省时间：' . $this->introspectedAt->format('Y-m-d H:i:s');
        $lines[] = '';
        $lines[] = '以下为该插件所有数据表的结构信息。查询数据时请使用下面的工具函数。';
        $lines[] = '';

        foreach ($this->tables as $table) {
            $lines[] = $this->buildTableSection($table);
        }

        if ($this->getRelationCount() > 0) {
            $lines[] = '### 表关系';
            $lines[] = '';
            foreach ($this->tables as $table) {
                if (empty($table->relations)) {
                    continue;
                }
                foreach ($table->relations as $rel) {
                    $lines[] = '- `' . $table->name . '` --' . $rel->getDescription();
                }
            }
            $lines[] = '';
        }

        $softDeleteTables = [];
        foreach ($this->tables as $table) {
            if ($table->hasSoftDelete) {
                $softDeleteTables[] = $table->name;
            }
        }
        if (!empty($softDeleteTables)) {
            $lines[] = '### 软删除提醒';
            $lines[] = '';
            $lines[] = '以下表使用软删除机制，查询时需过滤已删除记录：';
            $lines[] = '';
            foreach ($softDeleteTables as $tableName) {
                $lines[] = '- `' . $tableName . '`: 过滤条件 `' . ($this->getTable($tableName)->deleteTimeField ?? 'delete_time') . ' IS NULL`';
            }
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /**
     * 构建单张表的 Markdown 描述段落
     *
     * @param TableInfo $table
     * @return string
     */
    private function buildTableSection(TableInfo $table): string
    {
        $lines = [];
        $comment = $table->comment ? ' — ' . $table->comment : '';
        $softDeleteTag = $table->hasSoftDelete ? ' [软删除]' : '';

        $lines[] = '#### 表 `' . $table->name . '`' . $comment . $softDeleteTag;
        $lines[] = '';
        $lines[] = '| 字段 | 类型 | 说明 | 主键 | 可空 |';
        $lines[] = '|------|------|------|:----:|:----:|';

        foreach ($table->fields as $field) {
            $pk = $field->isPrimary ? '✓' : '';
            $nl = $field->isNullable ? '✓' : '';
            $lines[] = '| `' . $field->name . '` | ' . $field->type . ' | ' . $field->comment . ' | ' . $pk . ' | ' . $nl . ' |';
        }

        $lines[] = '';
        return implode("\n", $lines);
    }

    /**
     * 转为数组
     */
    public function toArray(): array
    {
        $tables = [];
        foreach ($this->tables as $table) {
            $tables[] = $table->toArray();
        }

        return [
            'plugin_ident'    => $this->pluginIdent,
            'tables'          => $tables,
            'introspected_at' => $this->introspectedAt->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * JSON 序列化
     */
    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        return $this->toArray();
    }
}
