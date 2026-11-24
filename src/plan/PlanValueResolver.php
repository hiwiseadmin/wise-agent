<?php
declare(strict_types=1);

namespace wise\agent\plan;

/**
 * 计划变量解析＼�
 *
 * 语义**逐条对齐** `PipelineEngine::resolveInputMapping()` / `resolveValue()`
 * —— 不是照文档猜的，而是用探针脚本实测后固化（见下表），并由
 * `tests/PlanVerifierTest::testValueResolutionMatchesPipelineEngine` 对拍锁死。
 *
 * ## 实测得到的精确语义（`src/pipeline/PipelineEngine.php`）
 *
 * | 写法 | 结果 |
 * |---|---|
 * | `$input.name` | 取值；**缺失 → `null`**（不回落原串） |
 * | `$input.` | 不匹配（**原样返回**） |
 * | `$steps.s1.data.total` | 取值（输出的 JSON 字符串先解码） |
 * | `$steps.s1.data.nope` | `null`（步骤在、路径不在） |
 * | `$steps.nope.data` | **原样返回字符串**（步骤不存在） |
 * | `$steps.s1`（单段） | **原样返回**（整串形式要求 ≥2 段） |
 * | `$steps.text.x`（输出是纯文本） | 返回该纯文本本身（走路径时遇到非数组就停） |
 * | `前缀-{$input.name}` | 内联替换（**只支持单段**） |
 * | `{$steps.s1}` | 内联替换为该步骤的**整份输出** |
 * | `{$steps.s1.data.total}` | **不替换**（内联不支持多段路径） |
 * | `{$input.nope}` / `{$input.nil}` | 替换为**空串** |
 * | ` $input.name `（带空格） | **原样返回**（不做 trim） |
 *
 * ## 一处**刻意不复制**的行为
 *
 * `{$input.arr}`（值为数组）在 `PipelineEngine` 里会触发 PHP
 * `Array to string conversion` 警告并产出无意义的 `'Array'`（实测）。
 * 本解析器改为 JSON 编码 —— 同样是「转成字符串」，但不产生警告、且信息可用。
 * 这是**唯一**的语义分歧，已在此登记。
 *
 * ## 为什么另写而不是复用 PipelineEngine
 *
 * 它的解析方法是 `protected`；为复用而放开可见性会让「计划执行」耦合「引擎内部实现」。
 * 因此同语义另实现，并用**对拍单测**保证一致 —— 契约靠测试锁定，而不是靠耦合。
 */
final class PlanValueResolver
{
    /**
     * 解析整份映射（递归处理嵌套数组）
     *
     * @param array<string, mixed> $mapping
     * @param array<string, mixed> $input
     * @param array<string, mixed> $stepsOutput
     * @return array<string, mixed>
     */
    public function resolveMapping(array $mapping, array $input, array $stepsOutput = []): array
    {
        $resolved = [];

        foreach ($mapping as $key => $value) {
            if (is_string($value)) {
                $resolved[$key] = $this->resolve($value, $input, $stepsOutput);
            } elseif (is_array($value)) {
                $resolved[$key] = $this->resolveMapping($value, $input, $stepsOutput);
            } else {
                $resolved[$key] = $value;
            }
        }

        return $resolved;
    }

    /**
     * 解析单个值
     */
    public function resolve(string $value, array $input, array $stepsOutput = []): mixed
    {
        if (preg_match('/^\$input\.([A-Za-z0-9_.\-]+)$/', $value, $matches) === 1) {
            return $this->lookup($input, $matches[1]);
        }

        if (preg_match('/^\$steps\.([A-Za-z0-9_\-]+)\.(.+)$/', $value, $matches) === 1) {
            if (!array_key_exists($matches[1], $stepsOutput)) {
                return $value;
            }

            return $this->lookup($stepsOutput, $matches[1] . '.' . $matches[2]);
        }

        if (str_contains($value, '{$')) {
            $replaced = preg_replace_callback(
                '/\{\$(input|steps)\.([A-Za-z0-9_\-]+)\}/',
                function (array $matches) use ($input, $stepsOutput): string {
                    if ($matches[1] === 'steps') {
                        return $this->stringify($stepsOutput[$matches[2]] ?? null);
                    }

                    return $this->stringify($this->lookup($input, $matches[2]));
                },
                $value
            );

            return $replaced ?? $value;
        }

        return $value;
    }

    /**
     * 按点号路径取值（与 PipelineEngine 的走法一致）
     *
     * 三条与它逐条对齐的规则：
     * 1. 当前值是字符串 ⇒ 先试 JSON 解码；**解码失败就到此为止**（返回该字符串，不返回 null）；
     * 2. 当前值不是数组/对象 ⇒ **返回到此为止的值**；
     * 3. 当前值是数组但键不存在 ⇒ `null`。
     *
     * @param array<string, mixed> $source
     */
    public function lookup(array $source, string $path): mixed
    {
        $current = $source;

        foreach (explode('.', $path) as $segment) {
            if (is_string($current)) {
                $decoded = json_decode($current, true);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    return $current;
                }

                $current = $decoded;
            }

            if (is_object($current)) {
                if (!property_exists($current, $segment)) {
                    return null;
                }

                $current = $current->{$segment};

                continue;
            }

            if (!is_array($current)) {
                return $current;
            }

            if (!array_key_exists($segment, $current)) {
                return null;
            }

            $current = $current[$segment];
        }

        if (is_string($current)) {
            $decoded = json_decode($current, true);

            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }

        return $current;
    }

    /**
     * 内联替换的字符串化（数组走 JSON，见类注释「刻意不复制」一节）
     */
    private function stringify(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return (string) json_encode($value, JSON_UNESCAPED_UNICODE);
    }
}
