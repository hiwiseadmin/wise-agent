<?php
declare(strict_types=1);

namespace wise\agent\form;

use think\Validate;
use wise\form\schema\DataFilter;
use wise\form\schema\ValidationGenerator;

/**
 * 表单校验桥（A2-2）
 *
 * **薄封装**：校验规则与数据过滤一律委托 wise-form-schema 的既有实现
 * （`ValidationGenerator` / `DataFilter`），本类只做三件事：
 *
 * 1. **解释**：把规则数组翻译成给人/AI 看的清单（`rulesOf()`）；
 * 2. **预检**：给出逐字段错误 + **实际会被写入的字段**（`check()`）；
 * 3. **诚实标注**：真实提交链路**是否真的会执行这套校验**（`isEnforced()`）。
 *
 * 真实提交的范本（`CrudAction.php:506-522` 文档注释）意图是：
 * 「同一份 `ValidationGenerator` 规则 → `think\Validate` → 同一份 `DataFilter` 过滤」。
 * A2 沿用**同一份规则 + 同一个 `think\Validate` + 同一个 `DataFilter`**，
 * 因此「A2 的预检结论」与「控制器若按范本意图实现会得出的结论」逐条一致（A2 版对拍）。
 *
 * ## ⚠️ 但范本的**代码写法**在 think-validate 下是静默失效的（实测）
 *
 * 框架文档写的是 `new Validate(ValidationGenerator::generateFor($schema, $data))`，
 * 而当前依赖 `topthink/think-validate` 的 `think\Validate`：
 *
 * | 写法 | 实测结果 |
 * |---|---|
 * | `new Validate($rules)->check($data)`（范本） | **恒为 `true`** —— 构造函数**零参数**（`Validate.php:334`），规则被丢弃 |
 * | `(new Validate())->check($data, $rules)` | ✅ 正常（`name不能为空` / `amount必须是数字`） |
 * | `(new Validate())->rule($rules)->check($data)` | ✅ 正常 |
 *
 * 即：**照范本实现的控制器，其 Schema 校验从来不生效**（这也是「预检必须诚实标注 `enforced`」的又一个理由）。
 * A2 采用能生效的入口，并对齐范本的**语义**而非它的错误写法。
 *
 * ## 可测试性
 * `schemaArray()` / `newValidate()` / `isEnforced()` 是 `protected` 接缝，因此本类**不加 `final`**。
 */
class FormValidationBridge
{
    /** 范本所在的 trait 文件名：方法源码出自它 ⇒ 说明**未覆写**（仍为空钩子） */
    private const CRUD_ACTION_FILE = 'CrudAction.php';

    /**
     * @param array<string, mixed> $options `form_schema.*` 覆盖项
     */
    public function __construct(private array $options = [])
    {
    }


    /**
     * 静态规则（不含数据相关的条件必填）
     *
     * @param FormSchema|array<string, mixed> $schema
     * @return array<string, string> `['field' => 'require|string']`
     */
    public function rulesOf(FormSchema|array $schema): array
    {
        return ValidationGenerator::generate($this->schemaArray($schema));
    }

    /**
     * 针对**本次数据**的有效规则（条件必填在这一步折叠进来）
     *
     * @param FormSchema|array<string, mixed> $schema
     * @param array<string, mixed>            $data
     * @return array<string, string>
     */
    public function effectiveRules(FormSchema|array $schema, array $data): array
    {
        return ValidationGenerator::generateFor($this->schemaArray($schema), $data);
    }

    /**
     * 规则清单的可读化（`form_fields` 展示用）
     *
     * @param FormSchema|array<string, mixed> $schema
     * @return array<int, array{field:string, label:string, rules:string, conditional:bool, required_now:bool}>
     */
    public function describeRules(FormSchema|array $schema, array $data = []): array
    {
        $stats = $this->schemaArray($schema);
        $rules = $this->effectiveRules($schema, $data);

        $out = [];
        foreach ($rules as $field => $rule) {
            $def = (array) ($stats['fields'][$field] ?? []);

            $out[] = [
                'field'        => (string) $field,
                'label'        => (string) ($def['label'] ?? ''),
                'rules'        => (string) $rule,
                'conditional'  => (bool) (($def['conditional_rules'] ?? []) !== []),
                'required_now' => str_contains((string) $rule, 'require'),
            ];
        }

        return $out;
    }


    /**
     * 预检一条待提交数据
     *
     * @param array<string, mixed> $data
     */
    public function check(FormSchema $schema, array $data, bool $isNew = true): FormValidateReport
    {
        $schemaArray = $this->schemaArray($schema);

        $rules = [];
        $exception = null;

        try {
            $rules = ValidationGenerator::generateFor($schemaArray, $data);
        } catch (\Throwable $e) {
            $exception = $e;
        }

        if ($exception !== null) {
            return new FormValidateReport(
                passed: false,
                errors: [['field' => '', 'rule' => 'schema', 'message' => $exception->getMessage()]],
                enforced: $this->isEnforced($schema->controller),
                enforcedNote: $this->enforcedNote($schema->controller),
                schemaSource: $schema->source,
                notes: [['code' => 'rules.failed', 'message' => '规则生成失败：' . $exception->getMessage()]],
            );
        }

        $validate = $this->newValidate();
        $passed   = (bool) $validate->check($data, $rules);

        $errors = $this->normalizeErrors($validate->getError(true), $rules);

        $accepted = [];
        $notes    = [];

        try {
            $accepted = (new DataFilter())->filter($schemaArray, $data);
        } catch (\Throwable $e) {
            $notes[] = ['code' => 'filter.failed', 'message' => '数据过滤失败：' . $e->getMessage()];
        }

        $coerced  = $this->coercedFields($data, $accepted);
        $filled   = $this->defaultedFields($data, $accepted);

        if ($filled !== []) {
            $notes[] = [
                'code'    => 'accepted.defaulted',
                'message' => sprintf('以下字段未提交，`DataFilter` 会补齐类型默认值：%s', implode(', ', $filled)),
            ];
        }

        $denied     = $schema->deniedKeys();
        $submitted  = array_keys($data);
        $notWritable = array_values(array_intersect(array_keys($denied), $submitted));
        $droppedWhitelist = array_values(array_filter(
            $notWritable,
            static fn(string $key): bool => ($denied[$key] ?? '') === FormFieldSchema::DENY_WHITELIST
        ));

        if ($notWritable !== []) {
            $notes[] = [
                'code'    => 'input.not_writable',
                'message' => sprintf(
                    '提交数据包含不可写字段（本工具不会为其生成值，请人工处理）：%s',
                    implode(', ', array_map(
                        static fn(string $key): string => $key . '(' . ($denied[$key] ?? '') . ')',
                        $notWritable
                    ))
                ),
            ];
        }

        if (!$schema->hasNativeSchema()) {
            $notes[] = [
                'code'    => 'schema.rebuilt',
                'message' => '字段目录来自回退路径（未反射到 defineForm），校验规则由目录重建，可能不含分组/联动/条件必填等语义。',
            ];
        }

        return new FormValidateReport(
            passed: $passed,
            errors: $errors,
            accepted: $accepted,
            coerced: $coerced,
            droppedSchema: $this->droppedFields($schemaArray, $data),
            droppedWhitelist: $droppedWhitelist,
            notWritable: $notWritable,
            enforced: $this->isEnforced($schema->controller),
            enforcedNote: $this->enforcedNote($schema->controller),
            schemaSource: $schema->source,
            notes: $notes,
        );
    }


    /**
     * 该控制器的真实提交链路是否会执行 Schema 校验
     *
     * ⚠️ 不能用 `ReflectionMethod::getDeclaringClass()` 判断：`validateBySchema()` 定义在 trait 里，
     * PHP 的 trait 方法会被**扁平化进使用它的类**，`getDeclaringClass()` 恒等于控制器本身，
     * 无法区分「继承来的空钩子」与「真的覆写了」。可靠依据是**方法源码文件**：
     * 出自 `CrudAction.php` ⇒ 仍是空钩子；出自控制器自有文件（或宿主的其它 trait）⇒ 已覆写。
     */
    public function isEnforced(string $controllerClass): bool
    {
        if ($controllerClass === '' || !class_exists($controllerClass)) {
            return false;
        }

        try {
            $method = new \ReflectionMethod($controllerClass, 'validateBySchema');
            $file   = $method->getFileName();

            if ($file === false) {
                return false;
            }

            return basename($file) !== self::CRUD_ACTION_FILE;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * 未启用校验时的明确提示（**禁止**宣称「预检通过即可提交」）
     */
    public function enforcedNote(string $controllerClass): string
    {
        if ($this->isEnforced($controllerClass)) {
            return '该控制器覆写了 validateBySchema()，真实提交会执行同一套 Schema 校验。';
        }

        return '该控制器的 validateBySchema() 未覆写（CrudAction 里是空钩子），**真实提交当前不执行 Schema 校验** —— '
            . '本结论仅为 Schema 契约层预检，预检通过 ≠ 提交会被系统校验通过。';
    }


    /**
     * @param FormSchema|array<string, mixed> $schema
     * @return array<string, mixed>
     */
    protected function schemaArray(FormSchema|array $schema): array
    {
        return $schema instanceof FormSchema ? $schema->validationSchema() : $schema;
    }

    /**
     * 新建校验器（**批量模式**：一次拿到全部字段的错误）
     *
     * ⚠️ 不能把规则传给构造函数：`think\Validate::__construct()` 是零参数的
     * （`vendor/topthink/think-validate/src/Validate.php:334`），传进去会被丢弃、
     * 校验恒为 true（静默失效）。规则必须交给 `check($data, $rules)`。
     */
    protected function newValidate(): Validate
    {
        $validate = new Validate();
        $validate->batch(true);

        return $validate;
    }


    /**
     * 归一化错误明细
     *
     * 批量模式下 `getError()` 返回 `['field' => '消息']`；非批量（或旧版本）返回字符串，
     * 此时退化为一条不定位的错误。规则串一并带上，便于 AI 理解「违反了哪条规则」。
     *
     * @param mixed                 $error `Validate::getError()` 的返回值
     * @param array<string, string> $rules
     * @return array<int, array{field:string, rule:string, message:string}>
     */
    protected function normalizeErrors(mixed $error, array $rules): array
    {
        if (is_array($error)) {
            $out = [];
            foreach ($error as $field => $message) {
                $out[] = [
                    'field'   => (string) $field,
                    'rule'    => (string) ($rules[$field] ?? ''),
                    'message' => (string) $message,
                ];
            }

            return $out;
        }

        $message = trim((string) $error);

        return $message === '' ? [] : [['field' => '', 'rule' => '', 'message' => $message]];
    }

    /**
     * 输入存在且被类型转换过的字段
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $accepted
     * @return string[]
     */
    protected function coercedFields(array $data, array $accepted): array
    {
        $coerced = [];

        foreach ($accepted as $key => $value) {
            if (!array_key_exists($key, $data)) {
                continue;
            }

            if ($data[$key] !== $value) {
                $coerced[] = (string) $key;
            }
        }

        return $coerced;
    }

    /**
     * 未提交但被补齐默认值的字段（非 null 才算补了值）
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $accepted
     * @return string[]
     */
    protected function defaultedFields(array $data, array $accepted): array
    {
        $filled = [];

        foreach ($accepted as $key => $value) {
            if (!array_key_exists($key, $data) && $value !== null) {
                $filled[] = (string) $key;
            }
        }

        return $filled;
    }

    /**
     * 因「Schema 未定义」会被丢弃的输入字段（`DataFilter` 静默丢弃未定义字段）
     *
     * @param array<string, mixed> $schemaArray
     * @param array<string, mixed> $data
     * @return string[]
     */
    protected function droppedFields(array $schemaArray, array $data): array
    {
        $defined  = array_keys((array) ($schemaArray['fields'] ?? []));
        $repeater = array_keys((array) ($schemaArray['repeaters'] ?? []));
        $known    = array_merge($defined, $repeater);

        return array_values(array_filter(
            array_keys($data),
            static fn(string $key): bool => !in_array($key, $known, true)
        ));
    }
}
