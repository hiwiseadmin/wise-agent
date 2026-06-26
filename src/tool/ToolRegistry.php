<?php
declare(strict_types=1);

namespace wise\agent\tool;

use think\facade\Event;
use wise\agent\contract\ToolInterface;

/**
 * 工具注册表 - 集中式工具管理
 *
 * 管理所有已注册的工具。插件可以通过事件（AiToolRegister）
 * 或直接注册自定义工具。
 *
 * 关于 AiToolRegister 事件的说明：该事件仅触发一次 — 在
 * 首次调用 instance() 时。如果插件在 ToolRegistry 已经
 * 被实例化之后才注册，则必须直接调用 register()，
 * 而不能依赖 AiToolRegister 事件。
 *
 * 用法：
 *   ToolRegistry::instance()->register($myTool);
 *   ToolRegistry::instance()->getToolSchemas(); // 用于 Function Calling
 */
class ToolRegistry
{
    protected static ?ToolRegistry $instance = null;

    /** @var array<string, ToolInterface|callable|array> */
    protected array $tools = [];

    /**
     * 获取单例实例
     *
     * 注意：AiToolRegister 事件仅在首次实例化时触发。
     * 延迟绑定的插件应通过 register() 直接注册工具。
     */
    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
            // 触发事件供插件注册工具（仅触发一次）
            Event::trigger('AiToolRegister', self::$instance);
        }
        return self::$instance;
    }

    /**
     * 注册一个工具
     *
     * @param ToolInterface|callable|array $tool 工具实例或定义
     * @param string|null                  $name 可选，工具名称覆盖
     */
    public function register(ToolInterface|callable|array $tool, ?string $name = null): void
    {
        if ($tool instanceof ToolInterface) {
            $this->tools[$name ?? $tool->getName()] = $tool;
        } elseif (is_callable($tool)) {
            // 使用提供的名称，或如果 $tool 是字符串函数名，则将其作为键
            // 否则为匿名可调用对象生成基于哈希的键
            if ($name !== null) {
                $key = $name;
            } elseif (is_string($tool)) {
                $key = $tool;
            } else {
                $key = spl_object_hash($tool instanceof \Closure ? $tool : \Closure::fromCallable($tool));
            }
            $this->tools[$key] = $tool;
        } elseif (is_array($tool) && isset($tool['name'])) {
            $this->tools[$name ?? $tool['name']] = $tool;
        }
    }

    /**
     * 按名称和类注册一个工具
     */
    public function registerClass(string $toolClass): void
    {
        if (!class_exists($toolClass)) {
            return;
        }
        $instance = new $toolClass();
        if ($instance instanceof ToolInterface) {
            $this->register($instance);
        }
    }

    /**
     * 按名称获取一个工具
     */
    public function get(string $name): ToolInterface|callable|array|null
    {
        return $this->tools[$name] ?? null;
    }

    /**
     * 检查工具是否存在
     */
    public function has(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    /**
     * 按名称执行一个工具
     *
     * @param string $name      工具名称
     * @param array  $arguments 要传递的参数
     * @return string 结果
     */
    public function execute(string $name, array $arguments): string
    {
        $tool = $this->get($name);

        if ($tool === null) {
            return json_encode(['error' => "Tool not found: {$name}"]);
        }

        try {
            if ($tool instanceof ToolInterface) {
                return $tool->execute($arguments);
            } elseif (is_callable($tool)) {
                return (string) call_user_func($tool, $arguments);
            } elseif (is_array($tool) && isset($tool['handler'])) {
                return (string) call_user_func($tool['handler'], $arguments);
            }
            return json_encode(['error' => "Tool {$name} is not executable"]);
        } catch (\Throwable $e) {
            return json_encode(['error' => $e->getMessage()]);
        }
    }

    /**
     * 获取用于 Function Calling 的所有工具 schema
     *
     * @param string[]|null $toolNames 要包含的特定工具（null = 全部）
     */
    public function getToolSchemas(?array $toolNames = null): array
    {
        $schemas = [];
        $tools = $toolNames !== null ? array_intersect_key($this->tools, array_flip($toolNames)) : $this->tools;

        foreach ($tools as $name => $tool) {
            if ($tool instanceof ToolInterface) {
                $schemas[] = [
                    'type'     => 'function',
                    'function' => [
                        'name'        => $tool->getName(),
                        'description' => $tool->getDescription(),
                        'parameters'  => $tool->getParameters(),
                    ],
                ];
            } elseif (is_array($tool)) {
                $schemas[] = [
                    'type'     => 'function',
                    'function' => [
                        'name'        => $tool['name'] ?? $name,
                        'description' => $tool['description'] ?? '',
                        'parameters'  => $tool['parameters'] ?? ['type' => 'object', 'properties' => []],
                    ],
                ];
            } elseif (is_callable($tool) && is_string($name) && !ctype_xdigit($name)) {
                // 具有有意义名称（非哈希值）的可调用工具 — 以最小 schema 包含
                $schemas[] = [
                    'type'     => 'function',
                    'function' => [
                        'name'        => $name,
                        'description' => 'Callable tool: ' . $name,
                        'parameters'  => ['type' => 'object', 'properties' => []],
                    ],
                ];
            }
        }

        return $schemas;
    }

    /**
     * 列出所有工具名称
     */
    public function list(): array
    {
        return array_keys($this->tools);
    }

    /**
     * 移除一个工具
     */
    public function unregister(string $name): void
    {
        unset($this->tools[$name]);
    }

    /**
     * 获取工具数量
     */
    public function count(): int
    {
        return count($this->tools);
    }

    /**
     * 将工具规范化为 ToolInterface
     *
     * 将可调用和数组类型的工具包装成 ToolInterface 适配器，
     * 供期望 ToolInterface 契约的下游消费者使用。
     *
     * @param string $name 已注册的工具名称
     * @return ToolInterface|null 若工具未找到或无法规范化，则返回 null
     */
    public function normalize(string $name): ?ToolInterface
    {
        $tool = $this->get($name);

        if ($tool === null) {
            return null;
        }

        if ($tool instanceof ToolInterface) {
            return $tool;
        }

        // 将 callable/array 包装为匿名 ToolInterface 适配器
        return new class($name, $tool) implements ToolInterface {
            private string $name;
            private $handler;

            public function __construct(string $name, $handler)
            {
                $this->name = $name;
                $this->handler = $handler;
            }

            public function getName(): string
            {
                return $this->name;
            }

            public function getDescription(): string
            {
                return 'Dynamic tool: ' . $this->name;
            }

            public function getParameters(): array
            {
                return ['type' => 'object', 'properties' => []];
            }

            public function execute(array $arguments): string
            {
                if (is_callable($this->handler)) {
                    return (string) call_user_func($this->handler, $arguments);
                }
                if (is_array($this->handler) && isset($this->handler['handler'])) {
                    return (string) call_user_func($this->handler['handler'], $arguments);
                }
                return json_encode(['error' => 'Tool handler is not callable']);
            }

            public function getPermission(): string
            {
                return '';
            }

            public function requireConfirmation(): bool
            {
                return false;
            }
        };
    }

    /**
     * 重置单例实例（用于长运行进程）
     *
     * 在持久化环境（如 Swoole、Workerman）中，在每个请求结束时
     * 调用此方法，以防止请求之间的状态泄漏。
     */
    public static function reset(): void
    {
        self::$instance = null;
    }
}
