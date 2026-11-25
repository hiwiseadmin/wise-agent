<?php
declare(strict_types=1);

namespace wise\agent\closure;

use wise\agent\closure\connector\AuditConnector;

/**
 * 连接器装配＼�
 *
 * 按 `closure.connectors` 把**可用**的内置连接器装进 {@see ClosureConnectorRegistry}。
 *
 * ## ⚠️ 设计更正（B2-2 实施期）：不再造「永远 skipped 的骨架类」
 *
 * 实施时否掉了这个做法，理由是它与 **A2 的既有决定**冲突：A2 明确拒绝
 * 「开关开了却没有实现」的假能力（`FormSchemaToolProvider` 只注册**已实现**的工具）。
 * 一个 `dispatch()` 永远返回"尚未实现"的类，只会让运维看到一堆 `skipped` 而猜不出原因。
 *
 * 改为：**未实现的连接器不注册**，投放时由 `ClosureDispatcher` 记
 * `skipped(connector_not_registered)`，本工厂再用 {@see self::unavailable()} 把
 * 「配置要求了谁、为什么没装上」讲清楚。于是：
 *
 * - 行为是**可见的**（投递行里写明 `connector_not_registered`）；
 * - 不存在"假装能用"的类；
 * - B2-3 落地后只要 `class_exists` 为真，这里**一行都不用改**。
 *
 * ## 自定义连接器
 *
 * `closure.connectors` 里的项可以是**类名**（含 `\`）：需实现 {@see ClosureConnectorInterface}
 * 且遵循构造约定 `__construct(array $options = [], ?PayloadMasker $masker = null)`
 * （内置连接器同一约定）。
 */
final class ClosureConnectorFactory
{
    use ClosureOptions;

    /**
     * 内置连接器：配置名 => 类名
     *
     * `notification` / `webhook` 的类由 **B2-3** 提供；在此之前它们不会出现在
     * {@see self::unavailable()} 之外的地方。
     */
    public const BUILTIN = [
        'notification' => 'wise\agent\closure\connector\NotificationConnector',
        'webhook'      => 'wise\agent\closure\connector\WebhookConnector',
        'audit'        => AuditConnector::class,
    ];

    /**
     * 默认启用清单＼�
     *
     * @var string[]
     */
    public const DEFAULT_CONNECTORS = ['notification', 'webhook', 'audit'];

    private ?PayloadMasker $masker;

    private ?ClosureConnectorRegistry $registry = null;

    /** @var array<int, array{connector:string, reason:string}> */
    private array $unavailable = [];

    /**
     * @param array<string, mixed> $options `closure.*` 覆盖项
     */
    public function __construct(array $options = [], ?PayloadMasker $masker = null)
    {
        $this->setClosureOptions($options);
        $this->masker = $masker;
    }

    /**
     * 装配后的注册表（进程内缓存，避免重复实例化）
     */
    public function registry(): ClosureConnectorRegistry
    {
        if ($this->registry !== null) {
            return $this->registry;
        }

        $this->registry    = new ClosureConnectorRegistry();
        $this->unavailable = [];

        foreach ($this->configuredNames() as $name) {
            $class = $this->classFor($name);

            if ($class === null) {
                $this->unavailable[] = [
                    'connector' => $name,
                    'reason'    => '未知连接器名（既不在内置清单 ' . implode('/', array_keys(self::BUILTIN)) . '，也不是类名）',
                ];

                continue;
            }

            if (!class_exists($class)) {
                $this->unavailable[] = [
                    'connector' => $name,
                    'reason'    => '连接器类不存在：' . $class . '（内置实现尚未提供）',
                ];

                continue;
            }

            if (!is_subclass_of($class, ClosureConnectorInterface::class)) {
                $this->unavailable[] = [
                    'connector' => $name,
                    'reason'    => '连接器类未实现 ' . ClosureConnectorInterface::class . '：' . $class,
                ];

                continue;
            }

            $this->registry->register(new $class($this->closureOptions(), $this->masker()));
        }

        return $this->registry;
    }

    /**
     * 配置要求但**没能装上**的连接器（配置 / discover / 运维提示用）
     *
     * @return array<int, array{connector:string, reason:string}>
     */
    public function unavailable(): array
    {
        $this->registry();

        return $this->unavailable;
    }

    /**
     * 实际装上的连接器名
     *
     * @return string[]
     */
    public function names(): array
    {
        return $this->registry()->names();
    }

    /**
     * @return string[]
     */
    private function configuredNames(): array
    {
        $configured = $this->closureArray('connectors', self::DEFAULT_CONNECTORS);
        $names      = [];

        foreach ($configured as $name) {
            $name = trim((string) $name);

            if ($name !== '' && !in_array($name, $names, true)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    private function classFor(string $name): ?string
    {
        if (isset(self::BUILTIN[$name])) {
            return self::BUILTIN[$name];
        }

        return str_contains($name, '\\') ? $name : null;
    }

    private function masker(): PayloadMasker
    {
        return $this->masker ??= PayloadMasker::fromOptions($this->closureOptions());
    }
}
