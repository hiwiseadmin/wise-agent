<?php
declare(strict_types=1);

namespace wise\agent\agent;

use think\facade\Config;
use think\facade\Event;
use wise\support\context\ContextualLogger;
use wise\agent\contract\AgentInterface;
use wise\agent\contract\ToolInterface;
use wise\agent\contract\UserContextInterface;
use wise\agent\context\ContextManager;
use wise\agent\cost\CostCalculator;
use wise\agent\cost\QuotaGuard;
use wise\agent\event\AgentComplete;
use wise\agent\event\AgentConfigure;
use wise\agent\event\AgentError;
use wise\agent\event\AgentStart;
use wise\agent\event\AgentStep;
use wise\agent\event\AiRequest;
use wise\agent\event\AiResponse;
use wise\agent\event\ToolExecute;
use wise\agent\exception\AiException;
use wise\agent\exception\QuotaExceededException;
use wise\agent\execution\ExecutionTracker;
use wise\agent\memory\MemoryFactory;
use wise\agent\memory\session\SessionUserContext;
use wise\agent\support\ToolSchemaNormalizer;
use wise\agent\tool\ToolRegistry;

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

        if ($provider) {
            $options['provider'] = $provider;
        }

        $agent = $this->create($agentType, $options);

        $conversation = $agent->getConversation();
        $conversation->addMessage('user', $message);

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

        $responseEvent = new AiResponse(
            $agent->getClient()->getProviderName(),
            $agent->getClient()->getModelName(),
            $response
        );
        Event::trigger($responseEvent);

        $response = $responseEvent->response;
        $content = $response['content'] ?? '';

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
        $sessionId = $agent->getSessionId();

        $this->assertQuotaAllowed($agent, $context);

        [$agentContext, $memory] = $this->prepareAgentRunContext($agent, $task, $context);

        $trackingId = ExecutionTracker::start('agent', [
            'session_id' => $sessionId,
            'task'       => $task,
            'agent_type' => $agent->getType(),
            'provider'   => $agent->getClient()->getProviderName(),
            'model'      => $agent->getClient()->getModelName(),
            'user_type'  => $this->userContext->getUserType(),
            'user_id'    => $this->userContext->getUserId(),
        ], ExecutionTracker::STATUS_RUNNING);

        $result = '';
        $allToolCalls = [];
        $error = null;

        try {
            $maxSteps = $agentContext->getMaxSteps();
            for ($step = 1; $step <= $maxSteps; $step++) {
                $agentContext->incrementStep();

                if ($step % 2 === 0) {
                    ExecutionTracker::heartbeat($trackingId, [
                        'steps'    => $agentContext->getStepCount(),
                        'duration' => round($agentContext->getDuration(), 3),
                    ]);
                }

                if ($agentContext->getStepCount() > $maxSteps) {
                    $result = 'Maximum agent steps reached. The task may be too complex.';
                    break;
                }

                if ($agentContext->getDuration() >= $agentContext->getOption('max_agent_duration', 300)) {
                    $result = 'Maximum agent duration exceeded. The task took too long.';
                    break;
                }

                $messages = $agentContext->getConversation()->getMessages();

                $toolSchemas = $agentContext->getToolRegistry()->getToolSchemas();

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

                $responseEvent = new AiResponse(
                    $agent->getClient()->getProviderName(),
                    $agent->getClient()->getModelName(),
                    $response
                );
                Event::trigger($responseEvent);
                $response = $responseEvent->response;

                if (!empty($response['usage'])) {
                    $agentContext->addUsage($response['usage']);
                }

                $toolCalls = $response['tool_calls'] ?? [];

                if (!empty($toolCalls)) {
                    $toolResults = $this->executeTools(
                        $agentContext->getToolRegistry(),
                        $toolCalls,
                        $agentContext->getOption('max_tool_executions_per_step', 5),
                        $agentContext->getOption('max_tool_execution_time', 30)
                    );

                    $agentContext->getConversation()->addMessage('assistant', $response['content'] ?? null, $toolCalls);

                    foreach ($toolCalls as $i => $call) {
                        $toolName = $call['function']['name'] ?? 'unknown';
                        $toolCallId = $call['id'] ?? "call_{$i}";
                        $toolResult = $toolResults[$i] ?? json_encode(['error' => 'Tool execution failed']);
                        $agentContext->getConversation()->addMessage('tool', $toolResult, null, $toolCallId, $toolName);
                    }
                } else {
                    $result = $response['content'] ?? '';

                    $agentContext->getConversation()->addMessage('assistant', $result);
                }

                $stepEvent = new AgentStep(
                    $sessionId,
                    $step,
                    $messages,
                    $response,
                    $toolCalls,
                    $toolResults ?? null
                );
                Event::trigger($stepEvent);

                if (empty($toolCalls)) {
                    break;
                }

                $toolResults = null;
            }

        } catch (\Throwable $e) {
            $error = $e;

            $errorEvent = new AgentError($sessionId, $e, $agentContext->getStepCount(), [
                'task'    => $task,
                'agent_type' => $agent->getType(),
            ]);
            Event::trigger($errorEvent);

            ContextualLogger::error("[WiseAgent] Error: {$e->getMessage()}", [
                'session_id' => $sessionId,
                'step'       => $agentContext->getStepCount(),
                'trace'      => $e->getTraceAsString(),
            ]);

            throw new AiException("Agent error: {$e->getMessage()}", 0, $e);
        } finally {
            if ($error) {
                ExecutionTracker::finish($trackingId, null, $error->getMessage(), [
                    'steps'        => $agentContext->getStepCount(),
                    'total_tokens' => $agentContext->getTotalTokens(),
                    'duration'     => round($agentContext->getDuration(), 3),
                    'metadata'     => $this->withCostMetadata($agentContext, $agentContext->getMetadata()),
                ]);
            } else {
                ExecutionTracker::finish($trackingId, $result, null, [
                    'steps'        => $agentContext->getStepCount(),
                    'total_tokens' => $agentContext->getTotalTokens(),
                    'duration'     => round($agentContext->getDuration(), 3),
                    'metadata'     => $this->withCostMetadata($agentContext, $agentContext->getMetadata()),
                ]);
            }

            if (!$error && $memory !== null) {
                try {
                    $memory->store($sessionId, 'last_result', $result, ['agent_result']);
                    $memory->store($sessionId, 'last_task', $task, ['agent_result']);
                    $memory->store($sessionId, 'step_count', $agentContext->getStepCount(), ['agent_meta']);
                } catch (\Throwable $e) {
                    ContextualLogger::warning("[WiseAgent] Memory store failed: {$e->getMessage()}");
                }
            }

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
     * 恢复中断的 Agent 执行（不添加新用户消息）
     *
     * 与 run() 的区别：resume() 不调用 prepareAgentRunContext()，
     * 而是直接基于现有 Conversation 状态运行 Agent Loop。
     * 适用场景：任务中断后用户点击"继续"按钮恢复执行。
     *
     * @param AgentInterface $agent   Agent 实例（已有完整 Conversation）
     * @param string         $task    原始任务描述（用于日志记录）
     * @return string Agent 结果
     * @throws AiException
     */
    public function resume(AgentInterface $agent, string $task): string
    {
        $sessionId = $agent->getSessionId();

        $this->assertQuotaAllowed($agent, []);

        $conversation = $agent->getConversation();

        $agentContext = new AgentContext(
            $sessionId,
            $task,
            $agent->getType(),
            $conversation,
            $agent->getClient(),
            $agent->getTools(),
            [
                'max_steps'                    => Config::get('wise-agent.agent.max_steps', 10),
                'max_tokens_per_step'          => Config::get('wise-agent.agent.max_tokens_per_step', 4096),
                'temperature'                  => Config::get('wise-agent.agent.temperature', 0.7),
                'max_agent_duration'           => (int) Config::get('wise-agent.security.max_agent_duration', 300),
                'max_tool_executions_per_step' => (int) Config::get('wise-agent.security.max_tool_executions_per_step', 5),
                'max_tool_execution_time'      => (int) Config::get('wise-agent.security.max_tool_execution_time', 30),
            ]
        );

        $trackingId = ExecutionTracker::start('agent', [
            'session_id' => $sessionId,
            'task'       => "[Resume] {$task}",
            'agent_type' => $agent->getType(),
            'provider'   => $agent->getClient()->getProviderName(),
            'model'      => $agent->getClient()->getModelName(),
            'user_type'  => $this->userContext->getUserType(),
            'user_id'    => $this->userContext->getUserId(),
        ], ExecutionTracker::STATUS_RUNNING);

        $result = '';
        $error = null;

        try {
            $maxSteps = $agentContext->getMaxSteps();
            for ($step = 1; $step <= $maxSteps; $step++) {
                $agentContext->incrementStep();

                if ($step % 2 === 0) {
                    ExecutionTracker::heartbeat($trackingId, [
                        'steps'    => $agentContext->getStepCount(),
                        'duration' => round($agentContext->getDuration(), 3),
                    ]);
                }

                if ($agentContext->getStepCount() > $maxSteps) {
                    $result = 'Maximum agent steps reached. The task may be too complex.';
                    break;
                }

                if ($agentContext->getDuration() >= $agentContext->getOption('max_agent_duration', 300)) {
                    $result = 'Maximum agent duration exceeded.';
                    break;
                }

                $messages = $conversation->getMessages();
                $toolSchemas = $agentContext->getToolRegistry()->getToolSchemas();

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

                $response = $requestEvent->skip
                    ? ['content' => $requestEvent->mockResponse ?? '', 'tool_calls' => [], 'usage' => []]
                    : $agent->getClient()->chat($requestEvent->messages, $requestEvent->tools, $requestEvent->options);

                $responseEvent = new AiResponse(
                    $agent->getClient()->getProviderName(),
                    $agent->getClient()->getModelName(),
                    $response
                );
                Event::trigger($responseEvent);
                $response = $responseEvent->response;

                if (!empty($response['usage'])) {
                    $agentContext->addUsage($response['usage']);
                }

                $toolCalls = $response['tool_calls'] ?? [];

                if (!empty($toolCalls)) {
                    $toolResults = $this->executeTools(
                        $agentContext->getToolRegistry(),
                        $toolCalls,
                        $agentContext->getOption('max_tool_executions_per_step', 5),
                        $agentContext->getOption('max_tool_execution_time', 30)
                    );

                    $conversation->addMessage('assistant', $response['content'] ?? null, $toolCalls);

                    foreach ($toolCalls as $i => $call) {
                        $toolName = $call['function']['name'] ?? 'unknown';
                        $toolCallId = $call['id'] ?? "call_{$i}";
                        $toolResult = $toolResults[$i] ?? json_encode(['error' => 'Tool execution failed']);
                        $conversation->addMessage('tool', $toolResult, null, $toolCallId, $toolName);
                    }
                } else {
                    $result = $response['content'] ?? '';
                    $conversation->addMessage('assistant', $result);
                    break;
                }
            }
        } catch (\Throwable $e) {
            $error = $e;
            ContextualLogger::error("[WiseAgent] Resume error: {$e->getMessage()}", [
                'session_id' => $sessionId,
            ]);
            throw new AiException("Agent resume error: {$e->getMessage()}", 0, $e);
        } finally {
            if ($error) {
                ExecutionTracker::finish($trackingId, null, $error->getMessage(), [
                    'steps'        => $agentContext->getStepCount(),
                    'total_tokens' => $agentContext->getTotalTokens(),
                    'duration'     => round($agentContext->getDuration(), 3),
                ]);
            } else {
                ExecutionTracker::finish($trackingId, $result, null, [
                    'steps'        => $agentContext->getStepCount(),
                    'total_tokens' => $agentContext->getTotalTokens(),
                    'duration'     => round($agentContext->getDuration(), 3),
                ]);
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

        $this->assertQuotaAllowed($agent, $options);

        [$agentContext, $memory] = $this->prepareAgentRunContext($agent, $message, $options);

        $trackingId = ExecutionTracker::start('agent', [
            'session_id' => $sessionId,
            'task'       => $message,
            'agent_type' => $agent->getType(),
            'provider'   => $agent->getClient()->getProviderName(),
            'model'      => $agent->getClient()->getModelName(),
            'user_type'  => $this->userContext->getUserType(),
            'user_id'    => $this->userContext->getUserId(),
        ], ExecutionTracker::STATUS_RUNNING);

        $finalResult = '';
        $totalToolCalls = [];
        $error = null;

        try {
            $maxSteps = $agentContext->getMaxSteps();
            for ($step = 1; $step <= $maxSteps; $step++) {
                $agentContext->incrementStep();

                if ($step % 2 === 0) {
                    ExecutionTracker::heartbeat($trackingId, [
                        'steps'        => $agentContext->getStepCount(),
                        'total_tokens' => $agentContext->getTotalTokens(),
                        'duration'     => round($agentContext->getDuration(), 3),
                    ]);
                    yield ['type' => 'heartbeat', 'step' => $agentContext->getStepCount()];
                }

                $stopKey = 'ai_stop_' . $agentContext->getSessionId();
                if (\think\facade\Cache::get($stopKey)) {
                    \think\facade\Cache::delete($stopKey);
                    $finalResult = '已停止生成';
                    yield ['type' => 'stopped', 'message' => $finalResult];
                    break;
                }

                if ($agentContext->getStepCount() > $maxSteps) {
                    $finalResult = 'Maximum agent steps reached. The task may be too complex.';
                    yield ['type' => 'error', 'message' => $finalResult];
                    break;
                }

                if ($agentContext->getDuration() >= $agentContext->getOption('max_agent_duration', 300)) {
                    $finalResult = 'Maximum agent duration exceeded. The task took too long.';
                    yield ['type' => 'error', 'message' => $finalResult];
                    break;
                }

                $messages = $agentContext->getConversation()->getMessages();
                $toolSchemas = $agentContext->getToolRegistry()->getToolSchemas();

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
                                $error = new \wise\agent\exception\AiException(
                                    $streamEvent['message'] ?? 'Stream error occurred'
                                );
                                $finalResult = $stepContent;
                                break 2;

                            case 'finish':
                                $stepToolCalls = $streamEvent['tool_calls'] ?? [];
                                $stepUsage = $streamEvent['usage'] ?? null;
                                break;

                            default:
                                yield $streamEvent;
                                break;
                        }
                    }
                }

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

                if (!empty($stepUsage)) {
                    $agentContext->addUsage($stepUsage);
                    yield ['type' => 'usage', 'data' => $stepUsage];
                }

                if (!empty($stepToolCalls)) {
                    yield [
                        'type'  => 'tool_call',
                        'calls' => $stepToolCalls,
                    ];

                    $toolResults = $this->executeTools(
                        $agentContext->getToolRegistry(),
                        $stepToolCalls,
                        $agentContext->getOption('max_tool_executions_per_step', 5),
                        $agentContext->getOption('max_tool_execution_time', 30)
                    );

                    yield [
                        'type'    => 'tool_result',
                        'results' => $toolResults,
                    ];

                    $agentContext->getConversation()->addMessage('assistant', $stepContent ?: null, $stepToolCalls);

                    foreach ($stepToolCalls as $i => $call) {
                        $toolName = $call['function']['name'] ?? 'unknown';
                        $toolCallId = $call['id'] ?? "call_{$i}";
                        $toolResult = $toolResults[$i] ?? json_encode(['error' => 'Tool execution failed']);
                        $agentContext->getConversation()->addMessage('tool', $toolResult, null, $toolCallId, $toolName);
                    }

                    $totalToolCalls = array_merge($totalToolCalls, $stepToolCalls);

                    $stepEvent = new AgentStep(
                        $sessionId,
                        $step,
                        $messages,
                        $responseData,
                        $stepToolCalls,
                        $toolResults
                    );
                    Event::trigger($stepEvent);

                    continue;
                } else {
                    $finalResult = $stepContent;
                    $agentContext->getConversation()->addMessage('assistant', $finalResult);

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

            ContextualLogger::error("[WiseAgent] Stream error: {$e->getMessage()}", [
                'session_id' => $sessionId,
                'step'       => $agentContext->getStepCount(),
                'trace'      => $e->getTraceAsString(),
            ]);

            yield ['type' => 'error', 'message' => "Agent error: {$e->getMessage()}"];
        } finally {
            if ($error) {
                ExecutionTracker::finish($trackingId, $finalResult, $error->getMessage(), [
                    'steps'        => $agentContext->getStepCount(),
                    'total_tokens' => $agentContext->getTotalTokens(),
                    'duration'     => round($agentContext->getDuration(), 3),
                    'metadata'     => $this->withCostMetadata($agentContext, $agentContext->getMetadata()),
                ]);
            } else {
                ExecutionTracker::finish($trackingId, $finalResult, null, [
                    'steps'        => $agentContext->getStepCount(),
                    'total_tokens' => $agentContext->getTotalTokens(),
                    'duration'     => round($agentContext->getDuration(), 3),
                    'metadata'     => $this->withCostMetadata($agentContext, $agentContext->getMetadata()),
                ]);
            }

            if (!$error && $memory !== null) {
                try {
                    $memory->store($sessionId, 'last_result', $finalResult, ['agent_result']);
                    $memory->store($sessionId, 'last_task', $message, ['agent_result']);
                    $memory->store($sessionId, 'step_count', $agentContext->getStepCount(), ['agent_meta']);
                } catch (\Throwable $e) {
                    ContextualLogger::warning("[WiseAgent] Memory store failed: {$e->getMessage()}");
                }
            }

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

            yield [
                'type'       => 'done',
                'result'     => $finalResult,
                'error'      => $error ? $error->getMessage() : null,
                'success'    => $error === null,
                'steps'      => $agentContext->getStepCount(),
                'usage'      => $agentContext->getUsage(),
                'duration'   => round($agentContext->getDuration(), 3),
                'tool_calls' => $totalToolCalls,
            ];
        }
    }

    /**
     * 构建「本次将会发送给模型」的完整请求，但不调用模型（CLI --dry-run）
     *
     * 与 {@see self::run()} 共用 {@see self::prepareAgentRunContext()}：记忆检索、
     * AgentContext 构建、AgentConfigure / AgentStart 事件、上下文注入（ContextManager）
     * 全部走真实链路，因此打印出来的 messages / tools 与真正发送的**完全一致**。
     *
     * 副作用边界：
     *   - 会触发 AgentStart / AgentConfigure 事件（日志类监听器会记日志）；
     *   - **不会**发起 HTTP 请求，**不会**写执行轨迹（ExecutionTracker 只在 run() 里）。
     *
     * @param AgentInterface $agent   Agent 实例
     * @param string         $task    用户消息
     * @param array          $context 额外上下文（同 run()）
     * @return array{provider:string, model:string, messages:array, tools:array, options:array}
     */
    public function preview(AgentInterface $agent, string $task, array $context = []): array
    {
        [$agentContext] = $this->prepareAgentRunContext($agent, $task, $context);

        return [
            'provider' => $agent->getClient()->getProviderName(),
            'model'    => $agent->getClient()->getModelName(),
            'messages' => $agentContext->getConversation()->getMessages(),
            'tools'    => ToolSchemaNormalizer::tools($agentContext->getToolRegistry()->getToolSchemas()),
            'options'  => [
                'session_id'  => $agentContext->getSessionId(),
                'temperature' => $agentContext->getOption('temperature', 0.7),
                'max_tokens'  => $agentContext->getOption('max_tokens_per_step', 4096),
                'max_steps'   => $agentContext->getMaxSteps(),
            ],
        ];
    }

    /**
     * 准备 Agent 运行上下文（run/runStream 公共逻辑）
     *
     * 执行步骤：
     *   0. 检索会话记忆并注入到 extraOptions
     *   1. 构建 Conversation（添加用户消息 + 记忆系统消息）
     *   2. 创建 AgentContext
     *   3. 触发 AgentConfigure + AgentStart 事件
     *   4. 注入上下文（Context Injection）
     *
     * @param AgentInterface $agent       Agent 实例
     * @param string         $task        任务/消息文本
     * @param array          $extraOptions 额外选项（传入引用，记忆信息会被写入）
     * @return array{0: AgentContext, 1: \wise\agent\contract\MemoryInterface|null}
     */
    protected function prepareAgentRunContext(AgentInterface $agent, string $task, array &$extraOptions): array
    {
        $sessionId = $agent->getSessionId();

        $memoryEnabled = Config::get('wise-agent.agent.enable_memory', true);
        $memory = null;
        if ($memoryEnabled) {
            try {
                $memory = MemoryFactory::create();
                $relevantMemories = $memory->getAll($sessionId);
                if (!empty($relevantMemories)) {
                    $extraOptions['injected_memories'] = $relevantMemories;
                }
            } catch (\Throwable $e) {
                ContextualLogger::warning("[WiseAgent] Memory retrieval failed: {$e->getMessage()}");
            }
        }

        $conversation = $agent->getConversation();
        $conversation->addMessage('user', $task);

        if (!empty($extraOptions['injected_memories'])) {
            foreach ($extraOptions['injected_memories'] as $memKey => $memValue) {
                $memoryContext = is_array($memValue) ? json_encode($memValue, JSON_UNESCAPED_UNICODE) : (string) $memValue;
                $conversation->addMessage('system', "[Memory: {$memKey}] {$memoryContext}", null, null, null, false);
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
                'temperature'         => $extraOptions['temperature'] ?? Config::get('wise-agent.agent.temperature', 0.7),
                'max_agent_duration'  => $maxAgentDuration,
                'max_tool_executions_per_step' => $maxToolExecutionsPerStep,
                'max_tool_execution_time' => (int) Config::get('wise-agent.security.max_tool_execution_time', 30),
            ], $extraOptions)
        );

        $configureEvent = new AgentConfigure(
            $sessionId,
            $agent->getType(),
            $task,
            $agentContext,
            $extraOptions
        );
        Event::trigger($configureEvent);

        $this->injectContext($agentContext, $conversation);

        $startEvent = new AgentStart($sessionId, $agent->getType(), $task, $extraOptions);
        Event::trigger($startEvent);

        return [$agentContext, $memory];
    }

    /**
     * 按顺序执行工具调用
     *
     * 每个工具调用都会触发 ToolExecute 事件，供插件拦截。
     * 支持单工具超时保护，防止因外部服务卡死阻塞整个 Agent Loop。
     *
     * @param ToolRegistry $registry      工具注册表
     * @param array        $toolCalls     要执行的工具调用
     * @param int          $maxPerStep    每步最大工具执行次数
     * @param int          $toolTimeoutSec 单工具超时秒数（0 = 不限制）
     */
    protected function executeTools(ToolRegistry $registry, array $toolCalls, int $maxPerStep = 5, int $toolTimeoutSec = 30): array
    {
        $results = [];
        $count = 0;

        foreach ($toolCalls as $call) {
            if ($count >= $maxPerStep) {
                $results[] = json_encode(['error' => "Maximum tool executions per step ({$maxPerStep}) reached"]);
                ContextualLogger::warning("[WiseAgent] Tool execution limit reached: {$maxPerStep}");
                break;
            }

            $toolName = $call['function']['name'] ?? 'unknown';
            $rawArgs = $call['function']['arguments'] ?? '{}';
            $arguments = json_decode($rawArgs, true);
            if (!is_array($arguments)) {
                ContextualLogger::warning("[WiseAgent] Failed to parse tool arguments for '{$toolName}': " . json_last_error_msg());
                $arguments = [];
            }

            $beforeEvent = new ToolExecute(ToolExecute::PHASE_BEFORE, $toolName, $arguments);
            Event::trigger($beforeEvent);

            if ($beforeEvent->skip) {
                $results[] = $beforeEvent->mockResult ?? json_encode(['skipped' => true]);
                $count++;
                continue;
            }

            $toolStart = microtime(true);
            try {
                if ($toolTimeoutSec > 0) {
                    $originalTimeout = (int) ini_get('max_execution_time');
                    set_time_limit($toolTimeoutSec);

                    try {
                        $result = $registry->execute($toolName, $beforeEvent->arguments);
                    } finally {
                        set_time_limit(max($originalTimeout, 120));
                    }
                } else {
                    $result = $registry->execute($toolName, $beforeEvent->arguments);
                }
            } catch (\Throwable $e) {
                $elapsed = round(microtime(true) - $toolStart, 3);
                ContextualLogger::warning("[WiseAgent] Tool '{$toolName}' failed after {$elapsed}s: {$e->getMessage()}");
                $result = json_encode([
                    'error' => "Tool '{$toolName}' execution failed: {$e->getMessage()}",
                    'elapsed' => $elapsed,
                ]);
            }

            $elapsed = round(microtime(true) - $toolStart, 3);
            if ($toolTimeoutSec > 0 && $elapsed > $toolTimeoutSec) {
                ContextualLogger::warning("[WiseAgent] Tool '{$toolName}' exceeded timeout ({$toolTimeoutSec}s), elapsed: {$elapsed}s");
            }

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

        $sessionId = $agent->getSessionId();

        $trackingId = ExecutionTracker::start('agent', [
            'session_id' => $sessionId,
            'task'       => $task,
            'agent_type' => $agent->getType(),
            'provider'   => $agent->getProviderName(),
            'user_type'  => $this->userContext->getUserType(),
            'user_id'    => $this->userContext->getUserId(),
        ], ExecutionTracker::STATUS_QUEUED);

        $jobData = [
            'session_id'  => $sessionId,
            'agent_type'  => $agent->getType(),
            'provider'    => $agent->getProviderName(),
            'task'        => $task,
            'options'     => $options,
            'tool_names'  => [],
            'tracking_id' => $trackingId,
            'user_id'     => $this->userContext->getUserId(),
            'user_type'   => $this->userContext->getUserType(),
        ];

        \think\facade\Queue::connection($queueConnection)->push($jobClass, $jobData);

        ContextualLogger::info("[WiseAgent] Dispatched async job [{$trackingId}] for session {$sessionId}");

        return $trackingId;
    }

    /**
     * 把本次执行的**成本估算**写进 metadata＼�
     *
     * 只在 `wise-agent.billing.enabled=true` 时计算；估算失败/配置关闭时静默跳过
     * （成本是"可观测"能力，绝不能因估算异常而中断执行轨迹落库）。
     *
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    protected function withCostMetadata(AgentContext $context, array $metadata): array
    {
        try {
            if (!(bool) config('wise-agent.billing.enabled', false)) {
                return $metadata;
            }

            $usage = $context->getUsage();
            $metadata['usage'] = [
                'prompt_tokens'     => (int) ($usage['prompt_tokens'] ?? 0),
                'completion_tokens' => (int) ($usage['completion_tokens'] ?? 0),
                'total_tokens'      => (int) ($usage['total_tokens'] ?? 0),
            ];

            $calculator = new CostCalculator((array) config('wise-agent.billing.pricing', []));
            $metadata['cost'] = $calculator->estimate(
                (string) $context->getClient()->getProviderName(),
                (string) $context->getClient()->getModelName(),
                $usage
            );
        } catch (\Throwable $e) {
            ContextualLogger::warning("[WiseAgent] Cost estimation failed: {$e->getMessage()}");
        }

        return $metadata;
    }

    /**
     * 配额守门＼�：在「开始一次 Agent 执行」这一层判定，超限抛
     * {@see QuotaExceededException}（携带 quota，由异常渲染链输出同核契约）。
     *
     * 配额检查本身异常（如配置读取失败）时记录日志后放行——只有「拿不到额度」
     * （QuotaGuard 的 hit 失败、fail-closed）才拒绝。
     */
    protected function assertQuotaAllowed(AgentInterface $agent, array $context): void
    {
        try {
            $guard = new QuotaGuard((array) config('wise-agent.billing.quota', []));
            $result = $guard->check([
                'user_type' => $this->userContext->getUserType(),
                'user_id'   => $this->userContext->getUserId(),
                'provider'  => $agent->getClient()->getProviderName(),
                'model'     => $agent->getClient()->getModelName(),
                'weight'    => max(1, (int) ($context['max_tokens'] ?? 4096)),
            ]);

            if (!$result['allowed']) {
                $quota = $result['quota'] ?? [];
                throw new QuotaExceededException(
                    sprintf(
                        'AI 用量已达本日配额（%s，已用 %d/%d）',
                        (string) ($quota['key'] ?? 'unknown'),
                        (int) ($quota['used'] ?? 0),
                        (int) ($quota['limit'] ?? 0)
                    ),
                    $quota
                );
            }
        } catch (QuotaExceededException $e) {
            throw $e;
        } catch (\Throwable $e) {
            ContextualLogger::warning("[WiseAgent] Quota check failed: {$e->getMessage()}");
        }
    }

    /**
     * 获取用户上下文，用于测试 / 依赖注入
     */
    public function getUserContext(): UserContextInterface
    {
        return $this->userContext;
    }

    /**
     * 注入上下文消息到 conversation
     *
     * 从 ContextManager 收集所有已注册 ContextProvider 的上下文
     * 并注入到 conversation 中，放在 system prompt 之后、用户消息之前。
     */
    protected function injectContext(AgentContext $agentContext, $conversation): void
    {
        try {
            $contextMessages = ContextManager::instance()->buildContext($agentContext);
            foreach ($contextMessages as $msg) {
                if (!empty($msg['content'])) {
                    $conversation->addMessage($msg['role'] ?? 'system', $msg['content'], null, null, null, false);
                }
            }
        } catch (\Throwable $e) {
            ContextualLogger::warning("[WiseAgent] Context injection failed: {$e->getMessage()}");
        }
    }
}
