# wise-agent

> 适用：WiseAdmin 1.0+ / PHP 8.1+ / ThinkPHP 6/8
> 状态：稳定
> 可独立使用：部分 —— 工具注册与 Pipeline 可单独用；模型调用、后台 UI 与能力发现依赖 WiseAdmin 宿主
> 直接依赖：wise-support

[![License](https://img.shields.io/badge/license-Apache%202.0-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%3E%3D8.1-8892BF.svg)](https://php.net)
[![ThinkPHP](https://img.shields.io/badge/ThinkPHP-6.0%20%7C%7C%208.0-1aad19.svg)](https://thinkphp.cn)
[![Package](https://img.shields.io/badge/package-wiseadmin%2Fwise--agent-orange.svg)](https://github.com/hiwiseadmin/wise-agent)

> WiseAdmin AI Agent —— 基于大语言模型的智能代理系统，支持多模型接入（OpenAI / DeepSeek）、对话记忆、工具调用与事件驱动扩展。集成了大模型接入、工具调用、技能编排、记忆系统、上下文注入、定时任务与 AI 插件桥接（AI Plugin Bridge）等能力，是 WiseAdmin 中「让 AI 操作业务数据」的统一底座。

---

## 目录

- [特性 / 核心能力](#特性--核心能力)
- [安装](#安装)
- [概念与架构](#概念与架构)
- [快速上手 / 使用示例](#快速上手--使用示例)
- [配置参考](#配置参考)
- [API / 方法参考](#api--方法参考)
- [事件 / 钩子](#事件--钩子)
- [Web 路由（HTTP API）](#web-路由http-api)
- [控制台命令](#控制台命令)
- [数据库表结构（DDL）](#数据库表结构ddl)
- [与其他 wise-* 包协作 / 扩展点](#与其他-wise--包协作--扩展点)
- [目录结构](#目录结构)
- [依赖关系](#依赖关系)
- [许可证](#许可证)

---

## 特性 / 核心能力

- **多模型接入**：内置 `OpenAiClient` / `DeepSeekClient`，通过 `AiClientFactory` 统一工厂创建，新增 Provider 仅需实现 `AiClientInterface` 并在 `config/wise-agent.php` 的 `providers` 中登记；`gpt-4o` / `deepseek-chat` 等模型即开即用。
- **ReAct 循环智能体**：`AgentManager` + `BaseAgent`（默认实现 `SimpleAgent`）封装「思考 → 调用工具 → 观察 → 再思考」的 agent loop，支持 `max_steps` 步数上限、每步 token 上限、温度等参数。
- **工具调用（Function Calling）**：`ToolRegistry` 统一管理所有工具（内置工具、业务包工具、插件工具、Schema 自动生成工具），通过 `ToolInterface` 标准化，自动生成 OpenAI 函数定义并注入提示词；`ToolSecurityCheck` 监听器做安全审计。
- **技能编排（Skill）**：`SkillManager` / `SkillRegistry` 提供可复用的高层能力（内容生成、数据分析、系统编排等），既可用 PHP 类实现，也可用 `SKILL.md` 声明式定义；支持评分、反馈与自我优化（`SkillOptimizer`）。
- **流水线（Pipeline）**：`PipelineEngine` / `PipelineRegistry` 支持声明式多步骤编排（步骤间输入输出映射、`$steps.xxx` 引用），用于「主题 → 标题 → 正文 → 润色」等复杂自动化场景。
- **记忆系统（Memory）**：`MemoryFactory` 以工厂模式切换 `DatabaseMemory`（默认）/`RedisMemory`；`SessionManager` 管理会话生命周期，`DatabaseMemory` 提供长期键值记忆。
- **上下文注入（Context）**：`ContextManager` 在每次 Agent 运行前注入系统/用户上下文（`SystemContextProvider`、`UserContextProvider`），让模型感知当前用户、权限与平台环境。
- **定时任务（Schedule）**：`AiTaskScheduler` / `AiTask` 支持 cron 表达式调度 pipeline / skill，状态持久化于 `ai_tasks` 表，可被 `wise:agent:discover` 或配置预定义。
- **异步执行与追踪**：基于 `think-queue`（`AiAgentJob` / `PipelineJob`）提交异步 Agent；`ExecutionTracker` 记录任务状态、进度与超时，`wise:agent:scan-timeouts` 清理卡死任务。
- **AI 插件桥接（AI Plugin Bridge）**：`PluginBridge` / `PluginAiDiscovery` 自动发现各业务插件暴露的 AI 能力（工具、工作流节点、适配器），是 wise-agent 与 `wise-*` 业务包解耦协作的核心机制。
- **Schema 自动发现**：`SchemaIntrospector` / `PluginSchemaDiscovery` 内省插件数据模型，自动生成标准 CRUD 工具、解析业务能力标签（inventory / sales / crm / content）、注入领域知识上下文；可 `materialize` 为 PHP 类固化。
- **事件驱动扩展**：贯穿生命周期的事件（`AgentStart`、`AgentStep`、`AiRequest`、`ToolExecute` …），默认由 `AiLogger` 记录、`ToolSecurityCheck` 审计；业务方可自由监听扩展。
- **Web 管理界面**：`AiController` 提供会话管理、流式聊天（SSE）、Provider/配置管理、工具/节点/适配器发现等 REST 端点，配套静态 HTML 页面（`chat-page`、`config-page`）。

---

## 安装

### 1. 通过 Composer 安装

```bash
composer require wiseadmin/wise-agent
```

### 2. 发布配置与静态资源

发布配置文件与后台静态 HTML 到应用目录：

```bash
# 发布配置文件 config/wise-agent.php
php think vendor:publish
# 发布包内 public/ 静态资源（后台 HTML 等）到 public/
php think wise:vendor:publish
```

安装时 `AgentServiceProvider::envSeeds()` 会向 `.env` 写入（或提示填写）：

| 键 | 类型 | 说明 |
| --- | --- | --- |
| `AI_ENCRYPT_KEY` | secret（自动随机生成） | 加密持久化的 AI Provider 配置 |
| `OPENAI_API_KEY` | external（用户填写） | OpenAI API Key |
| `DEEPSEEK_API_KEY` | external（用户填写） | DeepSeek API Key |

### 3. 执行数据库迁移

本包的迁移目录由 `AgentServiceProvider::boot()` 的 `registerMigrations()` 注册（默认 `{包根}/database/migrations`），因此需执行迁移创建 `ai_*` 数据表：

```bash
php think migrate:run
```

### 4. 发现 AI 能力（安装/更新插件后）

```bash
php think wise:agent:discover
```

---

## 5 分钟最小示例

> 前置：在 `.env` 填写 `OPENAI_API_KEY`（或 `DEEPSEEK_API_KEY`）；包依赖数据库表与 AI 能力发现。

```bash
php think vendor:publish
php think wise:vendor:publish
php think migrate:run
php think wise:agent:discover
```

```php
use function wise_agent_chat;

echo wise_agent_chat('用一句话解释什么是大模型');
```

## 概念与架构

wise-agent 以「**AgentManager 编排 + BaseAgent 执行循环 + 多能力子系统**」为核心，并通过 **AI 插件桥接** 与各个业务包协作。

```
                         ┌─────────────────────────────┐
   浏览器 / 调用方       │      AiController (REST/SSE)  │  RouteRegistrar (admin/ai/*)
        │                └───────────────┬─────────────┘
        │                                │
        ▼                                ▼
   ┌──────────────────────────────────────────────────────────┐
   │                    AgentManager（引擎编排）                 │
   │   run / runStream / resume / dispatchAsync / stop / configure│
   └───────────┬───────────────────────────────────┬───────────┘
               │                                   │
   ┌───────────▼──────────┐            ┌───────────▼──────────────┐
   │  BaseAgent / SimpleAgent  │            │      SkillManager           │
   │   ReAct 循环 (step loop)   │            │      PipelineEngine         │
   │   ├─ AiClientFactory       │            │      AiTaskScheduler         │
   │   │    OpenAiClient         │            │      FeedbackCollector       │
   │   │    DeepSeekClient       │            └────────────────────────────┘
   │   ├─ ToolRegistry (工具)     │
   │   ├─ MemoryFactory→DB/Redis │
   │   └─ ContextManager         │
   └───────────┬──────────┘
               │ 抛出事件
   ┌───────────▼───────────────────────────────────────────────┐
   │   事件系统 (event\*) → AiLogger（日志）/ ToolSecurityCheck（审计）│
   └───────────────────────────────┬───────────────────────────┘
                                    │ AI 插件桥接
   ┌────────────────────────────────▼──────────────────────────┐
   │  PluginBridge / PluginAiDiscovery / NodeRegistry / AdapterRegistry │
   │  SchemaIntrospector → 各 wise-* 业务包（automatically exposed）    │
   └────────────────────────────────────────────────────────────┘
```

### 核心子系统职责

| 子系统 | 关键类 | 职责 |
| --- | --- | --- |
| 引擎编排 | `AgentManager` | 创建/运行 Agent、流式、恢复、异步派发、停止、配置 |
| 执行循环 | `BaseAgent` / `SimpleAgent` | ReAct 思考-行动循环，工具选择，提示词构建 |
| 模型接入 | `AiClientFactory` / `OpenAiClient` / `DeepSeekClient` | 统一创建 LLM 客户端，封装 chat / 流式 / 断路器 |
| 工具 | `ToolRegistry` + `ToolInterface` | 工具注册、函数定义生成、按命名空间分组 |
| 技能 | `SkillManager` / `SkillRegistry` | 高层可复用能力，PHP 类或 SKILL.md |
| 流水线 | `PipelineEngine` / `PipelineRegistry` | 多步骤声明式编排 |
| 记忆 | `MemoryFactory` / `DatabaseMemory` / `RedisMemory` | 长期键值记忆，可切换存储 |
| 会话 | `SessionManager` / `SessionUserContext` | 会话生命周期与当前用户上下文 |
| 上下文 | `ContextManager` / `*ContextProvider` | 运行前注入系统/用户上下文 |
| 调度 | `AiTaskScheduler` / `AiTask` | cron 定时执行 pipeline/skill |
| 异步与追踪 | `ExecutionTracker` / `AiAgentJob` / `PipelineJob` | 队列异步执行与状态追踪 |
| 反馈 | `FeedbackCollector` / `SkillOptimizer` / `Feedback` | 技能评分、纠正、低分预警与优化 |
| 插件桥接 | `PluginBridge` / `PluginAiDiscovery` | 发现业务包暴露的 AI 能力 |
| Schema | `SchemaIntrospector` / `PluginSchemaDiscovery` / `BusinessCapabilityResolver` | 数据模型内省、自动工具、能力识别 |

---

## 快速上手 / 使用示例

> 以下示例均调用全局辅助函数（已通过 `composer.json` 的 `autoload.files` 自动加载 `src/helper.php`），可直接在控制器 / 命令行 / 定时任务中使用。

### 示例 1：最简对话（全局函数）

```php
use function wise_agent_chat;

// 与默认 Provider 直接对话，返回字符串
$answer = wise_agent_chat('用一句话解释什么是大模型');
echo $answer;
```

### 示例 2：使用 AgentManager 运行带工具的 Agent

```php
use wise\agent\agent\AgentManager;
use wise\agent\agent\SimpleAgent;

$manager = new AgentManager();
$agent   = new SimpleAgent('sess_' . uniqid());   // sessionId 唯一即可

$result  = $manager->run($agent, '帮我读取 /tmp/report.txt 并总结要点');
// $result 为 Agent 最终答复（string）
```

### 示例 3：为 Agent 指定工具（仅开放部分工具）

```php
use wise\agent\agent\AgentManager;
use wise\agent\agent\SimpleAgent;

$manager = new AgentManager();
$agent   = (new SimpleAgent('sess_001'))
    ->withContext(['user_role' => 'admin']);

// 仅向模型暴露 file_read / http_request 两个工具
$result = $manager->agent($agent)
    ->withTools(['file_read', 'http_request'])
    ->run('读取 https://example.com 的标题');
```

### 示例 4：定义并注册自定义工具

推荐继承 `BaseTool`（权限分类等都有合理默认值，只需覆写业务语义）：

```php
use wise\agent\tool\BaseTool;
use wise\agent\security\CapabilityAccess;

class WeatherTool extends BaseTool
{
    public function getName(): string              { return 'weather_query'; }
    public function getDescription(): string       { return '查询指定城市的天气'; }

    public function getParameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => ['city' => ['type' => 'string', 'description' => '城市名']],
            'required'   => ['city'],
        ];
    }

    public function execute(array $arguments): string
    {
        return json_encode(['city' => $arguments['city'], 'temp' => 26], JSON_UNESCAPED_UNICODE);
    }

    // ── 权限相关（BaseTool 已有默认值，按实际语义覆写）──
    public function getPermission(): string        { return 'ai.tool.weather_query'; }
    public function requireConfirmation(): bool    { return false; }                 // 自身确认下限
    public function getAccess(): string            { return CapabilityAccess::READ; } // 读/写/执行/管理
    public function getRiskLevel(): string         { return CapabilityAccess::RISK_LOW; }
}

// 注册后即可被任意 Agent 在循环中调用
wise_agent_tool(new WeatherTool());
```

`ToolInterface` 的方法一览（不继承 `BaseTool` 时需全部实现）：

| 方法 | 作用 | `BaseTool` 默认值 |
|---|---|---|
| `getName()` | 工具唯一名 | 由类名推导（`WeatherTool` → `weather`） |
| `getDescription()` | 给 LLM 看的描述 | 必填 |
| `getParameters()` | 参数 JSON Schema | 必填 |
| `execute(array)` | 执行逻辑 | 必填 |
| `getPermission()` | 权限标识，`''` = 免校验 | `''` |
| `requireConfirmation()` | 人工确认下限 | `false` |
| `getAccess()` | 访问类型 read/write/execute/admin | `execute`（保守） |
| `getRiskLevel()` | 风险等级 low/medium/high | `medium` |

Skill 同理，`SkillInterface` 新增 `getPermission()`（默认 `ai.skill.{name}`）、`getAccess()`、`getRiskLevel()`；
继承 `BaseSkill` 时 `getAccess()` 按 `getCategory()` 推断（content/data → read，code → write，business → execute），
SKILL.md 可用 front-matter 的 `permission` / `access` / `risk` 覆盖。

### 示例 5：执行一个技能（Skill）

```php
use function wise_agent_skill_execute;

// 执行内置内容生成技能，返回 SkillResult
$result = wise_agent_skill_execute('content.article_generate', [
    'title'   => '夏日饮品营销文案',
    'keywords'=> ['清爽', '解暑'],
]);

if ($result->isSuccess()) {
    echo $result->getOutput();
}

// 查看所有可用技能
$skills = wise_agent_skills();   // array
```

### 示例 6：运行声明式流水线（Pipeline）

```php
use function wise_agent_pipeline_run;

// 运行名为 cms.auto_publish 的流水线，onProgress 回调接收进度事件
$result = wise_agent_pipeline_run('cms.auto_publish', [
    'topic' => '夏季新品发布',
], function (array $event) {
    echo "[{$event['step']}] {$event['status']}\n";
});

echo $result->getOutput();   // 流水线最终输出
```

### 示例 7：会话与长期记忆

```php
use function wise_agent_session;
use function wise_agent_memory;
use function wise_agent_recall;

// 创建（或恢复）会话
$conversation = wise_agent_session();        // 新会话
$sid          = $conversation->getSessionId();

// 持久化一条长期记忆
wise_agent_memory($sid, 'user_preference', ['lang' => 'zh-CN'], ['profile']);

// 后续召回
$pref = wise_agent_recall($sid, 'user_preference', []);
```

### 示例 8：流式聊天（SSE，通过 HTTP 端点）

前端向后端 `POST /admin/ai/chat/stream` 发起 SSE 请求，后端 `AiController::chatStream()` 实时推送以下事件：

| 事件类型 | 含义 |
| --- | --- |
| `token` | 逐步生成的文本片段 |
| `tool_call` | 模型发起的工具调用 |
| `tool_result` | 工具执行结果 |
| `usage` | token 用量统计 |
| `error` | 错误信息 |
| `done` | 流式结束 |

### 示例 9：异步派发与状态追踪

```php
use wise\agent\agent\AgentManager;
use wise\agent\agent\SimpleAgent;

$manager = new AgentManager();
$agent   = new SimpleAgent('sess_async_001');

// 派发到队列异步执行，返回 tracking_id
$trackingId = $manager->dispatchAsync($agent, '生成本周销售周报');

// 客户端轮询（对应 GET /admin/ai/task/status?tracking_id=xxx）
$status = \wise\agent\execution\ExecutionTracker::getStatus($trackingId);
// ['status' => 'running'|'complete'|'timeout'|'stopped', 'progress' => ..., 'result' => ...]
```

### 示例 10：收集用户反馈 / 定时任务

```php
use function wise_agent_feedback;
use function wise_agent_schedule;

// 用户对技能评分（1-5），可附带纠正内容
wise_agent_feedback('content.translate', 5, '翻译很准确', null);

// 在代码里注册一个定时任务
$scheduler = wise_agent_schedule();
$scheduler->register(new \wise\agent\schedule\AiTask(
    'daily_report',            // id
    '每日销售周报',             // name
    'pipeline',                // type: pipeline | skill
    'cms.auto_publish',        // target
    '0 9 * * *',               // schedule (cron)
    ['topic' => '每日概览'],    // input
    'plugin:cms'               // source
));
```

### 示例 11：Schema 自动发现（插件开发）

```php
use function wise_agent_schema_discover;
use function wise_agent_schema_dump;

// 对插件 smartstock 执行数据模型内省 → 自动生成工具/节点/能力
$result = wise_agent_schema_discover('smartstock');

// 导出自动生成的 Schema（用于手动补全 manifest.json 的 ai_schema）
$json = wise_agent_schema_dump('smartstock', 'json');     // json
$md   = wise_agent_schema_dump('smartstock', 'markdown'); // markdown
```

对应控制台命令：

```bash
# 发现所有插件能力（安装/更新插件后）
php think wise:agent:discover

# 仅导出某插件的自动 Schema 为 JSON，便于填写 manifest
php think wise:agent:discover --dump=smartstock

# 自动补全 manifest.json 的 ai_schema 段
php think wise:agent:discover --bootstrap=smartstock

# 将自动生成的工具固化为插件内的 PHP 类
php think wise:agent:discover --materialize=smartstock
```

---

## 配置参考

配置文件：`config/wise-agent.php`（通过 `wise-agent` 配置键读取，合并自 `extra.think.config`）。

| 配置键 | 类型 | 默认值 | 说明 |
| --- | --- | --- | --- |
| `default` | string | `env('AI_DEFAULT_PROVIDER','openai')` | 默认 Provider 名称 |
| `providers` | array | openai / deepseek | 各 Provider 配置：`class` / `api_key` / `base_url` / `model` / `timeout` / `max_retry` |
| `circuit` | array | `failure_threshold=5`, `timeout=60`, `key_prefix='wise_agent:cb'` | Provider 级断路器参数 |
| `agent` | array | `max_steps=10`, `max_tokens_per_step=4096`, `temperature=0.7`, `default_type='simple'`, `system_prompt=...`, `enable_memory=true`, `session_ttl=86400` | Agent 默认运行参数与系统提示词 |
| `memory.default` | string | `database` | 记忆存储驱动：`database` / `redis` |
| `memory.stores` | array | `database`→`DatabaseMemory`, `redis`→`RedisMemory`(prefix `wai:mem:`) | 记忆存储实现映射 |
| `tools` | array | `file_read` / `file_write` / `db_query` / `http_request`（默认 `enabled=false`），`orchestrate_step`（`enabled=true`） | 内置工具开关与类名 |
| `skills.enabled` | bool | `true` | 是否启用技能系统 |
| `skills.dir` | string | `''`（默认包内置 `skills/`） | SKILL.md 扫描目录 |
| `skills.extra_dirs` | array | `[]` | 额外的 SKILL.md 目录（如插件 skills 目录） |
| `skills.builtin` | array | 12 个内置技能类名映射 | 内容类/数据类/系统类技能 |
| `skills.defaults` | array | `temperature=0.7`, `max_tokens=4096`, `max_steps=5`, `enable_memory=false` | 技能执行默认参数 |
| `log` | array | `enabled=true`, `channel='ai'` | 运行日志开关与通道 |
| `queue` | array | `connection='database'`, `job=AiAgentJob`, `pipeline_job=PipelineJob` | 异步队列配置 |
| `context.enabled` | bool | `true` | 是否启用上下文注入 |
| `context.builtin` | array | `SystemContextProvider=>true`, `UserContextProvider=>true` | 内置上下文注入器开关 |
| `pipeline.enabled` | bool | `true` | 是否启用流水线 |
| `pipeline.max_duration` | int | `600` | 流水线最大执行时间（秒） |
| `pipeline.default_retries` | int | `0` | 默认重试次数 |
| `pipeline.definitions` | array | `[]` | 声明式预定义流水线 |
| `schedule.enabled` | bool | `true` | 是否启用定时任务 |
| `schedule.tasks` | array | `[]` | 预定义定时任务（结构与 `AiTask` 一致） |
| `feedback.enabled` | bool | `true` | 是否启用反馈 |
| `feedback.low_rating_threshold` | float | `3.5` | 低分预警阈值 |
| `feedback.min_count` | int | `5` | 参与统计的最少反馈数 |
| `security.max_tool_executions_per_step` | int | `5` | 单步最大工具调用次数 |
| `security.max_tool_execution_time` | int | `30` | 单工具最大执行秒数 |
| `security.max_agent_duration` | int | `300` | Agent 最大执行秒数 |
| `security.max_pipeline_step_duration` | int | `120` | 流水线单步最大执行秒数 |
| `web.enable` | bool | `true` | 是否注册 Web 路由（总开关） |
| `web.prefix` | string | `admin/ai` | 路由前缀 |
| `web.middleware` | array | `[]` | 全局默认中间件 |
| `web.groups.admin` | array | `enable=true`, `middleware=[]` | 分组配置；**建议挂 RBAC 中间件保护** |
| `schema.auto_discover` | bool | `true` | 是否启用 Schema 自动发现 |
| `schema.cache_dir` | string | `wise-agent/schema` | Schema 缓存目录（相对 runtime） |
| `schema.cache_ttl` | int | `3600` | Schema 缓存 TTL（秒） |
| `schema.defaults` | array | `write_requires_confirmation=true`, `auto_exclude_sensitive=true`, `auto_exclude_system=true` | 自动生成工具的安全默认值 |
| `schema.capabilities` | array | `ecommerce` 示例（默认不启用）；内置：`inventory`/`sales`/`crm`/`content` | 业务能力标签注册 |

> ⚠️ **安全提示**：`web.groups.admin.middleware` 默认为空，所有 `/admin/ai/*` 端点涉及 AI 配置、API Key 与会话数据。生产环境务必通过配置挂载 `\wise\auth\middleware\RbacMiddleware::class` 等认证中间件。

---

## API / 方法参考

### 全局辅助函数（helper.php）

| 函数 | 签名 | 返回 | 说明 |
| --- | --- | --- | --- |
| `wise_agent` | `wise_agent(): AgentManager` | `AgentManager` | 获取 AgentManager 实例 |
| `wise_agent_chat` | `wise_agent_chat(string $message, string $provider = '', array $options = []): string` | `string` | 快速对话，返回文本 |
| `wise_agent_tool` | `wise_agent_tool(ToolInterface $tool): void` | `void` | 注册自定义工具 |
| `wise_agent_memory` | `wise_agent_memory(string $sessionId, string $key, mixed $value, array $tags = []): void` | `void` | 存储长期记忆 |
| `wise_agent_recall` | `wise_agent_recall(string $sessionId, string $key, mixed $default = null): mixed` | `mixed` | 召回长期记忆 |
| `wise_agent_session` | `wise_agent_session(?string $sessionId = null): Conversation` | `Conversation` | 创建/恢复会话 |
| `wise_agent_skill` | `wise_agent_skill(): SkillManager` | `SkillManager` | 获取技能管理器 |
| `wise_agent_skill_execute` | `wise_agent_skill_execute(string $skillName, array $input = [], array $options = []): SkillResult` | `SkillResult` | 执行技能 |
| `wise_agent_skills` | `wise_agent_skills(): array` | `array` | 所有可用技能概览 |
| `wise_agent_pipeline` | `wise_agent_pipeline(): PipelineEngine` | `PipelineEngine` | 获取流水线引擎 |
| `wise_agent_pipeline_run` | `wise_agent_pipeline_run(string $pipelineName, array $input = [], ?callable $onProgress = null): PipelineResult` | `PipelineResult` | 执行流水线 |
| `wise_agent_feedback` | `wise_agent_feedback(string $skillName, int $rating, ?string $comment = null, ?string $correction = null): bool` | `bool` | 收集技能反馈 |
| `wise_agent_schedule` | `wise_agent_schedule(): AiTaskScheduler` | `AiTaskScheduler` | 获取调度器 |
| `wise_agent_schema_discover` | `wise_agent_schema_discover(string $pluginIdent): SchemaDiscoveryResult` | `SchemaDiscoveryResult` | 对插件执行 Schema 发现 |
| `wise_agent_schema_discover_all` | `wise_agent_schema_discover_all(bool $force = false): array` | `SchemaDiscoveryResult[]` | 批量发现所有插件 |
| `wise_agent_schema_get` | `wise_agent_schema_get(string $pluginIdent): ?SchemaDiscoveryResult` | `SchemaDiscoveryResult\|null` | 获取缓存的发现结果 |
| `wise_agent_schema_dump` | `wise_agent_schema_dump(string $pluginIdent, string $format = 'json', bool $onlyEssential = false)` | `mixed` | 导出 Schema（json/markdown/array） |

### AgentManager

> 命名空间：`wise\agent\agent\AgentManager`

| 方法 | 签名 | 返回 | 说明 |
| --- | --- | --- | --- |
| `run` | `run(BaseAgent $agent, string $message, array $options = []): string` | `string` | 同步运行 Agent 并返回最终答复 |
| `runStream` | `runStream(BaseAgent $agent, string $message, array $options = []): \Generator` | `\Generator` | 流式运行，逐事件 `yield`（`type`/`content`/…） |
| `chat` | `chat(string $message, string $provider = '', array $options = []): string` | `string` | 无会话直接对话（走默认 Agent） |
| `chatStream` | `chatStream(string $message, string $provider = '', array $options = []): \Generator` | `\Generator` | 无会话流式对话 |
| `resume` | `resume(BaseAgent $agent, string $message = ''): string` | `string` | 恢复中断的任务（不新增用户消息） |
| `dispatchAsync` | `dispatchAsync(BaseAgent $agent, string $message, array $options = []): string` | `string` | 派发到队列异步执行，返回 `tracking_id` |
| `getTrackingStatus` | `getTrackingStatus(string $trackingId): ?array` | `array\|null` | 查询异步任务状态 |
| `stop` | `stop(string $sessionId): void` | `void` | 为会话设置停止标志 |
| `configure` | `configure(array $config): self` | `self` | 设置 AgentManager 级配置（provider / 运行时参数） |
| `agent` | `agent(BaseAgent $agent): self` | `self` | 链式绑定 Agent 实例（再调 `withTools` / `run`） |
| `withTools` | `withTools(array $toolNames): self` | `self` | 仅向 Agent 暴露指定工具 |

### BaseAgent / SimpleAgent

> 命名空间：`wise\agent\agent\BaseAgent`（抽象）、`wise\agent\agent\SimpleAgent`（默认实现）

| 方法 | 签名（BaseAgent） | 返回 | 说明 |
| --- | --- | --- | --- |
| `configure` | `configure(array $config): void` | `void` | 注入 Agent 配置（provider、system_prompt 等） |
| `reset` | `reset(): void` | `void` | 重置运行态 |
| `run` | `run(string $message): string` | `string` | 执行一轮对话（子类实现） |
| `initContext` | `initContext(): void` | `void` | 初始化运行上下文 |
| `step` | `step(): bool` | `bool` | 执行单个 ReAct 步骤，返回是否继续 |
| `shouldContinue` | `shouldContinue(): bool` | `bool` | 判断是否继续循环 |
| `finalize` | `finalize(): void` | `void` | 收尾处理 |
| `buildSystemPrompt` | `buildSystemPrompt(): string` | `string` | 构建系统提示词 |
| `getToolsForLlm` | `getToolsForLlm(): array` | `array` | 生成 LLM 函数定义数组 |
| `onAgentStart` | `onAgentStart(AgentStart $e): void` | `void` | 生命周期钩子（事件触发点） |
| `onAgentStep` | `onAgentStep(AgentStep $e): void` | `void` | 每步钩子 |
| `onAgentComplete` | `onAgentComplete(AgentComplete $e): void` | `void` | 完成钩子 |
| `onAgentError` | `onAgentError(AgentError $e): void` | `void` | 错误钩子 |
| `onAiRequest` | `onAiRequest(AiRequest $e): void` | `void` | 发起模型请求钩子 |
| `onAiResponse` | `onAiResponse(AiResponse $e): void` | `void` | 收到模型响应钩子 |
| `onToolExecute` | `onToolExecute(ToolExecute $e): void` | `void` | 工具执行钩子 |
| `onToolAuthorize` | `onToolAuthorize(ToolAuthorize $e): void` | `void` | 工具授权钩子 |
| `getClient` | `getClient(): AiClientInterface` | `AiClientInterface` | 获取当前 LLM 客户端 |
| `getToolRegistry` | `getToolRegistry(): ToolRegistry` | `ToolRegistry` | 获取工具注册表 |
| `getAgentType` | `getAgentType(): string` | `string` | Agent 类型标识 |
| `getConversation` | `getConversation(): ?ConversationInterface` | `ConversationInterface\|null` | 当前会话 |

| 方法 | 签名（SimpleAgent） | 返回 | 说明 |
| --- | --- | --- | --- |
| `__construct` | `__construct(string $sessionId, array $options = [])` | — | 以会话 ID 构造默认 Agent |
| `setConversation` | `setConversation(ConversationInterface $conversation): void` | `void` | 复用已有会话 |
| `getDefaultProvider` | `getDefaultProvider(): string` | `string` | 默认 Provider 名称 |
| `withContext` | `withContext(array $context): self` | `self` | 注入附加运行上下文 |
| `getSystemPrompt` | `getSystemPrompt(): string` | `string` | 当前系统提示词 |

### 核心契约接口（contract/）

| 接口 | 关键方法 | 说明 |
| --- | --- | --- |
| `AiClientInterface` | `chat(string $message, array $options = []): string`；`getProviderName(): string` | LLM 客户端统一契约；`OpenAiClient` / `DeepSeekClient` 实现 |
| `ToolInterface` | `getName(): string`；`getDescription(): string`；`getSchema(): array`；`execute(array $args = []): mixed`；`isSafe(): bool` | 工具标准化契约，所有工具须实现 |
| `MemoryInterface` | `store($sessionId,$key,$value,$tags=[])`；`retrieve($sessionId,$key,$default)`；`delete($sessionId,$key)`；`search($sessionId,$query,$limit)`；`clear($sessionId)` | 记忆存储契约 |
| `UserContextInterface` | `getId()`；`getUserId()`；`getUsername()`；`getRoles(): array`；`getPermissions(): array`；`getMetadata(): array` | 注入当前用户上下文 |
| `ConversationInterface` | `getId()`；`addMessage($role,$content,$meta)`；`getMessages(): array`；`getMessageCount(): int`；`clear(): void` | 会话消息契约 |
| `ConfigurableAgentInterface` | `configure(array $config): void`；`getConfig(): array` | 可配置 Agent 契约 |

### 核心服务一览

> 以下服务均由 `AgentServiceProvider` 绑定（多为单例 / 工厂闭包）。方法签名如需精确细节，请「详见源码」。

| 服务类 | 绑定方式 | 关键公共方法 | 说明 |
| --- | --- | --- | --- |
| `ToolRegistry` | 单例 | `register(ToolInterface)`, `registerClass(string $class)`, `get(string $name): ?ToolInterface`, `all(): array`, `getForLlm(): array`, `has(string $name)`, `remove(string $name)`, `removeNamespace(string $ns)` | 工具注册与查询 |
| `SkillManager` | 工厂 | `execute(string $skillName, array $input = [], array $options = []): SkillResult`, `getAvailableSkills(): array` | 技能执行（详见 `wise_agent_skill_execute`） |
| `SkillRegistry` | 工厂 | `registerClass($class)`, `loadFromDirectory($dir,$pattern)`, `get($name)`, `all()` | 技能注册与加载 |
| `PipelineEngine` | 工厂 | `run(string $name, array $input, ?callable $onProgress): PipelineResult` | 流水线执行 |
| `PipelineRegistry` | 工厂 | `registerDefinition($name,$config)`, `get($name)`, `all()` | 流水线定义注册 |
| `MemoryFactory` | 工厂（每次重建） | `create(): MemoryInterface`, `clearCache(): void` | 记忆工厂；依据 `memory.default` 选择存储 |
| `DatabaseMemory` | 单例 | 实现 `MemoryInterface`（store/retrieve/delete/search/clear） | 基于 `ai_memories` 表的记忆 |
| `SessionManager` | 单例 | `create(array $options = []): Conversation`, `resume(string $id): ?Conversation`, `getUserSessions(int $limit,int $offset): array`, `delete(string $id): bool`, `rename(string $id,string $title): bool`, `isOwnedByCurrentUser(string $id): bool`, `isInterrupted(string $id): array` | 会话生命周期管理 |
| `AiClientFactory` | 静态 | `make(string $provider = null): AiClientInterface`, `getProviders(): array`, `clearCache(): void` | Provider 客户端工厂 |
| `FeedbackCollector` | 单例 | `collect(Feedback $fb): bool` | 收集技能反馈 |
| `SkillOptimizer` | 工厂 | 读取反馈统计，触发低分预警 / 优化建议 | 技能自我优化 |
| `AiTaskScheduler` | 工厂 | `register(AiTask $task)`, `runDueTasks(): array`, `runTask(string $id): array`, `loadFromDatabase(): void` | 定时任务调度 |
| `AiTask` | 值对象 | 构造参数：`(id, name, type, target, schedule, input, source)` | 定时任务描述 |
| `ContextManager` | 单例 | `register(ContextProviderInterface $p)`, `inject(ContextInterface $ctx): void`（注入上下文） | 上下文注入编排 |
| `PluginBridge` | 工厂 | `getToolListGrouped(): array`, `getWorkflowNodes(): array`, `getAdapters(): array`, `callTool(string $name, array $params, RequestContext $ctx): mixed` | 业务包 AI 能力桥接 |
| `PluginAiDiscovery` | 工厂 | `discover(bool $force = false): array`, `discoverSchema(): array`, `getStats(): array` | AI 能力发现 |
| `SchemaIntrospector` | 工厂 | 内省数据模型、生成 Tool Schema | Schema 内省引擎 |
| `PluginSchemaDiscovery` | 工厂 | `discover(string $pluginIdent): SchemaDiscoveryResult`, `discoverAll(bool $force): array`, `getDiscovered(string $plugin): ?SchemaDiscoveryResult`, `dumpSchemaAsJson($p,$onlyEssential)`, `dumpSchemaAsMarkdown($p)`, `dumpSchemaForEditing($p,$onlyEssential)` | 插件 Schema 协调 |
| `BusinessCapabilityResolver` | 静态 | `initBuiltin(): void`；内置能力：`inventory` / `sales` / `crm` / `content` | 业务能力解析 |
| `NodeRegistry` / `AdapterRegistry` | 单例 | `instance()`；注册/查询工作流节点与模型适配器 | 节点/适配器注册表 |
| `ExecutionTracker` | 静态 | `getStatus(string $id): ?array`, `scanTimeouts(int $seconds): int` | 异步执行状态追踪 |
| `AiConfigManager` | 直接 new | `getAll(): array`, `get(string $provider): array`, `save(string $provider, array $data): bool` | 加密持久化 AI Provider 配置 |

---

## 事件 / 钩子

wise-agent 在 Agent 运行全生命周期抛出事件，默认由 `AiLogger`（记录日志）与 `ToolSecurityCheck`（工具安全审计）监听。`AgentServiceProvider::registerEventListeners()` 中以**事件类名**注册监听器（字符串名不会被命中）。

| 事件类（`wise\agent\event\`） | 触发时机 | 主要属性 |
| --- | --- | --- |
| `AgentStart` | Agent 开始运行 | `sessionId`, `agentType`, `provider`, `message` |
| `AgentConfigure` | Agent 被配置时 | `agent`, `config` |
| `AgentStep` | 每个 ReAct 步骤 | `step`, `action`（think/tool_call/...）, `content` |
| `AgentComplete` | Agent 正常完成 | `sessionId`, `result`, `steps`, `durationMs` |
| `AgentError` | 运行出错 | `sessionId`, `exception`, `step` |
| `AiRequest` | 向 LLM 发起请求 | `provider`, `model`, `messages`, `tools` |
| `AiResponse` | 收到 LLM 响应 | `provider`, `model`, `content`, `toolCalls`, `tokens` |
| `ToolExecute` | 工具执行前后 | `tool`, `args`, `result`, `durationMs`（`ToolSecurityCheck` 监听） |
| `ToolAuthorize` | 工具授权判定 | `toolName`, `permission`, `access`, `arguments`, `userId`, `userType`, `authorized`, `denied`, `decidedBy`, `reason` |
| `SkillAuthorize` | Skill 授权判定 | `skillName`, `permission`, `access`, `arguments`, `userId`, `userType`, `authorized`, `denied`, `decidedBy`, `reason` |

> 两者都继承 `CapabilityAuthorize`，由 `CapabilityAuthorizePolicy` 统一监听。

### AI 能力权限模型（分类骨架 + 细粒度下钻 + 审批兜底）

Tool 与 Skill **共用同一套判定**（`CapabilityAuthorizer`），避免「工具受控、技能裸奔」的口径分裂。

**判定顺序**：

1. `deny()` —— 硬拒绝，优先级最高，且不再走 RBAC 兜底
2. `allow()` —— 显式放行（无需给角色分配权限）
3. 均未命中 —— 回落 **wise-auth RBAC**：
   - 超级管理员（`super_admin_ids` / `super_admin` 角色）直通
   - 命中 `*` → 全局放行
   - 命中 `ai.capability.*` → 全部 AI 能力
   - 命中 `ai.access.{read|write|execute|admin}.*` → **按类别**放行（分类骨架）
   - 命中能力自身权限标识（支持 `ai.skill.*`、`plugin:x.auto.list.*` 前缀通配）→ 细粒度下钻

**访问类型（access）** 由能力自身声明（`ToolInterface::getAccess()` / `SkillInterface::getAccess()`），
`AccessResolver` 只做归一化（校验取值、补齐权限标识与归属插件）：

| 能力 | access 来源 |
|---|---|
| `BaseTool` 子类 | 默认 `execute`；各内置工具显式声明（`db_query`/`file_read` → read，`file_write`/`http_request` → write，`orchestrate_step` → execute） |
| `AutoGeneratedTool` / `BaseAutoTool` | 按 `toolType` 推导（create/update/delete → write，其余 → read；delete 风险 high） |
| `ActionTool` | manifest `ai_actions[x].access`，缺省 write |
| `BridgedMcpTool` | 保守 write + high |
| `BaseSkill` | 按 `getCategory()`：content/data → read，code → write，business → execute |
| `MarkdownSkill` | SKILL.md front-matter `access`，缺省按 category |
| `AutoSkill` | `*.auto_fill` → write，其余按 category |

### 审批策略（与权限解耦）

**权限**决定「能不能调用」（RBAC 一次性授予），**审批**决定「这一次调用是否需要人工介入」（按次）。
由 `ApprovalPolicy` 统一判定，规则优先级（首个命中生效）：

1. `rules.capabilities` —— 按能力标识精确 / 前缀通配（用于例外）
2. `rules.risk` —— 按风险等级
3. `rules.access` —— 按访问类型
4. `default` —— 兜底

```php
'security' => [
    'approval' => [
        'enabled' => true,
        'default' => 'none',                  // none | confirm | workflow
        'rules'   => [
            'risk'         => ['high' => 'confirm'],
            'access'       => ['read' => 'none', 'write' => 'confirm', 'admin' => 'workflow'],
            'capabilities' => ['plugin:*.auto.delete.*' => 'workflow'],
        ],

        // 审批单参数
        'request' => [
            'ttl'             => 900,        // 审批单有效期（秒），0 = 永不过期
            'max_uses'        => 1,          // 一张单可放行的调用次数
            'scope'           => 'capability', // capability = 申请人+能力即可；arguments = 还需参数一致
            'reject_cooldown' => 300,        // 被驳回后的冷静期（秒），0 = 关闭
        ],

        // 审批单生成后需要通知的管理员用户 ID（需安装 wise-notification；为空则不发通知）
        // 审批人解析优先级：notify_user_ids → workflow.approvers
        //                → 拥有 admin.ai_approval.approve 权限的用户 → 超级管理员
        'notify_user_ids' => [],

        // wise-workflow 集成（可选）
        'workflow' => [
            'enabled'   => true,      // 为每张审批单建立 ai_approval:{id} 实例
            'approvers' => [],        // 会签审批人（用户 ID）；为空 = 单人审批
            'policy'    => 'any',     // any=或签（任一人通过即流转）| all=会签
        ],
    ],
],
```

- 能力自身 `requireConfirmation() === true` 视为**下限**，策略只能加严不能放宽
- `confirm` / `workflow` 命中的都会**生成审批单并安全阻断**（fail-closed），工具结果中返回
  `required_action` + `approval_rule` + `approval_ident` 供前端 / AI / 审计消费

> 注意：判定结果**不做缓存**——授权事件允许按 `arguments` 细粒度判定，
> 按能力缓存会让同能力不同参数的第二次调用命中旧结论。昂贵的用户权限集合由 wise-auth 缓存。

### 审批闭环（审批单 + 审批中心）

**放行语义（重要）**：批准后**不重放**被拦截的历史调用（重放副作用型调用既危险又缺少会话上下文）。
正确流程为 —— 申请 → 人工批准 → **申请人重新发起同一调用** → 命中可用审批单并
**原子消费一次** → 本次放行。

审批单状态机（表 `ws_ai_approvals`）：

```
pending ──批准──▶ approved ──消费/用尽──▶ consumed
   │
   ├──驳回──▶ rejected
   ├──撤回──▶ cancelled
   └──超时──▶ expired
```

- 匹配维度：申请人（user_type + user_id）+ 能力类型 + 权限标识（`scope=arguments` 时再加参数指纹）
- 并发安全：带条件 UPDATE + 影响行数校验，同一张单不会被并发放行多次
- **系统上下文不建单**：控制台 / 队列 / 定时任务（无登录用户）视为运维可信身份直接放行，
  避免 schedule / pipeline 的写操作永久卡在 pending
- 幂等：同一（申请人 + 能力）已存在 pending 单时复用，不重复建单

**后台入口**：「插件管理 → AI 能力审批」（菜单由 `ExtPoints::MENU_ITEM` 声明，
执行 `php think menu:sync -p wise-agent` 落库，并自动派生 `admin.ai_approval.*` 权限）

**对话内一键批准**：AI 助手聊天面板会在被拦截时渲染「审批卡片」，无需跳转后台。

| 端点 | 作用 |
|---|---|
| `GET  /admin/ai/approval/pending` | 当前用户的审批单（待处理 + 最近结果） |
| `POST /admin/ai/approval/decide`  | 决策：`{ id\|ident, decision: approve\|reject\|cancel, note }` |

审批权判定（与后台审批中心同口径）：

1. 审批单必须处于 `pending`
2. **`confirm` 模式 + 申请人本人** → 允许（自查确认：确认自己要执行的操作）
3. 其余需要 `admin.ai_approval.{approve|reject|cancel}` 权限（超级管理员直通）
4. 会签未满时只记录意见、审批单保持 `pending`（返回 `message` 告知剩余人数）

前端行为（`public/statics/plugins/wise-agent/js/agent-chat.js`）：拉取审批单 → 按 id 合并渲染卡片
（`pending/approved/rejected/...` 各有样式）→ 点击「批准」后**自动续跑一轮对话**
（发送「已批准审批单 X，请继续执行刚才的操作」），由 AI 重新发起同一调用，后端消费审批单后放行。

**CLI**：

```bash
php think wise:agent:approvals                            # 列出待审批
php think wise:agent:approvals --status=all --limit=50     # 列出全部
php think wise:agent:approvals --approve=12 --note=同意     # 批准
php think wise:agent:approvals --reject=12 --note=风险过大   # 驳回
php think wise:agent:approvals --expire                    # 清理过期
```

**事件**（业务方可自行接入通知 / 工单）：

| 事件 | 时机 | 主要属性 |
|---|---|---|
| `ApprovalRequested` | 审批单创建后 | `approvalId`, `ident`, `capabilityKind`, `capabilityName`, `permission`, `access`, `risk`, `mode`, `rule`, `reason`, `requesterId`, `requesterType` |
| `ApprovalDecided` | 批准 / 驳回 / 撤回后 | `approvalId`, `ident`, `decision`, `capabilityName`, `permission`, `requesterId`, `deciderId`, `note` |

```php
Event::listen(\wise\agent\event\ApprovalRequested::class, function ($e) {
    // 推送到企微 / 钉钉 / 工单系统
    // $e->ident, $e->capabilityName, $e->requesterId, $e->mode ...
});
```

**可选集成**（均在 `wise\agent\workflow\ApprovalWorkflowBridge` 内收敛，异常自动降级）：

- **wise-workflow**：为每张单建立 `ai_approval:{id}` 实例（`pending → approved/rejected/cancelled`）；
  `workflow.approvers` 非空时按 `policy` 创建**会签任务**（`any` 或签 / `all` 会签），
  票数未满时审批单保持 pending；流转历史在审批详情页与 `getHistory()` 可取。
  未安装 / `workflow.enabled=false` 时审批单表自身即状态机，功能不受影响
- **wise-notification**：审批单生成时通知审批人、决策后回执申请人（`database` 通道，`sync` 立即发送）。
  审批人解析优先级：`notify_user_ids` → `workflow.approvers` → 拥有
  `admin.ai_approval.approve` 权限的用户 → 超级管理员。未安装时仅记日志

**AI 能力开关**：「插件管理 → 能力市场」中的 **AI 工具权限提供者**
（扩展点 `ai.tool.permission_provider`）可查看并启停各插件注册的 AI 权限提供者；
停用后其声明的权限不再注册，等价于关闭该插件的 AI 能力。



**权限树**（后台「权限配置」可见，由 `ToolPermissionRegistrar` 生成）：

```
AI 能力                         ai.capability
├─ 全部 AI 能力                 ai.capability.*        ← 一键全开
├─ 只读能力                     ai.access.read
│   ├─ 全部只读能力             ai.access.read.*       ← 日常勾选单元
│   ├─ AI 工具：db_query        ai.tool.db_query       ← 细粒度下钻
│   ├─ 插件 smartstock · 查询    plugin:smartstock.auto.list.*
│   └─ 技能：content.translate   ai.skill.content.translate
├─ 写入能力 / 编排执行 / 管理能力 ...
├─ 全部 AI 工具                 ai.tool.*               ← 汇总授权
├─ 全部技能                     ai.skill.*              ← 汇总授权
└─ 插件 xxx · 全部自动工具      plugin:xxx.auto.*       ← 汇总授权
```

授权建议：**日常只勾类别通配**（新增能力自动继承）；需要例外时再勾细粒度下钻项。

**声明式策略**由 `config('wise-agent.security.tool_authorize')` 驱动，工具与技能共用：

```php
'security' => [
    'tool_permission_check'  => true,   // 工具权限校验总开关
    'skill_permission_check' => true,   // 技能权限校验总开关（技能是编排入口，别随意关）
    'trust_console_context'  => true,   // 控制台/队列视为可信系统身份
    'tool_authorize' => [
        // 规则：'*' | 能力名 | 权限标识 | 前缀通配 | 访问类别(access:read)
        'allow' => ['access:read'],
        'deny'  => ['access:admin', 'ai.skill.smartstock.stock_out'],
        'allow_anonymous' => false,
    ],
],
```

自定义策略只需再注册一个监听器（例如按 `arguments` 做细粒度判定）：

```php
use think\facade\Event;
use wise\agent\event\ToolAuthorize;
use wise\agent\event\SkillAuthorize;

Event::listen(ToolAuthorize::class, function (ToolAuthorize $e) {
    if ($e->toolName === 'orchestrate_step'
        && ($e->arguments['skill_name'] ?? '') === 'smartstock.stock_list') {
        $e->deny('库存数据禁止通过 AI 查询', 'custom_policy');
    }
});
```

### AI 能力权限注册（后台可视化授权）

```bash
php think wise:agent:permissions            # 写入 / 更新权限表
php think wise:agent:permissions --dry-run  # 仅预览
php think wise:agent:permissions --prune    # 同时清理已废弃的 ai.* 历史权限
```

权限来源：配置 `security.tool_permissions.items` → 扩展点 `ai.tool.permission_provider`
（其它包 / 插件注册 provider 补充）→ `ToolRegistry` 中声明了 `getPermission()` 的工具
→ `SkillRegistry` 中的 Skill（`ai.skill.{name}`）→ 类别 / 汇总通配。

`tool_permissions` 子项：

| 配置项 | 默认 | 说明 |
|---|---|---|
| `group_name` | `AI 能力` | 权限树分组名称 |
| `include_all_wildcard` | `true` | 生成 `ai.capability.*`（一键全开） |
| `include_access_wildcard` | `true` | 生成 `ai.access.{read\|write\|execute\|admin}.*`（日常勾选单元） |
| `include_aggregate_wildcard` | `true` | 生成 `ai.tool.*` / `ai.skill.*` / `plugin:{ident}.auto.*` 汇总标识 |
| `include_skills` | `true` | 注册技能权限 `ai.skill.{name}` |
| `detail_level` | `aggregate` | `aggregate`=自动生成工具按「插件 × 操作类型」聚合（避免单插件上百条）；`full`=精确到表 |
| `items` | `[]` | 声明式补充/覆盖（插件可只写配置） |

**SKILL.md 可声明权限**：

```yaml
name: smartstock.stock_query
category: business
access: read                              # read | write | execute | admin（缺省按分类推断）
risk: low                                 # low | medium | high
permission: ai.skill.smartstock.stock_query   # 缺省为 ai.skill.{name}
```

### 监听示例（业务扩展）

```php
use wise\agent\event\AgentComplete;
use think\facade\Event;

Event::listen(AgentComplete::class, function (AgentComplete $e) {
    // 例如：将 AI 答复写入业务审计表 / 触发通知
    $e->sessionId;   // 会话 ID
    $e->result;      // 最终答复
    $e->steps;       // 执行步数
});
```

插件生命周期事件：`PluginInstall` / `PluginUninstall`（监听于 `AgentServiceProvider`）会触发 `ProviderCache::invalidate()` 与 `AiClientFactory::clearCache()`，并提示执行 `php think wise:agent:discover`。

---

## Web 路由（HTTP API）

路由由 `RouteRegistrar` 读取 `config('wise-agent.web')` 配置驱动注册（`web.enable=false` 时不注册），默认分组前缀 `admin/ai`。所有端点建议挂载 RBAC 中间件。控制器：`wise\agent\controller\AiController`。

| 方法 | 路径 | 动作 | 说明 |
| --- | --- | --- | --- |
| GET | `/admin/ai/config` | `config` | 获取 AI 配置 |
| POST | `/admin/ai/config/save` | `saveConfig` | 保存 Provider 配置（加密） |
| POST | `/admin/ai/config/test` | `testConnection` | 测试 Provider 连通性 |
| GET | `/admin/ai/providers` | `getProviders` | 可用 Provider 列表 |
| GET | `/admin/ai/models` | `getModels` | 指定 Provider 的模型列表 |
| GET | `/admin/ai/sessions` | `sessions` | 当前用户会话列表（分页） |
| GET | `/admin/ai/session/detail` | `sessionDetail` | 会话详情与消息列表 |
| POST | `/admin/ai/session/create` | `createSession` | 创建会话 |
| POST | `/admin/ai/session/rename` | `renameSession` | 重命名会话 |
| POST | `/admin/ai/session/delete` | `deleteSession` | 删除会话（属主校验） |
| POST | `/admin/ai/session/clear` | `clearSession` | 清空会话消息 |
| POST | `/admin/ai/chat/stream` | `chatStream` | SSE 流式聊天 |
| POST | `/admin/ai/chat/stop` | `stopChat` | 停止流式聊天 |
| POST | `/admin/ai/chat/resume` | `resumeChat` | 恢复中断对话（SSE） |
| GET | `/admin/ai/task/status` | `taskStatus` | 异步任务状态（tracking_id） |
| GET | `/admin/ai/session/interrupted-status` | `interruptedStatus` | 会话是否中断 |
| GET | `/admin/ai/tools` | `getTools` | 工具列表（按命名空间分组） |
| GET | `/admin/ai/workflow-nodes` | `getWorkflowNodes` | 工作流节点定义 |
| GET | `/admin/ai/adapters` | `getAdapters` | 模型适配器列表 |
| POST | `/admin/ai/tool/call` | `callTool` | 手动调用工具（调试） |
| GET | `/admin/ai/chat-page` | `chatPage` | 聊天页面（静态 HTML） |
| GET | `/admin/ai/config-page` | `configPage` | 配置页面（静态 HTML） |

---

## 控制台命令

| 命令 | 选项 | 说明 |
| --- | --- | --- |
| `php think wise:agent:discover` | `--force` 强制重建缓存；`--schema-only` 仅 Schema 发现；`-v` 详细；`--dump=plugin` 导出 JSON；`--dump-md=plugin` 导出 Markdown；`--bootstrap=plugin` 补全 manifest 的 ai_schema；`--materialize=plugin` 固化工具为 PHP 类；`--materialize-node=plugin` 固化节点 | 发现 AI 能力并自动内省插件 Schema |
| `php think wise:agent:scan-timeouts` | `--timeout` / `-t`（默认 `600` 秒） | 扫描并标记卡死的 Agent/Pipeline 执行为 timeout |

---

## 数据库表结构（DDL）

迁移文件位于 `database/migrations/`，通过 `php think migrate:run` 创建以下 `ai_*` 表（基于 ThinkPHP 迁移，以下为等价 MySQL DDL）。

### `ai_sessions` —— 会话表

```sql
CREATE TABLE `ai_sessions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `session_id` varchar(64) NOT NULL COMMENT '会话唯一标识',
  `user_id` int(11) NOT NULL DEFAULT 0 COMMENT '用户ID',
  `title` varchar(255) DEFAULT NULL COMMENT '会话标题',
  `status` varchar(32) NOT NULL DEFAULT 'active' COMMENT 'active/idle/expired',
  `provider` varchar(64) DEFAULT NULL COMMENT 'Provider 名称',
  `agent_type` varchar(64) DEFAULT NULL COMMENT 'Agent 类型',
  `context` text DEFAULT NULL COMMENT '上下文数据 JSON',
  `meta` text DEFAULT NULL COMMENT '元数据 JSON',
  `message_count` int(11) NOT NULL DEFAULT 0 COMMENT '消息数',
  `last_message_at` int(11) DEFAULT NULL COMMENT '最后消息时间',
  `expires_at` int(11) DEFAULT NULL COMMENT '过期时间',
  `create_time` int(11) NOT NULL COMMENT '创建时间',
  `update_time` int(11) NOT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_session_id` (`session_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI 会话表';
```

### `ai_messages` —— 消息表

```sql
CREATE TABLE `ai_messages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `session_id` varchar(64) NOT NULL COMMENT '会话ID',
  `role` varchar(32) NOT NULL COMMENT 'user/assistant/system/tool',
  `content` mediumtext NOT NULL COMMENT '消息内容',
  `tool_calls` text DEFAULT NULL COMMENT '工具调用 JSON',
  `tool_call_id` varchar(64) DEFAULT NULL COMMENT '工具调用ID',
  `name` varchar(64) DEFAULT NULL COMMENT '工具/函数名称',
  `meta` text DEFAULT NULL COMMENT '元数据 JSON',
  `create_time` int(11) NOT NULL,
  `update_time` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_session_id` (`session_id`),
  KEY `idx_role` (`role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI 消息表';
```

### `ai_memories` —— 长期记忆表

```sql
CREATE TABLE `ai_memories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `session_id` varchar(64) NOT NULL COMMENT '会话ID',
  `key` varchar(128) NOT NULL COMMENT '记忆键',
  `value` mediumtext NOT NULL COMMENT '记忆值（序列化）',
  `type` varchar(32) DEFAULT 'string' COMMENT 'string/json/array/object',
  `tags` varchar(255) DEFAULT NULL COMMENT '标签逗号分隔',
  `priority` int(11) DEFAULT 0 COMMENT '优先级',
  `expires_at` int(11) DEFAULT NULL COMMENT '过期时间',
  `create_time` int(11) NOT NULL,
  `update_time` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_session_key` (`session_id`,`key`),
  KEY `idx_session_id` (`session_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI 长期记忆表';
```

### `ai_tools` —— 工具注册表

```sql
CREATE TABLE `ai_tools` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(64) NOT NULL COMMENT '工具名称',
  `display_name` varchar(128) DEFAULT NULL,
  `description` text DEFAULT NULL COMMENT '工具描述',
  `namespace` varchar(64) DEFAULT 'builtin' COMMENT 'builtin/plugin:xxx',
  `source` varchar(32) DEFAULT 'config' COMMENT 'config/schema/code',
  `schema` text DEFAULT NULL COMMENT '工具参数 Schema JSON',
  `is_enabled` tinyint(1) DEFAULT 1 COMMENT '是否启用',
  `is_safe` tinyint(1) DEFAULT 0 COMMENT '是否安全（无需确认）',
  `tags` varchar(255) DEFAULT NULL,
  `meta` text DEFAULT NULL,
  `create_time` int(11) NOT NULL,
  `update_time` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_name_namespace` (`name`,`namespace`),
  KEY `idx_namespace` (`namespace`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI 工具注册表';
```

### `ai_agent_logs` —— 运行日志表

```sql
CREATE TABLE `ai_agent_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `session_id` varchar(64) NOT NULL COMMENT '会话ID',
  `request_id` varchar(64) DEFAULT NULL COMMENT '请求追踪ID',
  `agent_type` varchar(64) DEFAULT NULL COMMENT 'Agent 类型',
  `provider` varchar(64) DEFAULT NULL COMMENT 'Provider',
  `model` varchar(64) DEFAULT NULL COMMENT '模型',
  `step` int(11) DEFAULT 0 COMMENT '步骤序号',
  `event` varchar(64) DEFAULT NULL COMMENT '事件类型',
  `level` varchar(16) DEFAULT 'info' COMMENT 'info/warn/error',
  `message` mediumtext DEFAULT NULL COMMENT '日志内容',
  `tokens_in` int(11) DEFAULT 0,
  `tokens_out` int(11) DEFAULT 0,
  `duration_ms` int(11) DEFAULT 0 COMMENT '耗时毫秒',
  `tracking_status` varchar(32) DEFAULT NULL COMMENT 'tracking/complete/timeout/stopped',
  `tracking_updated_at` int(11) DEFAULT NULL COMMENT '追踪更新时间',
  `context` text DEFAULT NULL COMMENT '上下文 JSON',
  `create_time` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_session_id` (`session_id`),
  KEY `idx_request_id` (`request_id`),
  KEY `idx_event` (`event`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI 智能体运行日志';
```

### `ai_config` —— AI 配置表（加密持久化）

```sql
CREATE TABLE `ai_config` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `key` varchar(128) NOT NULL COMMENT '配置键（如 providers.openai.api_key）',
  `value` text DEFAULT NULL COMMENT '加密后的值',
  `is_encrypted` tinyint(1) DEFAULT 0 COMMENT '是否加密',
  `update_time` int(11) NOT NULL,
  `create_time` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_key` (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI 配置（加密持久化）';
```

### `ai_skills` —— 技能表

```sql
CREATE TABLE `ai_skills` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(128) NOT NULL COMMENT '技能名称',
  `display_name` varchar(128) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `namespace` varchar(64) DEFAULT 'builtin' COMMENT 'builtin/plugin:xxx',
  `source` varchar(32) DEFAULT 'code' COMMENT 'code/schema',
  `is_enabled` tinyint(1) DEFAULT 1,
  `usage_count` int(11) DEFAULT 0 COMMENT '使用次数',
  `avg_rating` decimal(3,2) DEFAULT 0.00 COMMENT '平均评分',
  `feedback_count` int(11) DEFAULT 0 COMMENT '反馈数',
  `meta` text DEFAULT NULL,
  `create_time` int(11) NOT NULL,
  `update_time` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_name_namespace` (`name`,`namespace`),
  KEY `idx_namespace` (`namespace`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI 技能表';
```

### `ai_pipeline_runs` —— 流水线运行记录表

```sql
CREATE TABLE `ai_pipeline_runs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `run_id` varchar(64) NOT NULL COMMENT '运行ID',
  `pipeline_name` varchar(128) NOT NULL COMMENT '流水线名称',
  `source` varchar(64) DEFAULT NULL COMMENT '来源（plugin:cms 等）',
  `status` varchar(32) DEFAULT 'running' COMMENT 'running/success/failed/cancelled',
  `input` text DEFAULT NULL COMMENT '输入 JSON',
  `output` text DEFAULT NULL COMMENT '输出 JSON',
  `current_step` int(11) DEFAULT 0 COMMENT '当前步骤',
  `total_steps` int(11) DEFAULT 0 COMMENT '总步骤',
  `error` text DEFAULT NULL COMMENT '错误信息',
  `started_at` int(11) DEFAULT NULL,
  `finished_at` int(11) DEFAULT NULL,
  `create_time` int(11) NOT NULL,
  `update_time` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_run_id` (`run_id`),
  KEY `idx_pipeline_name` (`pipeline_name`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI 流水线运行记录';
```

### `ai_feedback` —— 技能反馈表

```sql
CREATE TABLE `ai_feedback` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `skill_name` varchar(128) NOT NULL COMMENT '技能名称',
  `rating` tinyint(2) NOT NULL COMMENT '评分 1-5',
  `comment` text DEFAULT NULL COMMENT '评语',
  `correction` text DEFAULT NULL COMMENT '纠正内容',
  `user_id` int(11) DEFAULT 0 COMMENT '用户ID',
  `session_id` varchar(64) DEFAULT NULL COMMENT '会话ID',
  `create_time` int(11) NOT NULL,
  `update_time` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_skill_name` (`skill_name`),
  KEY `idx_rating` (`rating`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI 技能反馈表';
```

### `ai_tasks` —— 定时任务表

```sql
CREATE TABLE `ai_tasks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `task_id` varchar(64) NOT NULL COMMENT '任务ID',
  `name` varchar(128) DEFAULT NULL COMMENT '任务名',
  `type` varchar(32) DEFAULT 'pipeline' COMMENT 'pipeline/skill',
  `target` varchar(128) DEFAULT NULL COMMENT '目标（pipeline/skill 名）',
  `schedule` varchar(64) DEFAULT NULL COMMENT 'Cron 表达式',
  `input` text DEFAULT NULL COMMENT '输入 JSON',
  `source` varchar(64) DEFAULT NULL COMMENT '来源（plugin:xxx 等）',
  `status` varchar(32) DEFAULT 'active' COMMENT 'active/paused/disabled',
  `last_run` int(11) DEFAULT NULL COMMENT '上次运行时间',
  `next_run` int(11) DEFAULT NULL COMMENT '下次运行时间',
  `run_count` int(11) DEFAULT 0 COMMENT '运行次数',
  `meta` text DEFAULT NULL COMMENT '元数据 JSON',
  `create_time` int(11) NOT NULL,
  `update_time` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_task_id` (`task_id`),
  KEY `idx_schedule` (`schedule`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI 定时任务表';
```

> 迁移 `20260727001_add_tracking_to_agent_logs.php` 为 `ai_agent_logs` 追加 `request_id` / `tracking_status` / `tracking_updated_at` 三列及 `idx_request_id` 索引（见上表已含）。

---

## 与其他 wise-* 包协作 / 扩展点

wise-agent 本身**不直接依赖任何业务包**，而是通过「**AI 插件桥接（AI Plugin Bridge）**」机制与宿主及各 `wise-*` 业务包解耦协作——业务包在本体 `manifest.json` 中声明 AI 能力（工具 providers / 工作流节点 / 适配器 / `ai_schema`），wise-agent 在 `wise:agent:discover` 时通过 `PluginBridge` / `PluginAiDiscovery` 自动发现并加载，无需硬编码耦合。

- **`wise-support`（强依赖）**：提供 `RegistersMigrations`、`ServiceTrait`、`ConfigCheckCollector`、`ContextualLogger`、`EnvSeedProvider` 等基础设施契约，wise-agent 的服务注册、迁移发布、配置检查、`.env` 播种均基于此。
- **`wise-auth`（协作）**：`config/wise-agent.php` 的 `web.groups.admin.middleware` 注释建议挂载 `\wise\auth\middleware\RbacMiddleware::class`，以保护 `/admin/ai/*` 端点；`SessionUserContext` 实现 `UserContextInterface` 注入当前用户身份与权限。
- **`think-queue`（协作）**：异步 Agent（`dispatchAsync`）与流水线由 `AiAgentJob` / `PipelineJob` 投递到队列（连接配置见 `queue.connection`），由 Worker 消费。
- **业务包（自动桥接）**：任意 `wise-*` 业务包只要在 `manifest.json` 声明 `auto_introspect: true` 与 `ai_schema`，wise-agent 的 `SchemaIntrospector` 即内省其数据模型，自动生成 CRUD 工具（`list`/`get`/`search`/`stats`/`create`/`update`/`delete`/`list_with_relation`），并解析业务能力标签（`inventory`/`sales`/`crm`/`content`）注入领域上下文。还可 `materialize` 为插件内 PHP 类文件固化。
- **扩展点**：
  - 新增模型 Provider：实现 `AiClientInterface` 并在 `providers` 登记（或注册到 `AdapterRegistry`）。
  - 新增工具：实现 `ToolInterface` 后用 `wise_agent_tool()` 注册，或在插件 `manifest.json` 中以 provider 暴露。
  - 新增技能：继承技能基类并登记到 `skills.builtin`，或以 `SKILL.md` 声明。
  - 新增业务能力：实现 `BusinessCapability` 接口并在 `schema.capabilities` 注册。
  - 监听事件：通过 ThinkPHP `Event::listen` 监听 `wise\agent\event\*` 做审计、埋点、业务联动。

---

## 目录结构

```
wise-agent/
├── agent/                  # 引擎：AgentManager + BaseAgent/SimpleAgent
│   ├── AgentManager.php
│   ├── BaseAgent.php
│   ├── SimpleAgent.php
│   └── AgentContext.php
├── bridge/                 # AI 插件桥接
│   ├── PluginBridge.php
│   ├── PluginAiDiscovery.php
│   ├── ProviderCache.php
│   ├── NodeRegistry.php
│   └── AdapterRegistry.php
├── client/                 # LLM 客户端
│   ├── AiClientFactory.php
│   ├── OpenAiClient.php
│   └── DeepSeekClient.php
├── command/                # 控制台命令
│   ├── Discover.php        # wise:agent:discover
│   └── ScanTimeouts.php    # wise:agent:scan-timeouts
├── config/                 # 配置检查
│   ├── AgentSecretCheck.php
│   └── AgentCacheCheck.php
├── context/                # 上下文注入
│   ├── ContextManager.php
│   └── builtin/            # SystemContextProvider / UserContextProvider
├── contract/               # 核心契约接口
│   ├── AiClientInterface.php
│   ├── ToolInterface.php
│   ├── MemoryInterface.php
│   ├── UserContextInterface.php
│   ├── ConversationInterface.php
│   └── ConfigurableAgentInterface.php
├── controller/             # Web 控制器
│   └── AiController.php
├── dto/                    # 数据传输对象
├── event/                  # 生命周期事件（AgentStart / AiRequest / ToolExecute ...）
├── exception/              # 异常类
├── execution/              # 异步执行追踪
│   └── ExecutionTracker.php
├── feedback/               # 反馈与技能优化
│   ├── FeedbackCollector.php
│   ├── SkillOptimizer.php
│   └── Feedback.php
├── job/                    # 队列任务
│   ├── AiAgentJob.php
│   └── PipelineJob.php
├── listener/               # 事件监听器
│   ├── AiLogger.php
│   └── ToolSecurityCheck.php
├── memory/                 # 记忆系统
│   ├── MemoryFactory.php
│   ├── DatabaseMemory.php
│   ├── RedisMemory.php
│   └── session/            # SessionManager / Conversation / SessionUserContext
├── pipeline/               # 流水线引擎
│   ├── PipelineEngine.php
│   └── PipelineRegistry.php
├── schema/                 # Schema 自动发现
│   ├── SchemaIntrospector.php
│   ├── discovery/          # PluginSchemaDiscovery
│   ├── capability/         # BusinessCapabilityResolver + 内置能力
│   └── generator/          # ToolMaterializer / NodeMaterializer
├── schedule/               # 定时任务
│   ├── AiTaskScheduler.php
│   └── AiTask.php
├── skill/                  # 技能系统
│   ├── SkillManager.php
│   ├── SkillRegistry.php
│   ├── SkillResult.php
│   └── builtin/            # 内置技能（内容/数据/系统类）
├── tool/                   # 工具注册与内置工具
│   ├── ToolRegistry.php
│   └── builtin/            # FileRead/FileWrite/DatabaseQuery/HttpRequest/OrchestrateStep
├── traits/                 # 复用 trait
├── AgentServiceProvider.php
├── RouteRegistrar.php
├── helper.php              # 全局辅助函数
├── composer.json
├── config/wise-agent.php
├── database/migrations/    # 11 个迁移文件
├── skills/                 # 内置 SKILL.md 资源
├── public/html/            # 后台静态页面（chat / config）
└── docs/                   # 设计文档
```

---

## 依赖关系

取自 `composer.json`：

**require**

| 包 | 约束 | 说明 |
| --- | --- | --- |
| `php` | `>=8.1` | PHP 运行时 |
| `topthink/framework` | `^6.0 \|\| ^8.0` | ThinkPHP 框架 |
| `topthink/think-orm` | `^2.0 \|\| ^3.0` | ORM |
| `topthink/think-queue` | `^3.0` | 队列（异步 Agent / Pipeline） |
| `wiseadmin/wise-support` | `@dev` | 基础设施契约与服务Trait |
| `ext-curl` | `*` | HTTP 客户端 |
| `ext-json` | `*` | JSON 处理 |
| `ext-openssl` | `*` | AI 配置加密 |

**require-dev**

| 包 | 约束 | 说明 |
| --- | --- | --- |
| `phpunit/phpunit` | `^9.0 \|\| ^10.0 \|\| ^11.0` | 单元测试 |

**suggest**

| 扩展 | 说明 |
| --- | --- |
| `ext-redis` | 推荐使用 Redis 作为记忆存储后端（`RedisMemory`） |

**命名空间 / 自动加载**

- PSR-4：`wise\agent\` → `src/`
- 全局函数：`src/helper.php`（经 `autoload.files` 自动加载）
- 服务发现：`extra.think.services` → `wise\agent\AgentServiceProvider`
- 配置发布：`extra.think.config.wise-agent` → `config/wise-agent.php`
- 迁移路径：`AgentServiceProvider::boot()` 的 `registerMigrations()` → `{包根}/database/migrations`

---

## 许可证

[Apache-2.0](https://www.apache.org/licenses/LICENSE-2.0)

```
Copyright (c) 2024-2026 WiseAdmin Development Team

Licensed under the Apache License, Version 2.0
```
