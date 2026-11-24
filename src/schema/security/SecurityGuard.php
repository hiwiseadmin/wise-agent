<?php
declare(strict_types=1);

namespace wise\agent\schema\security;

use wise\agent\schema\dto\FieldInfo;
use wise\agent\schema\dto\TableInfo;
use wise\agent\schema\dto\PluginSchemaDTO;
use wise\agent\security\CapabilityAccess;

/**
 * 安全守卫
 *
 * 提供白名单安全模型，控制 AI 对插件数据表的访问权限。
 * 核心安全策略：
 *   - 表级白名单：仅允许访问指定的表
 *   - 字段级可读/可写控制：细粒度控制哪些字段 AI 可以读取/写入
 *   - 敏感字段自动排除：password、token、secret 等字段永远不可访问
 *   - 写操作安全等级：create/update/delete 默认需要确认
 *
 * 使用方式：
 *   $guard = SecurityGuard::fromTableWhitelist(['smartstock_product', 'smartstock_stock']);
 *   $guard->setWritableFields('smartstock_stock', ['quantity', 'warehouse_id']);
 *   if ($guard->isTableAllowed('smartstock_product')) { ... }
 */
class SecurityGuard
{
    /**
     * 敏感字段名模式（全局禁止，不可覆盖）
     *
     * @var string[]
     */
    const SENSITIVE_PATTERNS = [
        'password',
        'token',
        'secret',
        'api_key',
        'salt',
        'hash',
        'private_key',
        'access_token',
        'refresh_token',
        'encryption_key',
        'passwd',
        'pwd',
    ];

    /**
     * 系统字段（通常不应由 AI 直接操作）
     *
     * @var string[]
     */
    const SYSTEM_FIELDS = [
        'id',
        'create_time',
        'update_time',
        'delete_time',
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    /**
     * owner_key 候选字段名（用于自动探测行级数据归属）
     *
     * 命中即视为该表存在"数据归属人"字段，用于行级隔离。
     *
     * @var string[]
     */
    const OWNER_KEY_CANDIDATES = [
        'user_id',
        'admin_id',
        'created_by',
        'operator_id',
        'owner_id',
        'member_id',
        'creator_id',
        'uid',
        'userid',
    ];

    /** @var string[] 允许访问的表名（白名单） */
    private $allowedTables = [];

    /** @var array<string, string[]> 每张表可读的字段白名单（为空表示不做限制） */
    private $readableFields = [];

    /** @var array<string, string[]> 每张表可写的字段白名单（为空表示不做限制） */
    private $writableFields = [];

    /** @var bool 写操作是否需要确认 */
    private $writeRequiresConfirmation = true;

    /** @var bool 是否自动排除敏感字段 */
    private $autoExcludeSensitive = true;

    /** @var bool 是否自动排除系统字段 */
    private $autoExcludeSystem = true;

    /** @var array<string, string|null> 每张表的 owner_key（null 表示未设置） */
    private $ownerKeys = [];

    /** @var bool 是否自动探测 owner_key */
    private $autoDetectOwnerKey = true;

    /** @var bool 是否自动应用行级 owner 隔离（读操作） */
    private $autoOwnerScope = true;


    /**
     * 从表名白名单创建安全守卫
     *
     * @param string[] $tableNames 允许的表名列表
     * @return self
     */
    public static function fromTableWhitelist(array $tableNames): self
    {
        $guard = new self();
        foreach ($tableNames as $tableName) {
            $guard->allowTable($tableName);
        }
        return $guard;
    }

    /**
     * 从 manifest.json ai_schema 配置创建安全守卫
     *
     * 支持的配置格式：
     * {
     *   "auto_introspect": true,
     *   "allowed_tables": ["table1", "table2"],        // 表白名单
     *   "readable_fields": {"table1": ["field1"]},     // 可读字段
     *   "writable_fields": {"table1": ["field2"]},     // 可写字段
     *   "write_requires_confirmation": true
     * }
     *
     * @param array $aiSchema ai_schema 配置数组
     * @return self
     */
    public static function fromManifest(array $aiSchema): self
    {
        $guard = new self();

        if (isset($aiSchema['allowed_tables']) && is_array($aiSchema['allowed_tables'])) {
            foreach ($aiSchema['allowed_tables'] as $tableName) {
                $guard->allowTable($tableName);
            }
        }

        if (isset($aiSchema['readable_fields']) && is_array($aiSchema['readable_fields'])) {
            foreach ($aiSchema['readable_fields'] as $tableName => $fields) {
                if (is_array($fields)) {
                    $guard->setReadableFields($tableName, $fields);
                }
            }
        }

        if (isset($aiSchema['writable_fields']) && is_array($aiSchema['writable_fields'])) {
            foreach ($aiSchema['writable_fields'] as $tableName => $fields) {
                if (is_array($fields)) {
                    $guard->setWritableFields($tableName, $fields);
                }
            }
        }

        if (isset($aiSchema['write_requires_confirmation'])) {
            $guard->writeRequiresConfirmation = (bool) $aiSchema['write_requires_confirmation'];
        }

        if (isset($aiSchema['owner_keys']) && is_array($aiSchema['owner_keys'])) {
            foreach ($aiSchema['owner_keys'] as $tableName => $field) {
                $guard->setOwnerKey((string) $tableName, $field ? (string) $field : null);
            }
        }

        if (isset($aiSchema['auto_detect_owner_key'])) {
            $guard->setAutoDetectOwnerKey((bool) $aiSchema['auto_detect_owner_key']);
        }

        if (isset($aiSchema['auto_owner_scope'])) {
            $guard->setAutoOwnerScope((bool) $aiSchema['auto_owner_scope']);
        }

        return $guard;
    }

    /**
     * 从 PluginSchemaDTO 创建全开放安全守卫（所有表可读，无表可写）
     *
     * @param PluginSchemaDTO $schema
     * @return self
     */
    public static function fromSchema(PluginSchemaDTO $schema): self
    {
        $guard = new self();
        foreach ($schema->tables as $table) {
            if ($table instanceof TableInfo) {
                $guard->allowTable($table->name);
            }
        }
        return $guard;
    }


    /**
     * 将表名加入白名单
     *
     * @param string $tableName
     * @return $this
     */
    public function allowTable(string $tableName): self
    {
        if (!in_array($tableName, $this->allowedTables, true)) {
            $this->allowedTables[] = $tableName;
        }
        return $this;
    }

    /**
     * 将表名从白名单中移除
     *
     * @param string $tableName
     * @return $this
     */
    public function denyTable(string $tableName): self
    {
        $index = array_search($tableName, $this->allowedTables, true);
        if ($index !== false) {
            unset($this->allowedTables[$index]);
            $this->allowedTables = array_values($this->allowedTables);
        }
        return $this;
    }

    /**
     * 检查表是否在访问白名单内
     *
     * @param string $tableName
     * @return bool
     */
    public function isTableAllowed(string $tableName): bool
    {
        return in_array($tableName, $this->allowedTables, true);
    }

    /**
     * 获取所有允许的表名
     *
     * @return string[]
     */
    public function getAllowedTables(): array
    {
        return $this->allowedTables;
    }


    /**
     * 设置某张表的可读字段白名单
     *
     * @param string $tableName
     * @param string[] $fieldNames
     * @return $this
     */
    public function setReadableFields(string $tableName, array $fieldNames): self
    {
        $this->readableFields[$tableName] = $fieldNames;
        return $this;
    }

    /**
     * 设置某张表的可写字段白名单
     *
     * @param string $tableName
     * @param string[] $fieldNames
     * @return $this
     */
    public function setWritableFields(string $tableName, array $fieldNames): self
    {
        $this->writableFields[$tableName] = $fieldNames;
        return $this;
    }

    /**
     * 检查字段是否可读
     *
     * @param string $tableName
     * @param string $fieldName
     * @return bool
     */
    public function isFieldReadable(string $tableName, string $fieldName): bool
    {
        if ($this->autoExcludeSensitive && $this->isSensitiveField($fieldName)) {
            return false;
        }

        if (!$this->isTableAllowed($tableName)) {
            return false;
        }

        if (!isset($this->readableFields[$tableName]) || empty($this->readableFields[$tableName])) {
            return true;
        }

        return in_array($fieldName, $this->readableFields[$tableName], true);
    }

    /**
     * 检查字段是否可写
     *
     * @param string $tableName
     * @param string $fieldName
     * @return bool
     */
    public function isFieldWritable(string $tableName, string $fieldName): bool
    {
        if ($this->autoExcludeSensitive && $this->isSensitiveField($fieldName)) {
            return false;
        }

        if ($this->autoExcludeSystem && $this->isSystemField($fieldName)) {
            return false;
        }

        if (!$this->isTableAllowed($tableName)) {
            return false;
        }

        if (!isset($this->writableFields[$tableName]) || empty($this->writableFields[$tableName])) {
            return false;
        }

        return in_array($fieldName, $this->writableFields[$tableName], true);
    }

    /**
     * 过滤出可读的字段列表
     *
     * @param string $tableName 表名
     * @param FieldInfo[] $fields 字段列表
     * @return FieldInfo[] 可读字段列表
     */
    public function filterReadableFields(string $tableName, array $fields): array
    {
        $result = [];
        foreach ($fields as $field) {
            if (!($field instanceof FieldInfo)) {
                continue;
            }
            if ($this->isFieldReadable($tableName, $field->name)) {
                $result[] = $field;
            }
        }
        return $result;
    }

    /**
     * 过滤出可写的字段列表
     *
     * @param string $tableName 表名
     * @param FieldInfo[] $fields 字段列表
     * @return FieldInfo[] 可写字段列表
     */
    public function filterWritableFields(string $tableName, array $fields): array
    {
        $result = [];
        foreach ($fields as $field) {
            if (!($field instanceof FieldInfo)) {
                continue;
            }
            if ($this->isFieldWritable($tableName, $field->name)) {
                $result[] = $field;
            }
        }
        return $result;
    }

    /**
     * 获取某张表的可读字段名列表
     *
     * @param string $tableName
     * @return string[]
     */
    public function getReadableFieldNames(string $tableName): array
    {
        if (isset($this->readableFields[$tableName]) && !empty($this->readableFields[$tableName])) {
            return $this->readableFields[$tableName];
        }
        return [];
    }

    /**
     * 获取某张表的可写字段名列表
     *
     * @param string $tableName
     * @return string[]
     */
    public function getWritableFieldNames(string $tableName): array
    {
        if (isset($this->writableFields[$tableName]) && !empty($this->writableFields[$tableName])) {
            return $this->writableFields[$tableName];
        }
        return [];
    }


    /**
     * 设置写操作是否需要确认
     *
     * @param bool $require
     * @return $this
     */
    public function setWriteRequiresConfirmation(bool $require): self
    {
        $this->writeRequiresConfirmation = $require;
        return $this;
    }

    /**
     * 写操作是否需要确认
     *
     * @return bool
     */
    public function isWriteRequiresConfirmation(): bool
    {
        return $this->writeRequiresConfirmation;
    }

    /**
     * 设置是否自动排除敏感字段
     *
     * @param bool $auto
     * @return $this
     */
    public function setAutoExcludeSensitive(bool $auto): self
    {
        $this->autoExcludeSensitive = $auto;
        return $this;
    }

    /**
     * 设置是否自动排除系统字段
     *
     * @param bool $auto
     * @return $this
     */
    public function setAutoExcludeSystem(bool $auto): self
    {
        $this->autoExcludeSystem = $auto;
        return $this;
    }


    /**
     * 检查工具类型是否允许执行
     *
     * 写操作工具（create/update/delete）需要表在白名单内。
     * 读操作工具（list/get/search/stats）也需要表在白名单内。
     *
     * @param string $toolType
     * @param string $tableName
     * @return bool
     */
    public function isToolTypeAllowed(string $toolType, string $tableName): bool
    {
        if (!$this->isTableAllowed($tableName)) {
            return false;
        }

        if (in_array($toolType, CapabilityAccess::WRITE_TOOL_TYPES, true)) {
            $writable = $this->getWritableFieldNames($tableName);
            if ($toolType === 'update' || $toolType === 'delete') {
                return true;
            }
            if (empty($writable)) {
                return false;
            }
        }

        return true;
    }

    /**
     * 工具类型是否需要确认
     *
     * @param string $toolType
     * @return bool
     */
    public function toolTypeRequiresConfirmation(string $toolType): bool
    {
        if (!$this->writeRequiresConfirmation) {
            return false;
        }

        return in_array($toolType, CapabilityAccess::WRITE_TOOL_TYPES, true);
    }


    /**
     * 从 PluginSchemaDTO 中过滤出允许的表
     *
     * @param PluginSchemaDTO $schema
     * @return PluginSchemaDTO 过滤后的 Schema（仅包含允许的表）
     */
    public function filterSchema(PluginSchemaDTO $schema): PluginSchemaDTO
    {
        $filteredTables = [];

        foreach ($schema->tables as $table) {
            if (!($table instanceof TableInfo)) {
                continue;
            }
            if ($this->isTableAllowed($table->name)) {
                $filteredTable = clone $table;
                $filteredTable->fields = $this->filterReadableFields($table->name, $table->fields);
                $filteredTables[] = $filteredTable;
            }
        }

        return new PluginSchemaDTO($schema->pluginIdent, $filteredTables);
    }

    /**
     * 检查是否为敏感字段
     *
     * @param string $fieldName
     * @return bool
     */
    public function isSensitiveField(string $fieldName): bool
    {
        foreach (self::SENSITIVE_PATTERNS as $pattern) {
            if (stripos($fieldName, $pattern) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * 检查是否为系统字段
     *
     * @param string $fieldName
     * @return bool
     */
    public function isSystemField(string $fieldName): bool
    {
        return in_array($fieldName, self::SYSTEM_FIELDS, true);
    }


    /**
     * 设置是否自动探测 owner_key
     *
     * @param bool $auto
     * @return $this
     */
    public function setAutoDetectOwnerKey(bool $auto): self
    {
        $this->autoDetectOwnerKey = $auto;
        return $this;
    }

    /**
     * 是否自动探测 owner_key
     *
     * @return bool
     */
    public function isAutoDetectOwnerKey(): bool
    {
        return $this->autoDetectOwnerKey;
    }

    /**
     * 设置是否自动应用行级 owner 隔离
     *
     * @param bool $auto
     * @return $this
     */
    public function setAutoOwnerScope(bool $auto): self
    {
        $this->autoOwnerScope = $auto;
        return $this;
    }

    /**
     * 是否自动应用行级 owner 隔离
     *
     * @return bool
     */
    public function isAutoOwnerScope(): bool
    {
        return $this->autoOwnerScope;
    }

    /**
     * 显式设置某张表的 owner_key
     *
     * @param string      $tableName 表名
     * @param string|null $field     归属字段名（null 表示无归属）
     * @return $this
     */
    public function setOwnerKey(string $tableName, ?string $field): self
    {
        $this->ownerKeys[$tableName] = $field;
        return $this;
    }

    /**
     * 从表字段中自动探测 owner_key
     *
     * 按 OWNER_KEY_CANDIDATES 顺序匹配第一个存在的字段。
     *
     * @param TableInfo $table
     * @return string|null
     */
    public function detectOwnerKey(TableInfo $table): ?string
    {
        if (!$this->autoDetectOwnerKey) {
            return null;
        }

        $fieldNames = [];
        foreach ($table->fields as $field) {
            if ($field instanceof FieldInfo) {
                $fieldNames[] = $field->name;
            }
        }

        foreach (self::OWNER_KEY_CANDIDATES as $candidate) {
            if (in_array($candidate, $fieldNames, true)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * 批量自动探测 Schema 中所有表的 owner_key（不覆盖已显式设置的表）
     *
     * @param PluginSchemaDTO $schema
     * @return void
     */
    public function detectOwnerKeys(PluginSchemaDTO $schema): void
    {
        foreach ($schema->tables as $table) {
            if (!($table instanceof TableInfo)) {
                continue;
            }
            if (!array_key_exists($table->name, $this->ownerKeys)) {
                $this->ownerKeys[$table->name] = $this->detectOwnerKey($table);
            }
        }
    }

    /**
     * 获取某张表的 owner_key
     *
     * @param string $tableName
     * @return string|null
     */
    public function getOwnerKey(string $tableName): ?string
    {
        return $this->ownerKeys[$tableName] ?? null;
    }

    /**
     * 转为数组
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'allowed_tables'              => $this->allowedTables,
            'readable_fields'             => $this->readableFields,
            'writable_fields'             => $this->writableFields,
            'write_requires_confirmation'  => $this->writeRequiresConfirmation,
            'auto_exclude_sensitive'      => $this->autoExcludeSensitive,
            'auto_exclude_system'         => $this->autoExcludeSystem,
            'owner_keys'                  => $this->ownerKeys,
            'auto_detect_owner_key'       => $this->autoDetectOwnerKey,
            'auto_owner_scope'            => $this->autoOwnerScope,
        ];
    }
}
