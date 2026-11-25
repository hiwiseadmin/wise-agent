<?php
declare(strict_types=1);

namespace wise\agent\menu;

use think\helper\Str;
use wise\form\schema\Form;
use wise\form\schema\renderer\JsonSchemaRenderer;

/**
 * 控制器动作参数 Schema 推导器（A1-1）
 *
 * ## 职责
 * 把一个 `CrudAction` 标准动作推导成**给 LLM 用的 JSON Schema**，并给出一份
 * 「字段目录」（字段名 / 中文标签 / 归一化类型 / 枚举候选 / 是否可筛选可排序）。
 *
 * ## Schema 三级回退链＼�
 * ```
 * 第1级  defineForm() 反射（不触发构造函数）  —— 零副作用、零 DB 查询
 *    ↓ 失败（无 CrudSchemaTrait / defineForm 内部依赖未初始化属性）
 * 第2级  crudSchema() 反射（容器实例化控制器） —— 触发 initialize()，需守卫 app('http')
 *    ↓ 失败（CLI/队列环境 / 类不可实例化）
 * 第3级  DB 表结构（反射 getModel() → Db::name($table)->getFields()） —— 当前仓库的真实主路径
 *    ↓ 失败
 *        空目录（动作工具退化为「参数语义 Schema」，仍可用）
 * ```
 *
 * > **现状说明（已核验 2026-10-16）**：本仓库 12 个真实控制器 `use CrudTrait`
 * > （`app/admin/controller/{Menu,Admin,Permission,LoginLog}`、wise-login-security ×5、
 * > wise-operation-log ×1）但**都没有 `use CrudSchemaTrait`**，因此当前真实路径是**第3级**；
 * > 第1/2级服务于 `php think wise:crud` 生成式控制器（模板见 `extend/wise/crud/stubs/Controller.stub`）。
 * > 三级都要能用，不能只实现一级。
 *
 * ## 可测试性
 * 与容器/数据库交互的三个动作（`instantiate` / `modelTableOf` / `tableFieldsOf`）都是
 * `protected` 接缝，单测通过子类覆写即可覆盖第2/3级，无需真实 app 与 DB；
 * 因此本类**刻意不加 `final`**（与 `MenuActionMeta` 不同，DTO 无需继承）。
 */
class MenuActionSchemaResolver
{
    public const TYPE_STRING   = 'string';
    public const TYPE_INT      = 'integer';
    public const TYPE_NUMBER   = 'number';
    public const TYPE_BOOLEAN  = 'boolean';
    public const TYPE_DATE     = 'date';
    public const TYPE_DATETIME = 'datetime';
    public const TYPE_ENUM     = 'enum';
    public const TYPE_TEXT     = 'text';
    public const TYPE_UPLOAD   = 'upload';

    /** Schema 来源标记 */
    public const SOURCE_DEFINE_FORM = 'defineForm';
    public const SOURCE_CRUD_SCHEMA = 'crudSchema';
    public const SOURCE_DB          = 'db';
    public const SOURCE_NONE        = 'none';

    /**
     * 默认剔除的敏感字段（避免把口令/密钥暴露进 LLM Schema）
     *
     * @var string[]
     */
    private const DEFAULT_EXCLUDED_FIELDS = [
        'password', 'passwd', 'pwd', 'secret', 'token', 'salt',
        'api_key', 'private_key', 'credential', 'remember_token',
    ];

    /**
     * 默认剔除的敏感字段后缀
     *
     * @var string[]
     */
    private const DEFAULT_EXCLUDED_SUFFIXES = [
        '_password', '_passwd', '_secret', '_token', '_hash', '_salt',
        '_api_key', '_private_key', '_credential',
    ];

    /**
     * `CrudAction::sort()` 里硬编码的排序字段白名单（与源码保持同源）
     *
     * @var string[]
     */
    private const DEFAULT_SORT_FIELDS = ['sort', 'weigh', 'order_weight'];

    /** 写动作不应由 LLM 显式提供的自动列 */
    private const AUTO_COLUMNS = ['id', 'create_time', 'update_time', 'delete_time'];

    /** 数值/日期类字段额外支持的比较操作符 */
    private const COMPARE_OPS = ['gt', 'gte', 'lt', 'lte', 'between'];

    /** @var array<string, array> Form::toArray() 缓存 */
    private static array $formCache = [];

    /** @var array<string, array> 字段目录缓存 */
    private static array $fieldCache = [];

    /** @var array<string, string> Schema 来源缓存 */
    private static array $sourceCache = [];

    /** @var array<string, array> 动作 Schema 缓存 */
    private static array $schemaCache = [];

    /** @var array<string, mixed> */
    private array $options;

    /** @var array<int, array{step:string, message:string}> */
    private array $trace = [];

    /**
     * @param array<string, mixed> $options 覆盖配置（便于单测；默认读 `wise-agent.menu_actions.*`）
     */
    public function __construct(array $options = [])
    {
        $this->options = $options;
    }

    /**
     * 清空所有进程内缓存（长驻进程 / 测试用）
     */
    public static function clearCache(): void
    {
        self::$formCache   = [];
        self::$fieldCache  = [];
        self::$sourceCache = [];
        self::$schemaCache = [];
    }


    /**
     * 推导某动作的 JSON Schema
     *
     * @return array JSON Schema（空数组 = 该动作不生成工具）
     */
    public function resolve(string $controllerClass, string $action): array
    {
        $this->trace = [];
        $controller  = ltrim($controllerClass, '\\');
        $cacheKey    = $controller . '::' . $action;

        if (isset(self::$schemaCache[$cacheKey])) {
            return self::$schemaCache[$cacheKey];
        }

        if (!MenuActionMeta::isExposedAction($action)) {
            $this->addTrace('skip', sprintf('动作 %s 不暴露为工具（HTML 动作或未知动作）', $action));

            return self::$schemaCache[$cacheKey] = [];
        }

        $fields = $this->fields($controller);
        $this->addTrace('fields', sprintf(
            '字段 %d 个，来源 %s',
            count($fields),
            $this->schemaSource($controller)
        ));

        $schema = match ($action) {
            'getList'                          => $this->buildGetListSchema($fields),
            'save'                             => $this->buildSaveSchema($controller, $fields),
            'update'                           => $this->buildUpdateSchema($controller, $fields),
            'delete', 'restore', 'forceDelete' => $this->buildIdsSchema($action),
            'status'                           => $this->buildStatusSchema($fields),
            'sort'                             => $this->buildSortSchema($fields),
            default                            => [],
        };

        return self::$schemaCache[$cacheKey] = $schema;
    }

    /**
     * 推导并回填 `MenuActionMeta::parameters`
     */
    public function resolveFor(MenuActionMeta $meta): MenuActionMeta
    {
        if ($meta->fromAnnotation) {
            return $meta;
        }

        return $meta->withParameters($this->resolve($meta->controller, $meta->action));
    }

    /**
     * Form Schema（第1/2级产物）；第3级与失败时返回空数组
     *
     * @return array<string, mixed> `Form::toArray()` 结构
     */
    public function formSchema(string $controllerClass): array
    {
        $controller = ltrim($controllerClass, '\\');
        if (array_key_exists($controller, self::$formCache)) {
            return self::$formCache[$controller];
        }

        $schema = $this->fromDefineForm($controller);

        if ($schema === []) {
            $schema = $this->fromCrudSchema($controller);
        }

        return self::$formCache[$controller] = $schema;
    }

    /**
     * 字段目录（归一化后的可筛选/可排序维度）
     *
     * @return array<string, array<string, mixed>>
     */
    public function fields(string $controllerClass): array
    {
        $controller = ltrim($controllerClass, '\\');
        if (isset(self::$fieldCache[$controller])) {
            return self::$fieldCache[$controller];
        }

        $schema = $this->formSchema($controller);

        if ($schema !== []) {
            $fields = $this->normalizeFormSchema($schema);

            if ($this->opt('merge_db_fields', false)) {
                $fields = array_merge($this->normalizeDbFields($controller), $fields);
            }
        } else {
            $fields = $this->normalizeDbFields($controller);
        }

        return self::$fieldCache[$controller] = $this->stripSensitive($fields);
    }

    /**
     * 字段目录来源：defineForm | crudSchema | db | none
     */
    public function schemaSource(string $controllerClass): string
    {
        $controller = ltrim($controllerClass, '\\');
        if (!isset(self::$sourceCache[$controller])) {
            $this->fields($controller);
        }

        return self::$sourceCache[$controller] ?? self::SOURCE_NONE;
    }

    /**
     * 上一次 `resolve()` 的推导轨迹（审计 / 调试 / 单测断言）
     *
     * @return array<int, array{step:string, message:string}>
     */
    public function trace(): array
    {
        return $this->trace;
    }


    /**
     * 第1级：反射调用 `defineForm()`，**不触发构造函数**
     *
     * `CrudSchemaTrait` / `CrudAction` 都没有构造函数，生成式控制器的
     * `defineForm()` 是纯声明式的，因此可以在未初始化的对象上安全调用。
     */
    private function fromDefineForm(string $controllerClass): array
    {
        if (!class_exists($controllerClass)) {
            $this->addTrace('schema.defineForm', '类不存在，跳过');

            return [];
        }

        try {
            $rc = new \ReflectionClass($controllerClass);
            if ($rc->isAbstract() || !$rc->hasMethod('defineForm')) {
                $this->addTrace('schema.defineForm', '无 defineForm()，跳过');

                return [];
            }

            $method = $rc->getMethod('defineForm');
            $method->setAccessible(true);

            $form = new Form(Str::snake($rc->getShortName()));
            $method->invoke($rc->newInstanceWithoutConstructor(), $form);

            $schema = $form->toArray();
            if ($schema === []) {
                $this->addTrace('schema.defineForm', 'defineForm() 未声明任何字段');

                return [];
            }

            self::$sourceCache[$controllerClass] = self::SOURCE_DEFINE_FORM;
            $this->addTrace('schema.defineForm', sprintf('命中，字段 %d 个', count($schema['fields'] ?? [])));

            return $schema;
        } catch (\Throwable $e) {
            $this->addTrace('schema.defineForm', '失败：' . $e->getMessage());

            return [];
        }
    }

    /**
     * 第2级：容器实例化控制器后反射 `crudSchema()`
     *
     * 代价：会走 `initialize()`（admin 子类含 `assignData()`，依赖 `app('http')`）。
     */
    private function fromCrudSchema(string $controllerClass): array
    {
        if (!class_exists($controllerClass)) {
            return [];
        }

        try {
            $instance = $this->instantiate($controllerClass);
            if ($instance === null || !method_exists($instance, 'crudSchema')) {
                $this->addTrace('schema.crudSchema', '无实例或无 crudSchema()，跳过');

                return [];
            }

            $method = new \ReflectionMethod($instance, 'crudSchema');
            $method->setAccessible(true);
            $schema = $method->invoke($instance);

            if (!is_array($schema) || $schema === []) {
                $this->addTrace('schema.crudSchema', '返回空 Schema');

                return [];
            }

            self::$sourceCache[$controllerClass] = self::SOURCE_CRUD_SCHEMA;
            $this->addTrace('schema.crudSchema', sprintf('命中，字段 %d 个', count($schema['fields'] ?? [])));

            return $schema;
        } catch (\Throwable $e) {
            $this->addTrace('schema.crudSchema', '失败：' . $e->getMessage());

            return [];
        }
    }

    /**
     * 第3级：DB 表结构
     *
     * @return array<string, array<string, mixed>>
     */
    private function normalizeDbFields(string $controllerClass): array
    {
        $instance = $this->instantiate($controllerClass);
        if ($instance === null) {
            $this->addTrace('schema.db', '无法实例化控制器，跳过');

            return [];
        }

        $table = $this->modelTableOf($instance);
        if ($table === null) {
            $this->addTrace('schema.db', '无法解析模型表名，跳过');

            return [];
        }

        $rawFields = $this->tableFieldsOf($table);
        if ($rawFields === []) {
            $this->addTrace('schema.db', sprintf('表 %s 无字段信息', $table));

            return [];
        }

        self::$sourceCache[$controllerClass] ??= self::SOURCE_DB;

        $fields = [];
        foreach ($rawFields as $name => $info) {
            $name = is_string($name) ? $name : (string) ($info['name'] ?? '');
            if ($name === '') {
                continue;
            }

            $info = is_array($info) ? $info : [];
            $raw  = strtolower((string) ($info['type'] ?? 'varchar'));
            $type = $this->mapDbType($raw);

            [$label, $enum, $enumLabels] = $this->parseComment((string) ($info['comment'] ?? ''), $type, $name);

            if ($type === self::TYPE_ENUM && $enum === [] && preg_match_all("/'([^']*)'/", $raw, $m) > 0) {
                $enum       = array_map('strval', $m[1]);
                $enumLabels = $enum;
            }

            $fields[$name] = [
                'name'        => $name,
                'label'       => $label,
                'type'        => $type,
                'multiple'    => false,
                'required'    => !empty($info['notnull']) && !$this->isAutoColumn($name) && !array_key_exists('default', $info),
                'default'     => $info['default'] ?? null,
                'enum'        => $enum,
                'enum_labels' => $enumLabels,
                'help'        => '',
                'nullable'    => empty($info['notnull']),
                'filterable'  => true,
                'sortable'    => true,
                'source'      => 'db',
                'raw_type'    => $raw,
            ];
        }

        $this->addTrace('schema.db', sprintf('命中表 %s，字段 %d 个', $table, count($fields)));

        return $fields;
    }


    /**
     * 通过容器实例化控制器（失败返回 null，不抛异常）
     *
     * 第三个参数强制**新实例**：`Container::make()` 会缓存构造结果，复用实例会让控制器把
     * `$this->request` 钉死在旧请求上（详见 `MenuActionInvoker::makeController()` 的说明）。
     */
    protected function instantiate(string $controllerClass): ?object
    {
        if (!class_exists($controllerClass)) {
            return null;
        }

        try {
            if (function_exists('app')) {
                $instance = app()->make($controllerClass, [], true);
                if (is_object($instance)) {
                    return $instance;
                }
            }
        } catch (\Throwable) {
        }

        try {
            return new $controllerClass();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * 反射 `getModel()` 解析模型表名
     */
    protected function modelTableOf(object $instance): ?string
    {
        if (!method_exists($instance, 'getModel')) {
            return null;
        }

        try {
            $method = new \ReflectionMethod($instance, 'getModel');
            $method->setAccessible(true);
            $model = $method->invoke($instance);

            if (is_object($model) && method_exists($model, 'getTable')) {
                $table = (string) $model->getTable();
                if ($table !== '') {
                    return $table;
                }
            }

            if (is_object($model)) {
                $rc = new \ReflectionClass($model);
                while ($rc && $rc->hasProperty('name')) {
                    $prop = $rc->getProperty('name');
                    $prop->setAccessible(true);
                    $name = (string) $prop->getValue($model);
                    if ($name !== '') {
                        return $name;
                    }
                    $rc = $rc->getParentClass();
                }
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    /**
     * 读取表字段元数据（TP `getFields()` 结构）
     *
     * @return array<string, mixed>
     */
    protected function tableFieldsOf(string $table): array
    {
        if (!function_exists('app') || !class_exists(\think\facade\Db::class)) {
            return [];
        }

        try {
            $fields = \think\facade\Db::name($table)->getFields();

            return is_array($fields) ? $fields : [];
        } catch (\Throwable) {
            return [];
        }
    }


    /**
     * Form Schema → 归一化字段目录
     *
     * @param array<string, mixed> $schema
     * @return array<string, array<string, mixed>>
     */
    private function normalizeFormSchema(array $schema): array
    {
        $fields = [];

        foreach ((array) ($schema['fields'] ?? []) as $key => $def) {
            if (!is_array($def)) {
                continue;
            }

            $name  = (string) ($def['name'] ?? $key);
            if ($name === '') {
                continue;
            }

            $props     = is_array($def['props'] ?? null) ? $def['props'] : [];
            $formType  = (string) ($def['type'] ?? 'text');
            $options   = is_array($props['options'] ?? null) ? $props['options'] : [];
            $multiple  = !empty($props['multiple']) || $formType === 'checkbox';
            $type      = $this->mapFormType($formType, $options);
            $label     = trim((string) ($def['label'] ?? ''));
            $help      = trim((string) ($def['help'] ?? ''));
            $sortable  = !in_array($type, [self::TYPE_UPLOAD, self::TYPE_TEXT], true) && !$multiple;

            $fields[$name] = [
                'name'        => $name,
                'label'       => $label !== '' ? $label : $name,
                'type'        => $type,
                'multiple'    => $multiple,
                'required'    => !empty($def['required']),
                'default'     => $def['default'] ?? null,
                'enum'        => array_map('strval', array_keys($options)),
                'enum_labels' => array_map('strval', array_values($options)),
                'help'        => $help,
                'nullable'    => empty($def['required']),
                'filterable'  => $type !== self::TYPE_UPLOAD,
                'sortable'    => $sortable,
                'source'      => 'form',
                'raw_type'    => $formType,
            ];
        }

        return $fields;
    }

    /**
     * Form 字段类型 → 归一化类型
     *
     * @param array<string, mixed> $options
     */
    private function mapFormType(string $formType, array $options): string
    {
        return match ($formType) {
            'select', 'radio'               => $options !== [] ? self::TYPE_ENUM : self::TYPE_STRING,
            'checkbox'                      => self::TYPE_ENUM,
            'switch'                        => self::TYPE_BOOLEAN,
            'number'                        => self::TYPE_NUMBER,
            'date'                          => self::TYPE_DATE,
            'datetime'                      => self::TYPE_DATETIME,
            'textarea', 'editor', 'rich-text' => self::TYPE_TEXT,
            'image', 'file'                 => self::TYPE_UPLOAD,
            default                         => self::TYPE_STRING,
        };
    }

    /**
     * DB 列类型 → 归一化类型
     */
    private function mapDbType(string $raw): string
    {
        $type = preg_replace('/\(.*$/', '', $raw) ?? $raw;
        $type = trim($type);

        return match ($type) {
            'tinyint'                              => str_contains($raw, '(1)') ? self::TYPE_BOOLEAN : self::TYPE_INT,
            'smallint', 'mediumint', 'int', 'bigint', 'integer' => self::TYPE_INT,
            'decimal', 'numeric', 'float', 'double', 'real'      => self::TYPE_NUMBER,
            'date'                                 => self::TYPE_DATE,
            'datetime', 'timestamp'                => self::TYPE_DATETIME,
            'text', 'tinytext', 'mediumtext', 'longtext', 'json' => self::TYPE_TEXT,
            'enum', 'set'                          => self::TYPE_ENUM,
            default                                => self::TYPE_STRING,
        };
    }

    /**
     * 解析列注释：`状态 1=启用,0=禁用` → label + 枚举候选
     *
     * @return array{0: string, 1: string[], 2: string[]} [label, enumValues, enumLabels]
     */
    private function parseComment(string $comment, string $type, string $fieldName): array
    {
        $comment = trim($comment);
        $label   = $comment !== '' ? $comment : $fieldName;
        $enum    = [];
        $labels  = [];

        if (preg_match_all('/(-?\d+)\s*[=:：]\s*([^\s,，;；|]+)/u', $comment, $matches, PREG_SET_ORDER) > 0) {
            foreach ($matches as $match) {
                $value = (string) $match[1];
                if (in_array($value, $enum, true)) {
                    continue;
                }
                $enum[]   = $value;
                $labels[] = (string) $match[2];
            }

            $stripped = trim((string) preg_replace('/-?\d+\s*[=:：]\s*[^\s,，;；|]+/u', '', $comment), " \t\n\r,，;；|");
            if ($stripped !== '') {
                $label = $stripped;
            }
        }

        return [$label, $enum, $labels];
    }

    /**
     * 剔除敏感字段
     *
     * @param array<string, array<string, mixed>> $fields
     * @return array<string, array<string, mixed>>
     */
    private function stripSensitive(array $fields): array
    {
        $excluded = (array) $this->opt('excluded_fields', self::DEFAULT_EXCLUDED_FIELDS);
        $suffixes = (array) $this->opt('excluded_suffixes', self::DEFAULT_EXCLUDED_SUFFIXES);

        foreach (array_keys($fields) as $name) {
            $lower = strtolower((string) $name);

            if (in_array($lower, array_map('strtolower', $excluded), true)) {
                $this->addTrace('fields.sensitive', '剔除 ' . $name);
                unset($fields[$name]);
                continue;
            }

            foreach ($suffixes as $suffix) {
                if ($suffix !== '' && str_ends_with($lower, strtolower((string) $suffix))) {
                    $this->addTrace('fields.sensitive', '剔除 ' . $name);
                    unset($fields[$name]);
                    break;
                }
            }
        }

        return $fields;
    }

    private function isAutoColumn(string $name): bool
    {
        return in_array($name, self::AUTO_COLUMNS, true);
    }


    /**
     * getList：查询参数骨架 + 字段级筛选＼�
     *
     * @param array<string, array<string, mixed>> $fields
     */
    private function buildGetListSchema(array $fields): array
    {
        $maxLimit     = max(1, (int) $this->opt('max_limit', 100));
        $defaultLimit = min(max(1, (int) $this->opt('default_limit', 20)), $maxLimit);

        $filterable = array_filter($fields, static fn(array $f): bool => !empty($f['filterable']));
        $sortable   = [];
        foreach ($fields as $name => $field) {
            if (!empty($field['sortable'])) {
                $sortable[] = (string) $name;
            }
        }

        $properties = [
            'page'  => [
                'type'        => 'integer',
                'default'     => 1,
                'description' => '页码，从 1 开始',
            ],
            'limit' => [
                'type'        => 'integer',
                'default'     => $defaultLimit,
                'maximum'     => $maxLimit,
                'description' => sprintf('每页条数，最大 %d', $maxLimit),
            ],
            'order' => [
                'type'        => 'string',
                'description' => '排序表达式，如 `create_time desc`；多字段用英文逗号分隔'
                    . ($sortable !== [] ? '；可排序字段：' . implode('、', array_slice($sortable, 0, 30)) : ''),
            ],
            'field' => [
                'type'        => 'string',
                'description' => '字段投影（逗号分隔）；不填返回全部可读字段'
                    . ($fields !== [] ? '；可用字段：' . implode('、', array_slice(array_keys($fields), 0, 30)) : ''),
            ],
        ];

        $maxFilterFields = max(1, (int) $this->opt('max_filter_fields', 20));
        $maxProperties   = max(8, (int) $this->opt('max_filter_properties', 80));
        $truncated       = false;

        if ($filterable !== [] && count($filterable) <= $maxFilterFields) {
            foreach ($filterable as $name => $field) {
                foreach ($this->filterOperators($field) as $op) {
                    if (count($properties) + 1 >= $maxProperties) {
                        $truncated = true;
                        break 2;
                    }

                    $properties[$op === 'eq' ? (string) $name : $name . '__' . $op]
                        = $this->filterProperty((string) $name, $op, $field);
                }
            }
        } else {
            $truncated = true;
        }

        if ($truncated) {
            $properties['filters'] = $this->genericFiltersProperty(array_keys($filterable));
            $this->addTrace('getList.generic', '字段过多或超出属性上限 → 退化为通用 filters');
        }

        $schema = ['type' => 'object', 'properties' => $properties];
        $this->addTrace('schema.getList', sprintf('属性 %d 个', count($properties)));

        return $schema;
    }

    /**
     * @param array<string, array<string, mixed>> $fields
     */
    private function buildSaveSchema(string $controller, array $fields): array
    {
        $properties = $this->writeProperties($controller, $fields, ['id', 'ids']);
        $required   = $this->requiredFields($fields, $properties);

        $schema = ['type' => 'object', 'properties' => $properties];
        if ($required !== []) {
            $schema['required'] = $required;
        }

        $this->addTrace('schema.save', sprintf('属性 %d 个', count($properties)));

        return $schema;
    }

    /**
     * update：表单字段 + id/ids（至少提供一个）
     *
     * @param array<string, array<string, mixed>> $fields
     */
    private function buildUpdateSchema(string $controller, array $fields): array
    {
        $properties = $this->writeProperties($controller, $fields, ['id', 'ids']);
        $required   = $this->requiredFields($fields, $properties);

        $properties = array_merge($this->idProperties(), $this->idsProperty(), $properties);

        $schema = [
            'type'        => 'object',
            'description' => 'id 与 ids 至少提供一个：单条传 id，批量传 ids',
            'properties'  => $properties,
        ];
        if ($required !== []) {
            $schema['required'] = $required;
        }

        $this->addTrace('schema.update', sprintf('属性 %d 个', count($properties)));

        return $schema;
    }

    /**
     * delete / restore / forceDelete：仅 id/ids
     */
    private function buildIdsSchema(string $action): array
    {
        return [
            'type'        => 'object',
            'description' => 'id 与 ids 至少提供一个：单条传 id，批量传 ids',
            'properties'  => array_merge($this->idProperties(), $this->idsProperty()),
        ];
    }

    /**
     * status：id/ids + status（必填，枚举来自字段定义）
     *
     * @param array<string, array<string, mixed>> $fields
     */
    private function buildStatusSchema(array $fields): array
    {
        $statusField = $fields['status'] ?? null;
        $label       = is_array($statusField) ? (string) ($statusField['label'] ?? '状态') : '状态';

        $statusProperty = [
            'type'        => 'integer',
            'description' => sprintf('%s（必须 ≥ 0）', $label),
        ];

        if (is_array($statusField) && !empty($statusField['enum'])) {
            $statusProperty['enum']        = array_map('intval', $statusField['enum']);
            $statusProperty['description'] .= '；可选值：' . $this->enumHint($statusField);
        }

        return [
            'type'        => 'object',
            'description' => 'id 与 ids 至少提供一个：单条传 id，批量传 ids',
            'properties'  => array_merge($this->idProperties(), $this->idsProperty(), [
                'status' => $statusProperty,
            ]),
            'required'    => ['status'],
        ];
    }

    /**
     * sort：id/ids + sort + sort_field（白名单枚举）
     *
     * @param array<string, array<string, mixed>> $fields
     */
    private function buildSortSchema(array $fields): array
    {
        unset($fields);

        $sortFields = array_values(array_filter(
            (array) $this->opt('sort_fields', self::DEFAULT_SORT_FIELDS),
            static fn($v): bool => is_string($v) && $v !== ''
        ));

        return [
            'type'        => 'object',
            'description' => 'id 与 ids 至少提供一个：单条传 id，批量传 ids',
            'properties'  => array_merge($this->idProperties(), $this->idsProperty(), [
                'sort'       => [
                    'type'        => 'integer',
                    'description' => '排序值（必须 ≥ 0）',
                ],
                'sort_field' => [
                    'type'        => 'string',
                    'enum'        => $sortFields,
                    'default'     => $sortFields[0] ?? 'sort',
                    'description' => '排序字段，仅允许白名单值',
                ],
            ]),
            'required'    => ['sort'],
        ];
    }

    /**
     * 写类动作的字段属性：优先复用 `JsonSchemaRenderer`（Level 1/2），否则由归一化字段构建
     *
     * @param array<string, array<string, mixed>> $fields
     * @param string[]                            $exclude
     * @return array<string, array<string, mixed>>
     */
    private function writeProperties(string $controller, array $fields, array $exclude = []): array
    {
        $schema = self::$formCache[$controller] ?? [];

        if (is_array($schema) && $schema !== []) {
            try {
                $json    = (new JsonSchemaRenderer())->render($schema, ['title' => $schema['form'] ?? '']);
                $decoded = json_decode($json, true);
                $props   = is_array($decoded['properties'] ?? null) ? $decoded['properties'] : [];
            } catch (\Throwable) {
                $props = [];
            }
        } else {
            $props = [];
        }

        if ($props === []) {
            foreach ($fields as $name => $field) {
                $props[$name] = $this->fieldProperty($field);
            }
        }

        foreach (array_keys($props) as $name) {
            if (!isset($fields[$name])) {
                unset($props[$name]);
                continue;
            }

            if (($fields[$name]['source'] ?? '') === 'db' && $this->isAutoColumn((string) $name)) {
                unset($props[$name]);
            }
        }

        foreach ($exclude as $name) {
            unset($props[$name]);
        }

        foreach ($props as $name => &$property) {
            if (!is_array($property)) {
                unset($props[$name]);
                continue;
            }

            $field = $fields[$name] ?? null;
            if (!is_array($field)) {
                continue;
            }

            if (isset($property['enum']) && is_array($property['enum'])) {
                $property['enum'] = array_map('strval', $property['enum']);
            }
            if (isset($property['items']['enum']) && is_array($property['items']['enum'])) {
                $property['items']['enum'] = array_map('strval', $property['items']['enum']);
            }

            $property['description'] = $this->fieldDescription($field);
        }
        unset($property);

        return $props;
    }

    /**
     * 单字段 → JSON Schema 属性（DB 路径）
     *
     * @param array<string, mixed> $field
     * @return array<string, mixed>
     */
    private function fieldProperty(array $field): array
    {
        $type = (string) ($field['type'] ?? self::TYPE_STRING);

        $property = match ($type) {
            self::TYPE_INT      => ['type' => 'integer'],
            self::TYPE_NUMBER   => ['type' => 'number'],
            self::TYPE_BOOLEAN  => ['type' => 'boolean'],
            self::TYPE_UPLOAD   => ['type' => 'string'],
            default             => ['type' => 'string'],
        };

        if ($type === self::TYPE_ENUM && !empty($field['enum'])) {
            $property['enum'] = array_map('strval', (array) $field['enum']);
        }

        if (!empty($field['multiple'])) {
            $items = ['type' => 'string'];
            if (!empty($field['enum'])) {
                $items['enum'] = array_map('strval', (array) $field['enum']);
            }
            $property = ['type' => 'array', 'items' => $items];
        }

        $default = $field['default'] ?? null;
        if ($default !== null && $default !== '' && !is_array($default)) {
            $property['default'] = $default;
        }

        $property['description'] = $this->fieldDescription($field);

        return $property;
    }

    /**
     * getList 单个字段的属性
     *
     * @param array<string, mixed> $field
     * @return array<string, mixed>
     */
    private function filterProperty(string $name, string $op, array $field): array
    {
        $label = (string) ($field['label'] ?? $name);
        $enum  = !empty($field['enum']) ? array_map('strval', (array) $field['enum']) : [];

        if ($op === 'eq') {
            $property = ['type' => 'string', 'description' => sprintf('按「%s」精确匹配', $label)];
            if ($enum !== []) {
                $property['enum']        = $enum;
                $property['description'] .= '；可选值：' . $this->enumHint($field);
            }

            return $property;
        }

        $hint = match ($op) {
            'ne'              => '不等于',
            'gt'              => '大于',
            'gte'             => '大于等于',
            'lt'              => '小于',
            'lte'             => '小于等于',
            'between'         => '区间（两个值用英文逗号分隔：v1,v2）',
            'like'            => '模糊匹配（自动前后加 %，无需自行添加）',
            'notlike'         => '不模糊匹配',
            'notbetween'      => '不在区间内（两个值用英文逗号分隔）',
            default           => $op,
        };

        $property = [
            'type'        => 'string',
            'description' => sprintf('「%s」%s', $label, $hint),
        ];

        if (in_array($op, ['in', 'notin'], true)) {
            $items    = ['type' => 'string'];
            $property = ['type' => 'array', 'items' => $items, 'description' => sprintf('「%s」%s', $label, $op === 'in' ? '在列表中' : '不在列表中')];
            if ($enum !== []) {
                $property['items']['enum']  = $enum;
                $property['description']   .= '；可选值：' . $this->enumHint($field);
            }
        }

        return $property;
    }

    /**
     * 通用筛选逃生通道（字段过多时使用）
     *
     * 刻意用**数组**而不是原始 query string，避免引入二次解析面。
     *
     * @param string[] $fieldNames
     * @return array<string, mixed>
     */
    private function genericFiltersProperty(array $fieldNames): array
    {
        return [
            'type'        => 'array',
            'items'       => ['type' => 'string'],
            'description' => '筛选项，格式 `字段__操作符=值`（无操作符等价 eq），例如 '
                . '["status__eq=1","amount__gte=1000","create_time__between=2026-09-01 00:00:00,2026-09-30 23:59:59"]'
                . ($fieldNames !== []
                    ? '；可用字段：' . implode('、', array_slice($fieldNames, 0, 30))
                    : ''),
        ];
    }

    /**
     * 字段可用操作符（`eq` 永远在首位；不含 `exists` —— 见附录三现状事实 2）
     *
     * @param array<string, mixed> $field
     * @return string[]
     */
    private function filterOperators(array $field): array
    {
        $type = (string) ($field['type'] ?? self::TYPE_STRING);

        $ops = ['eq'];

        if (in_array($type, [self::TYPE_INT, self::TYPE_NUMBER, self::TYPE_DATE, self::TYPE_DATETIME], true)) {
            $ops = array_merge($ops, self::COMPARE_OPS);
        } elseif (in_array($type, [self::TYPE_TEXT, self::TYPE_STRING], true)) {
            $ops[] = 'like';
        } elseif ($type === self::TYPE_ENUM && empty($field['multiple'])) {
        }

        $ops[] = 'in';
        $ops[] = 'notin';

        return array_values(array_unique($ops));
    }

    /**
     * id 参数（单条）
     *
     * @return array<string, array<string, mixed>>
     */
    private function idProperties(): array
    {
        return [
            'id' => [
                'type'        => 'integer',
                'description' => '单条记录主键（与 ids 至少提供一个）',
            ],
        ];
    }

    /**
     * ids 参数（批量）
     *
     * @return array<string, array<string, mixed>>
     */
    private function idsProperty(): array
    {
        return [
            'ids' => [
                'type'        => 'array',
                'items'       => ['type' => 'integer'],
                'description' => '批量操作的主键列表（与 id 至少提供一个）',
            ],
        ];
    }

    /**
     * required 列表（只保留确实存在于 properties 的字段）
     *
     * @param array<string, array<string, mixed>> $fields
     * @param array<string, mixed>                $properties
     * @return string[]
     */
    private function requiredFields(array $fields, array $properties): array
    {
        $required = [];

        foreach ($fields as $name => $field) {
            if (!empty($field['required']) && isset($properties[$name])) {
                $required[] = (string) $name;
            }
        }

        return $required;
    }

    /**
     * 字段的中文描述（label + help + 类型 + 枚举候选）
     *
     * @param array<string, mixed> $field
     */
    private function fieldDescription(array $field): string
    {
        $label = (string) ($field['label'] ?? $field['name'] ?? '');
        $parts = [$label];

        if (!empty($field['help'])) {
            $parts[] = (string) $field['help'];
        }

        if (!empty($field['enum']) && ($field['type'] ?? '') !== self::TYPE_ENUM) {
            $parts[] = '可选值：' . $this->enumHint($field);
        }

        if (($field['type'] ?? '') === self::TYPE_UPLOAD) {
            $parts[] = '（文件/图片，传已上传的 URL 或路径）';
        }

        return implode('；', array_filter($parts));
    }

    /**
     * 枚举候选的展示串：`启用(1)/禁用(0)`
     *
     * @param array<string, mixed> $field
     */
    private function enumHint(array $field): string
    {
        $enum   = array_map('strval', (array) ($field['enum'] ?? []));
        $labels = array_map('strval', (array) ($field['enum_labels'] ?? []));

        $hints = [];
        foreach ($enum as $index => $value) {
            $label   = $labels[$index] ?? '';
            $hints[] = $label !== '' && $label !== $value ? sprintf('%s(%s)', $label, $value) : $value;
        }

        return implode('/', array_slice($hints, 0, 20));
    }


    /**
     * 读取选项（未显式传入时回退到 wise-agent 配置）
     */
    private function opt(string $key, mixed $default): mixed
    {
        if (array_key_exists($key, $this->options)) {
            return $this->options[$key];
        }

        if (!function_exists('config')) {
            return $default;
        }

        try {
            return config('wise-agent.menu_actions.' . $key, $default) ?? $default;
        } catch (\Throwable) {
            return $default;
        }
    }

    private function addTrace(string $step, string $message): void
    {
        $this->trace[] = ['step' => $step, 'message' => $message];
    }
}
