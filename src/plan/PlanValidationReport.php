<?php
declare(strict_types=1);

namespace wise\agent\plan;

/**
 * 计划校验报告＼�
 *
 * ## 为什么 `ok=false` 也要回传归一化后的计划
 *
 * 计划不合法时的正确动作是**回环给 LLM 修复**＼�，而修复需要看到
 * 「哪一步的哪个字段错了」。因此报告同时携带：
 * - `errors[]`：逐条错误（`step` / `field` / `code` / `message`），可直接拼进修复提示；
 * - `plan`：**归一化后**的计划（补了 `id`、覆盖了 `write`）+ `notes`，
 *   让修复提示基于与执行期完全一致的理解，而不是原始 JSON。
 */
final class PlanValidationReport implements \JsonSerializable
{
    /**
     * @param bool   $ok    是否通过全部校验
     * @param PlanDefinition $plan 归一化后的计划
     * @param array  $errors `[['step'=>..,'field'=>..,'code'=>..,'message'=>..]]`
     * @param array  $notes  `[['code'=>..,'message'=>..]]` 诚实标注（不阻断）
     */
    public function __construct(
        public readonly bool $ok,
        public readonly PlanDefinition $plan,
        public readonly array $errors = [],
        public readonly array $notes = [],
    ) {
    }

    /**
     * 错误码清单（便于单测与统计）
     *
     * @return string[]
     */
    public function codes(): array
    {
        return array_values(array_unique(array_map(
            static fn(array $error): string => (string) ($error['code'] ?? ''),
            $this->errors
        )));
    }

    /**
     * @return string[]
     */
    public function messages(): array
    {
        return array_map(
            static fn(array $error): string => (string) ($error['message'] ?? ''),
            $this->errors
        );
    }

    public function summary(): string
    {
        if ($this->ok) {
            return sprintf(
                '计划合法：%d 步（其中写步骤 %d 个）',
                count($this->plan->steps),
                count($this->plan->writeSteps())
            );
        }

        return sprintf('计划不合法：%d 项错误（首个：%s）', count($this->errors), $this->messages()[0] ?? '');
    }

    /**
     * 供 LLM 修复使用的提示（只含错误与归一化计划，不含执行细节）
     */
    public function repairHint(): string
    {
        if ($this->ok) {
            return '';
        }

        $lines = ['计划未通过校验，请按下列问题修正后重新输出整份计划：'];

        foreach ($this->errors as $error) {
            $lines[] = sprintf(
                '- [%s] 步骤 %s · 字段 %s：%s',
                (string) ($error['code'] ?? ''),
                (string) ($error['step'] ?? '-'),
                (string) ($error['field'] ?? '-'),
                (string) ($error['message'] ?? '')
            );
        }

        return implode("\n", $lines);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'ok'          => $this->ok,
            'errors'      => $this->errors,
            'notes'       => $this->notes,
            'write_steps' => array_map(
                static fn(PlanStep $step): string => $step->id,
                $this->plan->writeSteps()
            ),
            'plan'        => $this->plan->toArray(),
        ];
    }

    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        return $this->toArray();
    }
}
