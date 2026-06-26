<?php
declare(strict_types=1);

namespace wise\agent\agent;

use think\facade\Config;
use think\facade\Db;
use think\facade\Event;
use think\facade\Log;
use wise\agent\contract\AgentInterface;
use wise\agent\contract\ToolInterface;
use wise\agent\contract\UserContextInterface;
use wise\agent\event\AgentComplete;
use wise\agent\event\AgentConfigure;
use wise\agent\event\AgentError;
use wise\agent\event\AgentStart;
use wise\agent\event\AgentStep;
use wise\agent\event\AiRequest;
use wise\agent\event\AiResponse;
use wise\agent\event\ToolExecute;
use wise\agent\exception\AiException;
use wise\agent\memory\MemoryFactory;
use wise\agent\memory\session\SessionUserContext;

/**
 * Agent 管理器
 *
 * 驱动 Agent Run Loop 的核心引擎：
 * 1. 接收任务
 * 2. 使用工具和记忆构建 prompt
 * 3. 调用 LLM
 * 4. 解析响应（文本或工具调用）
 * 5. 若为工具调用 → 执行工具 → 将结果加入上下文 → 循环
 * 6. 若为文本 → Agent 完成 → 返回结果
 *
 * 每个阶段触发的事件允许插件拦截和扩展。
 *
 * 支持同步（run）和流式（runStream）两种执行模式。
 */
class AgentManager
{
    protected array $registeredAgents = [];
    protected UserContextInterface $userContext;

    public function __construct(?UserContextInterface $userContext = null)
    {
        $this->userContext = $userContext ?? new SessionUserContext();
    }

    /**
     * 注册一种 Agent 类型
     *
     * @param string $type       Agent 类型标识
     * @param string $agentClass Agent 类名
     */
    public function register(string $type, string $agentClass): void
    {
        $this->registeredAgents[$type] = $agentClass;
    }

    /**
     * 创建一个 Agent 实例
     *
     * @param string|null $type    Agent 类型（null = 使用配置默认值）
     * @param array       $options Agent 选项
     * @return AgentInterface
     */
    public function create(?string $type = null, array $options = []): AgentInterface
    {
        $type = $type ?: Config::get('wise-agent.agent.default_type', 'simple');

        if (isset($this->registeredAgents[$type])) {
            $class = $this->registeredAgents[$type];
            return new $class(null, array_merge(['agent_type' => $type], $options));
        }

        // 默认：SimpleAgent
        return new SimpleAgent(null, array_merge(['agent_type' => $type], $options));
    }

    /**
     * 快速聊天，不经过 Agent 循环
     *
     * @param string      $message  用户消息
     * @param string|null $provider Provider 名称
     * @param array       $options  额外选项
     * @return string AI 回复
     */
    public function chat(string $message, ?string $provider = null, array $options = []): string
    {
        $agentType = Config::get('wise-agent.agent.default_type', 'simple');

        // 将 provider 传入 options 以便 create() 可以直接使用
        if ($provider) {
            $options['provider'] = $provider;
        }

        $agent = $this->create($agentType, $options);

        // 单轮：无工具，直接对话
        $conversation = $agent->getConversation();
        $conversation->addMessage('user', $message);

        // 触发 AiRequest 事件
        $requestEvent = new AiRequest(
            $agent->getClient()->getProviderName(),
            $agent->getClient()->getModelName(),
            $conversation->getMessages(),
            [],
            ['temperature' => 0.7]
        );
        Event::trigger($requestEvent);

        if ($requestEvent->skip) {
            return $requestEvent->mockResponse ?? '';
        }

        $response = $agent->getClient()->chat(
            $requestEvent->messages,
            [],
            $requestEvent->options
        );

        // 触发 AiResponse 事件
        $responseEvent = new AiResponse(
            $agent->getClient()->getProviderName(),
            $agent->getClient()->getModelName(),
            $response
        );
        Event::trigger($responseEvent);

        $response = $responseEvent->response;
        $content = $response['content'] ?? '';

        // 保存消息
        $conversation->addMessage('assistant', $content);

        return $content;
    }

    /**
     * 运行 Agent（核心 Run Loop）
     *
     * @param AgentInterface $agent   Agent 实例
     * @param string         $task    任务描述
     * @param array          $context 额外上下文
     * @return string Agent 结果
     * @throws AiException
     */
    public function run(AgentInterface $agent, string $task, array $context = []): string
    {
        $startTime = microtime(true);
        $sessionId = $agent->getSessionId();

        // 0. 检索此会话的记忆
        $memoryEnabled = Config::get('wise-agent.agent.enable_memory', true);
        $memory = null;
        if ($memoryEnabled) {
            try {
                $memory = MemoryFactory::create();
                $relevantMemories = $memory->getAll($sessionId);
                // 将相关记忆作为额外上下文注入到系统 prompt 中
                if (!empty($relevantMemories)) {
                    $context['injected_memories'] = $relevantMemories;
                }
            } catch (\Throwable $e) {
                // 记忆检索非关键操作；记录日志并继续
                Log::warning("[WiseAgent] Memory retrieval failed: {$e->getMessage()}");
            }
        }

        // 1. 构建 Agent 上下文
        $conversation = $agent->getConversation();
        $conversation->addMessage('user', $task);

        // 将检索到的记忆作为系统消息注入
        if (!empty($context['injected_memories'])) {
            foreach ($context['injected_memories'] as $memKey => $memValue) {
                $memoryContext = is_array($memValue) ? json_encode($memValue, JSON_UNESCAPED_UNICODE) : (string) $memValue;
                $conversation->addMessage('system', "[Memory: {$memKey}] {$memoryContext}");
            }
        }

        $maxAgentDuration = (int) Config::get('wise-agent.security.max_agent_duration', 300);
        $maxToolExecutionsPerStep = (int) Config::get('wise-agent.security.max_tool_executions_per_step', 5);

        $agentContext = new AgentContext(
            $sessionId,
            $task,
            $agent->getType(),
            $conversation,
            $agent->getClient(),
            $agent->getTools(),
            array_merge([
                'max_steps'           => Config::get('wise-agent.agent.max_steps', 10),
                'max_tokens_per_step' => Config::get('wise-agent.agent.max_tokens_per_step', 4096),
                'temperature'         => Config::get('wise-agent.agent.temperature', 0.7),
                'max_agent_duration'  => $maxAgentDuration,
                'max_tool_executions_per_step' => $maxToolExecutionsPerStep,
            ], $context)
        );

        // 2. 触发 AgentConfigure 事件 — 在上下文创建与循环之间的钩子（M-16）
        $configureEvent = new AgentConfigure(
            $sessionId,
            $agent->getType(),
            $task,
            $agentContext,
            $context
        );
        Event::trigger($configureEvent);

        // 3. 触发 AgentStart 事件
        $startEvent = new AgentStart($sessionId, $agent->getType(), $task, $context);
        Event::trigger($startEvent);

        $result = '';
        $allToolCalls = [];
        $error = null;

        try {
            // 4. 主 Run Loop
            $maxSteps = $agentContext->getMaxSteps();
            for ($step = 1; $step <= $maxSteps; $step++) {
                $agentContext->incrementStep();

                // 在 LLM 调用之前检查是否达到最大步数
                if ($agentContext->isMaxStepsReached()) {
                    $result = 'Maximum agent steps reached. The task may be too complex.';
                    break;
                }

                // 检查是否超过 Agent 最大持续时间
                if ($agentContext->getDuration() >= $maxAgentDuration) {
                    $result = 'Maximum agent duration exceeded. The task took too long.';
                    break;
                }

                // 构建本步骤的消息
                $messages = $conversation->getMessages();

                // 获取工具 schema
                $toolSchemas = $agentContext->getToolRegistry()->getToolSchemas();

                // 触发 AiRequest 事件（插件可修改消息、跳过或模拟响应）
                $requestEvent = new AiRequest(
                    $agent->getClient()->getProviderName(),
                    $agent->getClient()->getModelName(),
                    $messages,
                    $toolSchemas,
                    [
                        'temperature' => $agentContext->getOption('temperature', 0.7),
                        'max_tokens'  => $agentContext->getOption('max_tokens_per_step', 4096),
                    ]
                );
                Event::trigger($requestEvent);

                $response = null;
                if ($requestEvent->skip) {
                    $response = ['content' => $requestEvent->mockResponse ?? '', 'tool_calls' => [], 'usage' => []];
                } else {
                    $response = $agent->getClient()->chat(
                        $requestEvent->messages,
                        $requestEvent->tools,
                        $requestEvent->options
                    );
                }

                // 触发 AiResponse 事件（插件可修改响应）
                $responseEvent = new AiResponse(
                    $agent->getClient()->getProviderName(),
                    $agent->getClient()->getModelName(),
                    $response
                );
                Event::trigger($responseEvent);
                $response = $responseEvent->response;

                // 跟踪 token 用量
                if (!empty($response['usage'])) {
                    $agentContext->addUsage($response['usage']);
                }

                // 检查工具调用
                $toolCalls = $response['tool_calls'] ?? [];

                if (!empty($toolCalls)) {
                    // --- 工具调用分支 ---
                    $toolResults = $this->executeTools(
                        $agentContext->getToolRegistry(),
                        $toolCalls,
                        $maxToolExecutionsPerStep
                    );

                    // 添加包含工具调用的 assistant 消息
                    $conversation->addMessage('assistant', $response['content'] ?? null, $toolCalls);

                    // 添加工具执行结果
                    foreach ($toolCalls as $i => $call) {
                        $toolName = $call['function']['name'] ?? 'unknown';
                        $toolCallId = $call['id'] ?? "call_{$i}";
                        $result = $toolResults[$i] ?? json_encode(['error' => 'Tool execution failed']);
                        $conversation->addMessage('tool', $result, null, $toolCallId, $toolName);
                    }
                } else {
                    // --- 文本响应分支：任务完成 ---
                    $result = $response['content'] ?? '';

                    // 添加 assistant 消息
                    $conversation->addMessage('assistant', $result);
                }

                // 为所有步骤触发 AgentStep 事件（包括工具调用和文本响应）（M-14）
                $stepEvent = new AgentStep(
                    $sessionId,
                    $step,
                    $messages,
                    $response,
                    $toolCalls,
                    $toolResults ?? null
                );
                Event::trigger($stepEvent);

                // 如果没有工具调用，则已完成
                if (empty($toolCalls)) {
                    break;
                }

                // 重置以准备下一次迭代
                $toolResults = null;
            }

        } catch (\Throwable $e) {
            $error = $e;

            // 触发 AgentError 事件
            $errorEvent = new AgentError($sessionId, $e, $agentContext->getStepCount(), [
                'task'    => $task,
                'agent_type' => $agent->getType(),
            ]);
            Event::trigger($errorEvent);

            Log::error("[WiseAgent] Error: {$e->getMessage()}", [
                'session_id' => $sessionId,
                'step'       => $agentContext->getStepCount(),
                'trace'      => $e->getTraceAsString(),
            ]);

            throw new AiException("Agent error: {$e->getMessage()}", 0, $e);
        } finally {
            // 5. 记录 Agent 执行日志
            $this->logExecution($agentContext, $result, $error);

            // 6. 完成后存储关键记忆
            if (!$error && $memoryEnabled && $memory !== null) {
                try {
                    $memory->store($sessionId, 'last_result', $result, ['agent_result']);
                    $memory->store($sessionId, 'last_task', $task, ['agent_result']);
                    $memory->store($sessionId, 'step_count', $agentContext->getStepCount(), ['agent_meta']);
                } catch (\Throwable $e) {
                    Log::warning("[WiseAgent] Memory store failed: {$e->getMessage()}");
                }
            }

            // 7. 触发 AgentComplete 事件（除非抛出了错误）
            if (!$error) {
                $completeEvent = new AgentComplete(
                    $sessionId,
                    $result,
                    $agentContext->getStepCount(),
                    $agentContext->getUsage(),
                    $agentContext->getDuration()
                );
                Event::trigger($completeEvent);
            }
        }

        return $result;
    }

    /**
     * 以流式输出方式运行 Agent
     *
     * 实现完整的 Agent Run Loop，但通过 yield 生成事件，
     * 以便通过 SSE 实时流式传输到前端。支持工具调用并包含
     * 中间状态更新。
     *
     * 生成的流事件类型：
     *   - ['type' => 'token', 'content' => '...']        — LLM 输出的文本 token
     *   - ['type' => 'tool_call', 'calls' => [...]]       — 检测到工具调用
     *   - ['type' => 'tool_result', 'results' => [...]]   — 工具执行结果
     *   - ['type' => 'usage', 'data' => [...]]            — token 用量信息
     *   - ['type' => 'error', 'message' => '...']         — 遇到错误
     *   - ['type' => 'done', 'result' => '...', ...]     — Agent 完成
     *
     * @param BaseAgent $agent   Agent 实例
     * @param string    $message 用户消息
     * @param array     $options 额外选项
     * @return \Generator  生成流式事件数组
     */
    public function runStream(BaseAgent $agent, string $message, array $options = []): \Generator
    {
        $sessionId = $agent->getSessionId();
        $startTime = microtime(true);

        // 0. 检索记忆
        $memoryEnabled = Config::get('wise-agent.agent.enable_memory', true);
        $memory = null;
        if ($memoryEnabled) {
            try {
                $memory = MemoryFactory::create();
                $relevantMemories = $memory->getAll($sessionId);
                if (!empty($relevantMemories)) {
                    $options['injected_memories'] = $relevantMemories;
                }
            } catch (\Throwable $e) {
                Log::warning("[WiseAgent] Memory retrieval failed: {$e->getMessage()}");
            }
        }

        // 1. 构建 Agent 上下文
        $conversation = $agent->getConversation();
        $conversation->addMessage('user', $message);

        // 将检索到的记忆作为系统消息注入
        if (!empty($options['injected_memories'])) {
            foreach ($options['injected_memories'] as $memKey => $memValue) {
                $memoryContext = is_array($memValue) ? json_encode($memValue, JSON_UNESCAPED_UNICODE) : (string) $memValue;
                $conversation->addMessage('system', "[Memory: {$memKey}] {$memoryContext}");
            }
        }

        $maxAgentDuration = (int) Config::get('wise-agent.security.max_agent_duration', 300);
        $maxToolExecutionsPerStep = (int) Config::get('wise-agent.security.max_tool_executions_per_step', 5);

        $agentContext = new AgentContext(
            $sessionId,
            $message,
            $agent->getType(),
            $conversation,
            $agent->getClient(),
            $agent->getTools(),
            array_merge([
                'max_steps'           => Config::get('wise-agent.agent.max_steps', 10),
                'max_tokens_per_step' => Config::get('wise-agent.agent.max_tokens_per_step', 4096),
                'temperature'         => $options['temperature'] ?? Config::get('wise-agent.agent.temperature', 0.7),
                'max_agent_duration'  => $maxAgentDuration,
                'max_tool_executions_per_step' => $maxToolExecutionsPerStep,
            ], $options)
        );

        // 触发 AgentConfigure 事件
        $configureEvent = new AgentConfigure(
            $sessionId,
            $agent->getType(),
            $message,
            $agentContext,
            $options
        );
        Event::trigger($configureEvent);

        // 触发 AgentStart 事件
        $startEvent = new AgentStart($sessionId, $agent->getType(), $message, $options);
        Event::trigger($startEvent);

        $finalResult = '';
        $totalToolCalls = [];
        $error = null;

        try {
            // 2. 主流式 Run Loop
            $maxSteps = $agentContext->getMaxSteps();
            for ($step = 1; $step <= $maxSteps; $step++) {
                $agentContext->incrementStep();

                // 检查限制条件
                if ($agentContext->isMaxStepsReached()) {
                    $finalResult = 'Maximum agent steps reached. The task may be too complex.';
                    yield ['type' => 'error', 'message' => $finalResult];
                    break;
                }

                if ($agentContext->getDuration() >= $maxAgentDuration) {
                    $finalResult = 'Maximum agent duration exceeded. The task took too long.';
                    yield ['type' => 'error', 'message' => $finalResult];
                    break;
                }

                $messages = $conversation->getMessages();
                $toolSchemas = $agentContext->getToolRegistry()->getToolSchemas();

                // 触发 AiRequest 事件
                $requestEvent = new AiRequest(
                    $agent->getClient()->getProviderName(),
                    $agent->getClient()->getModelName(),
                    $messages,
                    $toolSchemas,
                    [
                        'temperature' => $agentContext->getOption('temperature', 0.7),
                        'max_tokens'  => $agentContext->getOption('max_tokens_per_step', 4096),
                    ]
                );
                Event::trigger($requestEvent);

                // --- 流式 LLM 调用 ---
                $stepContent = '';
                $stepToolCalls = [];
                $stepUsage = null;

                if ($requestEvent->skip) {
                    $mockContent = $requestEvent->mockResponse ?? '';
                    yield ['type' => 'token', 'content' => $mockContent];
                    $stepContent = $mockContent;
                } else {
                    $streamGenerator = $agent->getClient()->chatStream(
                        $requestEvent->messages,
                        $requestEvent->tools,
                        $requestEvent->options
                    );

                    foreach ($streamGenerator as $streamEvent) {
                        switch ($streamEvent['type']) {
                            case 'token':
                                $stepContent .= $streamEvent['content'];
                                yield $streamEvent;
                                break;

                            case 'error':
                                yield $streamEvent;
                                // 继续循环 — 流式传输期间的错误会 yield 到前端
                                break 2;

                            case 'finish':
                                $stepToolCalls = $streamEvent['tool_calls'] ?? [];
                                $stepUsage = $streamEvent['usage'] ?? null;
                                break;

                            default:
                                // 透传任何意外事件
                                yield $streamEvent;
                                break;
                        }
                    }
                }

                // 触发 AiResponse 事件（用于日志/插件）
                $responseData = [
                    'content'    => $stepContent,
                    'tool_calls' => $stepToolCalls,
                    'usage'      => $stepUsage ?? [],
                ];
                $responseEvent = new AiResponse(
                    $agent->getClient()->getProviderName(),
                    $agent->getClient()->getModelName(),
                    $responseData
                );
                Event::trigger($responseEvent);

                // 跟踪 token 用量
                if (!empty($stepUsage)) {
                    $agentContext->addUsage($stepUsage);
                    yield ['type' => 'usage', 'data' => $stepUsage];
                }

                if (!empty($stepToolCalls)) {
                    // --- 工具调用分支（流式） ---
                    yield [
                        'type'  => 'tool_call',
                        'calls' => $stepToolCalls,
                    ];

                    $toolResults = $this->executeTools(
                        $agentContext->getToolRegistry(),
                        $stepToolCalls,
                        $maxToolExecutionsPerStep
                    );

                    yield [
                        'type'    => 'tool_result',
                        'results' => $toolResults,
                    ];

                    // 添加包含工具调用的 assistant 消息
                    $conversation->addMessage('assistant', $stepContent ?: null, $stepToolCalls);

                    // 添加工具执行结果
                    foreach ($stepToolCalls as $i => $call) {
                        $toolName = $call['function']['name'] ?? 'unknown';
                        $toolCallId = $call['id'] ?? "call_{$i}";
                        $result = $toolResults[$i] ?? json_encode(['error' => 'Tool execution failed']);
                        $conversation->addMessage('tool', $result, null, $toolCallId, $toolName);
                    }

                    $totalToolCalls = array_merge($totalToolCalls, $stepToolCalls);

                    // 触发 AgentStep 事件
                    $stepEvent = new AgentStep(
                        $sessionId,
                        $step,
                        $messages,
                        $responseData,
                        $stepToolCalls,
                        $toolResults
                    );
                    Event::trigger($stepEvent);

                    // 继续循环进入下一步
                    continue;
                } else {
                    // --- 文本响应分支：完成 ---
                    $finalResult = $stepContent;
                    $conversation->addMessage('assistant', $finalResult);

                    // 触发 AgentStep 事件
                    $stepEvent = new AgentStep(
                        $sessionId,
                        $step,
                        $messages,
                        $responseData,
                        [],
                        null
                    );
                    Event::trigger($stepEvent);

                    break;
                }
            }

        } catch (\Throwable $e) {
            $error = $e;

            $errorEvent = new AgentError($sessionId, $e, $agentContext->getStepCount(), [
                'task'    => $message,
                'agent_type' => $agent->getType(),
            ]);
            Event::trigger($errorEvent);

            Log::error("[WiseAgent] Stream error: {$e->getMessage()}", [
                'session_id' => $sessionId,
                'step'       => $agentContext->getStepCount(),
                'trace'      => $e->getTraceAsString(),
            ]);

            yield ['type' => 'error', 'message' => "Agent error: {$e->getMessage()}"];
        } finally {
            // 记录 Agent 执行日志
            $this->logExecution($agentContext, $finalResult, $error);

            // 存储记忆
            if (!$error && $memoryEnabled && $memory !== null) {
                try {
                    $memory->store($sessionId, 'last_result', $finalResult, ['agent_result']);
                    $memory->store($sessionId, 'last_task', $message, ['agent_result']);
                    $memory->store($sessionId, 'step_count', $agentContext->getStepCount(), ['agent_meta']);
                } catch (\Throwable $e) {
                    Log::warning("[WiseAgent] Memory store failed: {$e->getMessage()}");
                }
            }

            // 触发 AgentComplete 事件
            if (!$error) {
                $completeEvent = new AgentComplete(
                    $sessionId,
                    $finalResult,
                    $agentContext->getStepCount(),
                    $agentContext->getUsage(),
                    $agentContext->getDuration()
                );
                Event::trigger($completeEvent);
            }

            // 生成最终的 done 事件
            yield [
                'type'      => 'done',
                'result'    => $finalResult,
                'steps'     => $agentContext->getStepCount(),
                'usage'     => $agentContext->getUsage(),
                'duration'  => round($agentContext->getDuration(), 3),
                'tool_calls' => $totalToolCalls,
            ];
        }
    }

    /**
     * 按顺序执行工具调用
     *
     * 每个工具调用都会触发 ToolExecute 事件，供插件拦截。
     *
     * @param ToolRegistry $registry    工具注册表
     * @param array        $toolCalls   要执行的工具调用
     * @param int          $maxPerStep  每步最大工具执行次数
     */
    protected function executeTools(ToolRegistry $registry, array $toolCalls, int $maxPerStep = 5): array
    {
        $results = [];
        $count = 0;

        foreach ($toolCalls as $call) {
            // 强制执行每步最大工具执行次数限制
            if ($count >= $maxPerStep) {
                $results[] = json_encode(['error' => "Maximum tool executions per step ({$maxPerStep}) reached"]);
                Log::warning("[WiseAgent] Tool execution limit reached: {$maxPerStep}");
                break;
            }

            $toolName = $call['function']['name'] ?? 'unknown';
            $arguments = json_decode($call['function']['arguments'] ?? '{}', true) ?: [];

            // 触发 BEFORE 事件
            $beforeEvent = new ToolExecute(ToolExecute::PHASE_BEFORE, $toolName, $arguments);
            Event::trigger($beforeEvent);

            if ($beforeEvent->skip) {
                $results[] = $beforeEvent->mockResult ?? json_encode(['skipped' => true]);
                $count++;
                continue;
            }

            // 执行工具
            $result = $registry->execute($toolName, $beforeEvent->arguments);

            // 触发 AFTER 事件
            $afterEvent = new ToolExecute(ToolExecute::PHASE_AFTER, $toolName, $arguments, $result);
            Event::trigger($afterEvent);

            $results[] = $afterEvent->result ?? $result;
            $count++;
        }

        return $results;
    }

    /**
     * 通过队列异步调度 Agent
     *
     * @return string 任务 ID
     */
    public function dispatchAsync(AgentInterface $agent, string $task, array $options = []): string
    {
        $queueConnection = Config::get('wise-agent.queue.connection', 'database');
        $jobClass = Config::get('wise-agent.queue.job', '');

        if (empty($jobClass) || !class_exists($jobClass)) {
            throw new AiException('Async agent queue job class not configured');
        }

        $jobData = [
            'session_id'  => $agent->getSessionId(),
            'agent_type'  => $agent->getType(),
            'provider'    => $agent->getProviderName(),
            'task'        => $task,
            'options'     => $options,
            'tool_names'  => [], // 将从 agent 中解析
        ];

        $jobId = uniqid('ai_agent_', true);

        // 使用 think-queue
        \think\facade\Queue::connection($queueConnection)->push($jobClass, $jobData);

        return $jobId;
    }

    /**
     * 将 Agent 执行记录写入数据库
     */
    protected function logExecution(AgentContext $context, string $result, ?\Throwable $error = null): void
    {
        $table = Config::get('wise-agent.tables.agent_logs', 'ai_agent_logs');

        try {
            $data = [
                'session_id'   => $context->getSessionId(),
                'user_type'    => $this->userContext->getUserType(),
                'user_id'      => $this->userContext->getUserId(),
                'agent_type'   => $context->getAgentType(),
                'task'         => $context->getTask(),
                'result'       => mb_substr($result, 0, 10000),
                'steps'        => $context->getStepCount(),
                'total_tokens' => $context->getTotalTokens(),
                'duration'     => round($context->getDuration(), 3),
                'provider'     => $context->getClient()->getProviderName(),
                'model'        => $context->getClient()->getModelName(),
                'status'       => $error ? 2 : 1,
                'error'        => $error ? $error->getMessage() : null,
                'metadata'     => json_encode($context->getMetadata(), JSON_UNESCAPED_UNICODE),
                'create_time'   => date('Y-m-d H:i:s'),
            ];

            Db::table($table)->insert($data);
        } catch (\Throwable $e) {
            // 非关键操作：日志记录失败不应中断 Agent
            Log::warning("[WiseAgent] Failed to log execution: {$e->getMessage()}");
        }
    }

    /**
     * 获取用户上下文，用于测试 / 依赖注入
     */
    public function getUserContext(): UserContextInterface
    {
        return $this->userContext;
    }
}
