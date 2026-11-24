<?php
declare(strict_types=1);

namespace wise\agent\form;

use think\helper\Str;
use wise\agent\menu\MenuActionIntrospector;
use wise\agent\menu\MenuActionMeta;
use wise\agent\menu\MenuActionSchemaResolver;
use wise\agent\schema\security\SecurityGuard;
use wise\form\schema\Form;

/**
 * 表单 Schema 内省器（A2-1）
 *
 * 把 `CrudSchemaTrait::defineForm()` 的声明变成 {@see FormSchema} 目录＼�。
 *
 * ## 为什么不用 `crudSchema()`
 *
 * `CrudSchemaTrait::crudSchema()` 是 `protected`，且 `crudSchemaName()` 依赖
 * `$this->request->controller()`（`app/common/controller/traits/CrudSchemaTrait.php:75-78`）。
 * A2 用 `newInstanceWithoutConstructor()` 反射实例化控制器（**不触发构造函数副作用**），
 * 此时 `$this->request` 为 null，直接调 `crudSchema()` 会致命错误。因此这里自己
 * `new Form($name)` + 调用 `defineForm()` —— 与 `crudSchema()` 等价，且拿得到 **Form 对象**
 * （`getFields()` 提供字段类名，`toArray()` 提供元数据）。
 *
 * ## 三级回退（与 A1-1 同构，但 A2 保留更多元数据）
 *
 * ① 反射 `defineForm()`（首选，**完整 24 类元数据**）→ ② A1-1 的 `fields()`
 * （`crudSchema` / DB 兜底，只有 13 个键）→ ③ 空目录 + `source=none`。
 * 每次回退都在 `notes` 里留痕，并且 `source` 会如实标注——**不把「表结构推断」当「表单声明」**。
 *
 * ## 可测试性
 * `newController` / `invokeDefineForm` / `fallbackFields` / `modelOf` / `tableNameOf`
 * 都是 `protected` 接缝，单测可注入；因此本类**不加 `final`**。
 */
class FormSchemaIntrospector
{
    /** 进程内缓存（键 = 控制器 + 关键选项指纹） */
    private static array $cache = [];

    /** 目标注册表缓存：target 名 → 控制器 FQCN */
    private static ?array $targetCache = null;

    private MenuActionSchemaResolver $fallback;

    /** @var array<int, array{step:string, message:string}> */
    private array $trace = [];

    /**
     * @param array<string, mixed> $options `form_schema.*` 覆盖项
     */
    public function __construct(
        private array $options = [],
        ?MenuActionSchemaResolver $fallback = null
    ) {
        $this->fallback = $fallback ?? new MenuActionSchemaResolver($options);
    }

    /**
     * 清空进程内缓存（单测 / 长驻进程）
     */
    public static function resetCache(): void
    {
        self::$cache       = [];
        self::$targetCache = null;
    }

    /**
     * 可解释的表单目标（`target 名 => 控制器 FQCN`）
     *
     * 两个来源，配置优先、A1 内省兜底：
     * 1. `form_schema.targets` 显式声明（键 = 目标名，值 = 控制器 FQCN 或 `['controller' => …]`）；
     * 2. **A1 内省结果中有 `save` / `update` 动作的控制器**——「能写」才「有表单」，
     *    因此表单目标天然是 A1 动作目标的子集，目标名也天然一致（`MenuActionMeta::targetNameOf()`）。
     *
     * @return array<string, string>
     */
    public function targets(bool $force = false): array
    {
        if (self::$targetCache !== null && !$force) {
            return self::$targetCache;
        }

        $map = [];

        $declared = $this->opt('targets', []);
        if (is_array($declared)) {
            foreach ($declared as $name => $definition) {
                $controller = is_array($definition)
                    ? (string) ($definition['controller'] ?? '')
                    : (string) $definition;

                if ($controller !== '') {
                    $map[(string) $name] = ltrim($controller, '\\');
                }
            }
        }

        try {
            foreach ($this->metaIntrospector()->resolve(false) as $meta) {
                if (!$meta instanceof MenuActionMeta) {
                    continue;
                }

                if (!in_array($meta->action, ['save', 'update'], true)) {
                    continue;
                }

                $name = MenuActionMeta::targetNameOf($meta->controller);

                if ($name !== '' && !isset($map[$name])) {
                    $map[$name] = $meta->controller;
                }
            }
        } catch (\Throwable $e) {
            $this->addTrace('targets.introspectFailed', $e->getMessage());
        }

        return self::$targetCache = $map;
    }

    /**
     * @return string[]
     */
    public function availableTargets(): array
    {
        return array_keys($this->targets());
    }

    /**
     * 目标名 → 控制器 FQCN（未知目标返回 null）
     */
    public function resolveTarget(string $target): ?string
    {
        return $this->targets()[$target] ?? null;
    }

    /**
     * 是否是可解释的表单目标
     */
    public function supports(string $target): bool
    {
        return $this->resolveTarget($target) !== null;
    }

    /**
     * @return array<int, array{step:string, message:string}>
     */
    public function trace(): array
    {
        return $this->trace;
    }

    /**
     * 目标名（与 A1/A3 同一命名约定：`{ident}.{snake(controller)}`）
     */
    public function targetOf(string $controllerClass): string
    {
        return MenuActionMeta::targetNameOf($controllerClass);
    }

    /**
     * 内省一个控制器的表单声明
     *
     * @param array<string, mixed> $options 覆盖构造时的选项
     */
    public function forController(string $controllerClass, array $options = []): FormSchema
    {
        $controller = ltrim($controllerClass, '\\');
        $merged     = array_merge($this->options, $options);
        $cacheKey   = $controller . '|' . md5(json_encode($merged) ?: '');

        if (isset(self::$cache[$cacheKey])) {
            return self::$cache[$cacheKey];
        }

        $target   = $this->targetOf($controller);
        $instance = $this->newController($controller);

        $allowWrite = $this->allowWriteFieldsOf($controller);

        if ($instance !== null) {
            try {
                $schema = $this->fromDefineForm($instance, $controller, $target, $allowWrite, $merged);

                if ($schema !== null) {
                    return self::$cache[$cacheKey] = $schema;
                }
            } catch (\Throwable $e) {
                $failure = get_class($e) . ': ' . $e->getMessage();
            }
        }

        $schema = $this->fromFallback($controller, $target, $allowWrite, $merged);

        if (isset($failure)) {
            $schema->addNote('defineForm.failed', 'defineForm() 执行失败，已回退到精简目录：' . $failure);
        }

        return self::$cache[$cacheKey] = $schema;
    }


    /**
     * @param array<string, mixed> $options
     */
    protected function fromDefineForm(
        object $controller,
        string $controllerClass,
        string $target,
        ?array $allowWrite,
        array $options
    ): ?FormSchema {
        $form = new Form($this->schemaNameOf($controllerClass));

        try {
            $this->invokeDefineForm($controller, $form);
        } catch (\Throwable $e) {
            return null;
        }

        $schemaArray = $form->toArray();
        if (!is_array($schemaArray) || (array) ($schemaArray['fields'] ?? []) === []) {
            return null;
        }

        $objects = [];
        foreach ($form->getFields() as $name => $field) {
            $objects[(string) $name] = $field;
        }

        $fields = [];
        foreach ((array) $schemaArray['fields'] as $name => $def) {
            if (!is_array($def)) {
                continue;
            }

            $key   = (string) ($def['name'] ?? $name);
            $type  = (string) ($def['type'] ?? 'text');
            $props = (array) ($def['props'] ?? []);

            [$writable, $readable, $deny] = $this->decideWritability($key, $type, $allowWrite, $options);

            $fields[$key] = new FormFieldSchema(
                key: $key,
                label: (string) ($def['label'] ?? ''),
                type: $type,
                input: isset($objects[$key]) ? (new \ReflectionClass($objects[$key]))->getShortName() : '',
                required: (bool) ($def['required'] ?? false),
                default: $def['default'] ?? null,
                help: (string) ($def['help'] ?? ''),
                placeholder: (string) ($def['placeholder'] ?? ''),
                options: $this->normalizeOptions($props['options'] ?? []),
                multiple: (bool) ($props['multiple'] ?? false),
                min: $props['min'] ?? null,
                max: $props['max'] ?? null,
                step: $props['step'] ?? null,
                range: (bool) ($props['range'] ?? false),
                format: (string) ($props['format'] ?? ''),
                rules: (array) ($def['rules'] ?? []),
                group: isset($def['group']) && $def['group'] !== null ? (string) $def['group'] : null,
                dependencies: $this->normalizeList($def['dependencies'] ?? []),
                conditionalRules: $this->normalizeList($def['conditional_rules'] ?? []),
                renderable: $def['renderable'] ?? true,
                writable: $writable,
                readable: $readable,
                denyReason: $deny,
                source: FormSchema::SOURCE_DEFINE_FORM,
            );
        }

        $schema = new FormSchema(
            target: $target,
            controller: $controllerClass,
            formName: (string) (($schemaArray['form']['name'] ?? '') ?: $this->schemaNameOf($controllerClass)),
            source: FormSchema::SOURCE_DEFINE_FORM,
            fields: $fields,
            groups: $this->normalizeList($schemaArray['groups'] ?? []),
            repeaters: $this->normalizeList($schemaArray['repeaters'] ?? []),
            rawSchema: $schemaArray,
        );

        $denied = $schema->deniedKeys();
        if ($denied !== []) {
            $schema->addNote(
                'fields.denied',
                sprintf('以下字段不可由 AI 填充：%s', implode(', ', array_map(
                    static fn(string $k, string $reason): string => $k . '(' . $reason . ')',
                    array_keys($denied),
                    array_values($denied)
                )))
            );
        }

        return $schema;
    }


    /**
     * @param array<string, mixed> $options
     */
    protected function fromFallback(string $controllerClass, string $target, ?array $allowWrite, array $options): FormSchema
    {
        $fields  = [];
        $source  = FormSchema::SOURCE_NONE;
        $notes   = [];

        try {
            $raw = $this->fallbackFields($controllerClass);
        } catch (\Throwable $e) {
            $raw = [];
        }

        if ($raw !== []) {
            $isDb = true;
            foreach ($raw as $def) {
                if (!is_array($def)) {
                    continue;
                }

                if (($def['source'] ?? '') !== 'db') {
                    $isDb = false;
                    break;
                }
            }

            foreach ($raw as $name => $def) {
                if (!is_array($def)) {
                    continue;
                }

                $key  = (string) ($def['name'] ?? $name);
                $type = $this->mapFallbackType((string) ($def['type'] ?? 'string'));

                [$writable, $readable, $deny] = $this->decideWritability($key, $type, $allowWrite, $options);

                $fields[$key] = new FormFieldSchema(
                    key: $key,
                    label: (string) ($def['label'] ?? ''),
                    type: $type,
                    input: '',
                    required: (bool) ($def['required'] ?? false),
                    default: $def['default'] ?? null,
                    help: (string) ($def['help'] ?? ''),
                    options: $this->optionsFromFallback($def),
                    multiple: (bool) ($def['multiple'] ?? false),
                    writable: $writable,
                    readable: $readable,
                    denyReason: $deny,
                    source: $isDb ? FormSchema::SOURCE_DB : FormSchema::SOURCE_CRUD_SCHEMA,
                );
            }

            $source = $isDb ? FormSchema::SOURCE_DB : FormSchema::SOURCE_CRUD_SCHEMA;

            $notes[] = [
                'code'    => 'source.fallback',
                'message' => $isDb
                    ? '该控制器未反射到 defineForm / crudSchema，以下字段为**表结构推断**（不是表单声明），字段语义（分组/联动/条件必填/校验规则）不可用。'
                    : '字段元数据来自框架的 CRUD Schema 精简目录，缺少分组/联动/条件必填/自定义校验等语义。',
            ];
        } else {
            $notes[] = [
                'code'    => 'source.none',
                'message' => '该控制器既未声明 defineForm，也没有可用的 CRUD Schema 或表结构，无法解释表单。',
            ];
        }

        $schema = new FormSchema(
            target: $target,
            controller: $controllerClass,
            source: $source,
            fields: $fields,
        );

        foreach ($notes as $note) {
            $schema->addNote((string) $note['code'], (string) $note['message']);
        }

        return $schema;
    }


    /**
     * @param array<string, mixed> $options
     * @return array{0:bool, 1:bool, 2:string} [writable, readable, denyReason]
     */
    protected function decideWritability(string $key, string $type, ?array $allowWrite, array $options): array
    {
        if ((bool) ($options['exclude_sensitive'] ?? true) && $this->isSensitiveField($key)) {
            return [false, false, FormFieldSchema::DENY_SENSITIVE];
        }

        if ((bool) ($options['exclude_system'] ?? true) && $this->isSystemField($key)) {
            return [false, true, FormFieldSchema::DENY_SYSTEM];
        }

        if ($type === 'hidden' && !(bool) ($options['fill_hidden'] ?? false)) {
            return [false, true, FormFieldSchema::DENY_HIDDEN];
        }

        if (!(bool) ($options['fill_owner_fields'] ?? false) && $this->isOwnerField($key)) {
            return [false, true, FormFieldSchema::DENY_OWNER];
        }

        if (is_array($allowWrite) && !in_array($key, $allowWrite, true)) {
            return [false, true, FormFieldSchema::DENY_WHITELIST];
        }

        return [true, true, ''];
    }

    /**
     * 敏感字段（单一实现：{@see FormFieldSchema::isSensitive()}）
     */
    protected function isSensitiveField(string $field): bool
    {
        return FormFieldSchema::isSensitive($field);
    }

    protected function isSystemField(string $field): bool
    {
        return in_array($field, SecurityGuard::SYSTEM_FIELDS, true);
    }

    /**
     * 归属类字段（`admin_id` / `user_id` / `created_by` …）
     *
     * 默认不填：这类字段由系统或人工维护，AI 填它等于**代替人工指定数据归属**
     * ＼�。需要时用 `fill_owner_fields=true` 放开。
     */
    protected function isOwnerField(string $field): bool
    {
        return in_array($field, SecurityGuard::OWNER_KEY_CANDIDATES, true);
    }


    /**
     * 反射实例化控制器（**不触发构造函数**，避免副作用与容器依赖）
     */
    protected function newController(string $controllerClass): ?object
    {
        if (!class_exists($controllerClass)) {
            return null;
        }

        try {
            $reflection = new \ReflectionClass($controllerClass);
            if (!$reflection->hasMethod('defineForm')) {
                return null;
            }

            return $reflection->newInstanceWithoutConstructor();
        } catch (\Throwable) {
            return null;
        }
    }

    protected function invokeDefineForm(object $controller, Form $form): void
    {
        $method = new \ReflectionMethod($controller, 'defineForm');
        $method->setAccessible(true);
        $method->invoke($controller, $form);
    }

    /**
     * 回退到 A1-1 的字段目录（`defineForm` → `crudSchema` → DB 三级）
     *
     * @return array<string, array<string, mixed>>
     */
    protected function fallbackFields(string $controllerClass): array
    {
        return $this->fallback->fields($controllerClass);
    }

    /**
     * A1 的动作内省器（用于推导「哪些控制器有表单」）
     *
     * `protected` 接缝：单测可注入，避免离线环境下依赖菜单数据。
     */
    protected function metaIntrospector(): MenuActionIntrospector
    {
        return new MenuActionIntrospector($this->options);
    }

    private function addTrace(string $step, string $message): void
    {
        $this->trace[] = ['step' => $step, 'message' => $message];
    }

    private function opt(string $key, mixed $default): mixed
    {
        if (array_key_exists($key, $this->options)) {
            return $this->options[$key];
        }

        if (!function_exists('config')) {
            return $default;
        }

        try {
            return config('wise-agent.form_schema.' . $key, $default) ?? $default;
        } catch (\Throwable) {
            return $default;
        }
    }

    /**
     * 模型实例（用于读取「真实写白名单」`BaseModel::$allowWriteField`）
     */
    protected function modelOf(string $controllerClass): ?object
    {
        if (!class_exists($controllerClass)) {
            return null;
        }

        try {
            $controller = (new \ReflectionClass($controllerClass))->newInstanceWithoutConstructor();
            if (!method_exists($controller, 'getModel')) {
                return null;
            }

            $method = new \ReflectionMethod($controller, 'getModel');
            $method->setAccessible(true);
            $model = $method->invoke($controller);

            return is_object($model) ? $model : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * 模型的写字段白名单（`null` = 无白名单 ⇒ 不过滤 ⇒ 全部可写）
     *
     * @return string[]|null
     */
    protected function allowWriteFieldsOf(string $controllerClass): ?array
    {
        $model = $this->modelOf($controllerClass);

        if ($model === null) {
            return null;
        }

        try {
            if (!is_callable([$model, 'getAllowWriteFields'])) {
                return null;
            }

            $fields = $model->getAllowWriteFields();

            if (!is_array($fields) || $fields === []) {
                return null;
            }

            return array_values(array_map('strval', $fields));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * 表单名：控制器短名的蛇形（与 `CrudSchemaTrait::crudSchemaName()` 同口径）
     */
    protected function schemaNameOf(string $controllerClass): string
    {
        $short = substr((string) strrchr('\\' . $controllerClass, '\\'), 1);

        return strtolower(Str::snake($short === '' ? $controllerClass : $short));
    }


    /**
     * 可选值归一：`['1' => '启用']` → `[{value:1, label:'启用'}]`
     *
     * 值类型：全为数字串时收敛为 int（与 `RequestQueryBuilder::parseValue()` 的推断一致），
     * 其余保留字符串；`label === value` 时置空 label，避免出现 `启用(启用)`。
     *
     * @return array<int, array{value:mixed, label:string}>
     */
    protected function normalizeOptions(mixed $options): array
    {
        if (!is_array($options) || $options === []) {
            return [];
        }

        if (array_is_list($options) && isset($options[0]) && is_array($options[0]) && array_key_exists('value', $options[0])) {
            return array_values($options);
        }

        $allNumeric = true;
        foreach ($options as $value => $_) {
            if (!is_int($value) && !(is_string($value) && $value !== '' && (string) (int) $value === $value)) {
                $allNumeric = false;
                break;
            }
        }

        $out = [];
        foreach ($options as $value => $label) {
            if (is_array($label)) {
                continue;
            }

            $cast  = $allNumeric ? (int) $value : (string) $value;
            $text  = trim((string) $label);

            $out[] = [
                'value' => $cast,
                'label' => $text === (string) $cast ? '' : $text,
            ];
        }

        return $out;
    }

    /**
     * @param mixed $list
     * @return array<int, mixed>
     */
    protected function normalizeList(mixed $list): array
    {
        if ($list instanceof \Traversable) {
            return array_values(iterator_to_array($list));
        }

        return is_array($list) ? array_values($list) : [];
    }

    /**
     * A1-1 归一化类型 → 表单类型
     */
    protected function mapFallbackType(string $type): string
    {
        return match ($type) {
            'integer'  => 'number',
            'number'   => 'number',
            'boolean'  => 'switch',
            'date'     => 'date',
            'datetime' => 'datetime',
            'enum'     => 'select',
            'text'     => 'textarea',
            'upload'   => 'file',
            default    => 'text',
        };
    }

    /**
     * 回退目录里的枚举 → 表单选项
     *
     * @param array<string, mixed> $def
     * @return array<int, array{value:mixed, label:string}>
     */
    protected function optionsFromFallback(array $def): array
    {
        $values = $def['enum'] ?? [];
        $labels = $def['enum_labels'] ?? [];

        if (!is_array($values) || $values === []) {
            return [];
        }

        $map = [];
        foreach (array_values($values) as $index => $value) {
            if (!is_scalar($value)) {
                continue;
            }

            $map[$value] = is_array($labels) ? (string) ($labels[$index] ?? '') : '';
        }

        return $this->normalizeOptions($map);
    }
}
