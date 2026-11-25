<?php
declare(strict_types=1);

namespace wise\agent\plan;

/**
 * 计划能力查询＼�
 *
 * `PlanValidator` 需要三件事，全部从**注册表**读、不做任何缓存与推断：
 *
 * 1. 该能力**是否存在**（防「幻觉工具名」被当成成功）；
 * 2. 该能力的**参数名清单**（校验 `input` 的**键**位置；值位置不受限）；
 * 3. 该能力**是否写类**（由能力自身的 `access` 推导，**不接受 LLM 声明**）。
 *
 * 之所以抽成接口：① B1-1 可以完全离线单测（注入替身）；
 * ② 未来接入插件/Hub 能力源时只换实现，不动校验器。
 */
interface PlanCapabilityLookupInterface
{
    /**
     * 能力是否存在
     *
     * @param string $kind 见 {@see PlanStep}::KIND_*
     */
    public function exists(string $kind, string $target): bool;

    /**
     * 允许的参数名（**大小写敏感**，与能力 Schema 一致）
     *
     * `null` = 该能力的 Schema 不可得（如 Skill 未声明 `getInputSchema()`）——
     * 此时**跳过键校验**并记 note，而不是把合法参数误判成非法。
     *
     * @return string[]|null
     */
    public function argumentNames(string $kind, string $target): ?array;

    /**
     * 是否写类能力（`access ∈ {write, admin}` ⇒ true）
     */
    public function isWrite(string $kind, string $target): bool;
}
