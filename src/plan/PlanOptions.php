<?php
declare(strict_types=1);

namespace wise\agent\plan;

use wise\agent\support\ConfigOptions;

/**
 * 计划子系统的配置读取（`wise-agent.plan.*`）
 *
 * 实现已下沉到 {@see ConfigOptions}（B2-1 提取，供 `closure` 等子系统共用）；
 * 本 trait 只是**薄封装**：固定配置前缀，并保留 B1 时代的调用名
 * （`setPlanOptions` / `planOptions` / `planOpt` / `planLimit`），于是 B1 各类的调用点无需改动。
 */
trait PlanOptions
{
    use ConfigOptions;

    protected function configPrefix(): string
    {
        return 'wise-agent.plan';
    }

    /**
     * @param array<string, mixed> $options
     */
    protected function setPlanOptions(array $options): void
    {
        $this->setConfigOptions($options);
    }

    /**
     * @return array<string, mixed>
     */
    protected function planOptions(): array
    {
        return $this->configOptions();
    }

    /**
     * 读计划配置（支持点号路径）
     */
    protected function planOpt(string $key, mixed $default): mixed
    {
        return $this->opt($key, $default);
    }

    /**
     * 生效上限 = `min(配置上限, 计划声明)` —— **计划只能收紧**
     *
     * @param array<string, mixed> $limits 计划自报的 limits
     */
    protected function planLimit(string $key, int $default, array $limits = []): int
    {
        $configured = (int) $this->planOpt($key, $default);
        $declared   = $limits[$key] ?? null;

        if ($declared === null) {
            return max(0, $configured);
        }

        return max(0, min($configured, (int) $declared));
    }
}
