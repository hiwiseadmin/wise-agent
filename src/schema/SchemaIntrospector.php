<?php
declare(strict_types=1);

namespace wise\agent\schema;

use wise\agent\schema\attribute\AiFillable;
use wise\agent\schema\attribute\AiGenerated;
use wise\agent\schema\attribute\AiSearchable;
use wise\agent\schema\dto\FieldInfo;
use wise\agent\schema\dto\PluginSchemaDTO;
use wise\agent\schema\dto\RelationInfo;
use wise\agent\schema\dto\TableInfo;

/**
 * 插件数据模型内省器
 *
 * 从以下来源收集表结构信息（优先级从高到低）：
 *   1. 模型的 $schema 属性（显式声明）
 *   2. 数据库 SHOW FULL COLUMNS / Db::getFields()（字段注释、类型、默认值）
 *   3. 数据库 INFORMATION_SCHEMA（表注释）
 *   4. 模型 @property 注释（字段描述）
 *   5. 模型方法反射→关系检测（belongsTo/hasMany 等）
 *
 * 使用示例：
 *   $introspector = new SchemaIntrospector();
 *   $schema = $introspector->introspect('smartstock');
 *   echo $schema->toContextPrompt();
 */
class SchemaIntrospector
{
    /** @var string 插件标识 */
    private $pluginIdent = '';

    /** @var string 插件根目录 */
    private $pluginPath = '';

    /** @var string 插件命名空间前缀 */
    private $pluginNamespace = '';

    /** @var string[] 已内省的表名（防止重复） */
    private $introspectedTables = [];

    /** @var string 数据库前缀 */
    private $dbPrefix = '';

    /** @var array<string, array> 文件读取缓存，避免同一模型文件被重复读取 */
    private static $fileLineCache = [];

    /**
     * 设置数据库前缀（可选，自动从配置读取）
     *
     * @param string|null $prefix 表前缀
     */
    public function setDbPrefix(?string $prefix = null): void
    {
        if ($prefix !== null) {
            $this->dbPrefix = $prefix;
        } else {
            try {
                $this->dbPrefix = \think\facade\Db::getConfig('prefix') ?? '';
            } catch (\Throwable $e) {
                $this->dbPrefix = '';
            }
        }
    }

    /**
     * 对插件执行完整内省
     *
     * 流程：
     *   1. 扫描 plugin/{ident}/model/*.php → 模型类列表
     *   2. 逐个内省模型 → 提取字段、注释、软删除状态
     *   3. 构建表关系图 → 反射关系方法
     *
     * @param string $pluginIdent 插件标识（如 smartstock、wisecms）
     * @return PluginSchemaDTO
     */
    public function introspect(string $pluginIdent): PluginSchemaDTO
    {
        $this->pluginIdent    = $pluginIdent;
        $this->pluginPath     = plugin_path() . $pluginIdent . DIRECTORY_SEPARATOR;
        $this->pluginNamespace = 'plugin\\' . $pluginIdent . '\\';
        $this->introspectedTables = [];

        self::$fileLineCache = [];

        if ($this->dbPrefix === '') {
            $this->setDbPrefix(null);
        }

        $tables = [];

        $modelClasses = $this->scanModelClasses();

        $modelInstances = [];
        foreach ($modelClasses as $className) {
            try {
                $instance = new $className();
                $tableInfo = $this->introspectModelInstance($instance, $className);
                if ($tableInfo !== null) {
                    $tables[$tableInfo->name] = $tableInfo;
                    $modelInstances[$className] = $instance;
                }
            } catch (\Throwable $e) {
                continue;
            }
        }

        $relations = $this->buildRelationGraphFromInstances($modelInstances, $tables);
        foreach ($relations as $tableName => $tableRelations) {
            if (isset($tables[$tableName])) {
                $tables[$tableName]->relations = $tableRelations;
            }
        }

        return new PluginSchemaDTO($this->pluginIdent, array_values($tables));
    }

    /**
     * 扫描插件的 model/ 目录，返回模型类名列表
     *
     * @return string[] 完全限定类名数组
     */
    private function scanModelClasses(): array
    {
        $modelDir = $this->pluginPath . 'model' . DIRECTORY_SEPARATOR;

        if (!is_dir($modelDir)) {
            return [];
        }

        $classes = [];
        $phpFiles = glob($modelDir . '*.php');

        if (!is_array($phpFiles)) {
            return [];
        }

        foreach ($phpFiles as $file) {
            $className = $this->getClassNameFromFile($file);
            if ($className === null) {
                continue;
            }
            if (!class_exists($className)) {
                continue;
            }

            try {
                $reflection = new \ReflectionClass($className);
                if ($reflection->isSubclassOf(\think\Model::class) && !$reflection->isAbstract()) {
                    $classes[] = $className;
                }
            } catch (\Throwable $e) {
                continue;
            }
        }

        return $classes;
    }

    /**
     * 从文件路径推断完全限定类名
     *
     * 策略：
     *   1. 先用 token_get_all 解析文件中的 namespace 声明
     *   2. 回退：基于约定 plugin\{ident}\model\ + 文件名
     *
     * @param string $file 文件绝对路径
     * @return string|null 完全限定类名
     */
    private function getClassNameFromFile(string $file): ?string
    {
        $namespace = $this->extractNamespace($file);
        $className = pathinfo($file, PATHINFO_FILENAME);

        if ($namespace !== null) {
            return $namespace . '\\' . $className;
        }

        $relativePath = str_replace([$this->pluginPath, '.php', '/', '\\'], ['', '', '\\', '\\'], $file);
        $relativePath = ltrim($relativePath, '\\');

        return $this->pluginNamespace . 'model\\' . $className;
    }

    /**
     * 从 PHP 文件中提取 namespace 声明
     *
     * @param string $file PHP 文件路径
     * @return string|null namespace，未找到返回 null
     */
    private function extractNamespace(string $file): ?string
    {
        if (!file_exists($file)) {
            return null;
        }

        $source = file_get_contents($file);
        if ($source === false) {
            return null;
        }

        $tokens = token_get_all($source);
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if (!is_array($tokens[$i])) {
                continue;
            }
            if ($tokens[$i][0] !== T_NAMESPACE) {
                continue;
            }

            $namespace = '';
            for ($j = $i + 1; $j < $count; $j++) {
                if (!is_array($tokens[$j])) {
                    if ($tokens[$j] === ';' || $tokens[$j] === '{') {
                        break;
                    }
                    continue;
                }
                if ($tokens[$j][0] === T_STRING || $tokens[$j][0] === T_NAME_QUALIFIED || $tokens[$j][0] === T_NS_SEPARATOR) {
                    $namespace .= $tokens[$j][1];
                }
            }

            return trim($namespace);
        }

        return null;
    }

    /**
     * 内省单个模型（接受已实例化的模型对象）
     *
     * @param \think\Model $instance 模型实例
     * @param string $className 模型完全限定类名
     * @return TableInfo|null
     */
    private function introspectModelInstance(\think\Model $instance, string $className): ?TableInfo
    {
        $tableName = $instance->getName();
        if (empty($tableName)) {
            return null;
        }

        $fullTableName = $this->dbPrefix . $tableName;

        $tableInfo = new TableInfo();
        $tableInfo->name       = $tableName;
        $tableInfo->modelClass = $className;

        try {
            $tableInfo->primaryKey = $instance->getPk();
            if (empty($tableInfo->primaryKey)) {
                $tableInfo->primaryKey = 'id';
            }
        } catch (\Throwable $e) {
            $tableInfo->primaryKey = 'id';
        }

        if (in_array($tableName, $this->introspectedTables, true)) {
            return null;
        }
        $this->introspectedTables[] = $tableName;

        $tableInfo->fields = $this->extractFields($className, $fullTableName, $tableName);

        $tableInfo->comment = $this->getTableComment($fullTableName);

        $softDeleteResult = $this->detectSoftDelete($instance);
        $tableInfo->hasSoftDelete = $softDeleteResult['has'];
        $tableInfo->deleteTimeField = $softDeleteResult['field'];

        try {
            $tableInfo->hasTimestamps = $instance->getAutoWriteTimestamp();
        } catch (\Throwable $e) {
            $tableInfo->hasTimestamps = true;
        }

        return $tableInfo;
    }

    /**
     * 提取字段信息（多源融合）
     *
     * 数据源优先级：
     *   1. 模型 $schema 属性 → 字段名 + 类型
     *   2. 数据库 Db::getFields() → 完整类型 + 注释 + 主键 + 可空 + 默认值
     *   3. 模型 @property 注释 → 补充字段描述
     *
     * @param string $className 模型类名
     * @param string $fullTableName 完整表名（含前缀）
     * @param string $tableName 表名（不含前缀）
     * @return FieldInfo[]
     */
    private function extractFields(string $className, string $fullTableName, string $tableName): array
    {
        $fields = [];

        $schemaFields = $this->getModelSchema($className);

        try {
            $dbFields = \think\facade\Db::getFields($fullTableName);
        } catch (\Throwable $e) {
            $dbFields = [];
        }

        $propertyComments = $this->extractPropertyComments($className);

        $aiHints = $this->extractAiHints($className);

        $allFieldNames = array_unique(array_merge(
            array_keys($schemaFields),
            is_array($dbFields) ? array_keys($dbFields) : [],
            array_keys($propertyComments)
        ));

        foreach ($allFieldNames as $fieldName) {
            $field = new FieldInfo();
            $field->name = $fieldName;

            if (isset($schemaFields[$fieldName])) {
                $field->type = $this->mapDbType($schemaFields[$fieldName]);
            } elseif (isset($dbFields[$fieldName]['type'])) {
                $field->type = $this->mapDbType($dbFields[$fieldName]['type']);
            }

            if (isset($propertyComments[$fieldName])) {
                $field->comment = $propertyComments[$fieldName];
            } elseif (isset($dbFields[$fieldName]['comment'])) {
                $field->comment = (string) $dbFields[$fieldName]['comment'];
            }

            if (isset($dbFields[$fieldName])) {
                $field->isPrimary       = !empty($dbFields[$fieldName]['primary']);
                $field->isNullable      = empty($dbFields[$fieldName]['notnull']);
                $field->defaultValue    = $dbFields[$fieldName]['default'] ?? null;
                $field->isAutoIncrement = !empty($dbFields[$fieldName]['autoinc']);
                $field->rawType         = $dbFields[$fieldName]['type'] ?? '';

                if (!empty($field->rawType) && preg_match('/\((\d+)\)/', $field->rawType, $matches)) {
                    $field->maxLength = (int) $matches[1];
                }
            }

            if (in_array($fieldName, $aiHints['searchable'], true)) {
                $field->aiSearchable = true;
            }
            if (array_key_exists($fieldName, $aiHints['fillable'])) {
                $field->aiFillable = true;
                $field->aiFillPrompt = (string) ($aiHints['fillable'][$fieldName] ?? '');
            }
            if (isset($aiHints['generated'][$fieldName])) {
                $field->aiGeneratedSkill = (string) $aiHints['generated'][$fieldName];
            }

            $fields[$fieldName] = $field;
        }

        return $fields;
    }

    /**
     * 获取模型的 $schema 属性
     *
     * @param string $className 模型类名
     * @return array [fieldName => type]
     */
    private function getModelSchema(string $className): array
    {
        try {
            $reflection = new \ReflectionClass($className);
            if (!$reflection->hasProperty('schema')) {
                return [];
            }

            $property = $reflection->getProperty('schema');
            $property->setAccessible(true);

            $defaults = $reflection->getDefaultProperties();
            if (isset($defaults['schema']) && is_array($defaults['schema'])) {
                return $defaults['schema'];
            }
        } catch (\Throwable $e) {
        }

        return [];
    }

    /**
     * 映射数据库类型到简化类型
     *
     * @param string $dbType 原始数据库类型
     * @return string 简化类型 int|float|string|text|datetime|date|json
     */
    private function mapDbType(string $dbType): string
    {
        $dbType = strtolower($dbType);

        if (strpos($dbType, 'bigint') !== false) return 'int';
        if (strpos($dbType, 'int') !== false) return 'int';
        if (strpos($dbType, 'float') !== false) return 'float';
        if (strpos($dbType, 'double') !== false) return 'float';
        if (strpos($dbType, 'decimal') !== false) return 'float';
        if (strpos($dbType, 'datetime') !== false) return 'datetime';
        if (strpos($dbType, 'timestamp') !== false) return 'datetime';
        if (strpos($dbType, 'date') !== false) return 'date';
        if (strpos($dbType, 'time') !== false) return 'string';
        if (strpos($dbType, 'text') !== false) return 'text';
        if (strpos($dbType, 'mediumtext') !== false) return 'text';
        if (strpos($dbType, 'longtext') !== false) return 'text';
        if (strpos($dbType, 'json') !== false) return 'json';
        if (strpos($dbType, 'blob') !== false) return 'text';
        if (strpos($dbType, 'enum') !== false) return 'string';
        if (strpos($dbType, 'set') !== false) return 'string';
        if (strpos($dbType, 'tinyint') !== false) return 'int';

        return 'string';
    }

    /**
     * 构建表关系图
     *
     * 反射模型类中的所有 public 方法，
     * 通过返回类型和静态分析检测关系方法（belongsTo/hasMany 等）。
     *
     * @param \think\Model[] $instances 模型实例数组，key 为类名
     * @param TableInfo[] $tables 已内省的表（用于解析目标表名）
     * @return array [tableName => RelationInfo[]]
     */
    private function buildRelationGraphFromInstances(array $instances, array $tables): array
    {
        $relations = [];

        foreach ($instances as $className => $instance) {
            if (!$instance instanceof \think\Model) {
                continue;
            }

            $tableName = $instance->getName();

            if (empty($tableName)) {
                continue;
            }

            try {
                $reflection = new \ReflectionClass($className);
            } catch (\Throwable $e) {
                continue;
            }

            $methods = $reflection->getMethods(\ReflectionMethod::IS_PUBLIC);

            foreach ($methods as $method) {
                $declaringClass = $method->getDeclaringClass()->getName();
                if ($declaringClass !== $className) {
                    continue;
                }

                if (strpos($method->getName(), '__') === 0) {
                    continue;
                }

                if ($this->isRelationMethod($method)) {
                    $relation = $this->extractRelationInfo($method);
                    if ($relation !== null) {
                        $relations[$tableName][] = $relation;
                    }
                }
            }
        }

        return $relations;
    }

    /**
     * 判断方法是否返回 Relation 对象
     *
     * 两层检测：
     *   1. 检查返回类型是否为 \think\model\Relation 的子类
     *   2. 回退：静态分析方法体
     *
     * @param \ReflectionMethod $method
     * @return bool
     */
    private function isRelationMethod(\ReflectionMethod $method): bool
    {
        $returnType = $method->getReturnType();
        if ($returnType instanceof \ReflectionNamedType) {
            $typeName = $returnType->getName();
            if ($typeName === \think\model\Relation::class
                || is_subclass_of($typeName, \think\model\Relation::class)
            ) {
                return true;
            }
        }

        return $this->staticAnalysisDetectRelation($method);
    }

    /**
     * 静态分析：检测方法体中是否包含关系调用
     *
     * 识别模式：$this->belongsTo( / $this->hasMany( / $this->hasOne( / $this->belongsToMany(
     *
     * @param \ReflectionMethod $method
     * @return bool
     */
    private function staticAnalysisDetectRelation(\ReflectionMethod $method): bool
    {
        $source = $this->getMethodSource($method);
        if ($source === null) {
            return false;
        }

        $relationCalls = [
            '$this->belongsTo(',
            '$this->hasOne(',
            '$this->hasMany(',
            '$this->belongsToMany(',
            '$this->morphMany(',
            '$this->morphToMany(',
            '$this->morphByMany(',
        ];

        foreach ($relationCalls as $call) {
            if (strpos($source, $call) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * 提取关系详情
     *
     * 从方法体源码中解析：
     *   - 关系类型（belongsTo/hasMany 等）
     *   - 目标模型类（第一个参数，::class 引用）
     *   - 外键（第二个参数，字符串字面量）
     *   - 本地键（第三个参数）
     *
     * @param \ReflectionMethod $method
     * @return RelationInfo|null
     */
    private function extractRelationInfo(\ReflectionMethod $method): ?RelationInfo
    {
        $source = $this->getMethodSource($method);
        if ($source === null) {
            return null;
        }

        $relation = new RelationInfo();
        $relation->name = $method->getName();

        $typeOrder = ['belongsToMany', 'hasMany', 'belongsTo', 'hasOne', 'morphMany', 'morphToMany', 'morphByMany'];
        $relation->type = '';
        foreach ($typeOrder as $type) {
            if (strpos($source, '$this->' . $type . '(') !== false) {
                $relation->type = $type;
                break;
            }
        }

        if ($relation->type === '') {
            return null;
        }

        if (preg_match('/\$this->' . preg_quote($relation->type, '/') . '\s*\(\s*([a-zA-Z_]\w*(?:\\\\[a-zA-Z_]\w*)*)::class/', $source, $matches)) {
            $targetClass = $matches[1];
            $relation->targetClass = $targetClass;
            if (class_exists($targetClass)) {
                try {
                    $targetInstance = new $targetClass();
                    $relation->targetTable = $targetInstance->getName();
                } catch (\Throwable $e) {
                    $relation->targetTable = '';
                }
            }
        }

        if (preg_match('/\$this->' . preg_quote($relation->type, '/') . '\s*\([^,]+,\s*[\'"](\w+)[\'"]/', $source, $matches)) {
            $relation->foreignKey = $matches[1];
        }

        if (preg_match('/\$this->' . preg_quote($relation->type, '/') . '\s*\([^,]+,\s*[^,]+,\s*[\'"](\w+)[\'"]/', $source, $matches)) {
            $relation->localKey = $matches[1];
        }

        return $relation;
    }

    /**
     * 获取方法体源码
     *
     * @param \ReflectionMethod $method
     * @return string|null 方法体文本，失败返回 null
     */
    private function getMethodSource(\ReflectionMethod $method): ?string
    {
        try {
            $filename  = $method->getFileName();
            $startLine = $method->getStartLine();
            $endLine   = $method->getEndLine();

            if (!$filename || !$startLine || !$endLine) {
                return null;
            }

            if (!isset(self::$fileLineCache[$filename])) {
                $lines = file($filename);
                if ($lines === false) {
                    return null;
                }
                self::$fileLineCache[$filename] = $lines;
            }

            return implode('', array_slice(self::$fileLineCache[$filename], $startLine - 1, $endLine - $startLine + 1));
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * 获取表注释（从 INFORMATION_SCHEMA）
     *
     * @param string $fullTableName 完整表名（含前缀）
     * @return string
     */
    private function getTableComment(string $fullTableName): string
    {
        try {
            $database = \think\facade\Db::getConfig('database');
            $result = \think\facade\Db::query(
                'SELECT TABLE_COMMENT FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = ? AND TABLE_SCHEMA = ?',
                [$fullTableName, $database]
            );

            if (!empty($result) && isset($result[0]['TABLE_COMMENT'])) {
                return (string) $result[0]['TABLE_COMMENT'];
            }
        } catch (\Throwable $e) {
        }

        return '';
    }

    /**
     * 从 @property 注释中提取字段描述
     *
     * 匹配模式：@property type $fieldName description
     * 示例：@property string $name 商品名称 → ['name' => '商品名称']
     *
     * @param string $className 模型类名
     * @return array [fieldName => description]
     */
    private function extractPropertyComments(string $className): array
    {
        try {
            $reflection = new \ReflectionClass($className);
            $docComment = $reflection->getDocComment();
        } catch (\Throwable $e) {
            return [];
        }

        if ($docComment === false || $docComment === '') {
            return [];
        }

        $comments = [];
        if (preg_match_all('/@property\s+(\S+)\s+\$(\w+)\s+(.+)/', $docComment, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $comments[$match[2]] = trim($match[3]);
            }
        }

        return $comments;
    }

    /**
     * 从模型类的 AI 属性注解中提取字段提示
     *
     * 读取类级 PHP Attribute：
     *   - AiSearchable(...)  → 参与自然语言搜索的字段
     *   - AiFillable(...)    → 可被 AI 表单填充的字段（含提示词）
     *   - AiGenerated(...)   → 可由某 Skill 自动生成的字段
     *
     * @param string $className 模型类名
     * @return array{searchable: string[], fillable: array<string, string|null>, generated: array<string, string>}
     */
    private function extractAiHints(string $className): array
    {
        $hints = [
            'searchable' => [],
            'fillable'   => [],
            'generated'  => [],
        ];

        try {
            $reflection = new \ReflectionClass($className);
        } catch (\Throwable $e) {
            return $hints;
        }

        foreach ($reflection->getAttributes() as $attribute) {
            $name = $attribute->getName();

            try {
                $instance = $attribute->newInstance();
            } catch (\Throwable $e) {
                continue;
            }

            if ($name === AiSearchable::class && isset($instance->fields) && is_array($instance->fields)) {
                foreach ($instance->fields as $field) {
                    $hints['searchable'][] = (string) $field;
                }
                continue;
            }

            if ($name === AiFillable::class && isset($instance->fields) && is_array($instance->fields)) {
                foreach ($instance->fields as $field) {
                    $hints['fillable'][(string) $field] = isset($instance->prompt) ? $instance->prompt : null;
                }
                continue;
            }

            if ($name === AiGenerated::class && isset($instance->field)) {
                $hints['generated'][(string) $instance->field] = (string) ($instance->skill ?? '');
                continue;
            }
        }

        return $hints;
    }

    /**
     * 检测模型是否启用软删除
     *
     * 检测策略：
     *   1. 检查 `SoftDelete` trait
     *   2. 检查 `deleteTime` 属性
     *   3. 检查对 `SoftDeleteTrait` 的使用（WiseAdmin 自定义）
     *
     * @param \think\Model $instance
     * @return array ['has' => bool, 'field' => string|null]
     */
    private function detectSoftDelete(\think\Model $instance): array
    {
        $result = ['has' => false, 'field' => null];

        $traits = class_uses($instance);
        if (in_array(\think\model\concern\SoftDelete::class, $traits, true)) {
            $result['has'] = true;
            try {
                $ref = new \ReflectionClass($instance);
                $deleteProp = $ref->getProperty('deleteTime');
                $deleteProp->setAccessible(true);
                $result['field'] = $deleteProp->getValue($instance) ?: 'delete_time';
            } catch (\Throwable $e) {
                $result['field'] = 'delete_time';
            }
            return $result;
        }

        if (in_array('app\common\model\traits\SoftDeleteTrait', $traits, true)) {
            $result['has'] = true;
            $result['field'] = 'delete_time';
            return $result;
        }

        return $result;
    }
}
