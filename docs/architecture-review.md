# Wise-Agent Architecture Review

**Reviewer**: 高见远 (Bob, Software Architect)  
**Date**: 2025-06-17  
**Package**: `wiseadmin/wise-agent`  
**Compared to**: `wiseadmin/wise-login-security`

---

## 1. Overview Assessment

The wise-agent package is **well-structured overall** with a clear separation of concerns across Agent, Client, Contract, Event, Memory, and Tool layers. The event-driven architecture and the Agent Run Loop are thoughtfully designed for extensibility. The package follows the core wise-* subpackage patterns (ServiceProvider, ServiceTrait, mergeConfig, Publish command) consistently.

**Overall Grade**: **B+** — Solid foundation with several fixable gaps and one critical architectural flaw (dead memory layer).

---

## 2. Architecture Alignment vs. wise-login-security

### ✅ Well-Aligned Patterns

| Pattern | wise-agent | wise-login-security | Status |
|---------|-----------|---------------------|--------|
| ServiceProvider extends `\think\Service` | ✅ `wise\agent\Service` | ✅ `wise\loginsecurity\Service` | **Aligned** |
| `ServiceTrait` with `mergeConfig()` | ✅ `src/trait/ServiceTrait.php` | ✅ `src/trait/ServiceTrait.php` | **Aligned** |
| `composer.json` `extra.think.services` | ✅ Auto-discovery | ✅ Auto-discovery | **Aligned** |
| `composer.json` `extra.think.config` | ✅ `wise-agent` → `config/wise-agent.php` | ⚠️ `wise-login_security` → `config/wise-login-security.php` (minor inconsistency) | **Aligned** |
| Publish command pattern | ✅ `wise-agent:publish` | ✅ Multi-command (Publish, Unlock, SecurityTest) | **Aligned** |
| Contract/Interface separation | ✅ `src/Contract/` (4 interfaces) | ✅ `src/contract/` (1 interface: TotpUserInterface) | **Aligned** |
| Event system integration | ✅ 7 events + 2 listeners | ✅ `src/event/` with EventLogger | **Aligned** |
| `helper.php` with facade functions | ✅ 6 helper functions | ✅ Has helper.php | **Aligned** |

### ⚠️ Divergences

| Area | Issue | Severity |
|------|-------|----------|
| Config name in composer.json | wise-login-security uses `wise-login_security` (underscore) but Service calls `mergeConfig('wise-login-security')` — this is a bug in wise-login-security, not wise-agent. wise-agent is correct. | N/A |
| ServiceTrait duplication | Both packages have **identical** `ServiceTrait` code. This violates DRY and creates maintenance burden across all wise-* packages. | **MEDIUM** |
| wise-login-security has more bindings | wise-login-security binds 7+ interfaces with factory closures; wise-agent only binds 4 core classes and 1 interface. The level of DI sophistication is lower in wise-agent. | **LOW** |
| wise-login-security uses `loadRoutesFrom()` | wise-agent doesn't register routes (by design, it's a non-HTTP package). This is correct. | None |

---

## 3. Interface Design (4 Contracts)

### 3.1 AgentInterface

```php
interface AgentInterface
{
    public function getSessionId(): string;
    public function getType(): string;
    public function getSystemPrompt(): string;
    public function setSystemPrompt(string $prompt): self;
    public function getTools(): ToolRegistry;
    public function addTool(callable|string|array $tool, string $name): self;
    public function withTools(array $toolNames): self;
    public function withMemory(bool $enabled): self;
    public function getProviderName(): string;
    public function setProviderName(string $provider): self;
    public function run(string $task, array $context = []): string;
    public function dispatchAsync(string $task, array $options = []): string;
}
```

**Issues**:

| # | Problem | Severity | Recommendation |
|---|---------|----------|----------------|
| 1 | **Too broad** — Mixes agent identification, tool configuration, memory configuration, provider selection, and execution. Violates ISP (Interface Segregation Principle). | **MEDIUM** | Split into `AgentInterface` (identification + execution) and a separate `ConfigurableAgentInterface` for builder methods. |
| 2 | `addTool` accepts `callable|string|array` — too loosely typed. The `$name` parameter becomes meaningless when a `ToolInterface` is passed (the tool already has a name). | **MEDIUM** | Add an overload: `addTool(ToolInterface $tool): self` as a separate method. |
| 3 | Missing `getClient(): AiClientInterface` — The agent has a client internally (BaseAgent::getClient()) but it's not in the interface. Plugins that type-hint `AgentInterface` can't access the client. | **HIGH** | Add `getClient(): AiClientInterface` and `setClient(AiClientInterface $client): self` to the interface. |
| 4 | Missing `getConversation(): Conversation` — Same as above. Plugins need access to the conversation for context injection. | **HIGH** | Add `getConversation(): Conversation` to the interface. |
| 5 | `run()` returns `string` — Too restrictive. Multi-modal agents might return `array` or structured data. | **LOW** | Consider `run(string $task, array $context = []): AgentResult` where `AgentResult` is a value object. |

### 3.2 AiClientInterface

```php
interface AiClientInterface
{
    public function chat(array $messages, array $tools = [], array $options = []): array;
    public function getProviderName(): string;
    public function getModelName(): string;
    public function supports(string $feature): bool;
}
```

**Issues**:

| # | Problem | Severity | Recommendation |
|---|---------|----------|----------------|
| 6 | **No streaming support** — Missing `chatStream()` method. Streaming is essential for real-time UI feedback. | **HIGH** | Add `chatStream(array $messages, array $tools, array $options, callable $onChunk): array`. |
| 7 | Return type `array` is too vague — The return shape `['content' => '', 'tool_calls' => [], 'usage' => []]` is only documented in comments, not enforced. | **LOW** | Consider a `ChatResponse` value object, or at minimum document the shape more prominently. |
| 8 | `supports(string $feature): bool` — Good for extensibility, but the set of features isn't documented as constants anywhere. | **LOW** | Add `Feature` constants class or enum: `Feature::TOOLS`, `Feature::JSON_MODE`, `Feature::STREAMING`. |

### 3.3 ToolInterface

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

**Assessment**: **Well-designed.** Clean separation of concerns. The security hooks (`getPermission`, `requireConfirmation`) are forward-thinking.

| # | Problem | Severity | Recommendation |
|---|---------|----------|----------------|
| 9 | `execute()` returns `string` — All results are forced to strings. Complex tools might want to return structured data that gets post-processed differently. | **LOW** | Consider `execute(array $arguments): string|array`. The AgentManager could handle both types. |
| 10 | `getPermission()` returns empty string for "no restriction" — This is a magic value convention. | **LOW** | Document this clearly, or return `?string` with `null` meaning "no restriction". |

### 3.4 MemoryInterface

```php
interface MemoryInterface
{
    public function store(string $sessionId, string $key, mixed $value, array $tags = []): void;
    public function retrieve(string $sessionId, string $key, mixed $default = null): mixed;
    public function searchByTags(string $sessionId, array $tags): array;
    public function getAll(string $sessionId): array;
    public function forget(string $sessionId, ?string $key = null): void;
}
```

**Assessment**: **Clean and minimal.** The CRUD + tag-search pattern is appropriate.

| # | Problem | Severity | Recommendation |
|---|---------|----------|----------------|
| 11 | `RedisMemory::getAll()` returns `[]` — This is documented but is a silent data loss trap. A developer expecting to iterate all memories will get nothing. | **MEDIUM** | Either throw a `NotSupportedException` or implement it properly using `SCAN`. |
| 12 | `RedisMemory::forget()` with `$key=null` (clear all) doesn't actually clear all keys — It relies on TTL expiration. This is a correctness issue. | **HIGH** | Implement full clear using `SCAN` + `DEL`, or document this as a known limitation with a warning. |

---

## 4. Agent Run Loop Analysis

### 4.1 AgentManager::run() Flow

The run loop in `AgentManager::run()` follows a well-structured pattern:

```
1. Build AgentContext
2. Fire AgentStart event
3. for (step 1..maxSteps):
   a. Build messages from conversation
   b. Get tool schemas
   c. Fire AiRequest event → plugins can skip/modify
   d. Call LLM
   e. Fire AiResponse event → plugins can modify response
   f. Track usage
   g. If tool_calls: execute tools, fire AgentStep, continue
   h. If text response: add message, break (task complete)
4. catch Throwable → fire AgentError, throw AiException
5. finally: log execution, fire AgentComplete (if no error)
```

### 4.2 Issues Found

| # | Problem | Severity | Recommendation |
|---|---------|----------|----------------|
| **13** | **Loop termination at max steps has inconsistent state** — At line 246, when `isMaxStepsReached()` is true, the code sets `$result = 'Maximum agent steps reached...'` and breaks, BUT the tool calls and results for the current step have ALREADY been added to the conversation (lines 224-231). The LLM never gets a chance to synthesize a final answer from those tool results. | **MEDIUM** | Check `isMaxStepsReached()` BEFORE executing tools (move check before line 219). If max steps reached, break without executing tools and add a note to the user. |
| **14** | **Missing `max_agent_duration` enforcement** — The config defines `security.max_agent_duration => 300` (line 93 of config) but this is **never checked** in the run loop. | **HIGH** | Add a duration check in the loop: `if ($agentContext->getDuration() > $maxDuration) { break; }`. |
| **15** | **No rate limiting between steps** — The loop can fire LLM requests as fast as the API responds. There's no `usleep()` or backoff between steps. | **LOW** | Consider adding a configurable `step_delay_ms` option. |
| **16** | **AgentError swallows AgentComplete** — In the `finally` block (line 287), `AgentComplete` is only fired when `!$error`. But if the agent partially completed work before throwing, the results are lost to listeners. Consider firing a separate `AgentComplete` variant or always firing with a status flag. | **LOW** | Add a `status` field to `AgentComplete` for partial completion reporting. |
| **17** | **AgentContext created inside run() but never exposed to plugins** — Plugins listening to `AgentStart` get sessionId, agentType, task, and context array, but NOT the AgentContext object itself. They can't access usage stats or the tool registry. | **MEDIUM** | Pass the AgentContext (or at least a read-only view) through events. |

### 4.3 Separation between Agent and AgentManager

The current design has:
- **Agent** (BaseAgent/SimpleAgent): Holds configuration (tools, provider, memory flag, session).
- **AgentManager**: Holds the run loop logic and event orchestration.

This separation is **clean**. The Agent is a data/config holder, and the AgentManager is the execution engine. However:

| # | Issue | Severity |
|---|-------|----------|
| **18** | `BaseAgent::run()` (line 174) delegates to `AgentManager::run()`, but `AgentManager::chat()` (line 77) instantiates its own SimpleAgent internally. This is inconsistent — `chat()` ignores the registered agent types. | **LOW** |

---

## 5. Event Flow Analysis

### 5.1 Seven Events Coverage

| Event | Hook Point | Modifiable | Skip Support | Good? |
|-------|-----------|------------|--------------|-------|
| `AgentStart` | Before run loop | ❌ Read-only | ❌ | ✅ |
| `AiRequest` | Before each LLM call | ✅ messages, tools, options | ✅ skip + mockResponse | ✅ |
| `AiResponse` | After each LLM call | ✅ response | ❌ | ✅ |
| `ToolExecute` | Before/after each tool | ✅ arguments (before), result (after) | ✅ skip + mockResult | ✅ |
| `AgentStep` | After each step (tool calls only) | ❌ Read-only | ❌ | ⚠️ |
| `AgentComplete` | After successful run | ❌ Read-only | ❌ | ✅ |
| `AgentError` | On exception | ❌ Read-only | ❌ | ✅ |

### 5.2 Issues

| # | Problem | Severity | Recommendation |
|---|---------|----------|----------------|
| **19** | **AgentStep only fires on tool call steps** — If a step produces a text response (agent finishes without tool calls after intermediate steps), no AgentStep event fires. This means listeners tracking step-by-step progress won't see the final text-response step. | **MEDIUM** | Fire `AgentStep` for ALL steps, not just tool-call steps. Or rename the event to `AgentToolStep` and add a separate `AgentReasoningStep`. |
| **20** | **No `AgentBeforeRun` event** — There's no hook between agent creation and the start of the run loop to allow plugins to modify the agent configuration (add tools, change provider, inject memory). | **MEDIUM** | Add an `AgentConfigure` or `AgentBeforeRun` event that fires after AgentContext creation but before the loop starts. |
| **21** | String-based event names (`'AgentStart'`, etc.) could collide with other packages. In ThinkPHP 8, `Event::trigger(new AgentStart(...))` uses the class FQCN. But `Event::listen('AgentStart', ...)` uses a string name. The mapping between them is ThinkPHP-internal. | **LOW** | Document the convention clearly. Consider using FQCN constants. |

---

## 6. Tool System Analysis

### 6.1 ToolRegistry Design

The `ToolRegistry` is a **global singleton** with `instance()` + static `$instance`.

| # | Problem | Severity | Recommendation |
|---|---------|----------|----------------|
| **22** | **Global singleton couples all agents** — If two agents need different tool sets (e.g., a "safe" agent and an "admin" agent), they can't because both use `ToolRegistry::instance()`. The `withTools()` filter only restricts which tools the agent sees, but the underlying registry is shared. | **HIGH** | Either: (a) Make ToolRegistry instance-scoped (not singleton — allow `new ToolRegistry()`), or (b) Add a `ToolRegistry::scoped(array $allowedTools)` that returns a filtered view. |
| **23** | `AiToolRegister` event fires only ONCE (during first `instance()` call). If a plugin registers after the first call, it's silently ignored. | **MEDIUM** | Document this clearly. Or change to fire the event once AND provide a `registerListener()` method for late registration. |
| **24** | `register()` accepts `ToolInterface|callable|array` — The `callable` case (line 48-49) uses `spl_object_hash()` as the key, which is not reproducible and not meaningful. | **LOW** | Require callables to provide a name: `registerCallable(string $name, callable $callable)`. |

### 6.2 Tool Security Model

The security model is **good but incomplete**:

| Layer | Implemented? | Assessment |
|-------|-------------|------------|
| Permission check | `ToolInterface::getPermission()` | ✅ Defined, but NOT enforced by ToolSecurityCheck (line 32-36: permission is retrieved but never validated) |
| Confirmation required | `ToolInterface::requireConfirmation()` | ✅ Defined, but only logged, not enforced with a confirmation flow |
| Builtin tools disabled by default | Config `enabled: false` | ✅ Good security posture |
| Path traversal protection | `FileReadTool::resolvePath()` | ✅ Uses `realpath()` + prefix check |
| SQL injection protection | `DatabaseQueryTool::execute()` | ✅ Whitelist-based (SELECT/SHOW/DESCRIBE/EXPLAIN only) |
| SSRF protection | `HttpRequestTool::execute()` | ✅ Blocks localhost/127.0.0.1 |
| Rate limiting | Not implemented | ❌ Missing |

---

## 7. Memory Architecture Analysis

### 7.1 ❌ CRITICAL: Long-term Memory Layer is Dead Code

This is the most significant architectural flaw in the package.

**Evidence**:
- `MemoryInterface` → Bound in `Service::register()` (line 45)
- `MemoryFactory::create()` → Creates DatabaseMemory or RedisMemory
- `DatabaseMemory` / `RedisMemory` → Both fully implemented
- `BaseAgent::$memoryEnabled` → Set to `true` by default
- `AgentManager::run()` → **NEVER reads `$memoryEnabled`, NEVER calls `MemoryInterface` methods, NEVER injects memories into the conversation**

The entire long-term memory subsystem (3 files: `MemoryFactory`, `DatabaseMemory`, `RedisMemory`, the `ai_memories` table migration, the `memory` config section, the `wise_agent_memory()` and `wise_agent_recall()` helper functions) is **wired at the DI level but never integrated into the run loop**.

### 7.2 Short-term Memory (Conversation)

The `Conversation` class handles message history well:
- In-memory message array
- Database persistence via `persistMessage()`
- Auto-trim when exceeding `maxMessages` (simple slice, no summarization)
- History loading from database on construction

| # | Problem | Severity | Recommendation |
|---|---------|----------|----------------|
| **25** | **`trimIfNeeded()` uses simple array_slice** — It drops oldest messages without summarization, losing context permanently. | **MEDIUM** | Add a `summarize()` method that calls the LLM to compress old messages into a summary before trimming. |
| **26** | **No integration with long-term memory** — Memories stored via `DatabaseMemory::store()` are never injected into the conversation context. | **CRITICAL** | In the run loop, before building each step's messages, retrieve relevant memories by session+tags and prepend them as system messages. |

### 7.3 Factory Pattern

Both `MemoryFactory` and `AiClientFactory` follow the same pattern:
- Static `$instances` cache
- `create()` method with optional parameter
- `clearCache()` method

This is **consistent and well-designed**. Both use the config-driven approach of `wise-login-security`'s `CaptchaDriverFactory`.

---

## 8. Extensibility Analysis

### 8.1 Agent Customization

**Rating: Good** ✅

Plugins can create custom agents by extending `BaseAgent` and overriding `getType()` and `getSystemPrompt()`. The `AgentManager::register()` method allows custom types to be registered.

### 8.2 Tool Registration

**Rating: Good** ✅

Three pathways for tool registration:
1. Via `AiToolRegister` event (triggered on first `ToolRegistry::instance()`)
2. Via direct `ToolRegistry::instance()->register()`
3. Via `wise_agent_tool()` helper function

### 8.3 Provider Addition

**Rating: Good** ✅

`AiClientFactory::register()` allows plugins to add custom providers at runtime.

### 8.4 Event Interception

**Rating: Excellent** ✅

The `skip` + `mockResponse`/`mockResult` pattern on `AiRequest` and `ToolExecute` events enables clean zero-code-change interception.

---

## 9. Configuration Design

The configuration file is **well-structured and complete** with clear section headers.

| # | Issue | Severity |
|---|-------|----------|
| **27** | `queue.job` references `\wise\agent\job\AiAgentJob::class` — This class does NOT exist in the package. Calling `dispatchAsync()` will throw a `ProviderException` because `class_exists()` returns false and no fallback is provided. | **CRITICAL** |
| **28** | `security.max_tool_executions_per_step` (config, line 92) is defined but never enforced in code. The `ToolSecurityCheck` listener acknowledges it (line 23) but doesn't implement the check. | **MEDIUM** |
| **29** | `agent.default_type` defaults to `'simple'` but `SessionManager::create()` (line 32) defaults to `'default'` if no `agent_type` is passed. Inconsistent default values. | **LOW** |

---

## 10. Namespace and Autoloading

### PSR-4 Configuration

```json
"autoload": {
    "psr-4": {
        "wise\\agent\\": "src/"
    }
}
```

| # | Finding | Status |
|---|---------|--------|
| 30 | Namespace `wise\agent\` maps to `src/` — correctly matches directory structure. | ✅ |
| 31 | Directory names use **PascalCase** for subdirectories (Agent/, Client/, Contract/, Event/, Memory/, Tool/, etc.). This is valid PSR-4 but unconventional for PHP; typically lowercase is used. | ⚠️ Style concern |
| 32 | `src/helper.php` is autoloaded via `"files"` — correct for helper functions. | ✅ |
| 33 | `composer.json` `extra.think.config` key is `"wise-agent"` which matches `Service::mergeConfig('wise-agent')` — consistent. | ✅ |
| 34 | The `autoload-dev` namespace is `wise\agent\test\` mapping to `tests/`, but the test files declare `namespace wise\agent\test;` — **mismatch!** The dev namespace is `wise\agent\test\` (trailing backslash) which PSR-4 treats as a prefix, but `wise\agent\test` (no trailing backslash in test files) should still match. Actually, PSR-4 prefixes can have or not have a trailing backslash, the composer spec handles both. **This is fine.** | ✅ |

---

## 11. Dependencies

```json
"require": {
    "php": ">=8.1",
    "topthink/framework": "^6.0 || ^8.0",
    "topthink/think-orm": "^2.0 || ^3.0"
}
```

| # | Finding | Severity |
|---|---------|----------|
| 35 | **Missing `topthink/think-queue`** — The `dispatchAsync()` method (line 363) calls `\think\facade\Queue::connection()`. This requires `topthink/think-queue` or `topthink/think-queue-extend`. Without it, `dispatchAsync()` will throw a class-not-found error at runtime. | **HIGH** |
| 36 | **Missing `ext-curl`** — `HttpClient` and `HttpRequestTool` use `curl_*` functions. This should be in `require`. | **MEDIUM** |
| 37 | **Missing `ext-json`** — Used everywhere. Should be in `require` (though it's included in most PHP distributions). | **LOW** |
| 38 | **`topthink/think-orm` is listed but may not be needed** — The package uses `think\facade\Db` which comes from `topthink/framework`. The separate `think-orm` dependency may be redundant. | **LOW** |
| 39 | **No `suggest` for `ext-redis`** — RedisMemory requires Redis, but it's not even suggested. | **LOW** |
| 40 | **Missing `topthink/think-migration`** — The migrations use `think\migration\Migrator`. This package is needed to run migrations. | **MEDIUM** |

---

## 12. Test Coverage

| Test File | What It Tests | Coverage |
|-----------|---------------|----------|
| `EventTest.php` | Event object construction and properties | ✅ Events only |
| `ExceptionTest.php` | Exception hierarchy and metadata | ✅ Exceptions only |
| `ToolRegistryTest.php` | ToolRegistry register/get/execute/unregister | ✅ ToolRegistry only |

### Missing Tests (Critical Gaps)

| Component | Risk |
|-----------|------|
| `AgentManager::run()` loop | **HIGH** — Core logic, no tests |
| `AgentManager::chat()` | **HIGH** — Public API |
| `BaseAgent` functionality | **MEDIUM** |
| `Conversation` message management | **MEDIUM** |
| `DatabaseMemory` / `RedisMemory` | **MEDIUM** |
| `AiClientFactory` provider creation | **MEDIUM** |
| `OpenAiClient::parseResponse()` | **HIGH** — Response parsing is fragile |
| `HttpClient` retry logic | **MEDIUM** |
| `ToolSecurityCheck` | **MEDIUM** |
| `DatabaseQueryTool` SQL filtering | **HIGH** — Security-critical |

---

## 13. Additional Issues

| # | Problem | Severity | Details |
|---|---------|----------|---------|
| **41** | **Conversation::getAnswer() referenced in README but doesn't exist** | **MEDIUM** | README line 67-70 shows `$conv->getAnswer(...)` but the `Conversation` class has no such method. This is misleading documentation. |
| **42** | **Hardcoded session dependence** — `AgentManager::getCurrentUserId()` and `DatabaseMemory::getCurrentUserId()` both hardcode `session('admin_id') ?? session('user_id') ?? 0`. This couples the package to specific session structures. | **MEDIUM** | Create a `UserContextInterface` with `getUserId(): int` and `getUserType(): string` that the host app can bind. |
| **43** | **No `ConversationInterface`** — The `Conversation` class is used directly everywhere (AgentContext, BaseAgent, AgentManager) without an interface. This prevents swapping conversation implementations (e.g., file-based, Redis-based). | **MEDIUM** | Add `ConversationInterface` and bind it in the Service provider. |
| **44** | **`ToolInterface` not used consistently in `ToolRegistry`** — `register()` accepts `ToolInterface|callable|array`, and `get()` returns the same union type. This makes downstream code fragile (must type-check before calling). | **LOW** | Normalize all tools to `ToolInterface` internally via adapter wrappers. |
| **45** | **`Publish` command duplicates ServiceTrait logic** — `Publish::publishConfig()` (line 59-79) manually copies the config file, duplicating the `publishConfigFile()` logic in ServiceTrait. It should delegate to the trait. | **LOW** |
| **46** | **No graceful degradation when database tables don't exist** — The code has try/catch blocks throughout to handle missing tables, but this silently fails. A first-run user has no indication that migrations need to be run. | **LOW** |

---

## 14. Improvement Recommendations (Prioritized)

### 🔴 Critical (Must Fix)

1. **Integrate long-term memory into the run loop** — Add memory retrieval before each LLM call and memory storage after successful completion. Without this, the entire memory subsystem is dead code.

2. **Create or remove `AiAgentJob`** — Either create the `\wise\agent\job\AiAgentJob` class that `queue.job` references, or remove async support from the package.

3. **Add `topthink/think-queue` to composer.json dependencies** — `dispatchAsync()` uses `\think\facade\Queue` which is not guaranteed to be available.

### 🟡 High (Should Fix)

4. **Add `getClient()` and `getConversation()` to `AgentInterface`** — Plugins need these to interact with the agent.

5. **Add streaming support to `AiClientInterface`** — `chatStream()` is essential for real-time UI.

6. **Fix RedisMemory::forget() and getAll()** — These have correctness issues.

7. **Enforce `max_agent_duration` in the run loop** — Defined in config but never checked.

8. **Make ToolRegistry instance-scoped or support scoping** — The singleton pattern prevents multi-agent scenarios.

9. **Add `ext-curl` to composer.json requirements.**

### 🟢 Medium (Should Consider)

10. **Extract shared ServiceTrait to a common wise-core package** — Eliminates duplication across all wise-* packages.

11. **Create `UserContextInterface`** — Decouple from hardcoded session keys.

12. **Add `ConversationInterface`** — Enable conversation implementation swapping.

13. **Fire `AgentStep` for ALL steps, not just tool-call steps.**

14. **Add `AgentBeforeRun` event** — Allow plugins to configure the agent before execution.

15. **Enforce tool permissions in `ToolSecurityCheck`** — Currently the permission is retrieved but never validated.

16. **Fix `max_agent_duration` enforcement in run loop.**

### 🔵 Low (Nice to Have)

17. **Add `Feature` constants class for `AiClientInterface::supports()`.**

18. **Consider `AgentResult` value object instead of returning a raw string.**

19. **Add `summarize()` method to `Conversation`** for intelligent context compression.

20. **Fix README's non-existent `Conversation::getAnswer()` method reference.**

21. **Add `ext-json` and `ext-redis` suggestions to composer.json.**

---

## 15. Architecture Diagram

```
┌──────────────────────────────────────────────────┐
│                   composer.json                    │
│  extra.think.services → Service (auto-discover)   │
└──────────────────────┬───────────────────────────┘
                       │
              ┌────────▼────────┐
              │    Service.php   │
              │  - mergeConfig   │
              │  - bind DI       │
              │  - event listeners│
              │  - init tools     │
              └───┬───┬───┬─────┘
                  │   │   │
     ┌────────────┘   │   └──────────────┐
     ▼                ▼                  ▼
┌─────────┐   ┌──────────────┐   ┌──────────────┐
│ Agent   │   │   Memory     │   │    Tool      │
│ Layer   │   │   Layer      │   │   Layer      │
├─────────┤   ├──────────────┤   ├──────────────┤
│Manager  │   │MemoryFactory │   │ToolRegistry  │
│Context  │   │DatabaseMemory│   │BaseTool      │
│BaseAgent│   │RedisMemory   │   │4 builtin     │
│SimpleAgt│   │Conversation  │   │tools         │
└────┬────┘   │SessionManager│   └──────┬───────┘
     │        └──────┬───────┘          │
     │               │                  │
     │        ❌ NOT INTEGRATED         │
     │        into run loop!            │
     │                                  │
     ▼                                  ▼
┌──────────────┐              ┌─────────────────┐
│   Client     │              │    Events       │
│   Layer      │              │    Layer        │
├──────────────┤              ├─────────────────┤
│AiClientFact. │              │AgentStart       │
│OpenAiClient  │              │AgentStep        │
│DeepSeekClient│              │AgentComplete    │
│HttpClient    │              │AgentError       │
└──────────────┘              │AiRequest        │
                              │AiResponse       │
                              │ToolExecute      │
                              └─────────────────┘
```

---

## 16. Summary

The wise-agent package is a **well-architected foundation** with clear domain modeling and thoughtful extensibility hooks. The event-driven Agent Run Loop is the strongest design element, enabling plugins to intercept, modify, and extend behavior at every lifecycle point.

The three critical issues that must be addressed before production use are:
1. **Long-term memory is dead code** — never integrated into the agent run loop
2. **Missing `AiAgentJob` class** — async dispatch is broken
3. **Missing `think-queue` dependency** — runtime crash on async calls

With these fixes and the medium-priority improvements around scoping and interface completeness, this package will be a strong foundation for AI capabilities in the WiseAdmin framework.

---

*End of Architecture Review — 46 findings across 45 source files analyzed*
