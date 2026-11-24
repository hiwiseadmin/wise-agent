<?php
declare(strict_types=1);

namespace wise\agent\schema\dto;

/**
 * 表信息 DTO
 *
 * 描述数据库中的单张表，包含字段列表、关系图、模型元数据。
 */
class TableInfo implements \JsonSerializable
{
    /** @var string 表名（不含前缀） */
    public $name = '';

    /** @var string 表注释 */
    public $comment = '';

    /** @var string 模型完全限定类名 */
    public $modelClass = '';

    /** @var string 主键字段名 */
    public $primaryKey = 'id';

    /** @var FieldInfo[] 字段列表 */
    public $fields = [];

    /** @var RelationInfo[] 关系列表 */
    public $relations = [];

    /** @var bool 是否启用软删除 */
    public $hasSoftDelete = false;

    /** @var bool 是否启用自动时间戳 */
    public $hasTimestamps = true;

    /** @var string|null 删除时间字段名（仅软删除表） */
    public $deleteTimeField = null;

    /**
     * @param array $data 从数组构造
     */
    public static function fromArray(array $data): self
    {
        $table = new self();
        $table->name            = $data['name'] ?? '';
        $table->comment         = $data['comment'] ?? '';
        $table->modelClass      = $data['model_class'] ?? '';
        $table->primaryKey      = $data['primary_key'] ?? 'id';
        $table->hasSoftDelete   = $data['has_soft_delete'] ?? false;
        $table->hasTimestamps   = $data['has_timestamps'] ?? true;
        $table->deleteTimeField = $data['delete_time_field'] ?? null;

        $table->fields = [];
        if (isset($data['fields']) && is_array($data['fields'])) {
            foreach ($data['fields'] as $fieldData) {
                if (is_array($fieldData)) {
                    $table->fields[] = FieldInfo::fromArray($fieldData);
                }
            }
        }

        $table->relations = [];
        if (isset($data['relations']) && is_array($data['relations'])) {
            foreach ($data['relations'] as $relData) {
                if (is_array($relData)) {
                    $table->relations[] = RelationInfo::fromArray($relData);
                }
            }
        }

        return $table;
    }

    /**
     * 转为数组
     */
    public function toArray(): array
    {
        $fields = [];
        foreach ($this->fields as $field) {
            $fields[] = $field instanceof FieldInfo ? $field->toArray() : $field;
        }

        $relations = [];
        foreach ($this->relations as $relation) {
            $relations[] = $relation instanceof RelationInfo ? $relation->toArray() : $relation;
        }

        return [
            'name'               => $this->name,
            'comment'            => $this->comment,
            'model_class'        => $this->modelClass,
            'primary_key'        => $this->primaryKey,
            'fields'             => $fields,
            'relations'          => $relations,
            'has_soft_delete'    => $this->hasSoftDelete,
            'has_timestamps'     => $this->hasTimestamps,
            'delete_time_field'  => $this->deleteTimeField,
        ];
    }

    /**
     * 获取指定名称的字段
     *
     * @param string $name 字段名
     * @return FieldInfo|null
     */
    public function getField(string $name): ?FieldInfo
    {
        foreach ($this->fields as $field) {
            if ($field instanceof FieldInfo && $field->name === $name) {
                return $field;
            }
        }
        return null;
    }

    /**
     * 获取所有可搜索字段（排除敏感和系统字段）
     *
     * @return FieldInfo[] 关联数组 [fieldName => FieldInfo]
     */
    public function getSearchableFields(): array
    {
        $sensitive = ['password', 'token', 'secret', 'api_key', 'salt', 'hash', 'private_key', 'delete_time'];
        $result = [];
        foreach ($this->fields as $field) {
            if (!($field instanceof FieldInfo)) {
                continue;
            }
            if (in_array($field->name, $sensitive, true)) {
                continue;
            }
            if ($field->aiSearchable) {
                $result[$field->name] = $field;
                continue;
            }
            if ($field->type !== 'string') {
                continue;
            }
            $result[$field->name] = $field;
        }
        return $result;
    }

    /**
     * 获取所有数值类型字段（排除主键）
     *
     * @return FieldInfo[] 关联数组 [fieldName => FieldInfo]
     */
    public function getNumericFields(): array
    {
        $result = [];
        foreach ($this->fields as $field) {
            if (!($field instanceof FieldInfo)) {
                continue;
            }
            if (!$field->isNumeric()) {
                continue;
            }
            if ($field->isPrimary) {
                continue;
            }
            $result[$field->name] = $field;
        }
        return $result;
    }

    /**
     * 获取所有字段名
     *
     * @return string[]
     */
    public function getFieldNames(): array
    {
        return array_map(function ($f) {
            return $f instanceof FieldInfo ? $f->name : '';
        }, $this->fields);
    }

    /**
     * 获取所有日期时间字段名
     *
     * @return string[]
     */
    public function getDateFieldNames(): array
    {
        $names = [];
        foreach ($this->fields as $field) {
            if ($field instanceof FieldInfo && $field->isDateType()) {
                $names[] = $field->name;
            }
        }
        return $names;
    }

    /**
     * 获取指定类型的关系
     *
     * @param string $type belongsTo|hasOne|hasMany|belongsToMany
     * @return RelationInfo[]
     */
    public function getRelationsOfType(string $type): array
    {
        $result = [];
        foreach ($this->relations as $rel) {
            if ($rel instanceof RelationInfo && $rel->type === $type) {
                $result[] = $rel;
            }
        }
        return $result;
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
