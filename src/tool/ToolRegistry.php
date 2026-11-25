<?php
declare(strict_types=1);

namespace wise\agent\tool;

use think\facade\Event;
use wise\agent\contract\ToolInterface;
use wise\support\context\ContextualLogger;

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

    /** 优先级：显式声明（插件 / provider 主动注册，默认） */
    public const PRIORITY_EXPLICIT = 100;

    /** 优先级：自动生成（schema 内省产出的 CRUD 工具），不得覆盖显式声明 */
    public const PRIORITY_AUTO = 900;

    /** @var array<string, ToolInterface|callable|array> */
    protected array $tools = [];

    /** @var array<string, string> 工具名 → 命名空间 映射 */
    protected array $toolNamespaces = [];

    /** @var array<string, int> 工具名 → 注册优先级（数值越小越优先） */
    protected array $priorities = [];

    /** @var array<string, array{0: ToolInterface|callable|array, 1: string}> 已降级工具（name => [tool, namespace]） */
    protected array $degraded = [];

    /**
     * 隐藏工具（仍可被 get()/execute() 解析，但**不下发给 LLM**）
     *
     * 用途：两段式控制台工具面（`console.two_stage`）——具体命令工具需要留在注册表里，
     * 让 ToolSecurityCheck 能按 `ai.cmd.{name}` 做权限/审批校验；但 schema 不下发，
     * 由 cmd_search / cmd_run 按需检索与执行，从而把每步的 prompt 从数 k token 降下来。
     *
     * @var array<string, true>
     */
    protected array $hidden = [];

    /**
     * 注册键 → Function Calling 下发名（wire name）映射
     *
     * 注册键含命名空间前缀（`plugin:demo.list_users`、`mcp:server.tool`）时字符集
     * 不满足 OpenAI / DeepSeek 的 function name 规范 `^[a-zA-Z0-9_-]+$`，
     * 直接下发会被 provider 以 400 拒绝。这里做一次稳定映射，由
     * {@see self::resolveName()} 在执行时还原为注册键。
     *
     * @var array<string, string>
     */
    protected array $wireNames = [];

    /** @var array<string, string> 下发名 → 注册键（wire name 反查表） */
    protected array $wireToKey = [];

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
            try {
                Event::trigger('AiToolRegister', self::$instance);
            } catch (\Throwable $e) {
            }
        }
        return self::$instance;
    }

    /**
     * 创建新的独立实例（不使用单例）
     *
     * 用于需要工具隔离的场景，如 Skill 执行时创建独立 ToolRegistry。
     * 新实例不会触发 AiToolRegister 事件，需手动注册工具。
     *
     * @param array $presetTools 预注册工具 [name => ToolInterface]
     */
    public static function newInstance(array $presetTools = []): self
    {
        $instance = new self();
        foreach ($presetTools as $name => $tool) {
            $instance->register($tool, $name);
        }
        return $instance;
    }

    /**
     * 注册一个工具（支持命名空间）
     *
     * 当提供 $namespace 时，工具将以 namespacedName 注册，
     * 格式：'{namespace}.{name}'
     *
     * @param ToolInterface|callable|array $tool       工具实例或定义
     * @param string|null                  $name       可选，工具名称覆盖
     * @param string                       $namespace  命名空间（默认 'builtin'）
     */
    public function register(
        ToolInterface|callable|array $tool,
        ?string $name = null,
        string $namespace = 'builtin',
        int $priority = self::PRIORITY_EXPLICIT
    ): void {
        if ($tool instanceof ToolInterface) {
            $baseName = $name ?? $tool->getName();
            $fullName = $namespace === 'builtin' ? $baseName : $namespace . '.' . $baseName;
            if (!$this->claim($fullName, $priority)) {
                return;
            }
            $this->tools[$fullName] = $tool;
            $this->toolNamespaces[$fullName] = $namespace;
        } elseif (is_callable($tool)) {
            if ($name !== null) {
                $key = $namespace === 'builtin' ? $name : $namespace . '.' . $name;
            } elseif (is_string($tool)) {
                $key = $namespace === 'builtin' ? $tool : $namespace . '.' . $tool;
            } else {
                $key = spl_object_hash($tool instanceof \Closure ? $tool : \Closure::fromCallable($tool));
            }
            if (!$this->claim($key, $priority)) {
                return;
            }
            $this->tools[$key] = $tool;
            $this->toolNamespaces[$key] = $namespace;
        } elseif (is_array($tool) && isset($tool['name'])) {
            $baseName = $tool['name'];
            $fullName = $namespace === 'builtin' ? $baseName : $namespace . '.' . $baseName;
            $key      = $name ?? $fullName;
            if (!$this->claim($key, $priority)) {
                return;
            }
            $this->tools[$key] = $tool;
            $this->toolNamespaces[$key] = $namespace;
        }
    }

    /**
     * 抢占注册名（冲突时按优先级裁决）
     *
     * 背景（B3-5）：改造前同名即静默覆盖，覆盖顺序完全取决于调用顺序，
     * 于是"自动内省生成的 CRUD 工具"可能悄悄顶掉"插件显式声明的工具"。
     *
     * 规则：数值越小优先级越高；显式声明（100）高于自动生成（900）。
     * 冲突时**保留高优先级者**，并记录被忽略的一方，便于排查。
     *
     * @return bool true = 允许写入；false = 已存在更高优先级实现，本次忽略
     */
    protected function claim(string $key, int $priority): bool
    {
        if (!isset($this->tools[$key])) {
            $this->priorities[$key] = $priority;
            return true;
        }

        $existing = $this->priorities[$key] ?? self::PRIORITY_EXPLICIT;

        if ($priority < $existing) {
            $this->log('info', '同名工具被更高优先级实现覆盖', [
                'tool' => $key,
                'from' => $existing,
                'to'   => $priority,
            ]);

            $this->priorities[$key] = $priority;
            return true;
        }

        $this->log('warning', '忽略低优先级的同名工具注册', [
            'tool'      => $key,
            'existing'  => $existing,
            'attempted' => $priority,
        ]);

        return false;
    }

    /**
     * 安全写日志（无容器环境如单测下静默跳过）
     *
     * @param array<string, mixed> $context
     */
    protected function log(string $level, string $message, array $context = []): void
    {
        try {
            $level === 'info'
                ? ContextualLogger::info($message, $context)
                : ContextualLogger::warning($message, $context);
        } catch (\Throwable) {
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
     *
     * 名称可以是注册键，也可以是下发给 LLM 的 wire name（`plugin_demo_list_users`），
     * 后者会通过映射还原为注册键，保证 LLM 回调能命中真实工具。
     */
    public function get(string $name): ToolInterface|callable|array|null
    {
        $key = $this->wireToKey[$name] ?? $name;

        return $this->tools[$key] ?? null;
    }

    /**
     * 检查工具是否存在（注册键或 wire name 均可）
     */
    public function has(string $name): bool
    {
        return $this->get($name) !== null;
    }

    /**
     * 注册键 → Function Calling 下发名
     *
     * OpenAI / DeepSeek 要求 function name 匹配 `^[a-zA-Z0-9_-]+$`，
     * 而带命名空间的注册键（`plugin:demo.list_users`）含 `:` / `.`，
     * 直接下发会被 provider 以 400 拒绝。这里做稳定映射：
     *   - 合规名原样返回；
     *   - 非法字符折叠为 `_`；
     *   - 折叠后冲突时追加 `_2`、`_3` 后缀，保证一一对应。
     */
    public function wireName(string $key): string
    {
        if (isset($this->wireNames[$key])) {
            return $this->wireNames[$key];
        }

        if (preg_match('/^[a-zA-Z0-9_-]+$/', $key) === 1) {
            $wire = $key;
        } else {
            $wire = (string) preg_replace('/[^a-zA-Z0-9_-]+/', '_', $key);
            $wire = trim($wire, '_');
            if ($wire === '') {
                $wire = 'tool_' . substr(md5($key), 0, 8);
            }
        }

        $base = $wire;
        $seq  = 2;
        while (isset($this->wireToKey[$wire]) && $this->wireToKey[$wire] !== $key) {
            $wire = $base . '_' . $seq++;
        }

        $this->wireNames[$key]  = $wire;
        $this->wireToKey[$wire] = $key;

        return $wire;
    }

    /**
     * 下发名 → 注册键（未知名称原样返回）
     */
    public function resolveName(string $name): string
    {
        return $this->wireToKey[$name] ?? $name;
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
            if (isset($this->hidden[$name])) {
                continue;
            }

            if ($tool instanceof ToolInterface) {
                $schemas[] = [
                    'type'     => 'function',
                    'function' => [
                        'name'        => $this->wireName((string) $name),
                        'description' => $tool->getDescription(),
                        'parameters'  => $tool->getParameters(),
                    ],
                ];
            } elseif (is_array($tool)) {
                $schemas[] = [
                    'type'     => 'function',
                    'function' => [
                        'name'        => $this->wireName((string) $name),
                        'description' => $tool['description'] ?? '',
                        'parameters'  => $tool['parameters'] ?? ['type' => 'object', 'properties' => []],
                    ],
                ];
            } elseif (is_callable($tool) && is_string($name) && !ctype_xdigit($name)) {
                $schemas[] = [
                    'type'     => 'function',
                    'function' => [
                        'name'        => $this->wireName((string) $name),
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
     * 获取所有已注册工具（含名称和实例）
     *
     * @return array<string, ToolInterface|callable|array>
     */
    public function all(): array
    {
        return $this->tools;
    }

    /**
     * 移除一个工具（同时清理命名空间映射）
     */
    public function unregister(string $name): void
    {
        $key = $this->resolveName($name);

        unset($this->tools[$key]);
        unset($this->toolNamespaces[$key]);
        unset($this->hidden[$key]);

        if (isset($this->wireNames[$key])) {
            unset($this->wireToKey[$this->wireNames[$key]], $this->wireNames[$key]);
        }
    }

    /**
     * 隐藏一个工具：不参与 schema 下发，但仍可 get()/execute()
     *
     * 与 {@see self::degrade()} 的区别：
     *   - degrade：完全下线（tbl 移除 + 从 tools 删除），**安全层也查不到**；
     *   - hide   ：仅从「下发给 LLM 的清单」移除，注册表仍持有，
     *              因此 ToolSecurityCheck 仍能按该工具的权限标识做校验（两段式必需）。
     */
    public function hide(string $name): void
    {
        $key = $this->resolveName($name);

        if (isset($this->tools[$key])) {
            $this->hidden[$key] = true;
        }
    }

    /**
     * 取消隐藏
     */
    public function show(string $name): void
    {
        unset($this->hidden[$this->resolveName($name)]);
    }

    /**
     * 工具是否被隐藏（不下发但仍可执行）
     */
    public function isHidden(string $name): bool
    {
        return isset($this->hidden[$this->resolveName($name)]);
    }

    /**
     * 降级（下线）一个工具：从可见清单移除，但保留在 degraded 映射以便 restore＼�
     *
     * 不硬删除，保存原工具实例与命名空间。
     */
    public function degrade(string $name): bool
    {
        if (!isset($this->tools[$name])) {
            return false;
        }

        $this->degraded[$name] = [$this->tools[$name], $this->toolNamespaces[$name] ?? 'builtin'];
        $this->unregister($name);

        return true;
    }

    /**
     * 恢复一个被降级的工具（原命名空间一并恢复）
     */
    public function restore(string $name): bool
    {
        if (!isset($this->degraded[$name])) {
            return false;
        }

        [$tool, $namespace] = $this->degraded[$name];
        $this->tools[$name]            = $tool;
        $this->toolNamespaces[$name]   = $namespace;
        unset($this->degraded[$name]);

        return true;
    }

    /**
     * 工具是否处于降级（下线）状态
     */
    public function isDegraded(string $name): bool
    {
        return isset($this->degraded[$name]);
    }

    /**
     * 已降级工具名列表
     *
     * @return string[]
     */
    public function degraded(): array
    {
        return array_keys($this->degraded);
    }

    /**
     * 按命名空间获取所有工具名
     *
     * @param string $namespace 命名空间（如 'plugin:demo'）
     * @return string[] 工具全名列表
     */
    public function getByNamespace(string $namespace): array
    {
        $result = [];
        foreach ($this->toolNamespaces as $toolName => $ns) {
            if ($ns === $namespace) {
                $result[] = $toolName;
            }
        }
        return $result;
    }

    /**
     * 移除指定命名空间下的所有工具
     */
    public function removeNamespace(string $namespace): void
    {
        $toolsToRemove = $this->getByNamespace($namespace);
        foreach ($toolsToRemove as $toolName) {
            unset($this->tools[$toolName]);
            unset($this->toolNamespaces[$toolName]);
            unset($this->priorities[$toolName]);
        }
    }

    /**
     * 获取工具所属命名空间（未注册时返回空串）
     */
    public function getNamespace(string $name): string
    {
        return $this->toolNamespaces[$name] ?? '';
    }

    /**
     * 获取工具注册优先级（数值越小越优先）
     */
    public function getPriority(string $name): int
    {
        return $this->priorities[$name] ?? self::PRIORITY_EXPLICIT;
    }

    /**
     * 获取所有已注册的命名空间
     *
     * @return string[] 命名空间列表（去重）
     */
    public function getNamespaces(): array
    {
        return array_values(array_unique(array_values($this->toolNamespaces)));
    }

    /**
     * 按命名空间获取工具 schemas（用于 Function Calling）
     *
     * @param string $namespace 命名空间
     */
    public function getToolSchemasByNamespace(string $namespace): array
    {
        $schemas = [];
        $toolNames = $this->getByNamespace($namespace);

        foreach ($toolNames as $name) {
            $tool = $this->tools[$name];
            if ($tool instanceof ToolInterface) {
                $schemas[] = [
                    'type'     => 'function',
                    'function' => [
                        'name'        => (string) $name,
                        'description' => $tool->getDescription(),
                        'parameters'  => $tool->getParameters(),
                    ],
                ];
            }
        }

        return $schemas;
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

            public function getAccess(): string
            {
                return \wise\agent\security\CapabilityAccess::EXECUTE;
            }

            public function getRiskLevel(): string
            {
                return \wise\agent\security\CapabilityAccess::RISK_MEDIUM;
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
