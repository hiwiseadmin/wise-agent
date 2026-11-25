<?php
declare(strict_types=1);

namespace wise\agent\schema\dto;

/**
 * 关系信息 DTO
 *
 * 描述两个表之间的 ORM 关系。
 * 通过反射模型类的方法体静态分析提取。
 */
class RelationInfo implements \JsonSerializable
{
    /** @var string 关系方法名（如 category、stocks） */
    public $name = '';

    /** @var string 关系类型：belongsTo|hasOne|hasMany|belongsToMany|morphMany|morphToMany */
    public $type = '';

    /** @var string 目标表名（不含前缀） */
    public $targetTable = '';

    /** @var string 目标模型完全限定类名 */
    public $targetClass = '';

    /** @var string 外键字段名 */
    public $foreignKey = '';

    /** @var string 本地键字段名 */
    public $localKey = '';

    /**
     * @param array $data 从数组构造
     */
    public static function fromArray(array $data): self
    {
        $relation = new self();
        $relation->name        = $data['name'] ?? '';
        $relation->type        = $data['type'] ?? '';
        $relation->targetTable = $data['target_table'] ?? '';
        $relation->targetClass = $data['target_class'] ?? '';
        $relation->foreignKey  = $data['foreign_key'] ?? '';
        $relation->localKey    = $data['local_key'] ?? '';
        return $relation;
    }

    /**
     * 转为数组
     */
    public function toArray(): array
    {
        return [
            'name'         => $this->name,
            'type'         => $this->type,
            'target_table' => $this->targetTable,
            'target_class' => $this->targetClass,
            'foreign_key'  => $this->foreignKey,
            'local_key'    => $this->localKey,
        ];
    }

    /**
     * 获取关系的描述文本
     */
    public function getDescription(): string
    {
        $arrowMap = [
            'belongsTo'     => 'N:1 属于',
            'hasOne'        => '1:1 拥有',
            'hasMany'       => '1:N 拥有多个',
            'belongsToMany' => 'N:M 多对多',
            'morphMany'     => '1:N 多态拥有多个',
            'morphToMany'   => 'N:M 多态多对多',
        ];

        $arrow = isset($arrowMap[$this->type]) ? $arrowMap[$this->type] : $this->type;

        return "{$arrow} `{$this->targetTable}`"
            . ($this->foreignKey ? " (外键: {$this->foreignKey})" : '');
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
