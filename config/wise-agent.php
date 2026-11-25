<?php
declare(strict_types=1);

return [
    // =========================================================================
    // 默认 Provider
    // =========================================================================
    'default' => env('AI_DEFAULT_PROVIDER', 'deepseek'),

    // AI 前端入口版本（用于 /statics/plugins/wise-agent/js/agent-chat.js?v= 缓存失效）
    // 该版本号同时透传给同目录的 agent-chat.css（该 CSS 由 JS 动态注入，不经过
    // 框架的 HTML 资源版本化），前端有改动时必须递增，否则会命中旧缓存
    // 1.8.0：前端会话存储由 localStorage 改为 sessionStorage（每标签页一个会话，见 agent-chat.js 头注释）
    // 1.9.0：AI 助手入口收敛到顶栏（点击开居中弹窗），移除右下角浮动按钮与右侧抽屉形态
    // 1.9.1：init() 幂等化，修「历史会话按钮点击无效」（重复 init 导致 toggle 被绑两次）
    'version' => '1.9.1',

    // =========================================================================
    // LLM Provider 配置
    //
    // 出网 TLS 相关（所有 provider 通用，可在 .env 覆盖）：
    //   AI_CA_BUNDLE  —— 自定义根证书 PEM 路径。企业代理 / 本地抓包工具（Clash、Charles）
    //                    做 TLS 中间人时会替换证书链，PHP 侧若无对应根证书会报
    //                    "SSL certificate problem: self-signed certificate in certificate chain"。
    //                    把代理根证书（或导出的 Windows 根证书）指向此处即可恢复「开启校验」。
    //   AI_SSL_VERIFY —— 是否校验证书，默认 true。仅本机调试可置 false，生产必须保持 true。
    // =========================================================================
    'providers' => [
        'openai' => [
            'class'      => \wise\agent\client\OpenAiClient::class,
            'api_key'    => env('OPENAI_API_KEY', ''),
            'base_url'   => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
            'model'      => env('OPENAI_MODEL', 'gpt-4o'),
            'timeout'    => 60,
            'max_retry'  => 2,
            'ssl_verify' => env('AI_SSL_VERIFY', true),
            'ca_bundle'  => env('AI_CA_BUNDLE', ''),
        ],
        'deepseek' => [
            'class'      => \wise\agent\client\DeepSeekClient::class,
            'api_key'    => env('DEEPSEEK_API_KEY', ''),
            'base_url'   => env('DEEPSEEK_BASE_URL', 'https://api.deepseek.com/v1'),
            'model'      => env('DEEPSEEK_MODEL', 'deepseek-v4-flash'),
            'timeout'    => 60,
            'max_retry'  => 2,
            'ssl_verify' => env('AI_SSL_VERIFY', true),
            'ca_bundle'  => env('AI_CA_BUNDLE', ''),
        ],
    ],

    // =========================================================================
    // Provider 级断路器（Circuit Breaker）
    // =========================================================================
    'circuit' => [
        'failure_threshold' => 5,
        'timeout'           => 60,
        'key_prefix'        => 'wise_agent:cb',
    ],

    // =========================================================================
    // SSE (Server-Sent Events) 配置
    // =========================================================================
    // 'sse' => [
    //     'timeout'          => 300,
    //     'heartbeat_interval' => 15,
    //     'retry'            => 3000,
    //     'stream_tool_calls' => true,
    //     'buffer_size'      => 1,
    // ],

    // =========================================================================
    // Agent 默认配置
    // =========================================================================
    'agent' => [
        'max_steps'           => 10,
        'max_tokens_per_step' => 4096,
        'temperature'         => 0.7,
        'default_type'        => 'simple',
        'system_prompt'       => 'You are the WiseAdmin AI assistant. You have access to tools to accomplish tasks. Always respond in the same language as the user. When you have enough information, provide the final answer directly. Format the answer in Markdown when it improves readability: fenced code blocks with a language tag, lists and tables.',
        'enable_memory'       => true,
        'session_ttl'         => 86400,
    ],

    // =========================================================================
    // 记忆配置
    // =========================================================================
    'memory' => [
        'default' => 'database',
        'stores'  => [
            'database' => [
                'class' => \wise\agent\memory\DatabaseMemory::class,
            ],
            'redis' => [
                'class'  => \wise\agent\memory\RedisMemory::class,
                'prefix' => 'wai:mem:',
            ],
        ],
    ],

    // =========================================================================
    // 内置工具配置
    // =========================================================================
    'tools' => [
        'file_read'         => ['class' => \wise\agent\tool\builtin\FileReadTool::class,         'enabled' => false],
        'file_write'        => ['class' => \wise\agent\tool\builtin\FileWriteTool::class,        'enabled' => false],
        'db_query'          => ['class' => \wise\agent\tool\builtin\DatabaseQueryTool::class,      'enabled' => false],
        'http_request'      => ['class' => \wise\agent\tool\builtin\HttpRequestTool::class,       'enabled' => false],
        'orchestrate_step'  => ['class' => \wise\agent\tool\builtin\OrchestrateStepTool::class,   'enabled' => true],
    ],

    // =========================================================================
    // Skill 技能配置
    // =========================================================================
    'skills' => [
        // 是否启用 Skill 系统
        'enabled' => true,

        // SKILL.md 文件目录（包内置 skills/ 目录，可覆盖）
        // 设为空字符串则仅从 extra_dirs 加载
        'dir' => '',

        // 额外的 SKILL.md 扫描目录（插件 skills 目录）
        // 示例: [app()->getRootPath() . 'plugin/cms/skills']
        'extra_dirs' => [],

        // 内置 Skill 类名映射（PHP 类方式，与 SKILL.md 互补）
        'builtin' => [
            // 内容类
            'content.title_generate'     => \wise\agent\skill\builtin\TitleGenerateSkill::class,
            'content.article_generate'   => \wise\agent\skill\builtin\ArticleGenerateSkill::class,
            'content.rewrite_humanize'   => \wise\agent\skill\builtin\RewriteHumanizeSkill::class,
            'content.summarize'          => \wise\agent\skill\builtin\SummarizeSkill::class,
            'content.translate'          => \wise\agent\skill\builtin\TranslateSkill::class,
            'content.seo_optimize'       => \wise\agent\skill\builtin\SeoOptimizeSkill::class,
            // 数据类
            'data.analyze'               => \wise\agent\skill\builtin\DataAnalyzeSkill::class,
            'data.report_generate'       => \wise\agent\skill\builtin\ReportGenerateSkill::class,
            'data.trend_predict'         => \wise\agent\skill\builtin\TrendPredictSkill::class,
            'data.anomaly_detect'        => \wise\agent\skill\builtin\AnomalyDetectSkill::class,
            // 系统类
            'system.orchestrate'         => \wise\agent\skill\builtin\OrchestrateSkill::class,
        ],

        // Skill 执行默认配置
        'defaults' => [
            'temperature'   => 0.7,
            'max_tokens'    => 4096,
            'max_steps'     => 5,   // Skill 通常比 Agent 循环少
            'enable_memory' => false,
        ],
    ],

    // =========================================================================
    // 日志配置
    // =========================================================================
    'log' => [
        'enabled' => true,
        'channel' => 'ai',
    ],

    // =========================================================================
    // 队列配置（异步 Agent 使用）
    // =========================================================================
    'queue' => [
        'connection'  => 'database',
        'job'         => \wise\agent\job\AiAgentJob::class,
        'pipeline_job' => \wise\agent\job\PipelineJob::class,
    ],

    // =========================================================================
    // 上下文注入配置
    // =========================================================================
    'context' => [
        // 是否启用上下文注入
        'enabled' => true,

        // 内置上下文注入器
        'builtin' => [
            \wise\agent\context\builtin\SystemContextProvider::class => true,
            \wise\agent\context\builtin\UserContextProvider::class  => true,
        ],
    ],

    // =========================================================================
    // 流水线（Pipeline）配置
    // =========================================================================
    'pipeline' => [
        // 是否启用 Pipeline 系统
        'enabled' => true,

        // 最大执行时间（秒）
        'max_duration' => 600,

        // 默认重试次数
        'default_retries' => 0,

        // 预定义流水线（声明式）
        'definitions' => [
            // 示例：CMS 自动发布流水线
            // 'cms.auto_publish' => [
            //     'description' => '从主题到发布的全自动流水线',
            //     'triggers' => ['manual', 'schedule'],
            //     'source' => 'plugin:cms',
            //     'steps' => [
            //         ['skill' => 'content.title_generate', 'input_mapping' => ['topic' => '$input.topic', 'count' => 5], 'output_key' => 'titles'],
            //         ['skill' => 'content.article_generate', 'input_mapping' => ['title' => '$input.selected_title'], 'output_key' => 'article'],
            //         ['skill' => 'content.rewrite_humanize', 'input_mapping' => ['content' => '$steps.article'], 'output_key' => 'final'],
            //     ],
            // ],
        ],
    ],

    // =========================================================================
    // 调度配置
    // =========================================================================
    'schedule' => [
        'enabled' => true,

        // 预定义定时任务
        'tasks' => [],
    ],

    // =========================================================================
    // 反馈配置
    // =========================================================================
    'feedback' => [
        'enabled' => true,

        // 低分阈值（低于此值触发预警）
        'low_rating_threshold' => 3.5,

        // 最少反馈数才参与统计
        'min_count' => 5,
    ],

    // =========================================================================
    // 安全配置
    // =========================================================================
    'security' => [
        'max_tool_executions_per_step' => 5,
        'max_tool_execution_time'      => 30,   // 单工具最大执行秒数
        'max_agent_duration'          => 300,
        'max_pipeline_step_duration'  => 120,  // Pipeline 单步最大执行秒数

        // ---------------------------------------------------------------------
        // AI 能力权限校验（工具 + 技能）
        // ---------------------------------------------------------------------

        // 工具权限校验总开关。关闭后所有 AI 工具不再做权限校验（应急用，不建议生产关闭）
        'tool_permission_check' => true,

        // 技能权限校验总开关。技能是编排入口，关闭等于开放任意技能执行
        'skill_permission_check' => true,

        // 控制台 / 队列等无 HTTP 请求的上下文是否视为可信系统身份（放行并记日志）。
        // 定时任务、pipeline、队列消费依赖此开关；HTTP 匿名请求不适用。
        'trust_console_context' => true,

        // 声明式授权策略（由 wise\agent\listener\CapabilityAuthorizePolicy 读取，工具与技能共用）
        // 命中 deny  → 硬拒绝，且不再走 RBAC 兜底
        // 命中 allow → 直接放行（无需给角色分配权限）
        // 均未命中   → 回落 wise-auth RBAC（超级管理员直通）
        'tool_authorize' => [
            // 允许清单：'*' | 能力名(orchestrate_step) | 权限标识(ai.tool.db_query)
            //          | 前缀通配(ai.skill.*) | 访问类别(access:read / access:write)
            'allow' => [],
            // 拒绝清单：优先级高于 allow 与 RBAC
            'deny'  => [],
            // 未登录会话是否放行（默认 false，仅建议可信内网 / 调试环境开启）
            'allow_anonymous' => false,
        ],

        // AI 能力权限注册（写入 wise-auth 权限表，供后台按角色可视化勾选）
        // 同步入口：php think wise:agent:permissions / 后台「权限配置 → 更新权限列表」
        // 权限树：AI 能力 → [全部 AI 能力 | 只读能力 | 写入能力 | 编排执行 | 管理能力]
        'tool_permissions' => [
            // 权限树分组名称
            'group_name' => 'AI 能力',
            // 是否生成「全部 AI 能力」通配 ai.capability.*（一键全开）
            'include_all_wildcard' => true,
            // 是否生成类别通配 ai.access.{read|write|execute|admin}.*（日常勾选单元）
            'include_access_wildcard' => true,
            // 是否生成汇总标识 ai.tool.* / ai.skill.* / plugin:{ident}.auto.*
            'include_aggregate_wildcard' => true,
            // 是否注册技能权限 ai.skill.{name}
            'include_skills' => true,
            // 细粒度层级：aggregate = 自动生成工具按「插件 × 操作类型」聚合（推荐）
            //             full      = 精确到「插件 × 操作类型 × 表名」
            'detail_level' => 'aggregate',
            // 声明式补充（插件 / 业务方可只写配置）：
            // 'plugin:demo.auto.*' => ['name' => '演示插件 AI 工具', 'access' => 'write'],
            'items' => [],
        ],

        // 审批策略（与权限解耦：权限决定「能不能调用」，审批决定「本次是否需要人工介入」）
        //
        // 规则优先级（首个命中生效）：
        //   1. rules.capabilities  按能力标识（精确 / 前缀通配），用于例外
        //   2. rules.risk          按风险等级 low | medium | high
        //   3. rules.access        按访问类型 read | write | execute | admin
        //   4. default             兜底
        //
        // 模式：none（直接执行）| confirm（需人工确认）| workflow（需提交审批）
        // 说明：能力自身 requireConfirmation()=true 视为下限，策略只能加严不能放宽。
        //      命中 confirm / workflow 时会生成审批单（ws_ai_approvals）并安全阻断；
        //      批准后申请人重新发起同一调用，命中审批单并原子消费一次后放行（不重放历史调用）。
        'approval' => [
            'enabled' => true,
            'default' => 'none',
            'rules'   => [
                // 高危能力一律需要确认（如 delete / 出库 / 外部系统调用）
                'risk'         => ['high' => 'confirm'],
                // 管理类能力默认需要确认
                'access'       => ['admin' => 'confirm'],
                // 例外示例：
                // 'plugin:*.auto.delete.*' => 'workflow',
                // 'ai.tool.db_query'       => 'confirm',
                'capabilities' => [],
            ],

            // 审批单参数
            'request' => [
                // 有效期（秒）；0 = 永不过期
                'ttl' => 900,
                // 一张审批单可放行的调用次数
                'max_uses' => 1,
                // 匹配范围：capability = 申请人 + 能力即可；arguments = 还需调用参数完全一致
                'scope' => 'capability',
                // 被驳回后的冷静期（秒），期内不允许重复申请；0 = 关闭
                'reject_cooldown' => 300,
            ],

            // 审批单生成后需要通知的管理员用户 ID（需安装 wise-notification；为空则不发通知）
            // 解析优先级：notify_user_ids → workflow.approvers → 拥有
            // admin.ai_approval.approve 权限的用户 → 超级管理员（super_admin_ids）
            'notify_user_ids' => [],

            // wise-workflow 集成（可选，未安装时审批单表自身即状态机，功能不受影响）
            'workflow' => [
                // 工作流实例开关。开启后每张审批单建立 ai_approval:{id} 实例，
                // 可用 Workflow::getHistory() 审计流转，并在审批详情页展示
                'enabled' => true,
                // 会签审批人（用户 ID）。为空 = 单人审批（谁有审批权限谁可处理）
                'approvers' => [],
                // 会签策略：any=或签（任一人通过即流转）| all=会签（全部通过才流转）
                'policy' => 'any',
            ],
        ],
    ],

    // =========================================================================
    // 框架命令内省执行（A4：php think 命令 → AI 可调用工具）
    // =========================================================================
    // 把框架 / wise-* 包 / 插件命令内省为 AI 工具，在「白名单 + RBAC + 审批」护栏下执行。
    // 不做泛化 shell：命令前缀锁定 `php think`，底层走 CommandTool 子进程隔离执行。
    //
    // 内省时机：
    //   - CLI 进程：直接读 Console::all()（插件命令仅在 CLI 注册，见 §8）；
    //   - HTTP 进程：借 `php think wise:agent:console-dump --json` 子进程导出并缓存。
    'console' => [
        // 总开关：false 时完全不内省 / 不注册任何命令工具（默认关闭，需显式开启）
        'enabled' => false,

        // 白名单（前缀通配）：'*' 全开（不推荐）、'wise:*'、'cache:*' 等
        'allow' => [
            'wise:agent:*', 'cache:*', 'migrate:status', 'queue:status', 'list', 'help',
        ],

        // 拒绝清单（优先级高于 allow）
        'deny' => [
            'migrate:run', 'migrate:rollback', 'install:*', 'plugin:disable:*',
        ],

        // 写类 / 管理类命令是否强制确认（管理类恒为 true，不受此开关影响）
        'write_requires_confirmation' => true,

        // 命令结果结构化（§11）：
        //   true  → 优先让命令输出结构化结果（命令声明了 --json 时自动追加），
        //           AI 拿到 JSON 而非人读文本；
        //   false → 一律按文本处理（去 ANSI + 截断标记）。
        'prefer_json' => true,

        // ── 以下三项决定「模型看到的命令工具长什么样」（A4 §6 补充）──────────
        // 类型 / 枚举推断：从参数描述里的显式标注推导 JSON Schema 类型
        //   「整数 / 整型 / int」→ integer；「小数 / 浮点 / number」→ number；
        //   「可选值：a, b, c」「取值范围: 1|2|3」→ enum
        // 关闭后一律按 string / boolean / array<string> 下发（与早期行为一致）。
        'schema_inference' => true,

        // setHelp() 摘要注入工具 description 的字符上限（0 = 不注入）
        // 框架默认只内省 getDescription()，setHelp() 里的 usage / 示例模型看不到，
        // 开启后取前 N 个字符压成单行补进工具描述（避免多行文本稀释 token）。
        'help_max_length' => 300,

        // 命令工具数量上限（0 = 不限）
        // 每条命令 = 一个功能工具且每轮全量注入，白名单放太宽会稀释模型注意力。
        // 超限时按风险从低到高保留（read → write → admin），同风险保持内省顺序。
        'max_tools' => 0,

        // ── 两段式工具面（token 优化）────────────────────────────────────
        // false（默认）：每条命令 = 一个 function（cmd_xxx），schema 每轮全量下发。
        //                本机实测 14 条命令 ≈ 3.1k token/**每一步**（Agent 多步循环会重复付）。
        // true         ：只下发 cmd_search + cmd_run（≈0.3k token/步）。
        //                具体命令以 hidden 方式留在 ToolRegistry（不下发、仍可被
        //                ToolSecurityCheck 按 ai.cmd.{name} 校验），模型先检索再执行。
        // 安全语义完全不变：白名单、ai.cmd.* 权限、审批策略、参数白名单映射、审计都照旧。
        // 代价：首次使用命令前多一轮 cmd_search（多一次往返）。
        // 可用环境变量 AI_AGENT_TWO_STAGE=1 临时开启，便于 A/B 对比 token 消耗。
        'two_stage' => (bool) env('AI_AGENT_TWO_STAGE', false),

        // 两段式 cmd_search 默认返回条数（内部上限 50）
        'two_stage_search_limit' => 20,

        // 执行参数（透传 CommandTool）
        'timeout'           => 60,     // 单命令最大秒数（仅 proc_open 方法可强制生效）
        'max_output_length' => 65536,  // 输出截断（64KB）

        // 子进程执行方法：'auto' | 'proc_open' | 'exec' | 'shell_exec' | 'passthru'
        //   auto（默认）→ POSIX 用 proc_open（支持硬超时）；Windows 交给 CommandTool 自选（exec 优先）
        // 为什么 Windows 不用 auto=proc_open：PHP 在 Windows 上对 proc_open 管道做 stream_select
        //   不可靠，输出超过 8KB 后 select 不再报告可读 → 子进程写满管道阻塞 →
        //   proc_terminate 只杀 cmd.exe 外壳、proc_close 一直等待孙进程（实测 `php think list` 会卡住）。
        'method' => 'auto',

        // 内省结果缓存 TTL（秒）；PluginInstall/Uninstall 会通过失效标记自动重建
        'cache_ttl' => 3600,

        // 插件命令白名单：插件命令需在此显式声明才暴露（延续「不写也能用，写则可控」）
        // 'plugin:match3game:daily' => true,
        'plugin_commands' => [],
    ],

    // =========================================================================
    // 控制器动作内省执行（A1：CrudTrait 标准动作 → AI 可调用工具）
    // =========================================================================
    // 把宿主 / 插件后台控制器的 CrudAction 标准动作内省为 AI 工具并就地执行。
    //
    // 【A1-5 定稿原则：AI 代替人工 ⇒ 权限与人工完全一致；可以少于人工，绝不放大】
    //   · 权限判定**不另造一套**：动作调用被套进「人工同款中间件链」（见下方 pipeline），
    //     由链上的 RbacMiddleware 用 route + method 匹配 auth_permissions 的行动作级权限点；
    //   · 注册期还会过滤掉「无行动作级权限点」的动作（require_human_reachable）——
    //     whitelist 模式下人工访问这类路由同样被拒，故不该生成必然失败的入口；
    //   · `menu.action.*` 仅作 deny-only 加严（require_ai_permission=true 时才暴露）；
    //   · 写动作默认落只读沙箱（read_only），预演同样在中间件链内执行（无权限者连预演也拿不到）。
    // 命名空间：wise\agent\menu\ / 方案与验收见 docs/wise-agent-a1-5-implementation-report.md
    'menu_actions' => [
        // 总开关：false 时不内省、不注册任何动作工具（默认关闭，需显式开启）
        'enabled' => false,

        // ── A1-1：参数 Schema 推导（MenuActionSchemaResolver）──────
        // getList：字段数量超过该值时不逐字段展开，退化为通用 filters
        'max_filter_fields' => 20,
        // getList：筛选属性总数上限（超出即退化，预留 1 个槽位给 filters）
        'max_filter_properties' => 80,
        'default_limit' => 20,
        'max_limit'     => 100,
        // CrudAction::sort() 的排序字段白名单（与源码同源，改这里必须同步改源码）
        'sort_fields' => ['sort', 'weigh', 'order_weight'],
        // 敏感字段剔除（避免把口令/密钥暴露进 LLM Schema）
        'excluded_fields' => [
            'password', 'passwd', 'pwd', 'secret', 'token', 'salt',
            'api_key', 'private_key', 'credential', 'remember_token',
        ],
        'excluded_suffixes' => [
            '_password', '_passwd', '_secret', '_token', '_hash', '_salt',
            '_api_key', '_private_key', '_credential',
        ],
        // 是否把 defineForm() 未声明但 DB 存在的列也纳入字段目录（便于 getList 按这些列筛选）
        'merge_db_fields' => false,

        // ── A1-2 / A1-4：待接线项（此处先占位，避免后续散落魔法默认值）──
        // 写类动作是否强制确认（admin / high 风险恒为 true，不受此开关影响）
        'write_requires_confirmation' => true,
        // 无菜单引用的控制器是否暴露（无权限锚点默认不暴露）
        'allow_unmapped_controllers' => false,
        // ── A1-5：人工等价（权限判定完全复用 HTTP 中间件链）──────────
        // AI 的动作调用会被套进「人工同款中间件链」执行（见 wise\agent\menu\MenuActionPipeline），
        // 因此权限判定的唯一来源是链上的 RbacMiddleware：
        //   route(/{app}/{controller}/{action}) + method → auth_permissions 行动作级权限点 → checkAny/checkAll
        // 结果是「AI 的访问面 ≤ 人工」：人工做不了的动作，AI 同样被拒
        // （含 default_mode=whitelist 下「无权限点 = 拒绝」、IP 守卫、2FA、权限版本检查）。
        'pipeline' => [
            // 顺序与 app/admin/middleware.php 严格一致（除类注释里登记过的豁免）
            \wise\login\middleware\IpGuardMiddleware::class,
            \wise\auth\middleware\RbacMiddleware::class,
            \wise\login\middleware\TwoFactorMiddleware::class,
            \app\common\middleware\XssFilterMiddleware::class,
            \wise\operationlog\middleware\AuditLog::class,
            \wise\operationlog\middleware\OperationLog::class,
        ],
        // 链上中间件「类存在但无法实例化」时是否拒绝执行（fail-closed）。
        //   true（默认）→ 安全语义缺失就不放行；false → 跳过该中间件（形成已知缺口，不推荐）
        // 注意：类**不存在**（对应包未安装）会被从链中剔除，不算失败。
        'pipeline_fail_closed' => true,

        // 是否要求「行动作级权限点存在」才注册工具（默认 false）。
        //   false → 注册期只做动作白名单过滤；可达性由运行时 RbacMiddleware 判定
        //           （超管在路由匹配前直通，因此「无权限点」不等于「不可调用」）
        //   true  → whitelist 模式下无权限点的动作不生成工具（非超管视角更干净，
        //           但会因权限数据缺失而 fail-closed，CLI 冷启动时工具可能变少）
        'require_human_reachable' => false,

        // 审计来源标记：追加到合成请求的 User-Agent，最终落到 audit_log.user_agent（零 schema 变更），
        // 使「AI 代做」与「人工操作」可区分；权限与判定仍然完全一致。
        'audit_user_agent_marker' => 'WiseAgent/menu-action',

        // 第C层（加严）：是否要求额外的 menu.action.* AI 面权限
        //   false（默认）→ 工具 getPermission() 返回空串，不产生任何人工没有的权限面
        //   true         → 在人工判定之上再叠一层 RBAC（只能更严，不可能放松）
        'require_ai_permission' => false,

        // 第D层（加严）：只读沙箱（写动作降级为 dry-run 预演；预演同样在中间件链内执行）
        'read_only' => true,
        // 预演抽样条数
        'preview_rows' => 5,
        // 行级 owner 隔离（可选，默认关）
        'owner_scope' => ['enabled' => false, 'field' => 'admin_id'],
        // 控制器白/黑名单（支持 FQCN 前缀通配，如 'app\admin\controller\*'、'plugin:smartstock.product'）
        'allow_controllers' => [],
        'deny_controllers'  => [],
        // 额外动作白名单：控制器自定义 public 动作，格式 '{controllerClass}::{action}'（默认保守为 write/medium）
        'extra_actions'     => [],
        // 额外控制器白名单：无菜单引用的控制器（无权限锚点），仅在 allow_unmapped_controllers 开启时生效
        'extra_controllers' => [],
        // 内省缓存 TTL（秒）；插件装/卸会通过 cache_invalidated.flag 自动失效
        'cache_ttl' => 1800,

        // ── A1-3：执行器 ──
        // 入参是否按动作 Schema 严格校验（Schema 退化出 filters 通用模式时自动放宽）
        'strict_parameters' => true,
        // ids 长度上限（防批量误操作）
        'max_ids' => 100,
        // 文本模式结果截断长度
        'max_text_length' => 4000,
    ],

    // =========================================================================
    // 自然语言列表查询（A3：NL2Query）
    // =========================================================================
    // 把自然语言转译为**受约束的查询参数**（不是 SQL）：LLM 只输出结构化意图 QueryIntent，
    // 字段白名单、操作符、日期、枚举/关联取值全部由确定性代码处理，最终执行默认回到 A1
    // 的菜单动作（权限、审计、结果契约与人工操作完全一致）。
    //
    // 阶段：A3-1 已落地「字段目录 + 时间词解析」；A3-2 意图编译；A3-3 工具与 Prompt 预算；
    //       A3-4 接入 A1 执行面。方案见 docs/wise-agent-next-upgrade-plan.md 附录三。
    'list_query' => [
        // 总开关
        'enabled' => false,
        // 查询目标：由 A1 内省结果自动产出；也支持手工声明
        //   'smartstock.product' => [
        //       'controller'    => 'plugin\smartstock\controller\Product',
        //       'mode'          => 'builder',        // builder | model_search | auto
        //       'search_method' => 'search',         // mode=model_search 时使用
        //       'aliases'       => ['amount' => 'total_amount'],   // 对外键名 → 真实列名
        //   ],
        'targets' => [],
        // 是否从 A1 的菜单动作内省结果自动生成 target（recommended）
        'auto_from_menu_actions' => true,

        // ── 意图编译上限（防爆 Prompt / 防慢查询）──
        'max_filters'        => 12,
        'max_limit'          => 100,     // 最终仍受 paginate.max_list_rows(1000) 约束
        'default_limit'      => 20,
        'max_fields'         => 20,      // 字段投影上限
        'max_enum_values'    => 16,      // 超出则只给候选个数，由 lq_fields 按需拉取
        'max_prompt_chars'   => 4000,    // 目标清单注入上限
        'max_filter_fields'  => 20,      // 单 target 注入的维度数量上限

        // ── 值接地（A3-2）──
        'value_cache_ttl'    => 600,     // 关联/枚举接地结果缓存秒数
        'max_lookups'        => 3,       // 单次查询内关联接地次数上限（防 N+1）
        'ambiguous_as_code'  => 2,       // 歧义返回码（与失败 code:1 区分，便于 Agent 追问用户）

        // ── 安全 ──
        'allow_relations'    => false,   // 是否放开 with/with_count（Builder 对它们不做白名单校验）
        'allow_group_having' => false,   // 是否放开 group/having
        'owner_scope'        => ['enabled' => false, 'field' => 'admin_id'],

        // ── 词表 ──
        // 同义词：键或真实列名 → 词表（「金额大于 1000」→ amount）
        'synonyms' => [
            // 'amount'      => ['金额', '总额', 'money'],
            // 'region'      => ['区域', '地区', '大区', '华东区'],
            // 'create_time' => ['下单时间', '创建时间', '日期'],
        ],
        // ── A3-4：执行路径 ──
        // A1 不可用（menu_actions.enabled=false 或动作未内省到）时，是否退到 A3 的独立执行路径。
        // 独立执行同样把「取数」放进人工同款中间件链（权限/审计一致），仅「谁来查」不同。
        'standalone_fallback'      => true,
        // 被重写的 getList 的筛选能力判定策略（见 ListQueryTarget::FILTER_* 三态）：
        //   strict（默认）→ 重写过的 getList 不默认可筛选（有构建器引用记 unknown，仍需显式声明）；
        //   trust         → 方法体引用构建器即视为可筛选（有误判风险，实测反例见 LoginLog::getList）
        'customized_getlist_policy' => 'strict',
        // 无行动作级权限点、且未使用查询构建器的目标是否直接从目标清单剔除（默认 true）
        'skip_filter_unsupported'  => true,
        // 行级隔离（默认关闭 ⇒ 与人工一致；开启则 AI 更严，属允许的缩小）
        'owner_scope' => ['enabled' => false, 'field' => 'admin_id'],

        // ── A3-3：工具与上下文 ──
        // 是否把「可查询目标 + 精简维度」注入 system 上下文（完整目录由 lq_fields 按需拉取）
        'inject_context'        => true,
        // 上下文优先级（数值越大越靠前；默认晚于 SchemaContextProvider(30)、早于业务能力(25)）
        'context_priority'      => 28,
        // 注入的目标数量上限（超出提示用 lq_fields 查看）
        'max_targets'           => 20,
        // deny-only：默认不产生人工没有的权限面（lq_query / lq_fields）
        'require_ai_permission' => false,

        // 时间词解析时区（null = config('app.default_timezone')）
        'time_zone' => null,
    ],

    // =========================================================================
    // 表单 Schema 内省（A2：消费 CrudSchemaTrait::defineForm()）
    // =========================================================================
    // 把控制器声明的表单变成 AI 可消费的目录与工具：
    //   · form_fields   —— 解释字段（A2-3）
    //   · form_fill     —— 自然语言 → 字段值（纯计算，A2-3）
    //   · form_validate —— 预检一条待提交数据（纯计算，A2-2，已接线）
    //   · form_generate —— 生成 defineForm() 草案（默认不写盘，A2-4）
    //
    // 硬边界（附录四 §1）：A2 **不引入任何写路径**。fill / validate 是纯计算，
    // 落库永远交给 A1 的 save / update（权限、审计、沙箱、确认全部继承 A1）。
    //
    // 阶段：A2-1 目录（FormSchema/FormFieldSchema/FormSchemaIntrospector）；
    //       A2-2 校验桥 + form_validate。方案见 docs/wise-agent-next-upgrade-plan.md 附录四，
    //       验收见 docs/wise-agent-a2-2-implementation-report.md。
    'form_schema' => [
        // 总开关：false 时不注册任何表单工具（默认关闭，需显式开启）
        'enabled' => false,

        // 表单目标：由 A1 内省结果（有 save/update 动作的控制器）自动产出；
        // 也支持手工声明：'host.permission' => 'app\admin\controller\Permission'
        //             或 'host.permission' => ['controller' => 'app\admin\controller\Permission']
        'targets' => [],

        // ── 逐个工具的开关（generate 默认关，见附录四 §8）──
        //   generate 默认关：它产出的不是数据而是**代码声明**，而 defineForm() 是校验规则与
        //   字段可写性的来源 —— 改它 = 改未来的权限与校验语义（§8 的风险模型）。
        'tools' => [
            'fields'   => true,
            'fill'     => true,
            'validate' => true,
            'generate' => false,
        ],

        // ── 锚点权限（A2-4 / 验收 12）──
        // A2 **不新增权限概念**：判定复用 A1 同一条中间件链（MenuActionPipeline 内的
        // RbacMiddleware，route+method → auth_permissions），只是把业务执行体换成 no-op
        // （绝不借「判定」之名真跑一次 getList）。
        //   true （默认）→ 无该控制器锚点菜单权限的管理员调用 A2 目标时，得到与 A1 相同的 403；
        //   false        → 跳过判定（仅建议离线批处理场景使用）。
        // 「无请求上下文（CLI / discover / 单测）」与「控制器无锚点」两档不做判定并记 trace
        // （与 A1「中间件类不存在即视为未安装」同属非绕过的降级）；
        // 链上抛异常时按 menu_actions.pipeline_fail_closed（默认 true）拒绝。
        'check_anchor_permission' => true,

        // ── 上下文（A2-3）──
        'inject_context'   => true,
        // 数值越大越靠前；晚于 schema(30) / list_query(28)，早于业务能力(25)
        'context_priority' => 26,
        'max_targets'      => 20,
        'max_prompt_chars' => 3000,
        'max_fields'       => 30,      // 单目标注入字段上限
        'max_enum_values'  => 16,      // 超出只给个数，由 form_fields 按需拉取

        // ── 填充（A2-3）──
        'fill' => [
            'max_fields'           => 20,    // 单次填充字段上限
            'max_lookups'          => 3,     // 关联接地次数（同 A3）
            'value_cache_ttl'      => 600,
            'ambiguous_as_code'    => 2,     // 歧义返回码（与 A3 一致）
            'require_confirmation' => false, // 纯计算，默认无需确认；置 true 则仅产出草稿
        ],

        // ── 校验（A2-2）──
        'validate' => [
            'max_data_chars'          => 20000, // 待检数据体量上限
            'report_accepted_fields'  => true,  // 回传 DataFilter 后真正会写入的字段
        ],

        // ── 生成（A2-4：默认只出草案，不写盘）──
        'generate' => [
            'write_mode'    => 'never',        // never | assisted（附录四 §8）
            'allowed_paths' => [],             // assisted 模式下的目标路径白名单（相对应用根）
            'require_confirmation' => true,
            'style'         => 'crud_generator', // 复用 CrudGenerator 的字段类映射与写法
        ],

        // ── 可写性判定（A2-1 的 FormSchemaIntrospector）──
        // 真实写闸门来自模型 `$allowWriteField`（null = 不过滤 = 全部可写）；
        // 下列开关是 A2 在其之上叠加的**加严**（AI 可以少于人工，不可能多于人工）。
        // 敏感/系统字段恒不可写、不可放开（见 FormFieldSchema::DENY_* 常量）。
        'exclude_sensitive' => true,   // 敏感字段（password / *_token / *_hash …）既不写也不读
        'exclude_system'    => true,   // 系统字段（id / create_time / update_time …）不可写
        'fill_owner_fields' => false,  // 归属字段（admin_id / user_id / created_by …）默认不填
        'fill_hidden'       => false,  // hidden 类型字段默认不填

        // ── 安全（与 menu_actions 同语义）──
        // deny-only：false（默认）→ 工具 getPermission() 返回空串，不产生人工没有的权限面
        'require_ai_permission' => false,
        // 追加排除（在 A1-1 的 excluded_fields / excluded_suffixes 之上叠加；回退路径生效）
        'excluded_fields'       => [],
        'excluded_suffixes'     => [],
    ],

    // =========================================================================
    // 目标导向自治任务（B1：Plan → Execute → Verify → Close）
    // =========================================================================
    // 把一句业务目标变成一份**可校验、可中断、可续跑、可审计、可回放**的多步计划：
    //   · plan_run     —— 规划 + 执行（默认异步：可能含审批等待）
    //   · plan_status  —— 逐步状态查询
    //   · plan_resume  —— 批准后从被阻塞的那一步续跑（重发同一份参数以命中审批单）
    //
    // 硬边界（附录五 §1/§6.2）：执行器**不做授权**，写步骤一律走既有工具，
    // 于是 ToolSecurityCheck（权限+审批）与 MenuActionPipeline（RBAC/审计）原样生效。
    //
    // 方案见 docs/wise-agent-next-upgrade-plan.md 附录五，
    // 实施与验收见 docs/wise-agent-b1-1..b1-4-implementation-report.md。
    'plan' => [
        // 总开关：false 时不注册 plan 工具、不注册物化外壳节点（默认关闭）
        'enabled' => false,

        // ── Plan 阶段 ──
        'planner'              => 'llm',   // llm | manual（manual = 只接受显式传入的 steps，不调 LLM）
        'provider'             => '',      // 规划用的 LLM Provider（空 = 用 wise-agent.default）
        'max_plan_repairs'     => 2,       // 计划不合法时的回环修复次数（用尽即终止，不执行任何步骤）
        'max_catalog_chars'    => 4000,    // 能力目录注入上限（超阈值逐级丢描述/参数，能力名优先保住）
        'max_catalog_entries'  => 200,     // 目录条目上限（防插件装多了 Prompt 爆炸；超出计入 hidden）
        'planner_max_tokens'   => 4096,
        'planner_temperature'  => 0.2,     // 规划要稳，不用 0.7

        // ── Execute 阶段 ──
        'max_steps'            => 12,      // 计划步骤数上限
        'max_write_steps'      => 5,       // 写步骤数上限（防批量误操作）
        'max_step_duration'    => 120,     // 单步超时（秒）——由 PlanExecutor **事后**判定（PHP 同步执行无法抢占）
        'max_plan_duration'    => 180,     // 整个计划时长上限（秒）
        'max_plan_tokens'      => 60000,   // 整个计划 token 预算（仅改道消耗）
        'default_async'        => true,    // 可能含审批等待 ⇒ 默认异步（避免长时间占用对话轮次）
        'default_retries'      => 0,       // 生成计划时给步骤的默认重试次数

        // ── Verify 阶段 ──
        'max_replans'          => 2,       // 「改道」次数上限（唯一在 Execute 阶段调 LLM 的地方）
        'verify' => [
            'allow_schema_valid' => true,  // 允许 schema_valid 断言（复用 A2 的 ValidationGenerator 语义）
            'strict_tool_ok'     => true,  // 工具步骤默认断言 code=0
        ],

        // ── 安全（计划级加严，不改全局审批行为）──
        // 既有审批链在「无登录用户」的上下文（CLI/队列/定时任务）会**跳过审批**
        // （ApprovalManager.php:78-84）。默认（true）⇒ 含写步骤的计划在该上下文**拒绝执行**，
        // 而不是静默跳过审批；置 false 则放行并在结果里显式标注 system_context_writes。
        'require_user_context_for_writes' => true,
        'allowed_kinds'        => ['skill', 'tool', 'action', 'node'],
        'deny_capabilities'    => [],      // 能力名黑名单（前缀通配），如 'menu.action.*.forcedelete'
        'require_ai_permission'=> false,   // deny-only：默认不产生人工没有的权限面（同 A1-5/A2/A3）

        // ── 工具开关 ──
        'tools' => ['run' => true, 'status' => true, 'resume' => true],

        // ── 上下文 ──
        'inject_context'       => false,   // 是否把「可规划能力目录」注入常驻上下文（默认关，避免 Prompt 膨胀）
        'context_priority'     => 24,      // 低于 schema(30)/list_query(28)/form_schema(26)/业务能力(25)：目录属辅助信息，最后注入

        // ── 落库表名（B1-3；留出覆盖口，便于多库/多前缀场景）──
        'table' => [
            'runs'  => 'ai_plan_runs',
            'steps' => 'ai_plan_steps',
        ],
    ],

    // =========================================================================
    // 业务闭环连接器（B2：审批 → 通知 → 外部联动 → 审计/回放）
    // =========================================================================
    // 把 AI 链路上「本来就该有」的四环接上：**触发只用既有事件**（ApprovalRequested /
    // ApprovalDecided / ToolExecute(AFTER) / AgentComplete），**能力只用既有包**
    // （wise-notification / wise-webhook / wise-operation-log），重试下沉、失败留痕、
    // 可补投、可按 trace_id 回放。
    //
    // 硬边界（附录六 §1/§7）：连接器**不判定授权**（审批/RBAC 仍在既有链路），
    // 派发**绝不抛异常**（ToolExecute 的派发在 AgentManager 里未包 try/catch，
    // 抛异常会打断 AI 主链路），超时只告警**不改写投递结果**（否则补投会重复发送）。
    //
    // 方案见 docs/wise-agent-next-upgrade-plan.md 附录六，
    // 实施与验收见 docs/wise-agent-b2-1..b2-4-implementation-report.md。
    'closure' => [
        // 总开关：false 时不注册监听器、不产生任何行为（默认关闭）
        'enabled' => false,

        // ── 连接器 ──
        // 只装配**类存在**的连接器；未实现/未安装的名字不会注册，投放时记为
        // skipped(connector_not_registered)，并由 ClosureConnectorFactory::unavailable() 给出原因。
        // 也支持直接写类名（需实现 ClosureConnectorInterface，构造约定 (array $options, ?PayloadMasker $masker)）。
        'connectors' => ['notification', 'webhook', 'audit'],

        // ── 场景路由（唯一判定处：ClosureRouter）──
        'scenes' => [
            'approval_requested' => ['notification', 'webhook'],
            'approval_decided'   => ['notification', 'webhook', 'audit'],
            'tool_executed'      => ['audit'],
            'agent_completed'    => ['notification'],
            'task_failed'        => ['notification', 'audit'],
        ],
        // 哪些场景写审计；`audit_write_only=false` 时连读操作也审计（全量审计）
        'audit_scenes'            => ['approval_decided', 'tool_executed', 'task_failed'],
        'audit_write_only'        => true,
        // 读操作不触发通知/webhook（避免一次对话刷出几十条）；access 未知时**保守纳入**（漏审计代价更高）
        'only_write_capabilities' => true,
        'risk_levels'             => ['medium', 'high'],
        // 单连接器软超时（毫秒）：**只记 slow 告警，不改写投递结果**
        'timeout_ms'              => 3000,

        // ── 载荷（入库前脱敏；连接器出站时再各自过一遍）──
        // 空 = 用 PayloadMasker::DEFAULT_FIELDS；SAFE_FIELDS（total_tokens 等计数）豁免恒生效
        'mask_fields'       => [],
        'mask_depth'        => 4,
        'max_payload_chars' => 2000,

        // ── 通知 ──
        'notification' => [
            'templates' => [   // 模板 code（**必须先在 notification_templates 里存在**；缺失则用兜底文案 + note）
                // 'approval_decided' => 'ai_closure.approval_decided',
            ],
            'channels_by_scene' => [
                'approval_requested' => ['database', 'email'],
                'approval_decided'   => ['database'],
                'agent_completed'    => ['database'],
                'task_failed'        => ['database'],
            ],
            // 双发去重：ApprovalManager 的内置站内信**无开关**，故 B2 在这些场景默认跳过 database 通道
            'skip_channels_by_scene' => [
                'approval_requested' => ['database'],
                'approval_decided'   => ['database'],
            ],
            'takeover' => false,   // true = 发全通道（但内置站内信仍会重复，无法通过配置消除）
            // 收件人：留空 ⇒ 发给事件发起人；approval_requested 的审批人名单**不在事件里**，必须显式配置
            'recipients' => [
                // 'approval_requested' => [1, 2],
            ],
            'locale' => 'zh_CN',
            'sync'   => false,   // false = 交给 wise-notification 的队列与重试（status=queued 同样记 ok）
        ],

        // ── 外部联动 ──
        'webhook' => [
            // scene => url（**URL 只能来自这里**：不接受事件字段/LLM 提供，避免"往任意地址发数据"）
            'endpoints'         => [
                // 'approval_decided' => 'https://erp.example.com/hooks/ai',
            ],
            'secret'            => '',        // 空 = 用 wise-webhook 自己的 server.secret
            'dispatch'          => 'queue',   // queue（默认，避免拖慢 AI 主链路）| sync
            'max_payload_chars' => 8000,      // 只截 data，信封关键字段不动
        ],

        // ── 审计 ──
        'audit' => [
            // storage（默认，确定性）| event（走 wise-operation-log 的缓冲通道 + 主动 flush）
            // ⚠️ 常驻队列 worker 里 shutdown flush 不触发，故默认直存
            'via'     => 'storage',
            'channel' => 'ai.closure',
        ],

        // ── 批准后自动续跑计划（opt-in；不是绕过审批：仍走 PlanExecutor::resume()，
        //    写操作重发时仍被 gate() 匹配并原子消费一次审批单）──
        'auto_resume_plans' => false,

        // ── 保留与落库 ──
        'retain_days' => 180,   // 仅供 `closure prune --days` 参考；不会自动清理
        'table'       => [
            'events'     => 'ai_closure_events',
            'deliveries' => 'ai_closure_deliveries',
            // 自动续跑要反查计划侧的表（与 plan.table.* 同一套名字）
            'runs'       => 'ai_plan_runs',
            'steps'      => 'ai_plan_steps',
        ],
    ],

    // =========================================================================
    // 任务中心（C1 / 附录七 §7）
    // =========================================================================
    'tasks' => [
        // false 时不注册任务中心路由/菜单（C1-3 的页面与 C1-2 的 API 均不注册）
        'enabled'       => false,
        // task-cleanup 的默认参考值（不会自动清理，仅供命令默认值）
        'retain_days'   => 90,
        // 「正在运行但心跳过期」的阈值（秒，与 ScanTimeouts 默认一致）
        'stale_seconds' => 600,
        'dead_letter'   => [
            // 死信是否通知任务发起人（走 B2 的 task_failed 场景）
            'notify' => true,
        ],
    ],

    // =========================================================================
    // 成本治理（C2 / 附录七 §7）
    // =========================================================================
    'billing' => [
        // 成本估算总开关（false 时不计算成本、不写 metadata.cost）
        'enabled' => false,
        // 定价表：provider => model => ['input'=>每千 token 价, 'output'=>…, 'currency'=>'CNY']
        // 未列出的模型走 '*:default'（默认 0 价 ⇒ 不估钱，priced=false）
        'pricing' => [
            '*:default' => ['input' => 0, 'output' => 0, 'currency' => 'CNY'],
        ],
        // 日汇总保留（C2-2 usage-rollup 用）
        'rollup'   => ['retain_days' => 730],
        // 配额（C2-3 QuotaGuard 用）
        'quota'    => [
            'enabled'                 => false,  // 配额总开关（与 billing.enabled 独立）
            'enforce'                 => false,  // false = 只观测；true = 拒绝
            'store'                   => 'redis',// 生产必须 redis（file 有 TOCTOU）
            'per_user_daily_tokens'   => 200000,
            'per_role_daily_tokens'   => 0,      // 0 = 不限
            'per_plugin_daily_tokens' => 0,
            'per_model_daily_calls'   => 0,
            'fail_mode'               => 'closed', // 配额 fail-closed（限流类可另配 open）
        ],
    ],

    // =========================================================================
    // 只读沙箱（C3 / 附录七 §7）
    // =========================================================================
    'sandbox' => [
        'enabled'              => false,
        'mode'                 => 'new_only',   // off | new_only | all_writes
        'trusted_capabilities' => [],           // 前缀清单（"已验收"的能力，不受沙箱影响）
        'since'                => '',           // Y-m-d；能力接入时间不可得时按"不在可信清单即视为新"
        'preview'              => [
            'menu_action_surface' => true,      // menu.action.* 复用 A1 preview() 给出真实影响面
            'max_preview_rows'    => 20,
        ],
    ],

    // =========================================================================
    // 灰度放量（C3 / 附录七 §7）
    // =========================================================================
    'gray' => [
        'enabled'      => false,
        'exempt_roles' => ['super_admin'],      // 超管始终放量（避免管理员锁死）
        'rules'        => [
            // 'capability_prefix*' => ['roles'=>[], 'users'=>[], 'percent'=>0, 'denied'=>[]],
            // '*' => ['percent' => 0],         // 兜底：未列出的能力一律不放量（进入沙箱）
        ],
    ],

    // =========================================================================
    // 知识检索（P3-D1 / docs/wise-agent-p3-design.md）
    // =========================================================================
    'knowledge' => [
        'enabled'   => false,
        'top_k'     => 4,
        'min_score' => 0.5,         // 低于此分不注入（过滤噪声）
        'max_chars' => 4000,        // 单次注入上下文截断上限
        'history'   => [
            'enabled' => true,      // 是否附带历史成功轨迹（走 TaskQueryService）
            'limit'   => 3,
        ],
    ],

    // =========================================================================
    // 反馈自优化（P3-D2 / docs/wise-agent-p3-design.md）
    // =========================================================================
    'feedback' => [
        'enabled' => false,
        'health'  => [
            'window_calls'           => 50,   // 统计窗口（最近 N 次调用）
            'fail_threshold'         => 3,    // 连续失败 N 次 ⇒ 降权（D2-2）
            'low_score'              => 3.5,  // 用户评分低于此 ⇒ 预警
            'failure_rate_threshold' => 0.5,  // 失败率高于此 ⇒ ToolHealthChecker 预警
        ],
        'few_shot' => [
            'enabled'   => false,
            'max_shots' => 5,                // 每次注入的最大 few-shot 数（D2-2）
        ],
    ],

    // =========================================================================
    // UI 挂件注入（P3-D3 / docs/wise-agent-p3-design.md）
    // =========================================================================
    'widget' => [
        'enabled'        => false,
        'assistant'      => true,            // 右下角 AI 助手悬浮入口
        'list_filter'    => true,            // 列表页自然语言筛选（对接 A3）
        'form_fill'      => true,            // 表单页 AI 智能填充（对接 A2）
        'detail_explain' => true,            // 详情页 AI 解读
    ],

    // =========================================================================
    // Web 路由（配置驱动，由 RouteRegistrar 读取）
    // =========================================================================
    'web' => [
        // 总开关：false 时本包不注册任何 HTTP 路由
        'enable' => true,
        // 默认路由前缀（分组级 prefix 可覆盖）
        'prefix' => 'admin/ai',
        // 全局默认中间件（所有分组生效）
        'middleware' => [],
        // 分组配置：key 对应 RouteRegistrar::define() 中的 group('admin', ...)
        'groups' => [
            'admin' => [
                'enable' => true,
                // ⚠️ 安全提示：涉及 AI 配置 / API Key / 会话数据，
                // 生产环境务必挂载 RBAC 或其他认证中间件：
                // \wise\auth\middleware\RbacMiddleware::class
                'middleware' => [],
            ],
            // 审批中心页（/admin/ai_approval/*，包内独立后台页）
            // 前缀规避 web.prefix，单独挂到 admin 应用的 ai_approval 相对前缀。
            'admin_approval' => [
                'enable'     => true,
                'prefix'     => 'admin/ai_approval',
                'middleware' => [],
            ],
            // 任务中心页（/admin/ai_task/*，C1-3；路由注册受 tasks.enabled 门控）
            'admin_task' => [
                'enable'     => true,
                'prefix'     => 'admin/ai_task',
                'middleware' => [],
            ],
            // AI 对话页（/admin/ai_chat/*，重构自 chat-page 静态 HTML）
            'admin_chat' => [
                'enable'     => true,
                'prefix'     => 'admin/ai_chat',
                'middleware' => [],
            ],
            // AI 设置页（/admin/ai_config/*，重构自 config-page 静态 HTML）
            'admin_config' => [
                'enable'     => true,
                'prefix'     => 'admin/ai_config',
                'middleware' => [],
            ],
            // 会话管理页（/admin/ai_session/*，B4-8）
            // 会话数据含用户对话原文，属于敏感数据，生产务必挂 RBAC。
            'admin_session' => [
                'enable'     => true,
                'prefix'     => 'admin/ai_session',
                'middleware' => [],
            ],
            // 执行日志页（/admin/ai_execution_log/*，B4-8）
            'admin_execution_log' => [
                'enable'     => true,
                'prefix'     => 'admin/ai_execution_log',
                'middleware' => [],
            ],
        ],
    ],

    // =========================================================================
    // Prompt 模板（B4-7）
    //
    // 内置模板见 `wise\agent\prompt\PromptTemplateManager::BUILTIN`；
    // 此处按 id 覆盖或新增，支持两种写法：
    //   'id' => ['system' => '...', 'variables' => [...]]
    //   'id' => ['messages' => [['role' => 'system', 'content' => '...']]]
    // 占位符用 {{ name }}；required 变量缺失会抛异常（不静默填空）。
    // =========================================================================
    'prompts' => [],

    // =========================================================================
    // MCP (Model Context Protocol) Server 配置
    // =========================================================================
    'mcp' => [
        // 总开关：是否启用 MCP HTTP 端点（/admin/ai/mcp）
        // 该端点会暴露所有 AI 工具（含写操作），生产环境启用前务必挂载认证中间件
        'enable' => false,

        // 是否启用 stdio 命令（php think wise:agent:mcp）
        'stdio' => true,

        // 是否把 config('wise-mcp.clients') 中声明的外部 MCP Server 工具导入 ToolRegistry。
        // 默认关闭：连接外部服务器可能 spawn 子进程/发起网络请求，开启后建议配合队列或定时任务，
        // 避免每个 HTTP 请求都重复连接。
        // 需同时安装 wiseadmin/wise-mcp 包。
        'import_external' => false,
    ],

    // =========================================================================
    // Schema 自动发现配置（Phase 1-4）
    // =========================================================================
    'schema' => [
        // 是否启用 Schema 自动发现
        // 启用后，安装插件时自动：
        //   1. 内省插件数据模型
        //   2. 生成标准 CRUD Tool（list/get/search/stats/create/update/delete/list_with_relation）
        //   3. 解析业务能力标签（inventory/sales/crm/content）
        //   4. 注入领域知识 Context
        'auto_discover' => true,

        // Schema 缓存目录（相对于 runtime 路径）
        'cache_dir' => 'wise-agent/schema',

        // Schema 缓存 TTL（秒），默认 1 小时
        'cache_ttl' => 3600,

        // 默认的安全配置（全局，可被插件 manifest 覆盖）
        'defaults' => [
            // 写操作默认需要确认
            'write_requires_confirmation' => true,

            // 自动排除敏感字段（password/token/secret 等）
            'auto_exclude_sensitive' => true,

            // 自动排除系统字段（id/create_time/update_time 等）
            'auto_exclude_system' => true,

            // 自动探测 owner_key（user_id/created_by/operator_id 等归属字段）
            'auto_detect_owner_key' => true,

            // 自动应用行级 owner 隔离（读操作仅返回当前操作者数据，
            // 超管不受限；创建时自动写入归属人）
            'auto_owner_scope' => true,
        ],

        // 第三方能力注册（可扩展）
        // 实现 BusinessCapability 接口，在此处注册即可。
        // 注册后在插件 manifest.json 的 business_capabilities 中引用对应标签。
        //
        // 内置能力（始终可用）：inventory, sales, crm, content
        // 示例能力（取消注释即启用）：ecommerce
        'capabilities' => [
            'ecommerce' => \wise\agent\schema\capability\examples\EcommerceCapability::class,
            // 'finance'   => \app\ai\capability\FinanceCapability::class,
            // 'workflow'  => \app\ai\capability\WorkflowCapability::class,
        ],
    ],
];
