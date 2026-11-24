<?php
declare(strict_types=1);

namespace wise\agent\plan;

/**
 * 断言执行结果＼�
 *
 * 逐条记录**期望与实际** —— 这样失败原因既能回给 AI（`PlanStepRun::$verifyResult`），
 * 也能在「为什么这步没过」的排障中直接读，而不用重放。
 */
final class PlanVerifyResult implements \JsonSerializable
{
    /**
     * @param bool  $passed    是否全部通过（未声明断言 ⇒ passed=true 且 checked=0）
     * @param int   $checked   实际执行的断言数
     * @param array $failures  `[['type'=>..,'path'=>..,'expected'=>..,'actual'=>..,'message'=>..]]`
     * @param array $checkedTypes 已执行的断言类型（便于统计与回放）
     */
    public function __construct(
        public readonly bool $passed,
        public readonly int $checked = 0,
        public readonly array $failures = [],
        public readonly array $checkedTypes = [],
    ) {
    }

    /**
     * 未声明断言（读步骤允许）
     */
    public static function skipped(): self
    {
        return new self(true);
    }

    public function firstFailure(): string
    {
        return (string) ($this->failures[0]['message'] ?? '');
    }

    /**
     * @return string[]
     */
    public function failureMessages(): array
    {
        return array_map(static fn(array $f): string => (string) ($f['message'] ?? ''), $this->failures);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'passed'        => $this->passed,
            'checked'       => $this->checked,
            'checked_types' => $this->checkedTypes,
            'failures'      => $this->failures,
        ];
    }

    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        return $this->toArray();
    }
}
