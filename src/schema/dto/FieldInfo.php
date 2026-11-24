<?php
declare(strict_types=1);

namespace wise\agent\schema\dto;

/**
 * 字段信息 DTO
 *
 * 描述数据库表中单个字段的元数据。
 * 信息来源于模型 $schema 属性、数据库 SHOW FULL COLUMNS、以及 @property 注释。
 */
class FieldInfo implements \JsonSerializable
{
    /** @var string 字段名 */
    public $name = '';

    /** @var string 简化类型：int|float|string|text|datetime|date|json */
    public $type = 'string';

    /** @var string 字段注释/描述 */
    public $comment = '';

    /** @var bool 是否为主键 */
    public $isPrimary = false;

    /** @var bool 是否允许 NULL */
    public $isNullable = true;

    /** @var mixed 默认值 */
    public $defaultValue = null;

    /** @var int|null 字符串字段最大长度 */
    public $maxLength = null;

    /** @var bool 是否为自增字段 */
    public $isAutoIncrement = false;

    /** @var string 数据库原始类型（如 varchar(255)、int(11) unsigned） */
    public $rawType = '';

    /** @var bool 是否参与 AI 自然语言搜索（AiSearchable 注解） */
    public $aiSearchable = false;

    /** @var bool 是否可被 AI 表单填充（AiFillable 注解） */
    public $aiFillable = false;

    /** @var string AI 填充提示词（AiFillable 注解 prompt） */
    public $aiFillPrompt = '';

    /** @var string 自动生成该字段的 Skill 名称（AiGenerated 注解） */
    public $aiGeneratedSkill = '';

    /**
     * @param array $data 从数组构造
     */
    public static function fromArray(array $data): self
    {
        $field = new self();
        $field->name            = $data['name'] ?? '';
        $field->type            = $data['type'] ?? 'string';
        $field->comment         = $data['comment'] ?? '';
        $field->isPrimary       = $data['is_primary'] ?? false;
        $field->isNullable      = $data['is_nullable'] ?? true;
        $field->defaultValue    = $data['default_value'] ?? null;
        $field->maxLength       = $data['max_length'] ?? null;
        $field->isAutoIncrement = $data['is_auto_increment'] ?? false;
        $field->rawType         = $data['raw_type'] ?? '';
        $field->aiSearchable    = $data['ai_searchable'] ?? false;
        $field->aiFillable      = $data['ai_fillable'] ?? false;
        $field->aiFillPrompt    = $data['ai_fill_prompt'] ?? '';
        $field->aiGeneratedSkill = $data['ai_generated_skill'] ?? '';
        return $field;
    }

    /**
     * 转为数组
     */
    public function toArray(): array
    {
        return [
            'name'              => $this->name,
            'type'              => $this->type,
            'comment'           => $this->comment,
            'is_primary'        => $this->isPrimary,
            'is_nullable'       => $this->isNullable,
            'default_value'     => $this->defaultValue,
            'max_length'        => $this->maxLength,
            'is_auto_increment' => $this->isAutoIncrement,
            'raw_type'          => $this->rawType,
            'ai_searchable'     => $this->aiSearchable,
            'ai_fillable'       => $this->aiFillable,
            'ai_fill_prompt'    => $this->aiFillPrompt,
            'ai_generated_skill' => $this->aiGeneratedSkill,
        ];
    }

    /**
     * 是否为指定类型
     */
    public function isType(string $type): bool
    {
        return $this->type === $type;
    }

    /**
     * 是否为数值类型（int 或 float）
     */
    public function isNumeric(): bool
    {
        return in_array($this->type, ['int', 'float'], true);
    }

    /**
     * 是否为字符串类型
     */
    public function isString(): bool
    {
        return $this->type === 'string';
    }

    /**
     * 是否为日期时间类型
     */
    public function isDateType(): bool
    {
        return in_array($this->type, ['datetime', 'date'], true);
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
