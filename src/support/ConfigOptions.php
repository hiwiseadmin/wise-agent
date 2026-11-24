<?php
declare(strict_types=1);

namespace wise\agent\support;

/**
 * 子系统配置读取（B2-1 提取的**唯一**实现）
 *
 * 供各子系统（`plan` / `closure` / …）复用：原先每个子系统各抄一份 `opt()`，
 * 语义必须完全一致却很容易漂移（尤其「点号路径」那 12 行）。
 *
 * ## 三种来源的优先级
 *
 * 1. **构造注入的覆盖项**（如 `['audit' => ['via' => 'event']]`，支持点号路径）；
 * 2. `config($prefix . '.' . $key)`；
 * 3. 代码默认值。
 *
 * ⚠️ 点号路径必须**同时**支持「注入的嵌套数组」与「框架配置」，
 * 否则会出现「单测通过、上线不生效」这种极难排查的漂移（B1-2 实际踩到过）。
 *
 * 使用方式：`use ConfigOptions;` + 覆写 {@see self::configPrefix()}，或用各子系统的薄封装
 * （如 `wise\agent\plan\PlanOptions`），保持调用点语义清晰（`planOpt` / `closureOpt`）。
 */
trait ConfigOptions
{
    /** @var array<string, mixed> 构造注入的覆盖项 */
    private array $configOptions = [];

    /**
     * @param array<string, mixed> $options
     */
    protected function setConfigOptions(array $options): void
    {
        $this->configOptions = $options;
    }

    /**
     * @return array<string, mixed>
     */
    protected function configOptions(): array
    {
        return $this->configOptions;
    }

    /**
     * 配置前缀（如 `wise-agent.plan` / `wise-agent.closure`）
     *
     * 返回空串表示**不读框架配置**（只认注入项与默认值）—— 这让「不想依赖全局配置」的类
     * 也能安全地复用本 trait。
     */
    protected function configPrefix(): string
    {
        return '';
    }

    /**
     * 读配置（支持点号路径）
     */
    protected function opt(string $key, mixed $default): mixed
    {
        if (str_contains($key, '.')) {
            [$head, $tail] = explode('.', $key, 2);

            if (array_key_exists($head, $this->configOptions)
                && is_array($this->configOptions[$head])
                && array_key_exists($tail, $this->configOptions[$head])
            ) {
                return $this->configOptions[$head][$tail];
            }
        } elseif (array_key_exists($key, $this->configOptions)) {
            return $this->configOptions[$key];
        }

        $prefix = $this->configPrefix();

        if ($prefix === '' || !function_exists('config')) {
            return $default;
        }

        try {
            return config($prefix . '.' . $key, $default) ?? $default;
        } catch (\Throwable) {
            return $default;
        }
    }
}
