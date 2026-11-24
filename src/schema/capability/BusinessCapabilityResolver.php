<?php
declare(strict_types=1);

namespace wise\agent\schema\capability;

use wise\agent\schema\dto\PluginSchemaDTO;

/**
 * 业务能力解析器
 *
 * 将 manifest.json 中的 business_capabilities 标签翻译为：
 *   - System Prompt 上下文（领域知识）
 *   - 专用 AI Tools
 *   - 推荐 Skills
 *   - 推荐 Pipelines
 *
 * 支持静态注册和配置文件动态加载两种方式。
 *
 * 使用示例：
 *   // 注册能力
 *   BusinessCapabilityResolver::register('inventory', new InventoryCapability());
 *
 *   // 解析能力
 *   $result = BusinessCapabilityResolver::resolve('inventory', $schema);
 *   // $result->contextPrompt  → 注入 Agent system prompt
 *   // $result->tools          → 注册到 ToolRegistry
 *   // $result->skills         → 推荐给 SkillManager
 *   // $result->pipelines      → 注册到 PipelineRegistry
 *
 *   // 批量解析
 *   foreach (['inventory', 'sales'] as $tag) {
 *       $results[] = BusinessCapabilityResolver::resolve($tag, $schema);
 *   }
 */
class BusinessCapabilityResolver
{
    /** @var array<string, BusinessCapability> 已注册的能力定义 */
    private static $capabilities = [];

    /** @var bool 是否已初始化内置能力 */
    private static $builtinInitialized = false;

    /**
     * 注册业务能力定义
     *
     * @param string $tag 能力标签（如 inventory、sales）
     * @param BusinessCapability $capability 能力定义
     */
    public static function register(string $tag, BusinessCapability $capability): void
    {
        self::$capabilities[$tag] = $capability;
    }

    /**
     * 解析能力标签，返回注入内容
     *
     * @param string $tag 能力标签
     * @param PluginSchemaDTO $schema 插件 Schema
     * @return CapabilityResult
     */
    public static function resolve(string $tag, PluginSchemaDTO $schema): CapabilityResult
    {
        if (!self::$builtinInitialized) {
            self::initBuiltin();
        }

        $capability = self::$capabilities[$tag] ?? null;

        if ($capability === null) {
            $capability = self::loadFromConfig($tag);
        }

        if ($capability === null) {
            return new CapabilityResult('', [], [], [], $tag);
        }

        return $capability->apply($schema);
    }

    /**
     * 批量解析多个能力标签
     *
     * @param string[] $tags
     * @param PluginSchemaDTO $schema
     * @return CapabilityResult[]
     */
    public static function resolveMany(array $tags, PluginSchemaDTO $schema): array
    {
        $results = [];
        foreach ($tags as $tag) {
            $results[$tag] = self::resolve($tag, $schema);
        }
        return $results;
    }

    /**
     * 检查是否有某个能力
     *
     * @param string $tag
     * @return bool
     */
    public static function has(string $tag): bool
    {
        if (!self::$builtinInitialized) {
            self::initBuiltin();
        }
        return isset(self::$capabilities[$tag]);
    }

    /**
     * 获取所有已注册的能力标签
     *
     * @return string[]
     */
    public static function getRegisteredTags(): array
    {
        if (!self::$builtinInitialized) {
            self::initBuiltin();
        }
        return array_keys(self::$capabilities);
    }

    /**
     * 获取某个能力的定义实例
     *
     * @param string $tag
     * @return BusinessCapability|null
     */
    public static function get(string $tag): ?BusinessCapability
    {
        if (!self::$builtinInitialized) {
            self::initBuiltin();
        }
        return self::$capabilities[$tag] ?? null;
    }

    /**
     * 初始化内置业务能力
     *
     * 自动扫描 builtin/ 目录下所有实现了 BusinessCapability 的类，
     * 并按类中 getTag() 返回值自动注册。无需手动维护类名清单。
     *
     * 如果 builtin/ 目录不可用（如生产环境无文件系统访问），
     * 回退到手动注册列表。
     */
    public static function initBuiltin(): void
    {
        if (self::$builtinInitialized) {
            return;
        }

        $scanned = self::scanAndRegisterBuiltin();

        if (!$scanned) {
            $builtinClasses = [
                'inventory' => \wise\agent\schema\capability\builtin\InventoryCapability::class,
                'sales'     => \wise\agent\schema\capability\builtin\SalesCapability::class,
                'crm'       => \wise\agent\schema\capability\builtin\CrmCapability::class,
                'content'   => \wise\agent\schema\capability\builtin\ContentCapability::class,
            ];

            foreach ($builtinClasses as $tag => $class) {
                if (class_exists($class) && !isset(self::$capabilities[$tag])) {
                    self::register($tag, new $class());
                }
            }
        }

        self::$builtinInitialized = true;
    }

    /**
     * 自动扫描 builtin/ 目录并注册所有 BusinessCapability 实现
     *
     * 扫描 `src/schema/capability/builtin/*.php`，
     * 实例化所有继承 BusinessCapability 的类，
     * 按 getTag() 返回值自动注册。
     *
     * @return bool 是否成功扫描（true = 找到了至少一个类）
     */
    private static function scanAndRegisterBuiltin(): bool
    {
        $builtinDir = __DIR__ . DIRECTORY_SEPARATOR . 'builtin';

        if (!is_dir($builtinDir)) {
            return false;
        }

        $files = glob($builtinDir . DIRECTORY_SEPARATOR . '*.php') ?: [];
        $found = false;

        foreach ($files as $file) {
            $className = self::getClassFromFile($file);
            if ($className === null) {
                continue;
            }

            if (!class_exists($className)) {
                continue;
            }

            try {
                $reflection = new \ReflectionClass($className);
                if ($reflection->isAbstract() || !$reflection->isSubclassOf(BusinessCapability::class)) {
                    continue;
                }

                $instance = $reflection->newInstance();
                $tag = $instance->getTag();

                if (!empty($tag) && !isset(self::$capabilities[$tag])) {
                    self::register($tag, $instance);
                    $found = true;
                }
            } catch (\Throwable $e) {
            }
        }

        return $found;
    }

    /**
     * 从 PHP 文件名推断完全限定类名
     *
     * @param string $file 文件路径
     * @return string|null
     */
    private static function getClassFromFile(string $file): ?string
    {
        $basename = basename($file, '.php');

        $namespace = __NAMESPACE__ . '\\builtin';
        return $namespace . '\\' . $basename;
    }

    /**
     * 从配置文件动态加载能力
     *
     * @param string $tag
     * @return BusinessCapability|null
     */
    private static function loadFromConfig(string $tag): ?BusinessCapability
    {
        if (!function_exists('config')) {
            return null;
        }

        $capabilities = config('wise-agent.schema.capabilities');
        if (!is_array($capabilities) || !isset($capabilities[$tag])) {
            return null;
        }

        $class = $capabilities[$tag];
        if (is_string($class) && class_exists($class)) {
            $instance = new $class();
            if ($instance instanceof BusinessCapability) {
                self::register($tag, $instance);
                return $instance;
            }
        }

        return null;
    }

    /**
     * 重置所有注册（用于测试）
     */
    public static function reset(): void
    {
        self::$capabilities = [];
        self::$builtinInitialized = false;
    }
}
