<?php
declare(strict_types=1);

namespace wise\agent\plan;

/**
 * Verify 阶段断言契约＼�
 *
 * **闭集**：断言类型只有下面 9 种，全部是**确定性判定**。
 * 刻意不做「让 LLM 输出断言表达式」（无法审计、可被注入），也不接受自定义正则
 *
 * 本类是断言定义的**唯一来源**：`PlanValidator`（B1-1）用它校验计划合法性，
 * `PlanVerifier`（B1-3）用它执行判定 —— 避免两处各写一份集合导致漂移。
 *
 * ## 断言项形态
 *
 * ```php
 * ['type' => 'count_gte', 'path' => 'data.total', 'value' => 1, 'message' => '盘点结果不能为空']
 * ```
 *
 * - `path`：在**本步骤输出**里的取值路径（点号分隔，如 `data.list`）；
 *   以 `$steps.{id}.` 开头时表示**跨步骤引用**（此时该 id 必须早于当前步骤）；
 * - `value`：比较基准（`count_gte` / `gte` / `lte` / `equals` / `contains` 必填）；
 * - `message`：可选，失败时回传给 AI 的人类可读原因。
 */
final class PlanAssertions
{
    /** 工具响应契约判定（A1/A2/A3 同核：`{code:0}` 即成功） */
    public const TOOL_OK = 'tool_ok';

    /** 输出非空（按 `path` 取值后判定） */
    public const NOT_EMPTY = 'not_empty';

    /** 结果条数 ≥ value（`data.total` / 数组长度 / `data.list` 条数） */
    public const COUNT_GTE = 'count_gte';

    /** 路径存在 */
    public const EXISTS = 'exists';

    /** 等于 value（弱比较，容忍 '1' 与 1） */
    public const EQUALS = 'equals';

    /** 字符串包含 value */
    public const CONTAINS = 'contains';

    /** 数值 ≥ value */
    public const GTE = 'gte';

    /** 数值 ≤ value */
    public const LTE = 'lte';

    /** 输出按给定 Schema 校验（B1-3 复用 A2 的 `ValidationGenerator` 语义，只读） */
    public const SCHEMA_VALID = 'schema_valid';

    /**
     * 全部断言类型
     *
     * @var string[]
     */
    public const TYPES = [
        self::TOOL_OK,
        self::NOT_EMPTY,
        self::COUNT_GTE,
        self::EXISTS,
        self::EQUALS,
        self::CONTAINS,
        self::GTE,
        self::LTE,
        self::SCHEMA_VALID,
    ];

    /**
     * 除 `type` 外**必填**的字段
     *
     * @var array<string, string[]>
     */
    private const REQUIRED_FIELDS = [
        self::TOOL_OK      => [],
        self::NOT_EMPTY    => ['path'],
        self::COUNT_GTE    => ['path', 'value'],
        self::EXISTS       => ['path'],
        self::EQUALS       => ['path', 'value'],
        self::CONTAINS     => ['path', 'value'],
        self::GTE          => ['path', 'value'],
        self::LTE          => ['path', 'value'],
        self::SCHEMA_VALID => ['schema'],
    ];

    /** 允许出现在 `path` 里的形态：点号路径，或 `$steps.{id}.…` 跨步骤引用 */
    private const PATH_PATTERN = '/^(\$steps\.[A-Za-z0-9_\-]+\.)?[A-Za-z0-9_\-]+(\.[A-Za-z0-9_\-]+)*$/';

    /**
     * @return string[]
     */
    public static function types(): array
    {
        return self::TYPES;
    }

    /**
     * 该类型是否被允许（`schema_valid` 受配置开关约束）
     */
    public static function isSupported(string $type, bool $allowSchemaValid = true): bool
    {
        if ($type === self::SCHEMA_VALID && !$allowSchemaValid) {
            return false;
        }

        return in_array($type, self::TYPES, true);
    }

    /**
     * 单个断言项要求的字段
     *
     * @return string[]
     */
    public static function requiredFields(string $type): array
    {
        return self::REQUIRED_FIELDS[$type] ?? [];
    }

    /**
     * 逐条校验断言声明
     *
     * @param mixed $assertions 计划里的 `verify`
     * @return array<int, array{code:string, message:string}> 空数组 = 合法
     */
    public static function problemsOf(mixed $assertions, bool $allowSchemaValid = true): array
    {
        if (!is_array($assertions) || $assertions === []) {
            return [['code' => 'assertion_missing', 'message' => 'verify 不能为空']];
        }

        if (!array_is_list($assertions)) {
            return [['code' => 'assertion_malformed', 'message' => 'verify 必须是断言列表']];
        }

        $problems = [];

        foreach ($assertions as $index => $assertion) {
            if (!is_array($assertion)) {
                $problems[] = [
                    'code'    => 'assertion_malformed',
                    'message' => sprintf('#%d 断言必须是对象', $index),
                ];

                continue;
            }

            $type = strtolower(trim((string) ($assertion['type'] ?? '')));

            if ($type === '') {
                $problems[] = ['code' => 'assertion_malformed', 'message' => sprintf('#%d 缺少 type', $index)];

                continue;
            }

            if (!self::isSupported($type, $allowSchemaValid)) {
                $problems[] = [
                    'code'    => 'assertion_unsupported',
                    'message' => sprintf(
                        '#%d 不支持的断言类型 `%s`；允许：%s%s',
                        $index,
                        $type,
                        implode('/', self::TYPES),
                        $allowSchemaValid ? '' : '（schema_valid 已被配置关闭）'
                    ),
                ];

                continue;
            }

            if (self::looksLikeRegex($assertion['value'] ?? null)) {
                $problems[] = [
                    'code'    => 'assertion_regex_not_allowed',
                    'message' => sprintf('#%d 断言取值不接受正则（无法自动评估宽窄，请改用确定性断言）', $index),
                ];

                continue;
            }

            foreach (self::requiredFields($type) as $field) {
                if (!array_key_exists($field, $assertion) || $assertion[$field] === '' || $assertion[$field] === null) {
                    $problems[] = [
                        'code'    => 'assertion_missing_field',
                        'message' => sprintf('#%d 断言 `%s` 缺少必填字段 `%s`', $index, $type, $field),
                    ];
                }
            }

            if (isset($assertion['path']) && !preg_match(self::PATH_PATTERN, (string) $assertion['path'])) {
                $problems[] = [
                    'code'    => 'assertion_bad_path',
                    'message' => sprintf('#%d 断言路径 `%s` 形态非法', $index, (string) $assertion['path']),
                ];
            }

            if (in_array($type, [self::GTE, self::LTE, self::COUNT_GTE], true)
                && isset($assertion['value'])
                && !is_numeric($assertion['value'])
            ) {
                $problems[] = [
                    'code'    => 'assertion_bad_value',
                    'message' => sprintf('#%d 断言 `%s` 的 value 必须是数字', $index, $type),
                ];
            }
        }

        return $problems;
    }

    /**
     * 从断言项里取出跨步骤引用的步骤 id（`$steps.{id}.…` / `{$steps.{id}}`）
     *
     * @param mixed $assertion
     * @return string[]
     */
    public static function referencedStepIds(mixed $assertion): array
    {
        if (!is_array($assertion)) {
            return [];
        }

        $ids = [];

        foreach ($assertion as $value) {
            if (!is_string($value)) {
                continue;
            }

            if (preg_match_all('/\$steps\.([A-Za-z0-9_\-]+)/', $value, $matches) > 0) {
                foreach ($matches[1] as $id) {
                    $ids[] = $id;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * 是否形如正则（自写正则一律拒绝）
     */
    public static function looksLikeRegex(mixed $value): bool
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                if (self::looksLikeRegex($item)) {
                    return true;
                }
            }

            return false;
        }

        if (!is_string($value)) {
            return false;
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            return false;
        }

        return preg_match('#^/.*/[a-z]*$#', $trimmed) === 1
            || stripos($trimmed, 'regex') !== false;
    }
}
