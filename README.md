# wiseadmin/wise-agent

> WiseAdmin AI Agent — 为 ThinkPHP 8 框架提供 AI 能力的 Composer 包。

纯 PHP 实现，事件驱动架构，插件可扩展。支持 Agent 自动循环、工具调用（Function Calling）、多层次记忆系统。

[![PHP Version](https://img.shields.io/badge/PHP-%3E%3D8.1-blue)](https://www.php.net)
[![ThinkPHP](https://img.shields.io/badge/ThinkPHP-%5E6.0%20%7C%7C%20%5E8.0-green)](https://www.thinkphp.cn) 
[![License](https://img.shields.io/badge/license-MIT-brightgreen)](LICENSE)
[![Packagist](https://img.shields.io/badge/packagist-wiseadmin%2Fwise--agent-orange)](https://packagist.org/packages/wiseadmin/wise-agent)

---

## 特性

- **Agent 循环**: 自动分析任务 → 调用 LLM → 解析响应 → 调用工具 → 循环迭代，直到任务完成
- **工具系统**: 内置 `file_read` / `file_write` / `db_query` / `http_request` 4 个工具，插件可注册自定义工具
- **事件驱动**: 全生命周期 7 个事件（AgentStart/Step/Complete/Error + AiRequest/Response + ToolExecute），插件可零侵入扩展
- **多层记忆**: 短期记忆（对话上下文）+ 长期记忆（Database/Redis 持久化）
- **多 Provider**: 支持 OpenAI、DeepSeek 及任意 OpenAI 兼容 API
- **插件友好**: 三种接入方式（事件监听 / ServiceProvider / manifest.json 声明）
- **异步支持**: 通过 think-queue 支持异步 Agent 任务
- **纯 PHP**: 零额外二进制依赖，基于 cURL HTTP 通信

---

## 安装

```bash
# 1. 安装包
composer require wiseadmin/wise-agent:@dev

# 2. 发布配置和迁移文件
php think wise:agent:publish

# 3. 执行数据库迁移
php think migrate:run
```

推荐安装 Redis 扩展以获得更好的记忆存储性能：

```bash
# Redis 记忆存储
pecl install redis
```

### 路由配置

包内提供了预置路由文件 `route/ai.php`，包含 15 条 API 路由和 2 条页面入口路由。

**1. 将路由文件复制到项目路由目录：**

```bash
cp vendor/wiseadmin/wise-agent/route/ai.php app/admin/route/ai.php
```

**2. 在项目路由注册文件中引入并添加权限中间件：**

```php
// app/admin/route/admin.php
Route::group('admin', function () {
    // ... 已有路由 ...
    
    // AI Assistant routes
    require __DIR__ . '/ai.php';
})->middleware(\wise\auth\middleware\RbacMiddleware::class);
```

**3. 权限控制说明：**

路由级别的权限由 `\wise\auth\middleware\RbacMiddleware` 控制，遵循 wise-auth 的 whitelist 模式。

工具级别的权限由事件驱动：包内置的 `ToolSecurityCheck` 监听器在工具执行前触发 `\wise\agent\Event\ToolAuthorize` 事件（默认拒绝）。宿主应用应在 `app/event.php` 中监听此事件并调用 `RbacManager::check()` 决定是否放行：

```php
// app/event.php
\wise\agent\Event\ToolAuthorize::class => function ($event) {
    $rbac = app('wise.auth.manager');
    if ($rbac->isSuperAdmin($event->userId, $event->userType)) {
        $event->allow();
        return;
    }
    if ($rbac->check($event->userId, $event->userType, $event->permission)) {
        $event->allow();
    }
},
```

---

## 配置

### 环境变量

```env
# 默认 Provider
AI_DEFAULT_PROVIDER=openai

# OpenAI
OPENAI_API_KEY=sk-xxx
OPENAI_BASE_URL=https://api.openai.com/v1
OPENAI_MODEL=gpt-4o

# DeepSeek
DEEPSEEK_API_KEY=sk-xxx
DEEPSEEK_MODEL=deepseek-chat
```

### 完整配置

```php
// config/wise-agent.php
return [
    'default' => 'openai',                 // 默认 Provider
    'providers' => [...],                 // LLM Provider 配置
    'agent' => [
        'max_steps' => 10,               // 最大循环步数
        'max_tokens_per_step' => 4096,   // 每步最大 Token
        'temperature' => 0.7,            // 温度参数
        'system_prompt' => '...',        // 默认系统提示
        'enable_memory' => true,         // 启用长期记忆
        'session_ttl' => 86400,          // 会话有效期（秒）
    ],
    'memory' => [
        'default' => 'database',         // 默认记忆存储
        'stores' => [...],               // 记忆存储配置
    ],
    'tools' => [...],                    // 内置工具配置
    'log' => ['enabled' => true, 'channel' => 'ai'],
    'queue' => ['connection' => 'database'],
    'security' => [
        'max_tool_executions_per_step' => 5,
        'max_agent_duration' => 300,
    ],
    'tables' => [
        'sessions' => 'ai_sessions',
        'messages' => 'ai_messages',
        'memories' => 'ai_memories',
        'tools' => 'ai_tools',
        'agent_logs' => 'ai_agent_logs',
    ],
];
```

---

## 快速开始

### 1. 简单对话

```php
use wise\agent\Agent\AgentManager;

// 通过门面助手函数
$answer = wise_agent()->chat('请分析这个月的销售数据趋势');

// 或通过容器
$manager = app()->make(AgentManager::class);
$answer = $manager->chat('你好，介绍一下 WiseAdmin 框架');
```

### 2. 多轮对话（保持上下文）

```php
// 开始新对话
$conv = wise_agent_session();
$conv->addMessage('user', '创建一个用户表的设计');

// 通过 AgentManager 获取回复
$answer1 = wise_agent()->chat('创建一个用户表的设计');

// 继续对话（自动保持上下文 — 基于同一 session）
$answer2 = wise_agent()->chat('添加一个登录日志表');
```

### 3. Agent 任务（带工具调用）

```php
// 创建 Agent 并配置工具
$result = wise_agent()
    ->create('simple')
    ->withTools(['file_read', 'db_query'])
    ->withMemory(true)
    ->run('读取 config/app.php 文件，分析其中的配置项');

echo $result;
```

### 4. 自定义 Agent 类型

```php
namespace app\agent;

use wise\agent\Agent\BaseAgent;

class CodeReviewAgent extends BaseAgent
{
    public function getType(): string
    {
        return 'code-review';
    }

    public function getSystemPrompt(): string
    {
        return 'You are a senior code reviewer. Analyze code for bugs, security issues, and performance problems.';
    }
}

// 使用
$agent = new CodeReviewAgent();
$result = $agent->withTools(['file_read'])->run('Review app/admin/controller/Index.php');
```

---

## 插件开发指南

### 注册自定义工具

工具是实现 `wise\agent\Contract\ToolInterface` 的类。推荐继承 `BaseTool`:

```php
namespace plugin\my_plugin\tool;

use wise\agent\Tool\BaseTool;

class OrderQueryTool extends BaseTool
{
    public function getName(): string
    {
        return 'order_query';
    }

    public function getDescription(): string
    {
        return 'Query order information by order ID';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'order_id' => [
                    'type' => 'string',
                    'description' => 'The order ID to query',
                ],
            ],
            'required' => ['order_id'],
        ];
    }

    public function execute(array $arguments): string
    {
        $orderId = $arguments['order_id'];
        $order = \app\model\Order::find($orderId);
        return json_encode($order ? $order->toArray() : ['error' => 'Order not found']);
    }
}
```

### 注册工具（3 种方式）

**方式 1 — 通过事件（推荐）**:

```php
// plugins/my_plugin/event.php
return [
    'listen' => [
        'AiToolRegister' => function ($registry) {
            $registry->register(new \plugin\my_plugin\tool\OrderQueryTool());
        },
    ],
];
```

**方式 2 — 通过 ServiceProvider**:

```php
// plugins/my_plugin/BootServiceProvider.php
public function boot(): void
{
    parent::boot();
    \wise\agent\Tool\ToolRegistry::instance()->register(new OrderQueryTool());
}
```

**方式 3 — 通过函数**:

```php
wise_agent_tool(new OrderQueryTool());
```

### 监听 AI 事件

```php
// plugins/my_plugin/event.php
return [
    'listen' => [
        // 在 AI 请求前注入上下文
        'AiRequest' => function ($event) {
            $event->messages[] = [
                'role' => 'system',
                'content' => 'Current user: ' . session('admin_username'),
            ];
        },

        // 在 Agent 完成后处理结果
        'AgentComplete' => function ($event) {
            \think\facade\Log::info("Agent completed: {$event->sessionId}, steps: {$event->totalSteps}");
        },

        // 安全检查：拦截危险工具
        'ToolExecute' => function ($event) {
            if ($event->phase === 'before' && $event->toolName === 'file_delete') {
                $event->skip = true;
                $event->mockResult = 'Permission denied';
            }
        },
    ],
];
```

---

## API 参考

### 辅助函数

| 函数 | 说明 |
|------|------|
| `wise_agent()` | 获取 AgentManager 实例 |
| `wise_agent_chat($message, $provider?, $options?)` | 快速对话 |
| `wise_agent_tool($tool)` | 注册工具 |
| `wise_agent_memory($sessionId, $key, $value, $tags?)` | 存储记忆 |
| `wise_agent_recall($sessionId, $key, $default?)` | 检索记忆 |
| `wise_agent_session($sessionId?)` | 管理对话会话 |

### AgentManager

```php
$manager = app()->make(\wise\agent\Agent\AgentManager::class);

// 快速对话
$manager->chat(string $message, ?string $provider = null, array $options = []): string

// 创建 Agent
$manager->create(?string $type = null, array $options = []): AgentInterface

// 注册自定义 Agent 类型
$manager->register(string $type, string $agentClass): void

// 运行 Agent
$manager->run(AgentInterface $agent, string $task, array $context = []): string

// 异步执行
$manager->dispatchAsync(AgentInterface $agent, string $task, array $options = []): string
```

### AgentInterface

```php
interface AgentInterface
{
    public function getSessionId(): string;
    public function getType(): string;
    public function getSystemPrompt(): string;
    public function setSystemPrompt(string $prompt): self;
    public function withTools(array $toolNames): self;
    public function withMemory(bool $enabled): self;
    public function run(string $task, array $context = []): string;
    public function dispatchAsync(string $task, array $options = []): string;
}
```

### ToolInterface

```php
interface ToolInterface
{
    public function getName(): string;
    public function getDescription(): string;
    public function getParameters(): array;
    public function execute(array $arguments): string;
    public function getPermission(): string;
    public function requireConfirmation(): bool;
}
```

### 事件列表

| 事件 | 触发时机 | 可操作 |
|------|----------|--------|
| `AgentStart` | Agent 开始执行 | 读取上下文 |
| `AgentStep` | 每轮 LLM 交互后 | 记录/监控 |
| `AgentComplete` | Agent 成功完成 | 后处理 |
| `AgentError` | Agent 出错 | 错误处理 |
| `AiRequest` | 发送 LLM 请求前 | 修改/跳过/注入上下文 |
| `AiResponse` | 收到 LLM 响应后 | 修改响应 |
| `ToolExecute` | 工具执行前/后 | 安全校验/拦截/修改结果 |

---

## 数据库表

| 表名 | 说明 |
|------|------|
| `ai_sessions` | 对话会话记录 |
| `ai_messages` | 对话消息历史 |
| `ai_memories` | 长期记忆存储 |
| `ai_tools` | 工具注册表 |
| `ai_agent_logs` | Agent 运行日志 |

---

## 安全注意事项

1. **API Key 安全**: 使用 `.env` 文件存储 API Key，不要提交到版本控制
2. **工具权限**: 内置工具默认禁用，需在配置中显式开启
3. **路径限制**: `file_read` / `file_write` 工具限制在项目根目录内
4. **SQL 安全**: `db_query` 工具仅允许只读查询（SELECT/SHOW/DESCRIBE/EXPLAIN）
5. **网络安全**: `http_request` 工具禁止 localhost 请求
6. **事件拦截**: 通过 `ToolExecute` 事件可在工具执行前进行安全校验

---

## 目录结构

```
wise-agent/
├── composer.json
├── phpunit.xml
├── config/
│   └── wise-agent.php
├── database/
│   └── migrations/
│       ├── 20260617001_create_ai_sessions.php
│       ├── 20260617002_create_ai_messages.php
│       ├── 20260617003_create_ai_memories.php
│       ├── 20260617004_create_ai_tools.php
│       └── 20260617005_create_ai_agent_logs.php
├── src/
│   ├── Service.php                    # 服务提供者
│   ├── helper.php                     # 辅助函数
│   ├── trait/
│   │   └── ServiceTrait.php
│   ├── Agent/
│   │   ├── AgentManager.php           # Agent 管理器（核心 Run Loop）
│   │   ├── BaseAgent.php              # Agent 基类
│   │   ├── SimpleAgent.php            # 默认 Agent
│   │   └── AgentContext.php           # 运行上下文
│   ├── Client/
│   │   ├── AiClientFactory.php        # Provider 工厂
│   │   ├── OpenAiClient.php           # OpenAI 适配
│   │   ├── DeepSeekClient.php         # DeepSeek 适配
│   │   └── HttpClient.php             # HTTP 通信层
│   ├── Contract/
│   │   ├── AgentInterface.php
│   │   ├── AiClientInterface.php
│   │   ├── ToolInterface.php
│   │   └── MemoryInterface.php
│   ├── Event/
│   │   ├── AgentStart.php
│   │   ├── AgentStep.php
│   │   ├── AgentComplete.php
│   │   ├── AgentError.php
│   │   ├── AiRequest.php
│   │   ├── AiResponse.php
│   │   └── ToolExecute.php
│   ├── Exception/
│   │   ├── AiException.php
│   │   ├── ProviderException.php
│   │   └── ToolException.php
│   ├── Listener/
│   │   ├── AiLogger.php
│   │   └── ToolSecurityCheck.php
│   ├── Memory/
│   │   ├── MemoryFactory.php
│   │   ├── DatabaseMemory.php
│   │   ├── RedisMemory.php
│   │   └── Session/
│   │       ├── Conversation.php
│   │       └── SessionManager.php
│   ├── Tool/
│   │   ├── ToolRegistry.php
│   │   ├── BaseTool.php
│   │   └── builtin/
│   │       ├── FileReadTool.php
│   │       ├── FileWriteTool.php
│   │       ├── DatabaseQueryTool.php
│   │       └── HttpRequestTool.php
│   └── command/
│       └── Publish.php
└── tests/
    ├── bootstrap.php
    ├── ToolRegistryTest.php
    ├── EventTest.php
    └── ExceptionTest.php
```

---

## License

MIT
