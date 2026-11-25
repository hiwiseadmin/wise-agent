<?php
declare(strict_types=1);

namespace wise\agent\plan;

/**
 * 断言执行器＼�
 *
 * 只执行 {@see PlanAssertions} 里的**闭集**断言，**全部是确定性判定**：
 * 不做 LLM 自评、不接受自由表达式、不解释正则。
 *
 * ## 取值口径（与解析器同源）
 *
 * - `path` 不带前缀 ⇒ 在**本步骤输出**里取值（输出的 JSON 字符串会先解码）；
 * - `path` 以 `$steps.{id}.` 开头 ⇒ 在**更早步骤的输出**里取值；
 * - 取不到（`null`）时：`not_empty` / `exists` 判失败，`count_gte` 记 0，
 *   数值比较（`gte`/`lte`）判失败并给出「取不到值」的原因（**不静默当成 0**）。
 *
 * ## `count_gte` 的计数口径
 *
 * | 取到的值 | 计数 |
 * |---|---|
 * | 数组 | 元素个数 |
 * | 数值 | 数值本身（便于直接对 `data.total` 断言） |
 * | 其它 / 取不到 | 0 |
 */
class PlanVerifier
{
    use PlanOptions;

    private PlanValueResolver $values;

    /**
     * @param array<string, mixed> $options `plan.verify.*` 覆盖项
     */
    public function __construct(
        array $options = [],
        ?PlanValueResolver $values = null
    ) {
        $this->setPlanOptions($options);
        $this->values = $values ?? new PlanValueResolver();
    }

    /**
     * 执行一个步骤声明的全部断言
     *
     * @param array<string, mixed> $input       计划级输入（断言里 `$input.x` 用）
     * @param array<string, mixed> $stepsOutput 已完成步骤的输出（`$steps.x` 用）
     */
    public function verify(PlanStep $step, mixed $output, array $input = [], array $stepsOutput = []): PlanVerifyResult
    {
        if (!$step->hasVerify()) {
            return PlanVerifyResult::skipped();
        }

        $failures = [];
        $types    = [];
        $checked  = 0;

        foreach ($step->verify as $assertion) {
            if (!is_array($assertion)) {
                continue;
            }

            $type = strtolower(trim((string) ($assertion['type'] ?? '')));

            if ($type === '') {
                continue;
            }

            $types[] = $type;
            $checked++;

            $failure = $this->check($type, $assertion, $output, $input, $stepsOutput);

            if ($failure !== null) {
                $failures[] = $failure;
            }
        }

        return new PlanVerifyResult($failures === [], $checked, $failures, array_values(array_unique($types)));
    }

    /**
     * 单条断言判定
     *
     * @param array<string, mixed> $assertion
     * @param array<string, mixed> $input
     * @param array<string, mixed> $stepsOutput
     * @return array<string, mixed>|null `null` = 通过
     */
    public function check(
        string $type,
        array $assertion,
        mixed $output,
        array $input = [],
        array $stepsOutput = []
    ): ?array {
        $path     = (string) ($assertion['path'] ?? '');
        $expected = $assertion['value'] ?? null;
        $actual   = $this->resolvePath($path, $output, $input, $stepsOutput);
        $decoded  = $this->decode($output);

        switch ($type) {
            case PlanAssertions::TOOL_OK:
                if (!is_array($decoded) || !array_key_exists('code', $decoded)) {
                    return $this->fail($type, $path, 0, null, '输出不是同核契约（缺少 code 字段），无法判定工具是否成功');
                }

                if ((int) $decoded['code'] !== 0) {
                    return $this->fail(
                        $type,
                        $path,
                        0,
                        $decoded['code'],
                        sprintf('工具未成功：code=%s msg=%s', (string) $decoded['code'], (string) ($decoded['msg'] ?? ''))
                    );
                }

                return null;

            case PlanAssertions::NOT_EMPTY:
                if ($actual === null || $actual === '' || $actual === []) {
                    return $this->fail($type, $path, '非空', $actual, sprintf('`%s` 为空（期望非空）', $path));
                }

                return null;

            case PlanAssertions::EXISTS:
                if ($actual === null) {
                    return $this->fail($type, $path, '存在', null, sprintf('`%s` 不存在', $path));
                }

                return null;

            case PlanAssertions::COUNT_GTE:
                $count = $this->countOf($actual);

                if ($count < (int) $expected) {
                    return $this->fail(
                        $type,
                        $path,
                        '>= ' . (int) $expected,
                        $count,
                        sprintf('`%s` 计数 %d < 期望 %d', $path, $count, (int) $expected)
                    );
                }

                return null;

            case PlanAssertions::EQUALS:
                if ($actual != $expected) {
                    return $this->fail($type, $path, $expected, $actual, sprintf('`%s` 不等于期望值', $path));
                }

                return null;

            case PlanAssertions::CONTAINS:
                if (!$this->containsValue($actual, $expected)) {
                    return $this->fail($type, $path, $expected, $actual, sprintf('`%s` 不包含期望值', $path));
                }

                return null;

            case PlanAssertions::GTE:
            case PlanAssertions::LTE:
                if (!is_numeric($actual)) {
                    return $this->fail(
                        $type,
                        $path,
                        $expected,
                        $actual,
                        sprintf('`%s` 取不到可比较的数值（实际：%s）', $path, $this->describe($actual))
                    );
                }

                $pass = $type === PlanAssertions::GTE
                    ? (float) $actual >= (float) $expected
                    : (float) $actual <= (float) $expected;

                if (!$pass) {
                    return $this->fail(
                        $type,
                        $path,
                        ($type === PlanAssertions::GTE ? '>= ' : '<= ') . $expected,
                        $actual,
                        sprintf('`%s` = %s，不满足 %s %s', $path, (string) $actual, $type, (string) $expected)
                    );
                }

                return null;

            case PlanAssertions::SCHEMA_VALID:
                return $this->checkSchema((array) ($assertion['schema'] ?? []), $decoded, $path);

            default:
                return $this->fail($type, $path, null, null, sprintf('不支持的断言类型 `%s`（执行期拒绝）', $type));
        }
    }

    /**
     * `schema_valid`：薄复用 A2 的校验桥（只读），不重造第二套校验语义
     *
     * @param array<string, mixed> $schema
     */
    protected function checkSchema(array $schema, mixed $decoded, string $path): ?array
    {
        if ($schema === []) {
            return $this->fail(PlanAssertions::SCHEMA_VALID, $path, null, null, 'schema_valid 缺少 schema');
        }

        if (!(bool) $this->planOpt('verify.allow_schema_valid', true)) {
            return $this->fail(
                PlanAssertions::SCHEMA_VALID,
                $path,
                null,
                null,
                'schema_valid 已被配置关闭（plan.verify.allow_schema_valid=false）'
            );
        }

        $bridgeClass = 'wise\agent\form\FormValidationBridge';

        if (!class_exists($bridgeClass)) {
            return $this->fail(
                PlanAssertions::SCHEMA_VALID,
                $path,
                null,
                null,
                'schema_valid 需要 wise-agent 的表单校验桥（FormValidationBridge），当前不可用'
            );
        }

        $data = is_array($decoded) && is_array($decoded['data']['values'] ?? null)
            ? $decoded['data']['values']
            : $decoded;

        if (!is_array($data)) {
            return $this->fail(PlanAssertions::SCHEMA_VALID, $path, null, null, '待校验的数据不是键值对');
        }

        try {
            $bridge = new $bridgeClass();
            $report = $bridge->check($schema, $data, true);
            $passed = method_exists($report, 'isValid') ? (bool) $report->isValid() : true;
        } catch (\Throwable $e) {
            return $this->fail(PlanAssertions::SCHEMA_VALID, $path, null, null, '校验失败：' . $e->getMessage());
        }

        if (!$passed) {
            $summary = method_exists($report, 'firstError') ? (string) $report->firstError() : '';

            return $this->fail(PlanAssertions::SCHEMA_VALID, $path, '符合 schema', null, '数据不符合 schema：' . $summary);
        }

        return null;
    }

    /**
     * 按 `path` 取值（支持 `$steps.{id}.` 前缀）
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed> $stepsOutput
     */
    protected function resolvePath(string $path, mixed $output, array $input, array $stepsOutput): mixed
    {
        $path = trim($path);

        if ($path === '') {
            return $this->decode($output);
        }

        if (preg_match('/^\$steps\.(.+)$/', $path, $matches) === 1) {
            return $this->values->lookup($stepsOutput, $matches[1]);
        }

        if (preg_match('/^\$input\.(.+)$/', $path, $matches) === 1) {
            return $this->values->lookup($input, $matches[1]);
        }

        $decoded = $this->decode($output);

        if (!is_array($decoded)) {
            return null;
        }

        return $this->values->lookup($decoded, $path);
    }

    /**
     * 输出归一：JSON 字符串 → 数组；其它原样
     */
    protected function decode(mixed $output): mixed
    {
        if (!is_string($output)) {
            return $output;
        }

        $decoded = json_decode($output, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $output;
    }

    private function countOf(mixed $value): int
    {
        if (is_array($value)) {
            return count($value);
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);

            if (is_array($decoded)) {
                return count($decoded);
            }
        }

        return 0;
    }

    private function containsValue(mixed $actual, mixed $expected): bool
    {
        if (is_array($actual)) {
            return in_array($expected, $actual, false);
        }

        if (is_string($actual)) {
            return str_contains($actual, (string) $expected);
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function fail(string $type, string $path, mixed $expected, mixed $actual, string $message): array
    {
        return [
            'type'     => $type,
            'path'     => $path,
            'expected' => $expected,
            'actual'   => $actual,
            'message'  => $message,
        ];
    }

    private function describe(mixed $value): string
    {
        if (is_scalar($value)) {
            return (string) $value;
        }

        return $value === null ? 'null' : (json_encode($value, JSON_UNESCAPED_UNICODE) ?: gettype($value));
    }
}
