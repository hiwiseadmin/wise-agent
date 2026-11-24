<?php
declare(strict_types=1);

namespace wise\agent\closure;

/**
 * 连接器注册表＼�
 *
 * 刻意**不是单例**：`ClosureDispatcher` 自己持有实例（可注入），
 * 于是单测能拿到一个干净的注册表，而实例也能在装配时覆盖内置连接器。
 *
 * 注册按**名字**去重：同名重复注册以最后一次为准（便于实例覆盖内置实现），
 * 且 `names()` 保持**注册顺序**（配置里的 `scenes` 才是最终执行顺序，见 {@see ClosureRouter}）。
 */
class ClosureConnectorRegistry
{
    /** @var array<string, ClosureConnectorInterface> */
    private array $connectors = [];

    public function register(ClosureConnectorInterface $connector): void
    {
        $this->connectors[$connector->name()] = $connector;
    }

    /**
     * @param ClosureConnectorInterface[] $connectors
     */
    public function registerMany(array $connectors): void
    {
        foreach ($connectors as $connector) {
            if ($connector instanceof ClosureConnectorInterface) {
                $this->register($connector);
            }
        }
    }

    public function has(string $name): bool
    {
        return isset($this->connectors[$name]);
    }

    public function get(string $name): ?ClosureConnectorInterface
    {
        return $this->connectors[$name] ?? null;
    }

    /**
     * @return string[] 注册顺序
     */
    public function names(): array
    {
        return array_keys($this->connectors);
    }

    /**
     * @return array<string, ClosureConnectorInterface>
     */
    public function all(): array
    {
        return $this->connectors;
    }

    public function count(): int
    {
        return count($this->connectors);
    }
}
