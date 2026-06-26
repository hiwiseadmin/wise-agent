# Wise-Agent 代码审计报告

> 审计人：严过关 (QA Engineer)
> 审计日期：2025-07-10
> 审计范围：wiseadmin/wise-agent 全部源码（54个文件）

---

## 总览

| 严重度 | 数量 |
|--------|------|
| 🔴 Critical | 5 |
| 🟠 High | 8 |
| 🟡 Medium | 15 |
| 🟢 Low | 12 |
| **合计** | **40** |

---

## 🔴 Critical Issues

### C-01: ToolRegistry 中 callable 工具注册时丢失名称
- **文件**: `src/Tool/ToolRegistry.php:50` + `src/Agent/BaseAgent.php:89-99`
- **描述**: `BaseAgent::addTool(callable|string|array $tool, string $name)` 接收了 `$name` 参数，但调用 `ToolRegistry::register($tool)` 时**从未传递 `$name`**。对于 callable 工具，`register()` 使用 `spl_object_hash()` 生成 key，导致：
  1. 调用方无法通过名称检索该工具
  2. LLM 无法通过 Function Calling 调用该工具（因为 `getToolSchemas()` 会跳过 callable）
  3. 同一个 callable 注册两次会产生两个条目
- **修复建议**: 
  - `ToolRegistry::register()` 增加可选 `$name` 参数
  - `BaseAgent::addTool()` 将 `$name` 传递给 `register()`
  - 或者在 `getToolSchemas()` 中支持 callable 类型

### C-02: 迁移文件中表名配置完全失效
- **文件**: 所有 5 个 migration 文件 (`database/migrations/2026061700*.php`)
- **描述**: 迁移文件调用 `$this->getTableConfig()` 获取配置，然后使用 `$config['table']` 获取表名。但 `config('wise-agent.tables')` 返回的是：
  ```php
  ['sessions' => 'ai_sessions', 'messages' => 'ai_messages', ...]
  ```
  而代码查找 `$config['table']` —— 这个 key **根本不存在**！所有迁移始终使用硬编码的 fallback 值。配置中的自定义表名永远不会生效。
- **修复建议**: 各迁移使用对应的 key：
  - CreateAiSessionsTable → `$config['sessions']`
  - CreateAiMessagesTable → `$config['messages']`
  - CreateAiMemoriesTable → `$config['memories']`
  - CreateAiToolsTable → `$config['tools']`
  - CreateAiAgentLogsTable → `$config['agent_logs']`

### C-03: DatabaseQueryTool SQL 注入 - INTO OUTFILE 绕过
- **文件**: `src/Tool/builtin/DatabaseQueryTool.php:70-75`
- **描述**: 危险关键词黑名单缺少 `INTO`。攻击者可通过 LLM 提示注入执行：
  ```sql
  SELECT * FROM users INTO OUTFILE '/var/www/html/export.php'
  ```
  `SELECT` 前缀检查通过，`INTO` 不在黑名单中，数据被写入任意文件。
- **修复建议**: 将 `INTO`、`LOAD`、`EXEC`、`EXECUTE`、`BENCHMARK`、`SLEEP` 加入黑名单；或使用白名单方式（仅允许纯 SELECT 无子句）。

### C-04: DatabaseQueryTool SQL 注入 - UNION 数据泄露
- **文件**: `src/Tool/builtin/DatabaseQueryTool.php:64-67`
- **描述**: 只检查语句是否以 `SELECT/SHOW/DESCRIBE/EXPLAIN` 开头，但 `UNION SELECT` 等子查询不受限制。LLM 可生成：
  ```sql
  SELECT id, username FROM users UNION SELECT password, email FROM admins
  ```
  这允许跨表读取敏感数据。
- **修复建议**: 增加表级别权限控制或限制可查询的表白名单。

### C-05: FileReadTool::resolvePath() 中 realpath() 返回 false 导致 TypeError
- **文件**: `src/Tool/builtin/FileReadTool.php:107`
- **描述**: 
  ```php
  if (!str_starts_with($resolved, realpath($this->basePath))) {
  ```
  当 `$this->basePath` 不存在时，`realpath()` 返回 `false`，传入 `str_starts_with()` 会在 PHP 8.x 中抛出 `TypeError`，导致 Agent 崩溃。
- **修复建议**: 
  ```php
  $realBasePath = realpath($this->basePath);
  if ($realBasePath === false || !str_starts_with($resolved, $realBasePath)) {
      return null;
  }
  ```

---

## 🟠 High Issues

### H-01: HttpRequestTool SSRF 防护可被多种方式绕过
- **文件**: `src/Tool/builtin/HttpRequestTool.php:78-83`
- **描述**: localhost 阻止基于 `in_array($host, $blockedHosts)` 的简单匹配，可被以下方式绕过：
  - `http://127.0.0.1.nip.io/` (DNS rebinding)
  - `http://0x7f000001/` (十六进制 IP)
  - `http://2130706433/` (十进制 IP)
  - `http://[::ffff:127.0.0.1]/` (IPv6 映射)
  - `http://localhost.internal.company.com/`
- **修复建议**: 使用 `dns_get_record()` 解析后检查 IP，或使用 `curl` 的 `CURLOPT_PROTOCOLS` 和自定义 `CURLOPT_OPENSOCKETFUNCTION` 回调验证目标 IP。

### H-02: HttpClient::request() 非 ProviderException 异常穿透重试逻辑
- **文件**: `src/Client/HttpClient.php:65-78`
- **描述**: `try/catch` 只捕获 `ProviderException`。如果 `doRequest()` 抛出 `\TypeError`、`\Error`、`\InvalidArgumentException` 等，重试循环被直接穿透，不会重试。
- **修复建议**: 增加 `catch (\Throwable $e)` 作为兜底，或确保 `doRequest()` 所有异常都包装为 `ProviderException`。

### H-03: RedisMemory::forget(null) 无法清除所有 session 数据
- **文件**: `src/Memory/RedisMemory.php:100-109`
- **描述**: 当 `$key === null` 时（应清除 session 下所有数据），方法直接返回，注释说"依赖 TTL 过期"。但 TTL 默认为 30 天，数据在"遗忘"后仍可存活长达 30 天，违反数据隐私预期。
- **修复建议**: 使用 Redis `SCAN` + `DEL` 模式，或维护一个 session key 索引集合来支持批量删除。

### H-04: RedisMemory::getAll() 始终返回空数组
- **文件**: `src/Memory/RedisMemory.php:93-98`
- **描述**: 核心接口方法 `getAll()` 直接 `return []`——功能缺失。调用方（如 Conversation 的历史加载逻辑）依赖此方法时会得到空结果。接口文档承诺"获取所有 memory entries"，但 Redis 实现无法履约。
- **修复建议**: 至少文档化此限制；或维护 session key 索引；或使用 Redis `SCAN` 模式匹配。

### H-05: AgentManager::run() 中 maxSteps 检查在工具执行之后
- **文件**: `src/Agent/AgentManager.php:168-263`
- **描述**: 在第 10 步（maxSteps=10）时流程为：`incrementStep() → LLM调用 → 工具执行 → isMaxStepsReached()检查 → break`。这意味着即使已达步数上限，仍会多执行一轮工具调用。这可能导致非预期的副作用（如文件写入、HTTP 请求等）。
- **修复建议**: 将 `isMaxStepsReached()` 检查移到循环开始处（在 incrementStep 之后、LLM 调用之前）。

### H-06: ToolRegistry 单例模式在常驻进程环境下不安全
- **文件**: `src/Tool/ToolRegistry.php:21-37`
- **描述**: 静态属性 `$instance` 在 Swoole/Workerman 等常驻进程环境下是进程级共享的。如果一个请求修改了 `$instance` 状态，会影响到后续所有请求。
- **修复建议**: 提供 `reset()` 方法用于请求结束时清理；或使用协程上下文隔离实例。

### H-07: DatabaseQueryTool SQL 执行无超时保护
- **文件**: `src/Tool/builtin/DatabaseQueryTool.php:83`
- **描述**: `Db::query($query)` 没有设置查询超时或最大行数限制。恶意或意外的复杂查询（如多表 JOIN 无索引）可能导致数据库被长时间阻塞（DoS）。
- **修复建议**: 在执行前设置 `MAX_EXECUTION_TIME` hint 或 connection timeout。

### H-08: Service 中 MemoryInterface 绑定使用单例但其依赖配置
- **文件**: `src/Service.php:45-47`
- **描述**: 
  ```php
  $this->app->bind(MemoryInterface::class, function (App $app) {
      return MemoryFactory::create();
  });
  ```
  这个闭包绑定的结果在 ThinkPHP 中默认是单例。如果运行时配置更改（如切换 memory store），已绑定的实例不会更新。且 `MemoryFactory::create()` 内部使用静态缓存 `static::$instances`，即使重新调用也不会重建。
- **修复建议**: 使用 `bind()` 的非单例模式，或确保 MemoryFactory 的 `clearCache()` 在配置变更时被调用。

---

## 🟡 Medium Issues

### M-01: json_decode() 返回值歧义 - false/0 被当作空数组
- **文件**: `src/Client/HttpClient.php:153`
- **描述**: `json_decode($responseBody, true) ?: []` — 当 API 返回合法 JSON `false` 或 `0` 时，`?:` 操作符会将它们替换为 `[]`。应使用显式检查：
  ```php
  $result = json_decode($responseBody, true);
  return json_last_error() === JSON_ERROR_NONE ? $result : [];
  ```

### M-02: ToolRegistry::getToolSchemas() 跳过 callable 类型工具
- **文件**: `src/Tool/ToolRegistry.php:125-144`
- **描述**: foreach 中 callable 工具既不满足 `instanceof ToolInterface` 也不满足 `is_array`，被静默跳过，LLM 永远看不到这些工具。如果是设计选择，需要文档化；如果是疏漏，需要添加对 callable 的支持。

### M-03: FileWriteTool::resolvePath() 与 FileReadTool::resolvePath() 不一致
- **文件**: `src/Tool/builtin/FileReadTool.php:94-112` vs `src/Tool/builtin/FileWriteTool.php:93-117`
- **描述**: FileReadTool 支持绝对路径（`/` 和 `C:\` 开头），FileWriteTool 将所有路径视为相对路径。这种不一致性意味着：
  - 读工具：可以验证 `/etc/passwd` 是否在 basePath 外（并拒绝）
  - 写工具：`/etc/passwd` 被拼接到 basePath 后，变成 `{basePath}/etc/passwd`
  虽然写工具更安全（因为绝对路径被限制），但两个同名方法行为不同会造成维护困惑。
- **修复建议**: 统一两个 resolvePath 实现，或提取到 BaseTool 作为共享方法。

### M-04: DatabaseQueryTool 允许无 LIMIT 的 SELECT *
- **文件**: `src/Tool/builtin/DatabaseQueryTool.php:79-80`
- **描述**: 当用户查询不包含 `LIMIT` 时追加默认 `LIMIT 100`。但空 LIMIT 检查使用 `str_contains($upperQuery, 'LIMIT')`，可能匹配到字段名或字符串字面量中的 "limit"。
- **修复建议**: 使用正则 `/\bLIMIT\s+\d+/i` 进行更精确的匹配。

### M-05: Conversation::trimIfNeeded() 未实现真正的摘要压缩
- **文件**: `src/Memory/Session/Conversation.php:131-138`
- **描述**: 方法注释写"Summarize and compress when too long"，但实际只做了 `array_slice` 截断旧消息，没有摘要。Agent 会丢失早期上下文信息。
- **修复建议**: 实现真正的摘要功能（调用 LLM 生成对话摘要保留为系统消息），或更新注释说明当前行为。

### M-06: SessionManager::resume() 静默吞噬异常
- **文件**: `src/Memory/Session/SessionManager.php:62-81`
- **描述**: 如果 session 记录不存在，`catch (\Throwable $e)` 吞掉异常后继续创建 Conversation。Conversation 构造函数会尝试从 messages 表加载历史——如果 messages 表也不存在，异常再次被 `loadHistory()` 吞掉。最终用户得到一个空对话，没有任何错误提示。
- **修复建议**: 至少记录日志；区分"表不存在"和"记录不存在"两种情况。

### M-07: AiClientFactory 实例缓存不响应运行时配置变更
- **文件**: `src/Client/AiClientFactory.php:30-36`
- **描述**: `static::$instances[$cacheKey]` 缓存只在进程生命周期内有效。如果程序中途修改了 `wise-agent.providers.{$name}` 配置（例如通过 Config::set），缓存不会失效。虽然有 `register()` 方法会 `unset` 缓存，但直接修改配置不会触发。

### M-08: BaseAgent::addTool() 中 string 类型处理有缺陷
- **文件**: `src/Agent/BaseAgent.php:89-99`
- **描述**: 
  ```php
  if (is_string($tool) && class_exists($tool)) {
      // ... handles ToolInterface classes
  }
  $this->getTools()->register($tool);
  ```
  如果 `$tool` 是字符串且 `class_exists()` 返回 false（类不存在），会fallthrough到 `register($tool)`。`register()` 中对字符串调用 `is_callable()`——如果是全局函数名则注册为callable，否则静默失败（什么都不做）。
- **修复建议**: 在 fallthrough 前增加 else 分支处理；或对无效输入抛出异常。

### M-09: Service.php 中 bind() 创建冗余的 MemoryFactory 实例
- **文件**: `src/Service.php:45-47` + `src/helper.php:58`
- **描述**: `helper.php` 中 `wise_agent_memory()` 调用 `app()->make(MemoryFactory::class)->create()`。但 MemoryFactory 并未在容器中绑定，`app()->make()` 只是实例化一个空对象再调 `create()`。正确方式是 `MemoryFactory::create()` 直接调用静态方法。

### M-10: composer.json minimum-stability: "dev" 引入不稳定依赖风险
- **文件**: `composer.json:46`
- **描述**: `"minimum-stability": "dev"` 使 Composer 解析所有依赖的 dev 版本，可能导致生产环境安装不稳定的依赖版本。
- **修复建议**: 改为 `"stable"` 或 `"rc"`。

### M-11: DatabaseMemory::store() 使用 MySQL 专有的 REPLACE INTO
- **文件**: `src/Memory/DatabaseMemory.php:31`
- **描述**: `Db::table()->replace()` 使用 MySQL 专有的 `REPLACE INTO` 语法。ThinkPHP 宣称支持多种数据库（PgSQL、SQLite），此语法在其他驱动上不可用。
- **修复建议**: 改用先检查存在再 INSERT/UPDATE 的兼容方式。

### M-12: AgentManager::dispatchAsync() 引用的 job 类未检查是否存在
- **文件**: `src/Agent/AgentManager.php:345` + `config/wise-agent.php:84`
- **描述**: 配置中 `queue.job` 被设为 `\wise\agent\job\AiAgentJob::class`，但此文件在包中**不存在**。虽然有 `class_exists()` 检查（抛出异常），但默认配置引用了一个不存在的类。
- **修复建议**: 创建 `AiAgentJob` 类或更新默认配置为 `''` + 文档说明。

### M-13: HttpClient 重试延迟为线性而非指数退避
- **文件**: `src/Client/HttpClient.php:68`
- **描述**: 重试延迟使用 `$retryDelay * 1000 * $attempt`（线性增长）。对于 rate limit (429) 或服务器过载场景，指数退避（如 `$retryDelay * pow(2, $attempt)`）更为合理。

### M-14: ToolSecurityCheck Listener 权限检查为空操作
- **文件**: `src/Listener/ToolSecurityCheck.php:33-36`
- **描述**: 
  ```php
  if (!empty($permission)) {
      // Delegate to application's permission system
      // For now, always allow (apps can add their own listeners)
  }
  ```
  权限非空时什么检查都不做，直接注释"always allow"。这意味着所有工具的 `getPermission()` 返回值形同虚设。
- **修复建议**: 至少实现基本的权限检查逻辑，或明确标注为占位符/TODO。

### M-15: FileReadTool 绝对路径检测不支持 Windows UNC 路径
- **文件**: `src/Tool/builtin/FileReadTool.php:96`
- **描述**: Windows 绝对路径检测正则 `/^[A-Z]:\\\/i` 不处理 UNC 路径（如 `\\server\share\file`）。这些路径在 Windows 上是合法的绝对路径，但会被当作相对路径处理。

---

## 🟢 Low Issues

### L-01: ToolRegistryTest 使用反射重置单例但未清空 $tools
- **文件**: `tests/ToolRegistryTest.php:18-22`
- **描述**: setUp 中只重置了 `$instance` 为 null，但未清空 `$tools`。如果前一个测试的实例仍被某处引用，其 `$tools` 数组仍保留旧数据（虽然新 `instance()` 会创建新实例）。

### L-02: tests/bootstrap.php 手动 require 在 PSR-4 autoload 下多余
- **文件**: `tests/bootstrap.php`
- **描述**: composer.json 已配置 `"wise\\agent\\": "src/"` 的 PSR-4 autoload，手动 require 是多余的且可能导致类重复定义警告。

### L-03: AiLogger::handle() 参数无类型声明
- **文件**: `src/Listener/AiLogger.php:29`
- **描述**: `public function handle($event): void` — 参数无类型提示。虽然是设计选择（处理多种事件类型），但可接受 `object` 类型。

### L-04: AgentManager::chat() 中 provider 参数传入但可能被 create() 忽略
- **文件**: `src/Agent/AgentManager.php:77-83`
- **描述**: 
  ```php
  $agent = $this->create('simple', $options);
  if ($provider) {
      $agent->setProviderName($provider);
  }
  ```
  `create('simple', $options)` 创建 SimpleAgent 时，如果 `$options` 中没有 `'provider'` key，则使用默认 provider。然后 `setProviderName($provider)` 覆盖它。但如果 `$options` 中有 provider，则先设置再覆盖，逻辑冗余。

### L-05: AgentContext::getMaxSteps() 有两条配置读取路径
- **文件**: `src/Agent/AgentContext.php:121-124`
- **描述**: `getMaxSteps()` 从 `$this->options['max_steps']` 读取，fallback 到 `config('wise-agent.agent.max_steps', 10)`。但在 AgentManager 中，`options['max_steps']` 已经从 config 加载过（第 152 行）。两条路径读同一个值但 fallback 不同（AgentManager 无 fallback 硬编码值），可能导致不一致。

### L-06: Publish 命令忽略 force 选项对迁移文件的作用
- **文件**: `src/command/Publish.php:98-105`
- **描述**: 迁移文件发布循环中已存在的文件会被跳过（`[skip]`），`--force` 选项只影响配置文件。如果用户想覆盖已发布的迁移文件，没有方法做到。

### L-07: BaseTool::getName() 命名转换对于包含 "Tool" 子串的类名有隐患
- **文件**: `src/Tool/BaseTool.php:36-37`
- **描述**: `str_replace('Tool', '', 'ToolBoxTool')` → `'Box'`，最终名称 `'box'`。虽然实际工具类不会这样命名，但转换逻辑不够精确。
- **修复建议**: 使用 `preg_replace('/Tool$/', '', $class)` 仅移除后缀。

### L-08: Event 类未继承统一基类
- **文件**: `src/Event/*.php` (全部7个)
- **描述**: 所有事件类都是独立的普通 PHP 类，无基类。虽然有 `timestamp` 公共字段，但没有接口约束。这在使用 `instanceof` 检测的模式中（如 AiLogger）不会出错，但缺少类型体系的一致性。

### L-09: AgentManager::chat() 始终使用 'simple' 类型
- **文件**: `src/Agent/AgentManager.php:79`
- **描述**: `$this->create('simple', $options)` — agent 类型硬编码为 'simple'。如果 SimpleAgent 未被注册（极端情况），会 fallthrough 到直接 `new SimpleAgent()`。但用户可能期望快速聊天使用默认 agent 类型。

### L-10: DatabaseMemory 和 SessionManager 中 getCurrentUserId/Type 代码重复
- **文件**: `src/Memory/DatabaseMemory.php:106-117` 和 `src/Memory/Session/SessionManager.php:141-149`
- **描述**: 两个类各自实现了相同的 `getCurrentUserId()` 和 `getCurrentUserType()` 方法。应提取到 trait 或工具类。

### L-11: HttpRequestTool 中 curl_exec 无超时非致命错误处理
- **文件**: `src/Tool/builtin/HttpRequestTool.php:113`
- **描述**: `if ($response === false)` 只检查了完全失败情况。部分响应（如超时后收到部分数据）的检测缺失。

### L-12: OpenAiClient::formatTools() 和 ToolRegistry::getToolSchemas() 工具格式双重适配
- **文件**: `src/Client/OpenAiClient.php:80-94`
- **描述**: `formatTools()` 同时支持 `$tool['function']['name']` 和 `$tool['name']` 两种格式。`getToolSchemas()` 始终输出 `['function' => ['name' => ...]]` 格式。双重适配增加了维护负担且可能掩盖 schema 格式错误。

---

## 附录：架构层面观察

### 1. 缺少 AiAgentJob 类
`config/wise-agent.php:84` 引用了 `\wise\agent\job\AiAgentJob::class`，但 `src/job/` 目录不存在。异步 Agent 功能无法使用。

### 2. Security 配置未强制执行
`config/wise-agent.php:90-93` 定义了 `max_tool_executions_per_step` 和 `max_agent_duration`，但在 AgentManager 的 Run Loop 中均未被检查。ToolSecurityCheck listener 中虽有引用但仅读取未执行限制。

### 3. 测试覆盖不足
现有测试仅覆盖 ToolRegistry、Event 和 Exception 三个基础组件。核心的 AgentManager Run Loop、HttpClient 重试逻辑、各 builtin 工具的安全性测试完全缺失。

---

*报告结束。共发现 40 个问题。建议优先修复 5 个 Critical 和 8 个 High 级别问题。*
