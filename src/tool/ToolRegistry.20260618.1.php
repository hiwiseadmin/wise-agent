<?php
declare(strict_types=1);

namespace wise\agent\Tool;

use think\facade\Event;
use wise\agent\Contract\ToolInterface;

/**
 * Tool registry - centralized tool management
 *
 * Manages all registered tools. Plugins can register custom tools
 * via events (AiToolRegister) or directly.
 *
 * NOTE on AiToolRegister event: The event fires only once — on the first
 * call to instance(). If plugins register after ToolRegistry has already
 * been instantiated, they must call register() directly rather than
 * relying on the AiToolRegister event.
 *
 * Usage:
 *   ToolRegistry::instance()->register($myTool);
 *   ToolRegistry::instance()->getToolSchemas(); // For Function Calling
 */
class ToolRegistry
{
    protected static ?ToolRegistry $instance = null;

    /** @var array<string, ToolInterface|callable|array> */
    protected array $tools = [];

    /**
     * Get singleton instance
     *
     * NOTE: AiToolRegister event fires only on first instantiation.
     * Late-bound plugins should register tools directly via register().
     */
    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
            // Fire event for plugins to register tools (fires once)
            Event::trigger('AiToolRegister', self::$instance);
        }
        return self::$instance;
    }

    /**
     * Register a tool
     *
     * @param ToolInterface|callable|array $tool Tool instance or definition
     * @param string|null                  $name Optional tool name override
     */
    public function register(ToolInterface|callable|array $tool, ?string $name = null): void
    {
        if ($tool instanceof ToolInterface) {
            $this->tools[$name ?? $tool->getName()] = $tool;
        } elseif (is_callable($tool)) {
            // Use provided name, or if $tool is a string function name, use it as key
            // Otherwise generate a hash-based key for anonymous callables
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
     * Register a tool by name and class
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
     * Get a tool by name
     */
    public function get(string $name): ToolInterface|callable|array|null
    {
        return $this->tools[$name] ?? null;
    }

    /**
     * Check if a tool exists
     */
    public function has(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    /**
     * Execute a tool by name
     *
     * @param string $name      Tool name
     * @param array  $arguments Arguments to pass
     * @return string Result
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
     * Get all tool schemas for Function Calling
     *
     * @param string[]|null $toolNames Specific tools to include (null = all)
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
                // Callable tool with a meaningful name (not a hash) — include with minimal schema
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
     * List all tool names
     */
    public function list(): array
    {
        return array_keys($this->tools);
    }

    /**
     * Remove a tool
     */
    public function unregister(string $name): void
    {
        unset($this->tools[$name]);
    }

    /**
     * Get tool count
     */
    public function count(): int
    {
        return count($this->tools);
    }

    /**
     * Normalize a tool to ToolInterface
     *
     * Wraps callable and array-type tools into a ToolInterface adapter
     * for downstream consumers that expect a ToolInterface contract.
     *
     * @param string $name Tool name as registered
     * @return ToolInterface|null Null if tool not found or not normalizable
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

        // Wrap callable/array into an anonymous ToolInterface adapter
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
     * Reset the singleton instance (for long-running processes)
     *
     * Call this at the end of each request in persistent environments
     * (e.g., Swoole, Workerman) to prevent state leakage between requests.
     */
    public static function reset(): void
    {
        self::$instance = null;
    }
}
