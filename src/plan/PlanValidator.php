<?php
declare(strict_types=1);

namespace wise\agent\plan;

/**
 * 计划校验器＼�
 *
 * 自治编排的**第一道确定性闸门**：LLM 产出的计划在**执行前**逐条校验，
 * 不合法就回环修复（{@see PlanValidationReport::repairHint()}），
 * `max_plan_repairs` 用尽即终止 —— **一步都不执行**。
 *
 * ## 11 条校验
 *
 * | # | 校验 | 错误码 |
 * |---|---|---|
 * | 1 | 步骤数 ≤ `max_steps`（计划声明的上限**只能收紧**） | `too_many_steps` |
 * | 2 | `kind` 命中 `allowed_kinds` | `unknown_step_kind` |
 * | 3 | `target` 真实存在 | `unknown_capability` |
 * | 4 | `target` 未命中 `deny_capabilities`（前缀通配） | `denied_capability` |
 * | 5 | `id` 唯一非空；引用只指向**更早**的步骤（`input` / `condition` / `verify`） | `invalid_reference` |
 * | 6 | `input` 的**键** ∈ 能力参数名（Schema 不可得时跳过并记 note） | `unknown_argument` |
 * | 7 | 写步骤必须声明非空 `verify` | `write_step_without_verify` |
 * | 8 | 断言合法（闭集 / 必填字段 / 路径形态 / 不接受正则） | `invalid_assertion` |
 * | 9 | 失败策略合法；`replan` 次数 ≤ `max_replans`；`retries ≥ 0` | `invalid_failure_policy` |
 * | 10 | `timeout` ≤ `max_step_duration` 且 > 0 | `timeout_too_large` |
 * | 11 | 写步骤数 ≤ `max_write_steps` | `too_many_writes` |
 *
 * ## 三条安全语义（都在这里落地）
 *
 * 1. **`write` 由能力 access 推导并覆盖声明值** —— 计划里写 `write:false` 无法伪装成只读；
 * 若声明值与推导值冲突，记 `step.write_declared_ignored` note（审计可见）。
 * 2. **计划自带的 `limits` 只能收紧**：生效值 = `min(配置上限, 计划声明)`，
 * 否则一份被注入的计划只要自带 `max_steps=999` 就能绕过上限。
 * 3. **值位置自由、键位置受约束**：`input` 的**值**可以是任意自然语言（注入载荷只能待在这里），
 * 但**键**必须是能力 Schema 里的参数名 —— 与 A2-3「载荷只能落在值位置」是同一道闸。
 *
 * `RegistryCapabilityLookup` 是默认实现；单测注入替身即可完全离线。
 */
class PlanValidator
{
    use PlanOptions;

    /** 默认上限�一致） */
    private const DEFAULTS = [
        'max_steps'         => 12,
        'max_write_steps'   => 5,
        'max_step_duration' => 120,
        'max_replans'       => 2,
    ];

    /**
     * @param array<string, mixed> $options `plan.*` 覆盖项
     */
    public function __construct(
        array $options = [],
        private ?PlanCapabilityLookupInterface $lookup = null
    ) {
        $this->setPlanOptions($options);
    }

    public function validate(PlanDefinition $plan): PlanValidationReport
    {
        $errors = [];
        $notes  = [];

        $lookup      = $this->lookup();
        $allowed     = $this->allowedKinds();
        $deny        = $this->denyPatterns();
        $allowSchema = (bool) $this->planOpt('verify.allow_schema_valid', true);
        $maxSteps    = $this->limit($plan, 'max_steps');
        $maxWrites   = $this->limit($plan, 'max_write_steps');
        $maxStepSecs = $this->limit($plan, 'max_step_duration');
        $maxReplans  = $this->limit($plan, 'max_replans');

        if ($plan->isEmpty()) {
            $errors[] = $this->error('-', 'steps', 'empty_plan', '计划没有任何步骤');
        }

        if (count($plan->steps) > $maxSteps) {
            $errors[] = $this->error(
                '-',
                'steps',
                'too_many_steps',
                sprintf('步骤数 %d 超过上限 %d', count($plan->steps), $maxSteps)
            );
        }

        $indexes  = [];
        $seenIds  = [];
        foreach ($plan->steps as $index => $step) {
            if (!$step instanceof PlanStep) {
                continue;
            }

            if (isset($seenIds[$step->id])) {
                $errors[] = $this->error(
                    $step->id,
                    'id',
                    'invalid_reference',
                    sprintf('步骤 id `%s` 重复', $step->id)
                );

                continue;
            }

            $seenIds[$step->id] = true;
            $indexes[$step->id] = $index + 1;
        }

        $normalized  = [];
        $writeCount  = 0;
        $replanCount = 0;
        $unchecked   = [];

        foreach ($plan->steps as $index => $step) {
            if (!$step instanceof PlanStep) {
                continue;
            }

            $no = $index + 1;

            if (!in_array($step->kind, $allowed, true)) {
                $errors[] = $this->error(
                    $step->id,
                    'kind',
                    'unknown_step_kind',
                    sprintf('不支持的步骤种类 `%s`；允许：%s', $step->kind, implode('/', $allowed))
                );
            }

            $exists = false;
            if ($step->target === '') {
                $errors[] = $this->error($step->id, 'target', 'unknown_capability', '缺少 target');
            } elseif ($this->denied($step->target, $deny)) {
                $errors[] = $this->error(
                    $step->id,
                    'target',
                    'denied_capability',
                    sprintf('能力 `%s` 命中 plan.deny_capabilities 黑名单', $step->target)
                );
            } else {
                $exists = $lookup->exists($step->kind, $step->target);

                if (!$exists) {
                    $errors[] = $this->error(
                        $step->id,
                        'target',
                        'unknown_capability',
                        sprintf('能力不存在：%s:%s', $step->kind, $step->target)
                    );
                }
            }

            $write = $exists ? $lookup->isWrite($step->kind, $step->target) : $step->write;

            if ($step->write !== $write) {
                $notes[] = $this->note(
                    'step.write_declared_ignored',
                    sprintf('步骤 %s 声明的 write=%s 与实际 access 推导不符，已按推导值 %s 处理', $step->id, $step->write ? 'true' : 'false', $write ? 'true' : 'false')
                );
            }

            if ($write) {
                $writeCount++;
            }

            foreach ($step->references() as $ref) {
                if (!isset($indexes[$ref])) {
                    $errors[] = $this->error($step->id, 'input', 'invalid_reference', sprintf('引用了不存在的步骤 `%s`', $ref));
                    continue;
                }

                if ($indexes[$ref] >= $no) {
                    $errors[] = $this->error(
                        $step->id,
                        'input',
                        'invalid_reference',
                        sprintf('只能引用更早的步骤，`%s` 不早于当前步骤', $ref)
                    );
                }
            }

            if ($exists) {
                $names = $lookup->argumentNames($step->kind, $step->target);

                if (is_array($names)) {
                    foreach (array_keys($step->input) as $key) {
                        if (!in_array((string) $key, $names, true)) {
                            $errors[] = $this->error(
                                $step->id,
                                'input.' . $key,
                                'unknown_argument',
                                sprintf('能力 %s 不接受参数 `%s`；可用参数：%s', $step->target, (string) $key, implode('/', $names))
                            );
                        }
                    }
                } else {
                    $unchecked[] = $step->id;
                }
            }

            if ($write && !$step->hasVerify()) {
                $errors[] = $this->error(
                    $step->id,
                    'verify',
                    'write_step_without_verify',
                    '写步骤必须声明至少一条断言（写操作必须能被自证）'
                );
            }

            if ($step->hasVerify()) {
                foreach (PlanAssertions::problemsOf($step->verify, $allowSchema) as $problem) {
                    $errors[] = $this->error($step->id, 'verify', 'invalid_assertion', (string) $problem['message']);
                }

                foreach ($step->verify as $assertion) {
                    foreach (PlanAssertions::referencedStepIds($assertion) as $ref) {
                        if (!isset($indexes[$ref]) || $indexes[$ref] >= $no) {
                            $errors[] = $this->error(
                                $step->id,
                                'verify',
                                'invalid_reference',
                                sprintf('断言引用的步骤 `%s` 不存在或不早于当前步骤', $ref)
                            );
                        }
                    }
                }
            }

            if (!in_array($step->onVerifyFail, PlanStep::ON_VERIFY_FAIL, true)) {
                $errors[] = $this->error(
                    $step->id,
                    'on_verify_fail',
                    'invalid_failure_policy',
                    sprintf('on_verify_fail 必须是 %s 之一', implode('/', PlanStep::ON_VERIFY_FAIL))
                );
            }

            if ($step->onVerifyFail === PlanStep::REPLAN) {
                $replanCount++;
            }

            if ($step->retries < 0) {
                $errors[] = $this->error($step->id, 'retries', 'invalid_failure_policy', 'retries 不能为负');
            }

            if ($step->timeout !== null && ($step->timeout <= 0 || $step->timeout > $maxStepSecs)) {
                $errors[] = $this->error(
                    $step->id,
                    'timeout',
                    'timeout_too_large',
                    sprintf('单步超时 %d 秒非法；允许范围 1~%d 秒（plan.max_step_duration）', $step->timeout, $maxStepSecs)
                );
            }

            $normalized[] = $step->withWrite($write);
        }

        if ($writeCount > $maxWrites) {
            $errors[] = $this->error(
                '-',
                'steps',
                'too_many_writes',
                sprintf('写步骤 %d 个超过上限 %d（plan.max_write_steps）', $writeCount, $maxWrites)
            );
        }

        if ($replanCount > $maxReplans) {
            $errors[] = $this->error(
                '-',
                'steps',
                'invalid_failure_policy',
                sprintf('声明 replan 的步骤 %d 个超过上限 %d（plan.max_replans）', $replanCount, $maxReplans)
            );
        }

        if ($unchecked !== []) {
            $notes[] = $this->note(
                'arguments.unchecked',
                sprintf('以下能力的参数 Schema 不可得，已跳过参数键校验（执行期由能力自身拒绝非法参数）：%s', implode(', ', $unchecked))
            );
        }

        if ($writeCount > 0) {
            $notes[] = $this->note(
                'plan.contains_writes',
                sprintf('本计划含 %d 个写步骤，执行时逐个走既有审批/RBAC 链（AI 不新增写路径）', $writeCount)
            );
        }

        return new PlanValidationReport(
            ok: $errors === [],
            plan: $plan->withSteps($normalized)->withNotes(array_merge($plan->notes, $notes)),
            errors: $errors,
            notes: $notes
        );
    }


    protected function lookup(): PlanCapabilityLookupInterface
    {
        return $this->lookup ??= new RegistryCapabilityLookup();
    }


    /**
     * 生效上限 = `min(配置上限, 计划声明)` —— **计划只能收紧**
     */
    private function limit(PlanDefinition $plan, string $key): int
    {
        return $this->planLimit($key, self::DEFAULTS[$key] ?? 0, $plan->limits);
    }

    /**
     * @return string[]
     */
    private function allowedKinds(): array
    {
        $configured = $this->planOpt('allowed_kinds', PlanStep::KINDS);

        if (!is_array($configured)) {
            return PlanStep::KINDS;
        }

        $allowed = array_values(array_intersect(array_map('strval', $configured), PlanStep::KINDS));

        return $allowed === [] ? PlanStep::KINDS : $allowed;
    }

    /**
     * @return string[]
     */
    private function denyPatterns(): array
    {
        $configured = $this->planOpt('deny_capabilities', []);

        if (!is_array($configured)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn(mixed $pattern): string => trim((string) $pattern),
            $configured
        ), static fn(string $pattern): bool => $pattern !== ''));
    }

    /**
     * @param string[] $patterns
     */
    private function denied(string $target, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if ($pattern === $target) {
                return true;
            }

            if (str_contains($pattern, '*')) {
                $regex = '/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/';

                if (preg_match($regex, $target) === 1) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return array{step:string, field:string, code:string, message:string}
     */
    private function error(string $step, string $field, string $code, string $message): array
    {
        return ['step' => $step, 'field' => $field, 'code' => $code, 'message' => $message];
    }

    /**
     * @return array{code:string, message:string}
     */
    private function note(string $code, string $message): array
    {
        return ['code' => $code, 'message' => $message];
    }

}
