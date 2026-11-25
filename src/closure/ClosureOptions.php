<?php
declare(strict_types=1);

namespace wise\agent\closure;

use wise\agent\support\ConfigOptions;

/**
 * 业务闭环的配置读取（`wise-agent.closure.*`，B2-1）
 *
 * 实现复用 {@see ConfigOptions}（与 `plan` 子系统**同一份**点号路径语义）；
 * 本 trait 固定前缀，并提供语义清晰的调用名。
 */
trait ClosureOptions
{
    use ConfigOptions;

    protected function configPrefix(): string
    {
        return 'wise-agent.closure';
    }

    /**
     * @param array<string, mixed> $options
     */
    protected function setClosureOptions(array $options): void
    {
        $this->setConfigOptions($options);
    }

    /**
     * @return array<string, mixed>
     */
    protected function closureOptions(): array
    {
        return $this->configOptions();
    }

    /**
     * 读闭环配置（支持点号路径，如 `audit.via`）
     */
    protected function closureOpt(string $key, mixed $default): mixed
    {
        return $this->opt($key, $default);
    }

    /**
     * 读一个数组型配置（非数组时回落默认值）
     *
     * @param mixed $default
     * @return array<mixed>
     */
    protected function closureArray(string $key, array $default = []): array
    {
        $value = $this->closureOpt($key, $default);

        return is_array($value) ? $value : $default;
    }

    /**
     * 读一个布尔型配置（`'0'` / `'false'` 也应判为 false）
     */
    protected function closureBool(string $key, bool $default): bool
    {
        $value = $this->closureOpt($key, $default);

        if (is_string($value)) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
        }

        return (bool) $value;
    }
}
