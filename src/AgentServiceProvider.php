<?php
declare(strict_types=1);

namespace wise\agent;

use wise\support\contracts\EnvSeedProvider;


use wise\support\context\ContextualLogger;

use wise\support\traits\RegistersMigrations;

use think\App;
use wise\agent\agent\AgentManager;
use wise\agent\bridge\AdapterRegistry;
use wise\agent\bridge\NodeRegistry;
use wise\agent\bridge\PluginAiDiscovery;
use wise\agent\bridge\PluginBridge;
use wise\agent\bridge\ProviderCache;
use wise\agent\contract\MemoryInterface;
use wise\agent\contract\ToolInterface;
use wise\agent\contract\UserContextInterface;
use wise\agent\context\ContextManager;
use wise\agent\context\builtin\SystemContextProvider;
use wise\agent\context\builtin\UserContextProvider;
use wise\agent\feedback\FeedbackCollector;
use wise\agent\feedback\SkillOptimizer;
use wise\agent\listener\AiLogger;
use wise\agent\listener\CapabilityAuthorizePolicy;
use wise\agent\listener\ToolSecurityCheck;
use wise\agent\memory\DatabaseMemory;
use wise\agent\memory\MemoryFactory;
use wise\agent\memory\session\SessionManager;
use wise\agent\memory\session\SessionUserContext;
use wise\agent\schema\SchemaIntrospector;
use wise\agent\schema\discovery\PluginSchemaDiscovery;
use wise\agent\schema\capability\BusinessCapabilityResolver;
use wise\agent\skill\SkillManager;
use wise\agent\skill\SkillRegistry;
use wise\agent\tool\ToolRegistry;

/**
 * Wise-Agent 服务提供者
 *
 * 通过 composer.json extra.think.services 注册以实现自动发现。
 *
 * 职责：
 *  1. 合并配置
 *  2. 绑定接口到实现
 *  3. 注册控制台命令
 *  4. 注册事件监听器
 *  5. 初始化内置工具
 */
class AgentServiceProvider extends \think\Service implements EnvSeedProvider
{

    use \wise\support\traits\RegistersMigrations;
    use \wise\support\traits\ServiceTrait;
    /**
     * 提供的服务映射
     */
    /**
     * 实际注册逻辑（首次 make 时执行）
     */
    /**
     * 声明需要播种到 .env 的密钥/凭证
     *
     * - AI_ENCRYPT_KEY 为内部加密密钥（加密持久化的 AI 供应商配置），安装时随机生成
     * - OPENAI/DEEPSEEK 的 API Key 为第三方凭证，留空占位由用户填写
     * - 其余静态默认值（provider/base_url/model）沿用 config 默认值，不写入 .env
     */
    public static function envSeeds(): array
    {
        return [
            'AI_ENCRYPT_KEY' => [
                'type'      => 'secret',
                'generator' => 'random_bytes_32',
                'comment'   => 'wise-agent 持久化 AI 配置加密密钥',
            ],
            'OPENAI_API_KEY' => [
                'type'    => 'external',
                'default' => '',
                'comment' => 'OpenAI API Key（platform.openai.com 获取）',
            ],
            'DEEPSEEK_API_KEY' => [
                'type'    => 'external',
                'default' => '',
                'comment' => 'DeepSeek API Key（platform.deepseek.com 获取）',
            ],
        ];
    }

    public function register(): void
    {
        $this->mergeConfig('wise-agent');

        $this->registerAiLogChannel();

        $this->app->make(\wise\support\config\ConfigCheckCollector::class)->register(
            new config\AgentSecretCheck($this->app->config)
        );

        $this->app->make(\wise\support\config\ConfigCheckCollector::class)->register(
            new config\AgentCacheCheck($this->app)
        );

        $this->app->make(\wise\support\config\ConfigCheckCollector::class)->register(
            new \wise\agent\checker\AiStaleTaskChecker()
        );
        $this->app->make(\wise\support\config\ConfigCheckCollector::class)->register(
            new \wise\agent\checker\AiDeadLetterChecker()
        );

        $this->app->make(\wise\support\config\ConfigCheckCollector::class)->register(
            new \wise\agent\feedback\ToolHealthChecker()
        );

        $this->app->bind(AgentManager::class, AgentManager::class);
        $this->app->bind(SessionManager::class, SessionManager::class);
        $this->app->bind(ToolRegistry::class, ToolRegistry::class);

        $this->app->bind(MemoryInterface::class, function (App $app) {
            MemoryFactory::clearCache();
            return MemoryFactory::create();
        });

        $this->app->bind(UserContextInterface::class, SessionUserContext::class);

        $this->app->bind(DatabaseMemory::class, DatabaseMemory::class);

        $this->app->bind(SkillRegistry::class, function (App $app) {
            $registry = new SkillRegistry();

            $builtinSkills = $app->config->get('wise-agent.skills.builtin', []);
            foreach ($builtinSkills as $name => $class) {
                if (class_exists($class)) {
                    try {
                        $registry->registerClass($class);
                    } catch (\Throwable $e) {
                        ContextualLogger::warning("[WiseAgent] Failed to register skill '{$name}' ({$class}): {$e->getMessage()}");
                    }
                }
            }

            $skillsDir = $app->config->get('wise-agent.skills.dir', '');
            if (empty($skillsDir)) {
                $skillsDir = dirname(__DIR__, 1) . '/skills';
            }

            if (is_dir($skillsDir)) {
                try {
                    $stats = $registry->loadFromDirectory($skillsDir, '*.skill.md');
                } catch (\Throwable $e) {
                }
            }

            $extraDirs = $app->config->get('wise-agent.skills.extra_dirs', []);
            foreach ($extraDirs as $dir) {
                if (is_dir($dir)) {
                    try {
                        $registry->loadFromDirectory($dir, '*.skill.md');
                    } catch (\Throwable $e) {
                    }
                }
            }

            return $registry;
        });

        $this->app->bind(SkillManager::class, function (App $app) {
            $registry = $app->make(SkillRegistry::class);
            $agentManager = $app->make(AgentManager::class);
            $config = $app->config->get('wise-agent.skills.defaults', []);
            return new SkillManager($registry, $agentManager, $config);
        });

        $this->app->bind(\wise\agent\pipeline\PipelineRegistry::class, function (App $app) {
            $registry = new \wise\agent\pipeline\PipelineRegistry();

            $definitions = $app->config->get('wise-agent.pipeline.definitions', []);
            foreach ($definitions as $name => $config) {
                $registry->registerDefinition($name, $config);
            }

            return $registry;
        });

        $this->app->bind(\wise\agent\pipeline\PipelineEngine::class, function (App $app) {
            $registry = $app->make(\wise\agent\pipeline\PipelineRegistry::class);
            $skillManager = $app->make(SkillManager::class);
            return new \wise\agent\pipeline\PipelineEngine($registry, $skillManager);
        });

        $this->app->bind(FeedbackCollector::class, FeedbackCollector::class);
        $this->app->bind(SkillOptimizer::class, function (App $app) {
            return new SkillOptimizer($app->make(FeedbackCollector::class));
        });

        $this->app->bind(\wise\agent\schedule\AiTaskScheduler::class, function (App $app) {
            $scheduler = new \wise\agent\schedule\AiTaskScheduler(
                $app->make(\wise\agent\pipeline\PipelineEngine::class),
                $app->make(SkillManager::class),
                $app->make(AgentManager::class)
            );

            $tasks = $app->config->get('wise-agent.schedule.tasks', []);
            foreach ($tasks as $taskData) {
                if (is_array($taskData) && isset($taskData['id'])) {
                    $scheduler->register(new \wise\agent\schedule\AiTask(
                        $taskData['id'],
                        $taskData['name'] ?? '',
                        $taskData['type'] ?? 'pipeline',
                        $taskData['target'] ?? '',
                        $taskData['schedule'] ?? '',
                        $taskData['input'] ?? [],
                        $taskData['source'] ?? null
                    ));
                }
            }

            $scheduler->loadFromDatabase();

            return $scheduler;
        });

        $this->app->bind(ProviderCache::class, ProviderCache::class);

        $this->app->bind(PluginAiDiscovery::class, function (App $app) {
            $discovery = new PluginAiDiscovery($app);

            if ($app->config->get('wise-agent.schema.auto_discover', true)) {
                $discovery->setSchemaIntrospector(
                    $app->make(SchemaIntrospector::class)
                );
            }

            return $discovery;
        });

        $this->registerSchemaBindings();

        $this->app->bind(PluginBridge::class, function (App $app) {
            return new PluginBridge($app);
        });

        $this->app->bind(NodeRegistry::class, function () {
            return NodeRegistry::instance();
        });

        $this->app->bind(AdapterRegistry::class, function () {
            return AdapterRegistry::instance();
        });

        $this->initializeContextProviders();

        $this->initializeBuiltinTools();

        $this->app->bind(\wise\agent\prompt\PromptTemplateManager::class, function (App $app) {
            return new \wise\agent\prompt\PromptTemplateManager(
                (array) $app->config->get('wise-agent.prompts', [])
            );
        });

        $this->commands([
            \wise\agent\command\AiCapabilities::class,
            \wise\agent\command\ConsoleDump::class,
            \wise\agent\command\Discover::class,
            \wise\agent\command\ScanTimeouts::class,
            \wise\agent\command\RunDueTasks::class,
            \wise\agent\command\UsageRollupCommand::class,
            \wise\agent\command\Chat::class,
            \wise\agent\command\FakeClientDemo::class,
            \wise\agent\command\SyncToolPermissions::class,
            \wise\agent\command\Approvals::class,
            \wise\agent\command\PlanMaterialize::class,
            \wise\agent\command\Closure::class,
            \wise\agent\mcp\McpServeCommand::class,
        ]);

        $this->initializePlanTools();
    }

    /**
     * 动态注册 AI 专用日志通道
     *
     * AiLogger 使用 config('wise-agent.log.channel')（默认 'ai'）写入日志。
     * 若通道未在 config/log.php 中定义，Log::channel('ai') 会抛出
     * "Log channel [ai] not found"，进而阻断 Agent 调用流程。
     * 此处动态注册，用户无需手动修改 config/log.php；
     * 若用户已定义同名通道，则优先使用用户配置。
     */
    protected function registerAiLogChannel(): void
    {
        $channels = $this->app->config->get('log.channels', []);

        if (!isset($channels['ai'])) {
            $channels['ai'] = [
                'type'           => 'File',
                'path'           => runtime_path() . 'log/ai/',
                'single'         => false,
                'apart_level'    => [],
                'max_files'      => 30,
                'json'           => false,
                'realtime_write' => false,
                'format'         => '[%s][%s] %s',
            ];
            $this->app->config->set(['channels' => $channels], 'log');
        }
    }

    public function boot(): void
    {
        $this->registerMigrations();
        $this->registerEventListeners();

        $this->initializeFrameworkTools();

        $this->initializeConsoleTools();

        $this->initializeMenuActionTools();

        $this->initializeListQueryTools();

        $this->initializeFormSchemaTools();

        $this->registerFrontendModule();

        $this->registerWidgetModule();

        $this->registerPermissionExtPoint();

        $this->registerAdminMenu();

        $this->registerPermissionSyncProvider();

        \wise\agent\workflow\AiApprovalWorkflow::register();

        $this->registerMcpIntegration();

        RouteRegistrar::register();
    }

    /**
     * 与通用 wise-mcp 包集成
     *
     * 1) Server 侧：把 wise-agent 工具以 MCP 能力暴露（注册 mcp.tool_provider）
     * 2) Client 侧：把配置中的外部 MCP 工具导入 ToolRegistry（默认关闭，按需开启）
     *
     * 全部以 class_exists / interface_exists 守卫，wise-mcp 未安装时零副作用。
     */
    protected function registerMcpIntegration(): void
    {
        if (interface_exists(\wise\mcp\contract\ToolProviderInterface::class)
            && class_exists(\wise\support\ExtPoint::class)) {
            try {
                \wise\support\ExtPoint::register(
                    \wise\mcp\contract\ToolProviderInterface::EXT_POINT,
                    'agent',
                    \wise\agent\mcp\AgentToolProvider::class
                );
            } catch (\Throwable $e) {
                ContextualLogger::warning('[WiseAgent] Failed to register MCP tool provider: ' . $e->getMessage());
            }
        }

        if (interface_exists(\wise\mcp\contract\ToolInvokerInterface::class)) {
            try {
                $this->app->bind(
                    \wise\mcp\contract\ToolInvokerInterface::class,
                    \wise\agent\mcp\AgentToolInvoker::class
                );
            } catch (\Throwable $e) {
                ContextualLogger::warning('[WiseAgent] Failed to bind MCP tool invoker: ' . $e->getMessage());
            }
        }

        if (!class_exists(\wise\mcp\client\McpClientFactory::class)) {
            return;
        }
        if (!$this->app->config->get('wise-agent.mcp.import_external', false)) {
            return;
        }
        if ((array) $this->app->config->get('wise-mcp.clients', []) === []) {
            return;
        }

        try {
            $importer = new \wise\agent\mcp\ExternalMcpToolImporter(
                $this->app->make(\wise\mcp\client\McpClientFactory::class),
                $this->app->make(\wise\mcp\client\ConnectionManager::class),
                ToolRegistry::instance()
            );
            $importer->importAll();
        } catch (\Throwable $e) {
            ContextualLogger::warning('[WiseAgent] Failed to import external MCP tools: ' . $e->getMessage());
        }
    }

    /**
     * 注册 AI 助手前端入口到框架扩展点 admin.frontend.module
     *
     * 框架通过 ExtPoint 通用发现并注入前端，不感知 AI 具体实现；
     * 卸载本包即自动移除该前端入口。
     *
     * priority=9999：AI 助手是常驻浮层，刻意排在清单末尾，避免顶栏/浮层类
     * 入口（如 wise-health-check=100）被它挤到后面。该值同时决定前端挂载次序
     * （见 ExtPoints::ADMIN_FRONTEND_MODULE 与 wise-bootstrap.js::_loadFeatureModules）。
     */
    protected function registerFrontendModule(): void
    {
        try {
            \wise\support\ExtPoint::register(
                \wise\support\ExtPoints::ADMIN_FRONTEND_MODULE,
                'wise-agent.chat',
                function () {
                    return [
                        'key' => 'agentChat',
                        'url' => wise_agent_frontend_entry(),
                    ];
                },
                9999
            );
        } catch (\Throwable $e) {
            ContextualLogger::warning('[WiseAgent] Failed to register frontend module: ' . $e->getMessage());
        }
    }

    /**
     * 注册 AI 挂件前端模块＼�：注入统一 ai-widget.js，
     * 由前端按页面类型挂载列表筛选/表单填充/详情解读/AI 助手入口。
     *
     * priority=9998：排在 AI 助手聊天（9999）之前，挂件先挂载；
     * AI 助手入口复用 agent-chat.js 的 agentChat.init()（动态 import），不重复实现聊天壳。
     */
    protected function registerWidgetModule(): void
    {
        try {
            \wise\support\ExtPoint::register(
                \wise\support\ExtPoints::ADMIN_FRONTEND_MODULE,
                'wise-agent.widget',
                function () {
                    $version = '1.0.0';
                    try {
                        $v = config('wise-agent.version', '');
                        if (is_string($v) && $v !== '') {
                            $version = $v;
                        }
                    } catch (\Throwable) {
                    }

                    return [
                        'key' => 'aiWidget',
                        'url' => '/statics/plugins/wise-agent/js/ai-widget.js?v=' . rawurlencode($version),
                    ];
                },
                9998
            );
        } catch (\Throwable $e) {
            ContextualLogger::warning('[WiseAgent] Failed to register widget module: ' . $e->getMessage());
        }
    }

    /**
     * 定义「AI 工具权限提供者」扩展点
     *
     * 其它包 / 插件可注册 provider（类名或闭包）向权限表补充 AI 工具权限声明，
     * 由 wise\agent\security\ToolPermissionRegistrar 在同步时统一收取。
     *
     * 防御式 definePoint：重复定义不抛异常，因此与其它包的启动顺序无关。
     */
    protected function registerPermissionExtPoint(): void
    {
        try {
            if (!\wise\support\ExtPoint::defined(\wise\agent\security\ToolPermissionRegistrar::EXT_POINT)) {
                \wise\support\ExtPoint::definePoint(
                    \wise\agent\security\ToolPermissionRegistrar::EXT_POINT,
                    \wise\support\ExtPoint::POLICY_MULTI
                );
            }
        } catch (\Throwable $e) {
            ContextualLogger::warning('[WiseAgent] Failed to define permission ext point: ' . $e->getMessage());
        }
    }

    /**
     * 声明后台「AI 助手」菜单（启动台形态）
     *
     * 通过 ExtPoints::MENU_ITEM 扩展点注册，由宿主 MenuItemSync 幂等落库，
     * 并自动派生 admin.ai_* 系列权限（供 RBAC 中间件与角色授权使用）。
     * 统一承载 AI 对话 / AI 设置 / 能力审批 / 用量仪表盘 / 任务中心
     * （任务中心由 AiMenuProvider::items() 内部按 tasks.enabled 门控）。
     *
     * 落库执行：php think menu:sync
     */
    protected function registerAdminMenu(): void
    {
        if (!interface_exists(\wise\support\contracts\MenuItemProvider::class)
            || !class_exists(\wise\support\ExtPoint::class)) {
            return;
        }

        try {
            \wise\support\ExtPoint::register(
                \wise\support\ExtPoints::MENU_ITEM,
                'wise-agent',
                \wise\agent\menu\AiMenuProvider::class
            );
        } catch (\Throwable $e) {
            ContextualLogger::warning('[WiseAgent] Failed to register admin menu: ' . $e->getMessage());
        }
    }

    /**
     * 注册「权限同步提供者」到框架扩展点
     *
     * 宿主通过 ExtPoints::PERMISSION_SYNC_PROVIDER 遍历各包注册的提供者并调用
     * sync()（见 app\common\service\PermissionSyncRegistry），因此框架侧
     * （安装初始化 / 后台「更新权限列表」）不再直接引用本包的任何类。
     *
     * 防御式 definePoint：重复定义不抛异常，与其它包的启动顺序无关；
     * wise-support 缺失时静默跳过（包可脱离宿主独立运行）。
     */
    protected function registerPermissionSyncProvider(): void
    {
        if (!class_exists(\wise\support\ExtPoint::class)
            || !class_exists(\wise\support\ExtPoints::class)) {
            return;
        }

        try {
            if (!\wise\support\ExtPoint::defined(\wise\support\ExtPoints::PERMISSION_SYNC_PROVIDER)) {
                \wise\support\ExtPoint::definePoint(
                    \wise\support\ExtPoints::PERMISSION_SYNC_PROVIDER,
                    \wise\support\ExtPoint::POLICY_MULTI
                );
            }

            \wise\support\ExtPoint::register(
                \wise\support\ExtPoints::PERMISSION_SYNC_PROVIDER,
                'wise-agent',
                \wise\agent\security\ToolPermissionRegistrar::class
            );
        } catch (\Throwable $e) {
            ContextualLogger::warning('[WiseAgent] Failed to register permission sync provider: ' . $e->getMessage());
        }
    }

    /**
     * 注册 AI 生命周期事件的事件监听器
     */
    protected function registerEventListeners(): void
    {
        $event = $this->app->event;

        $logger = new AiLogger();
        $event->listen(\wise\agent\event\AgentStart::class, [$logger, 'handle']);
        $event->listen(\wise\agent\event\AgentConfigure::class, [$logger, 'handle']);
        $event->listen(\wise\agent\event\AgentStep::class, [$logger, 'handle']);
        $event->listen(\wise\agent\event\AgentComplete::class, [$logger, 'handle']);
        $event->listen(\wise\agent\event\AgentError::class, [$logger, 'handle']);
        $event->listen(\wise\agent\event\AiRequest::class, [$logger, 'handle']);
        $event->listen(\wise\agent\event\AiResponse::class, [$logger, 'handle']);

        $security = new ToolSecurityCheck();
        $event->listen(\wise\agent\event\ToolExecute::class, [$security, 'handle']);

        $sandbox = new \wise\agent\security\SandboxGuard();
        $event->listen(\wise\agent\event\ToolExecute::class, [$sandbox, 'handle']);

        $policy = new CapabilityAuthorizePolicy();
        $event->listen(\wise\agent\event\ToolAuthorize::class, [$policy, 'handle']);
        $event->listen(\wise\agent\event\SkillAuthorize::class, [$policy, 'handle']);

        $event->listen('PluginInstall', function ($event) {
            $cache = new ProviderCache($this->app);
            $cache->invalidate();
            \wise\agent\client\AiClientFactory::clearCache();
            ContextualLogger::info('wise-agent: Plugin installed, cache invalidated. Run: php think wise:agent:discover');
        });

        $event->listen('PluginUninstall', function ($event) {
            $cache = new ProviderCache($this->app);
            $cache->invalidate();

            $pluginIdent = $event->pluginIdent ?? '';
            if ($pluginIdent) {
                $namespace = 'plugin:' . $pluginIdent;
                ToolRegistry::instance()->removeNamespace($namespace);
                NodeRegistry::instance()->removeProvider($namespace);
            }

            ContextualLogger::info('wise-agent: Plugin uninstalled, cache invalidated. Run: php think wise:agent:discover');
        });

        $this->registerTriggerEventListeners();

        $this->registerClosureListeners();
    }

    /**
     * 注册业务闭环监听器（B2）
     *
     * 订阅四个**既有**事件（清单来自 {@see \wise\agent\closure\ClosureListener::events()}，
     * 单一事实来源，避免与 `discover` 输出漂移）：
     *
     * - `ApprovalRequested` / `ApprovalDecided`（`ApprovalManager` 派发，此前**无人订阅**）
     * - `ToolExecute`（只处理 AFTER；BEFORE 归 `ToolSecurityCheck`）
     * - `AgentComplete`
     *
     * 两重门控：这里判 `closure.enabled`（关闭时零开销），监听器内部也再判一次
     * （便于被直接实例化使用时同样可控）。注册失败静默降级 —— 闭环是**增强能力**，
     * 绝不能因为它影响 AI 主链路。
     */
    protected function registerClosureListeners(): void
    {
        try {
            $options = (array) $this->app->config->get('wise-agent.closure', []);

            if (!(bool) ($options['enabled'] ?? false)) {
                return;
            }

            $listener = new \wise\agent\closure\ClosureListener($options);

            foreach (\wise\agent\closure\ClosureListener::events() as $eventClass) {
                $this->app->event->listen($eventClass, [$listener, 'handle']);
            }
        } catch (\Throwable $e) {
            ContextualLogger::warning('[WiseAgent] Failed to register closure listeners: ' . $e->getMessage());
        }
    }

    /**
     * 注册框架级 AI 工具（机制3：菜单/动作内省）
     *
     * 将后台菜单导航内省为 AI 可调用工具，让 AI 理解系统功能结构。
     * 工具实例在 execute() 时才查询数据库，注册本身零成本。
     */
    protected function initializeFrameworkTools(): void
    {
        try {
            $introspector = new \wise\agent\framework\MenuActionIntrospector();
            $introspector->registerTools();
        } catch (\Throwable $e) {
            ContextualLogger::warning('[WiseAgent] Failed to register framework tools: ' . $e->getMessage());
        }
    }

    /**
     * 注册框架命令工具（A4）
     *
     * 将 `php think` 命令（框架 / wise-* 包 / 插件）内省为 AI 可调用工具。
     * 默认关闭（`wise-agent.console.enabled=false`）；开启后：
     *   - CLI 进程直接读 Console::all()；
     *   - HTTP 进程走 `php think wise:agent:console-dump --json` 子进程 + 缓存。
     * 内省失败（disable_functions 禁用 proc_open 等）时静默降级，不影响其它 AI 能力。
     */
    /**
     * 注册自治计划（B1）
     *
     * 两件事（都受 `wise-agent.plan.enabled=false` 门控）：
     *
     * 1. **工具** `plan_run` / `plan_status` / `plan_resume`（`wise\agent\plan\`）；
     * 2. **物化外壳节点** `plan:step`（`PlanNodeProvider`）——`plan-materialize` 生成的流水线
     *    依赖它才能被执行（`PipelineEngine::isPluginNode()` 要求命名空间已注册）。
     *
     * 执行器本身**不做授权**：写步骤一律走既有工具，于是 A1-5 的权限/审批/审计原样生效。
     * 注册失败静默降级，不影响其它 AI 能力。
     */
    protected function initializePlanTools(): void
    {
        try {
            if (!(bool) $this->app->config->get('wise-agent.plan.enabled', false)) {
                return;
            }

            (new \wise\agent\plan\PlanToolProvider())->registerTo();

            \wise\agent\bridge\NodeRegistry::instance()->registerProvider(new \wise\agent\plan\PlanNodeProvider());
        } catch (\Throwable $e) {
            ContextualLogger::warning('[WiseAgent] Failed to register plan tools: ' . $e->getMessage());
        }
    }

    /**
     * 注册框架命令工具（A4）
     *
     * 将 `php think` 命令（框架 / wise-* 包 / 插件）内省为 AI 可调用工具。
     * 默认关闭（`wise-agent.console.enabled=false`）；开启后：
     *   - CLI 进程直接读 Console::all()；
     *   - HTTP 进程走 `php think wise:agent:console-dump --json` 子进程 + 缓存。
     * 内省失败（disable_functions 禁用 proc_open 等）时静默降级，不影响其它 AI 能力。
     */
    protected function initializeConsoleTools(): void
    {
        try {
            if (!(bool) $this->app->config->get('wise-agent.console.enabled', false)) {
                return;
            }

            (new \wise\agent\console\ConsoleCommandToolProvider())->registerTo();
        } catch (\Throwable $e) {
            ContextualLogger::warning('[WiseAgent] Failed to register console command tools: ' . $e->getMessage());
        }
    }

    /**
     * 注册后台菜单动作工具（A1）
     *
     * 把「菜单可达的 `CrudAction` 标准动作」内省为 AI 可调用工具
     * （`wise\agent\menu\`，内省结果缓存于 `runtime/wise-agent/menu_actions.json`）。
     * 默认关闭（`wise-agent.menu_actions.enabled=false`）。
     *
     * 同时注册「菜单权限 ⇔ AI 能力授权」桥接：默认 `require_ai_permission=false` 时，
     * 管理员只要拥有该控制器锚点菜单的权限，即可通过 `menu.action.*` 的能力授权
     * （避免新增权限点导致既有角色集体失效）。内省/注册失败时静默降级，不影响其它 AI 能力。
     */
    protected function initializeMenuActionTools(): void
    {
        try {
            if (!(bool) $this->app->config->get('wise-agent.menu_actions.enabled', false)) {
                return;
            }

            $provider = new \wise\agent\menu\MenuActionToolProvider();
            $provider->registerTo();
        } catch (\Throwable $e) {
            ContextualLogger::warning('[WiseAgent] Failed to register menu action tools: ' . $e->getMessage());
        }
    }

    /**
     * 注册自然语言列表查询工具（A3）
     *
     * 注册 `lq_query`（自然语言 → 受约束查询参数 → 执行，默认委托 A1 的 getList）
     * 与 `lq_fields`（按需拉取维度目录）。**纯只读**，默认关闭
     * （`wise-agent.list_query.enabled=false`）。
     */
    protected function initializeListQueryTools(): void
    {
        try {
            if (!(bool) $this->app->config->get('wise-agent.list_query.enabled', false)) {
                return;
            }

            (new \wise\agent\query\ListQueryToolProvider())->registerTo();
        } catch (\Throwable $e) {
            ContextualLogger::warning('[WiseAgent] Failed to register list query tools: ' . $e->getMessage());
        }
    }

    /**
     * 注册表单 Schema 工具（A2）
     *
     * 消费 `CrudSchemaTrait::defineForm()`：**纯只读 / 纯计算**，不落库
     * （落库唯一入口是 A1 的 `save`/`update`，权限/审计/沙箱全部继承 A1）。
     * 默认关闭（`wise-agent.form_schema.enabled=false`）。
     *
     * A2-2 注册 `form_validate`；A2-3/A2-4 的 `form_fields` / `form_fill` / `form_generate`
     * 由同一个提供者追加，无需改动此处。
     */
    protected function initializeFormSchemaTools(): void
    {
        try {
            if (!(bool) $this->app->config->get('wise-agent.form_schema.enabled', false)) {
                return;
            }

            (new \wise\agent\form\FormSchemaToolProvider())->registerTo();
        } catch (\Throwable $e) {
            ContextualLogger::warning('[WiseAgent] Failed to register form schema tools: ' . $e->getMessage());
        }
    }

    /**
     * 从 ProviderCache 恢复 ai_triggers 的 event 监听器
     *
     * 插件声明的 event 触发器需要在每个请求都注册 Event::listen，
     * 因此从持久化缓存中读取触发器定义并注册。
     */
    protected function registerTriggerEventListeners(): void
    {
        try {
            $cache = $this->app->make(ProviderCache::class);
            $cached = $cache->get();
            $triggers = $cached['triggers'] ?? [];

            if (empty($triggers) || !is_array($triggers)) {
                return;
            }

            $registrar = new \wise\agent\trigger\TriggerRegistrar();
            foreach ($triggers as $namespace => $triggerList) {
                if (!is_array($triggerList)) {
                    continue;
                }
                $ident = str_starts_with($namespace, 'plugin:')
                    ? substr($namespace, 7)
                    : $namespace;
                $registrar->registerEvents($ident, $triggerList);
            }
        } catch (\Throwable $e) {
        }
    }

    /**
     * 从配置初始化内置工具
     */
    protected function initializeBuiltinTools(): void
    {
        $configuredTools = $this->app->config->get('wise-agent.tools', []);
        $registry = ToolRegistry::instance();

        foreach ($configuredTools as $name => $config) {
            if (empty($config['enabled'])) {
                continue;
            }

            $class = $config['class'] ?? null;
            if (!$class || !class_exists($class)) {
                continue;
            }

            $instance = $this->app->make($class);
            if ($instance instanceof ToolInterface) {
                $registry->register($instance);
            }
        }
    }

    /**
     * 初始化内置上下文注入器
     */
    protected function initializeContextProviders(): void
    {
        if (!$this->app->config->get('wise-agent.context.enabled', true)) {
            return;
        }

        $builtin = $this->app->config->get('wise-agent.context.builtin', []);
        $manager = ContextManager::instance();

        if (($builtin[SystemContextProvider::class] ?? true)) {
            $manager->register(new SystemContextProvider());
        }

        if (($builtin[UserContextProvider::class] ?? true)) {
            $manager->register(new UserContextProvider());
        }

        if ((bool) $this->app->config->get('wise-agent.list_query.enabled', false)) {
            $manager->register(new \wise\agent\query\ListQueryContextProvider());
        }

        if ((bool) $this->app->config->get('wise-agent.form_schema.enabled', false)) {
            $manager->register(new \wise\agent\form\FormSchemaContextProvider());
        }
    }

    /**
     * 注册 Schema 子系统绑定（Phase 1-4）
     *
     * 绑定：
     *   - SchemaIntrospector: 核心内省引擎
     *   - PluginSchemaDiscovery: 发现协调器
     *   - BusinessCapabilityResolver: 初始化内置能力
     */
    protected function registerSchemaBindings(): void
    {
        $this->app->bind(SchemaIntrospector::class, function () {
            return new SchemaIntrospector();
        });

        $this->app->bind(PluginSchemaDiscovery::class, function (App $app) {
            return new PluginSchemaDiscovery(
                $app->make(SchemaIntrospector::class)
            );
        });

        BusinessCapabilityResolver::initBuiltin();
    }
}
