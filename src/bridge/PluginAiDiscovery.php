<?php
declare(strict_types=1);

namespace wise\agent\bridge;

use think\App;
use wise\agent\contract\AdapterProviderInterface;
use wise\agent\contract\NodeProviderInterface;
use wise\agent\contract\ToolInterface;
use wise\agent\contract\ToolProviderInterface;
use wise\agent\schema\SchemaIntrospector;
use wise\agent\schema\discovery\PluginSchemaDiscovery;
use wise\agent\skill\SkillRegistry;
use wise\agent\skill\parser\MarkdownSkill;

/**
 * 插件 AI 能力发现
 *
 * 扫描标准插件（通过 manifest.json）与 Composer 包
 * （通过 composer.json 的 extra.think-ai）中声明的 AI 能力提供者。
 *
 * 已注册的提供者类型：
 *   - ToolProviderInterface    -> ToolRegistry（Function Calling 工具）
 *   - NodeProviderInterface    -> NodeRegistry（工作流流水线节点）
 *   - AdapterProviderInterface -> AdapterRegistry（自定义模型适配器）
 *
 * 缓存策略：
 *   - 首次请求：扫描文件系统、实例化提供者并注册，
 *     随后将「命名空间 -> 类名」映射持久化到 ProviderCache。
 *   - 后续请求：直接从缓存恢复提供者，不再扫描文件系统。
 *   - 缓存失效：PluginInstall/PluginUninstall 事件会置位标记；
 *     手动执行 "wise:agent:discover" 可强制刷新。
 */
class PluginAiDiscovery
{
    protected App $app;
    protected ProviderCache $cache;

    /**
     * 发现结果统计
     *
     * @var array{tools: int, nodes: int, adapters: int, actions: int, triggers: int, schema_plugins: int, errors: string[]}
     */
    protected array $stats = ['tools' => 0, 'nodes' => 0, 'adapters' => 0, 'actions' => 0, 'triggers' => 0, 'schema_plugins' => 0, 'errors' => []];

    /**
     * 「命名空间 -> ai_actions 配置」映射，随 ProviderCache 持久化。
     *
     * @var array<string, array>
     */
    protected array $discoveredActions = [];

    /**
     * 「命名空间 -> ai_triggers 配置」映射，随 ProviderCache 持久化。
     *
     * @var array<string, array>
     */
    protected array $discoveredTriggers = [];

    /**
     * 发现过程中收集的「命名空间 -> 提供者类名」映射。
     * 会持久化到 ProviderCache，供 loadFromCache() 恢复时使用。
     *
     * @var array<string, array{tool_providers: string[], node_providers: string[], adapter_providers: string[], schema_plugins: string[]}>
     */
    protected array $discoveredNamespaces = [];

    /** @var SchemaIntrospector|null */
    protected ?SchemaIntrospector $schemaIntrospector = null;

    public function __construct(App $app)
    {
        $this->app   = $app;
        $this->cache = new ProviderCache($app);
    }

    /**
     * 注入 Schema 内省器（用于 Schema 自动发现）
     *
     * @param SchemaIntrospector $introspector
     * @return $this
     */
    public function setSchemaIntrospector(SchemaIntrospector $introspector): self
    {
        $this->schemaIntrospector = $introspector;
        return $this;
    }

    /**
     * 执行发现：扫描插件与 Composer 包中的 AI 提供者。
     *
     * 缓存命中（且非强制）：直接从缓存恢复提供者，不扫描文件系统。
     * 缓存未命中或 force=true：执行完整的文件系统扫描，然后写入缓存。
     *
     * @param bool $force 为 true 时跳过缓存并执行完整扫描。
     * @return array{tools: int, nodes: int, adapters: int, errors: string[]}
     */
    public function discover(bool $force = false): array
    {
        $this->stats                = ['tools' => 0, 'nodes' => 0, 'adapters' => 0, 'actions' => 0, 'triggers' => 0, 'schema_plugins' => 0, 'errors' => []];
        $this->discoveredNamespaces = [];
        $this->discoveredActions    = [];
        $this->discoveredTriggers   = [];

        if (!$force) {
            $cached = $this->cache->get();
            if ($cached !== null) {
                $this->loadFromCache($cached);
                return $this->stats;
            }
        }

        $this->discoverStandardPlugins();
        $this->discoverComposerPackages();

        if ($this->schemaIntrospector !== null) {
            $this->discoverSchema();
        }

        $this->cache->set([
            'namespaces' => $this->discoveredNamespaces,
            'actions'    => $this->discoveredActions,
            'triggers'   => $this->discoveredTriggers,
        ]);

        return $this->stats;
    }

    /**
     * 扫描标准插件目录（plugin/{ident}/manifest.json）。
     *
     * 读取每个插件 manifest.json 中的 ai_providers 配置项。
     */
    public function discoverStandardPlugins(): void
    {
        $pluginDir = $this->app->getRootPath() . 'plugin';
        if (!is_dir($pluginDir)) {
            return;
        }

        $pluginDirs = glob($pluginDir . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [];
        foreach ($pluginDirs as $dir) {
            $ident        = basename($dir);
            $manifestFile = $dir . DIRECTORY_SEPARATOR . 'manifest.json';

            if (!file_exists($manifestFile)) {
                continue;
            }

            $manifest = json_decode(file_get_contents($manifestFile), true);
            if (!is_array($manifest)) {
                continue;
            }

            $providers = $manifest['ai_providers'] ?? [];
            $actions   = $manifest['ai_actions'] ?? [];
            $triggers  = $manifest['ai_triggers'] ?? [];
            if (empty($providers) && empty($actions) && empty($triggers)) {
                continue;
            }

            $namespace = 'plugin:' . $ident;
            $this->registerProviders($namespace, $providers, false);

            $this->registerAutoScanTools($ident, $providers, $namespace);

            $this->discoverActions($ident, $actions, $namespace);

            $this->discoverTriggers($ident, $triggers);

            $this->discoverPluginSkills($ident, $dir);
        }
    }

    /**
     * 扫描 Composer 已安装的包（vendor/composer/installed.json）。
     *
     * 读取每个包的 extra.think-ai，获取提供者类名列表。
     */
    public function discoverComposerPackages(): void
    {
        $installedFile = $this->app->getRootPath() . 'vendor' . DIRECTORY_SEPARATOR . 'composer' . DIRECTORY_SEPARATOR . 'installed.json';
        if (!file_exists($installedFile)) {
            return;
        }

        $installed = json_decode(file_get_contents($installedFile), true);
        if (!is_array($installed)) {
            return;
        }

        $packages = $installed['packages'] ?? $installed;
        foreach ($packages as $package) {
            $extra = $package['extra']['think-ai'] ?? null;
            if (empty($extra)) {
                continue;
            }

            $packageName = $package['name'] ?? 'unknown';
            $namespace   = 'composer:' . $packageName;

            $providers = [
                'tool'    => $extra['tool_providers'] ?? [],
                'node'    => $extra['node_providers'] ?? [],
                'adapter' => $extra['adapter_providers'] ?? [],
            ];

            $this->registerProviders($namespace, $providers, true);
        }
    }

    /**
     * 为指定命名空间注册所有类型的提供者。
     *
     * 校验类是否存在且实现对应接口，实例化后注册到相应的注册表，
     * 并记录类名用于缓存持久化。
     *
     * @param string $namespace  例如 'plugin:demo' 或 'composer:vendor/pkg'
     * @param array  $providers  提供者类引用（插件为扁平格式，Composer 为数组格式）
     * @param bool   $isArray    Composer 包传 true（已是数组），插件传 false（单字符串）
     */
    protected function registerProviders(string $namespace, array $providers, bool $isArray = false): void
    {
        $toolClasses = $isArray
            ? ($providers['tool'] ?? [])
            : (($providers['tool'] ?? null) ? [$providers['tool']] : []);
        foreach ($toolClasses as $class) {
            if (!$this->validateClass($class, ToolProviderInterface::class)) {
                $this->stats['errors'][] = "ToolProvider validation failed: {$class}";
                continue;
            }
            try {
                $instance = $this->app->make($class);
                $this->registerToolProvider($instance);
                $actualNs = $instance->getNamespace();
                $this->trackProvider($actualNs, 'tool_providers', $class);
                $this->stats['tools']++;
            } catch (\Throwable $e) {
                $this->stats['errors'][] = "Failed to instantiate ToolProvider {$class}: " . $e->getMessage();
            }
        }

        $nodeClasses = $isArray
            ? ($providers['node'] ?? [])
            : (($providers['node'] ?? null) ? [$providers['node']] : []);
        foreach ($nodeClasses as $class) {
            if (!$this->validateClass($class, NodeProviderInterface::class)) {
                $this->stats['errors'][] = "NodeProvider validation failed: {$class}";
                continue;
            }
            try {
                $instance = $this->app->make($class);
                $this->registerNodeProvider($instance);
                $actualNs = $instance->getNamespace();
                $this->trackProvider($actualNs, 'node_providers', $class);
                $this->stats['nodes']++;
            } catch (\Throwable $e) {
                $this->stats['errors'][] = "Failed to instantiate NodeProvider {$class}: " . $e->getMessage();
            }
        }

        $adapterClasses = $isArray
            ? ($providers['adapter'] ?? [])
            : (($providers['adapter'] ?? null) ? [$providers['adapter']] : []);
        foreach ($adapterClasses as $class) {
            if (!$this->validateClass($class, AdapterProviderInterface::class)) {
                $this->stats['errors'][] = "AdapterProvider validation failed: {$class}";
                continue;
            }
            try {
                $instance = $this->app->make($class);
                $this->registerAdapterProvider($instance);
                $actualNs = $instance->getNamespace();
                $this->trackProvider($actualNs, 'adapter_providers', $class);
                $this->stats['adapters']++;
            } catch (\Throwable $e) {
                $this->stats['errors'][] = "Failed to instantiate AdapterProvider {$class}: " . $e->getMessage();
            }
        }
    }

    /**
     * 将提供者类按其真实命名空间记录到 discoveredNamespaces。
     */
    protected function trackProvider(string $namespace, string $type, string $class): void
    {
        if (!isset($this->discoveredNamespaces[$namespace])) {
            $this->discoveredNamespaces[$namespace] = [
                'tool_providers'    => [],
                'node_providers'    => [],
                'adapter_providers' => [],
            ];
        }
        if (!in_array($class, $this->discoveredNamespaces[$namespace][$type], true)) {
            $this->discoveredNamespaces[$namespace][$type][] = $class;
        }
    }

    /**
     * 将工具提供者注册到 ToolRegistry。
     *
     * 只替换「该 provider 自己产出的同名工具」，不整段清除命名空间。
     * 原因（同 B3-1）：同一插件可能既有 `ai_providers.tool` 又有 `ai_actions`，
     * 后者的工具同属 `plugin:{ident}` 命名空间；整段 `removeNamespace()` 会把
     * 前面刚注册的工具一起清掉，表现为「插件声明的工具随机消失」。
     */
    protected function registerToolProvider(ToolProviderInterface $provider): void
    {
        $namespace = $provider->getNamespace();
        $registry  = \wise\agent\tool\ToolRegistry::instance();

        foreach ($provider->getTools() as $tool) {
            if ($tool instanceof ToolInterface) {
                $key = $namespace === 'builtin'
                    ? $tool->getName()
                    : $namespace . '.' . $tool->getName();

                if ($registry->has($key)) {
                    $registry->unregister($key);
                }

                $registry->register($tool, null, $namespace);
            }
        }
    }

    /**
     * 将节点提供者注册到 NodeRegistry。
     */
    protected function registerNodeProvider(NodeProviderInterface $provider): void
    {
        $registry = NodeRegistry::instance();
        $registry->registerProvider($provider);
    }

    /**
     * 将适配器提供者注册到 AdapterRegistry。
     */
    protected function registerAdapterProvider(AdapterProviderInterface $provider): void
    {
        $registry = AdapterRegistry::instance();
        $registry->registerAdapter($provider);
    }

    /**
     * 校验类是否存在并实现指定接口。
     *
     * @param string $class     完全限定类名
     * @param string $interface 必需的接口完全限定名
     */
    protected function validateClass(string $class, string $interface): bool
    {
        if (!class_exists($class)) {
            return false;
        }
        if (!is_a($class, $interface, true)) {
            return false;
        }
        return true;
    }

    /**
     * 依据缓存元数据恢复提供者注册。
     *
     * 遍历缓存的「命名空间 -> 类名」映射，通过容器重新实例化各提供者，
     * 并注册到对应的注册表，从而避免文件系统扫描。
     *
     * 对类缺失的情况做容错处理（例如插件已卸载但缓存未失效）。
     *
     * @param array $cached ProviderCache::get() 返回的缓存数据
     */
    protected function loadFromCache(array $cached): void
    {
        $namespaces = $cached['namespaces'] ?? [];

        foreach ($namespaces as $namespace => $info) {
            foreach (($info['tool_providers'] ?? []) as $class) {
                if (!class_exists($class)) {
                    $this->stats['errors'][] = "Cached ToolProvider class not found: {$class} (namespace: {$namespace})";
                    continue;
                }
                try {
                    $instance = $this->app->make($class);
                    if ($instance instanceof ToolProviderInterface) {
                        $this->registerToolProvider($instance);
                        $this->trackProvider($namespace, 'tool_providers', $class);
                        $this->stats['tools']++;
                    }
                } catch (\Throwable $e) {
                    $this->stats['errors'][] = "Failed to restore ToolProvider {$class} from cache: " . $e->getMessage();
                }
            }

            foreach (($info['node_providers'] ?? []) as $class) {
                if (!class_exists($class)) {
                    $this->stats['errors'][] = "Cached NodeProvider class not found: {$class} (namespace: {$namespace})";
                    continue;
                }
                try {
                    $instance = $this->app->make($class);
                    if ($instance instanceof NodeProviderInterface) {
                        $this->registerNodeProvider($instance);
                        $this->trackProvider($namespace, 'node_providers', $class);
                        $this->stats['nodes']++;
                    }
                } catch (\Throwable $e) {
                    $this->stats['errors'][] = "Failed to restore NodeProvider {$class} from cache: " . $e->getMessage();
                }
            }

            foreach (($info['adapter_providers'] ?? []) as $class) {
                if (!class_exists($class)) {
                    $this->stats['errors'][] = "Cached AdapterProvider class not found: {$class} (namespace: {$namespace})";
                    continue;
                }
                try {
                    $instance = $this->app->make($class);
                    if ($instance instanceof AdapterProviderInterface) {
                        $this->registerAdapterProvider($instance);
                        $this->trackProvider($namespace, 'adapter_providers', $class);
                        $this->stats['adapters']++;
                    }
                } catch (\Throwable $e) {
                    $this->stats['errors'][] = "Failed to restore AdapterProvider {$class} from cache: " . $e->getMessage();
                }
            }
        }

        foreach (($cached['actions'] ?? []) as $namespace => $actions) {
            if (!is_array($actions) || empty($actions)) {
                continue;
            }
            $ident = str_starts_with($namespace, 'plugin:')
                ? substr($namespace, 7)
                : $namespace;
            $this->discoverActions($ident, $actions, $namespace);
        }

        foreach (($cached['triggers'] ?? []) as $namespace => $triggers) {
            if (!is_array($triggers) || empty($triggers)) {
                continue;
            }
            $ident = str_starts_with($namespace, 'plugin:')
                ? substr($namespace, 7)
                : $namespace;
            try {
                $registrar = new \wise\agent\trigger\TriggerRegistrar();
                $registrar->registerEvents($ident, $triggers);
            } catch (\Throwable $e) {
                $this->stats['errors'][] = "Trigger restore [{$ident}]: " . $e->getMessage();
            }
        }
    }

    /**
     * 获取发现统计信息。
     *
     * @return array{tools: int, nodes: int, adapters: int, actions: int, schema_plugins: int, errors: string[]}
     */
    public function getStats(): array
    {
        return $this->stats;
    }

    /**
     * 获取已发现的「命名空间 -> 提供者」映射。
     *
     * @return array<string, array>
     */
    public function getDiscoveredNamespaces(): array
    {
        return $this->discoveredNamespaces;
    }


    /**
     * 对所有启用了 auto_introspect 的插件执行 Schema 发现
     *
     * 扫描所有插件目录，读取 manifest.json 中 ai_schema.auto_introspect。
     * 对启用的插件执行完整发现流水线：
     *   内省 → 生成 Tool → 解析业务能力 → 注入 Context
     */
    public function discoverSchema(): void
    {
        $pluginDir = $this->app->getRootPath() . 'plugin';
        if (!is_dir($pluginDir)) {
            return;
        }

        $discovery = new PluginSchemaDiscovery($this->schemaIntrospector);
        $pluginDirs = glob($pluginDir . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [];

        foreach ($pluginDirs as $dir) {
            $ident = basename($dir);
            $manifestFile = $dir . DIRECTORY_SEPARATOR . 'manifest.json';

            if (!file_exists($manifestFile)) {
                continue;
            }

            $manifest = json_decode(file_get_contents($manifestFile), true);
            if (!is_array($manifest)) {
                continue;
            }

            $aiSchema = $manifest['ai_schema'] ?? [];
            if (empty($aiSchema['auto_introspect'])) {
                continue;
            }

            try {
                $result = $discovery->discover($ident, $manifest);
                if ($result->isSuccess()) {
                    $this->stats['schema_plugins']++;
                    $this->trackProvider(
                        'schema:' . $ident,
                        'schema_plugins',
                        $ident
                    );
                }

                foreach ($result->errors as $error) {
                    $this->stats['errors'][] = "Schema discovery [{$ident}]: {$error}";
                }
            } catch (\Throwable $e) {
                $this->stats['errors'][] = "Schema discovery [{$ident}]: " . $e->getMessage();
            }
        }
    }


    /**
     * 自动扫描插件目录下的 ToolInterface 实现
     *
     * 当 manifest.json 中 ai_providers.tool 设为 "auto:ai/tool" 时触发。
     * 扫描 plugin/{ident}/ai/tool/*.php，自动发现并注册所有 ToolInterface 类。
     * 无需维护 ToolProvider 类。
     */
    private function registerAutoScanTools(string $ident, array $providers, string $namespace): void
    {
        $toolValue = $providers['tool'] ?? null;
        if (!is_string($toolValue) || !str_starts_with($toolValue, 'auto:')) {
            return;
        }

        $subPath = substr($toolValue, 5);
        $scanDir = $this->app->getRootPath() . 'plugin' . DIRECTORY_SEPARATOR
            . $ident . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $subPath);

        if (!is_dir($scanDir)) {
            return;
        }

        $phpFiles = glob($scanDir . DIRECTORY_SEPARATOR . '*.php') ?: [];
        $toolRegistry = \wise\agent\tool\ToolRegistry::instance();

        foreach ($phpFiles as $file) {
            $className = $this->resolveClassFromFile($file, $ident, $subPath);
            if ($className === null || !class_exists($className)) {
                continue;
            }

            if (!is_subclass_of($className, ToolInterface::class)) {
                continue;
            }

            try {
                $instance = $this->app->make($className);
                $toolRegistry->register($instance, $instance->getName(), $namespace);
                $this->trackProvider($namespace, 'tool_providers', $className);
                $this->stats['tools']++;
            } catch (\Throwable $e) {
                $this->stats['errors'][] = "Auto-scan tool failed [{$className}]: " . $e->getMessage();
            }
        }
    }

    /**
     * 从文件路径和插件目录推断完全限定类名
     */
    private function resolveClassFromFile(string $file, string $ident, string $subPath): ?string
    {
        $className = pathinfo($file, PATHINFO_FILENAME);

        $source = @file_get_contents($file);
        if ($source !== false && preg_match('/namespace\s+([a-zA-Z_\\\]+)\s*;/', $source, $m)) {
            return $m[1] . '\\' . $className;
        }

        $nsPart = str_replace('/', '\\', $subPath);
        return 'plugin\\' . $ident . '\\' . $nsPart . '\\' . $className;
    }


    /**
     * 从 manifest.json 的 ai_actions 生成并注册声明式动作工具
     *
     * 每个动作映射到插件已有的 service 方法，由 ActionToolGenerator
     * 包装为 ToolInterface，注册到 ToolRegistry（namespace plugin:{ident}）。
     * 插件无需编写任何 Tool 类。
     *
     * @param string $ident    插件标识
     * @param array  $actions  ai_actions 配置
     * @param string $namespace 工具命名空间
     */
    private function discoverActions(string $ident, array $actions, string $namespace): void
    {
        if (empty($actions) || !is_array($actions)) {
            return;
        }

        try {
            $generator = new \wise\agent\action\ActionToolGenerator($ident, $actions);
            $registry  = \wise\agent\tool\ToolRegistry::instance();

            $count = $generator->registerTo($registry, $namespace);
            if ($count > 0) {
                $this->stats['actions'] += $count;
                $this->discoveredActions[$namespace] = $actions;
            }
        } catch (\Throwable $e) {
            $this->stats['errors'][] = "Action discovery [{$ident}]: " . $e->getMessage();
        }
    }


    /**
     * 从 manifest.json 的 ai_triggers 注册 schedule/event 触发器
     *
     * - schedule 触发器 → AiTaskScheduler 注册（持久化到数据库）
     * - event 触发器     → Event::listen（当前进程生效，缓存后每请求从 cache 恢复）
     *
     * @param string $ident    插件标识
     * @param array  $triggers ai_triggers 配置
     */
    private function discoverTriggers(string $ident, array $triggers): void
    {
        if (empty($triggers) || !is_array($triggers)) {
            return;
        }

        try {
            $registrar = new \wise\agent\trigger\TriggerRegistrar();
            $count = $registrar->register($ident, $triggers);
            if ($count > 0) {
                $this->stats['triggers'] += $count;
                $this->discoveredTriggers['plugin:' . $ident] = $triggers;
            }
        } catch (\Throwable $e) {
            $this->stats['errors'][] = "Trigger discovery [{$ident}]: " . $e->getMessage();
        }
    }


    /**
     * 自动发现插件的 Skill 定义文件
     *
     * 解析为 MarkdownSkill 并注册到 SkillManager。
     */
    private function discoverPluginSkills(string $ident, string $pluginDir): void
    {
        $skillDir = $pluginDir . DIRECTORY_SEPARATOR . 'ai' . DIRECTORY_SEPARATOR . 'skill';
        if (!is_dir($skillDir)) {
            return;
        }

        $skillFiles = glob($skillDir . DIRECTORY_SEPARATOR . '*.skill.md') ?: [];
        if (empty($skillFiles)) {
            return;
        }

        $skillRegistry = SkillRegistry::instance();

        foreach ($skillFiles as $file) {
            $skillName = pathinfo($file, PATHINFO_FILENAME);
            $skillName = preg_replace('/\.skill$/', '', $skillName);

            try {
                $content = file_get_contents($file);
                if ($content === false || trim($content) === '') {
                    continue;
                }

                $skill = new MarkdownSkill($skillName, $content);
                $skillRegistry->register($skill);
            } catch (\Throwable $e) {
                $this->stats['errors'][] = "Skill discovery [{$ident}/{$skillName}]: " . $e->getMessage();
            }
        }
    }
}
