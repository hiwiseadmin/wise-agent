<?php
declare(strict_types=1);

namespace wise\agent\Agent;

use think\facade\Config;
use think\facade\Db;
use think\facade\Event;
use think\facade\Log;
use wise\agent\Contract\AgentInterface;
use wise\agent\Contract\ToolInterface;
use wise\agent\Contract\UserContextInterface;
use wise\agent\Event\AgentComplete;
use wise\agent\Event\AgentConfigure;
use wise\agent\Event\AgentError;
use wise\agent\Event\AgentStart;
use wise\agent\Event\AgentStep;
use wise\agent\Event\AiRequest;
use wise\agent\Event\AiResponse;
use wise\agent\Event\ToolExecute;
use wise\agent\Exception\AiException;
use wise\agent\Memory\MemoryFactory;
use wise\agent\Memory\Session\SessionUserContext;

/**
 * Agent manager
 *
 * Core engine that drives the Agent Run Loop:
 * 1. Receive task
 * 2. Build prompt with tools + memory
 * 3. Call LLM
 * 4. Parse response (text or tool call)
 * 5. If tool call → execute tool → add result to context → loop
 * 6. If text → agent complete → return result
 *
 * Events fired at each stage enable plugins to intercept and extend.
 *
 * Supports both synchronous (run) and streaming (runStream) execution modes.
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
     * Register an agent type
     *
     * @param string $type       Agent type identifier
     * @param string $agentClass Agent class name
     */
    public function register(string $type, string $agentClass): void
    {
        $this->registeredAgents[$type] = $agentClass;
    }

    /**
     * Create an agent instance
     *
     * @param string|null $type    Agent type (null = use config default)
     * @param array       $options Agent options
     * @return AgentInterface
     */
    public function create(?string $type = null, array $options = []): AgentInterface
    {
        $type = $type ?: Config::get('wise-agent.agent.default_type', 'simple');

        if (isset($this->registeredAgents[$type])) {
            $class = $this->registeredAgents[$type];
            return new $class(null, array_merge(['agent_type' => $type], $options));
        }

        // Default: SimpleAgent
        return new SimpleAgent(null, array_merge(['agent_type' => $type], $options));
    }

    /**
     * Quick chat without agent loop
     *
     * @param string      $message  User message
     * @param string|null $provider Provider name
     * @param array       $options  Extra options
     * @return string AI response
     */
    public function chat(string $message, ?string $provider = null, array $options = []): string
    {
        $agentType = Config::get('wise-agent.agent.default_type', 'simple');

        // Pass provider in options so create() can use it directly
        if ($provider) {
            $options['provider'] = $provider;
        }

        $agent = $this->create($agentType, $options);

        // Single-turn: no tools, just direct chat
        $conversation = $agent->getConversation();
        $conversation->addMessage('user', $message);

        // Fire AiRequest event
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

        // Fire AiResponse event
        $responseEvent = new AiResponse(
            $agent->getClient()->getProviderName(),
            $agent->getClient()->getModelName(),
            $response
        );
        Event::trigger($responseEvent);

        $response = $responseEvent->response;
        $content = $response['content'] ?? '';

        // Save message
        $conversation->addMessage('assistant', $content);

        return $content;
    }

    /**
     * Run agent (core Run Loop)
     *
     * @param AgentInterface $agent   Agent instance
     * @param string         $task    Task description
     * @param array          $context Additional context
     * @return string Agent result
     * @throws AiException
     */
    public function run(AgentInterface $agent, string $task, array $context = []): string
    {
        $startTime = microtime(true);
        $sessionId = $agent->getSessionId();

        // 0. Retrieve memory for this session
        $memoryEnabled = Config::get('wise-agent.agent.enable_memory', true);
        $memory = null;
        if ($memoryEnabled) {
            try {
                $memory = MemoryFactory::create();
                $relevantMemories = $memory->getAll($sessionId);
                // Inject relevant memories as additional context in the system prompt
                if (!empty($relevantMemories)) {
                    $context['injected_memories'] = $relevantMemories;
                }
            } catch (\Throwable $e) {
                // Memory retrieval is non-critical; log and continue
                Log::warning("[WiseAgent] Memory retrieval failed: {$e->getMessage()}");
            }
        }

        // 1. Build agent context
        $conversation = $agent->getConversation();
        $conversation->addMessage('user', $task);

        // Inject retrieved memories as system messages
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

        // 2. Fire AgentConfigure event — hook between context creation and loop (M-16)
        $configureEvent = new AgentConfigure(
            $sessionId,
            $agent->getType(),
            $task,
            $agentContext,
            $context
        );
        Event::trigger($configureEvent);

        // 3. Fire AgentStart event
        $startEvent = new AgentStart($sessionId, $agent->getType(), $task, $context);
        Event::trigger($startEvent);

        $result = '';
        $allToolCalls = [];
        $error = null;

        try {
            // 4. Main Run Loop
            $maxSteps = $agentContext->getMaxSteps();
            for ($step = 1; $step <= $maxSteps; $step++) {
                $agentContext->incrementStep();

                // Check max steps BEFORE LLM call
                if ($agentContext->isMaxStepsReached()) {
                    $result = 'Maximum agent steps reached. The task may be too complex.';
                    break;
                }

                // Check max agent duration
                if ($agentContext->getDuration() >= $maxAgentDuration) {
                    $result = 'Maximum agent duration exceeded. The task took too long.';
                    break;
                }

                // Build messages for this step
                $messages = $conversation->getMessages();

                // Get tool schemas
                $toolSchemas = $agentContext->getToolRegistry()->getToolSchemas();

                // Fire AiRequest event (plugins can modify messages, skip, or mock)
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

                // Fire AiResponse event (plugins can modify response)
                $responseEvent = new AiResponse(
                    $agent->getClient()->getProviderName(),
                    $agent->getClient()->getModelName(),
                    $response
                );
                Event::trigger($responseEvent);
                $response = $responseEvent->response;

                // Track usage
                if (!empty($response['usage'])) {
                    $agentContext->addUsage($response['usage']);
                }

                // Check for tool calls
                $toolCalls = $response['tool_calls'] ?? [];

                if (!empty($toolCalls)) {
                    // --- Tool call branch ---
                    $toolResults = $this->executeTools(
                        $agentContext->getToolRegistry(),
                        $toolCalls,
                        $maxToolExecutionsPerStep
                    );

                    // Add assistant message with tool calls
                    $conversation->addMessage('assistant', $response['content'] ?? null, $toolCalls);

                    // Add tool results
                    foreach ($toolCalls as $i => $call) {
                        $toolName = $call['function']['name'] ?? 'unknown';
                        $toolCallId = $call['id'] ?? "call_{$i}";
                        $result = $toolResults[$i] ?? json_encode(['error' => 'Tool execution failed']);
                        $conversation->addMessage('tool', $result, null, $toolCallId, $toolName);
                    }
                } else {
                    // --- Text response branch: task complete ---
                    $result = $response['content'] ?? '';

                    // Add assistant message
                    $conversation->addMessage('assistant', $result);
                }

                // Fire AgentStep event for ALL steps (both tool-call and text-response) (M-14)
                $stepEvent = new AgentStep(
                    $sessionId,
                    $step,
                    $messages,
                    $response,
                    $toolCalls,
                    $toolResults ?? null
                );
                Event::trigger($stepEvent);

                // If no tool calls, we're done
                if (empty($toolCalls)) {
                    break;
                }

                // Reset for next iteration
                $toolResults = null;
            }

        } catch (\Throwable $e) {
            $error = $e;

            // Fire AgentError event
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
            // 5. Log agent execution
            $this->logExecution($agentContext, $result, $error);

            // 6. Store key memory after completion
            if (!$error && $memoryEnabled && $memory !== null) {
                try {
                    $memory->store($sessionId, 'last_result', $result, ['agent_result']);
                    $memory->store($sessionId, 'last_task', $task, ['agent_result']);
                    $memory->store($sessionId, 'step_count', $agentContext->getStepCount(), ['agent_meta']);
                } catch (\Throwable $e) {
                    Log::warning("[WiseAgent] Memory store failed: {$e->getMessage()}");
                }
            }

            // 7. Fire AgentComplete event (unless error was thrown)
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
     * Run agent with streaming output
     *
     * Implements the full agent Run Loop but yields events for real-time
     * streaming to the frontend via SSE. Supports tool calling with
     * intermediate status updates.
     *
     * Event types yielded:
     *   - ['type' => 'token', 'content' => '...']        — text token from LLM
     *   - ['type' => 'tool_call', 'calls' => [...]]       — tool calls detected
     *   - ['type' => 'tool_result', 'results' => [...]]   — tool execution results
     *   - ['type' => 'usage', 'data' => [...]]            — token usage info
     *   - ['type' => 'error', 'message' => '...']         — error encountered
     *   - ['type' => 'done', 'result' => '...', ...]     — agent complete
     *
     * @param BaseAgent $agent   Agent instance
     * @param string    $message User message
     * @param array     $options Extra options
     * @return \Generator  Yields streaming event arrays
     */
    public function runStream(BaseAgent $agent, string $message, array $options = []): \Generator
    {
        $sessionId = $agent->getSessionId();
        $startTime = microtime(true);

        // 0. Retrieve memory
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

        // 1. Build agent context
        $conversation = $agent->getConversation();
        $conversation->addMessage('user', $message);

        // Inject retrieved memories as system messages
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

        // Fire AgentConfigure event
        $configureEvent = new AgentConfigure(
            $sessionId,
            $agent->getType(),
            $message,
            $agentContext,
            $options
        );
        Event::trigger($configureEvent);

        // Fire AgentStart event
        $startEvent = new AgentStart($sessionId, $agent->getType(), $message, $options);
        Event::trigger($startEvent);

        $finalResult = '';
        $totalToolCalls = [];
        $error = null;

        try {
            // 2. Main streaming Run Loop
            $maxSteps = $agentContext->getMaxSteps();
            for ($step = 1; $step <= $maxSteps; $step++) {
                $agentContext->incrementStep();

                // Check limits
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

                // Fire AiRequest event
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

                // --- Streaming LLM call ---
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
                                // Continue the loop — errors during streaming are yielded to frontend
                                break 2;

                            case 'finish':
                                $stepToolCalls = $streamEvent['tool_calls'] ?? [];
                                $stepUsage = $streamEvent['usage'] ?? null;
                                break;

                            default:
                                // Pass through any unexpected events
                                yield $streamEvent;
                                break;
                        }
                    }
                }

                // Fire AiResponse event (for logging/plugins)
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

                // Track usage
                if (!empty($stepUsage)) {
                    $agentContext->addUsage($stepUsage);
                    yield ['type' => 'usage', 'data' => $stepUsage];
                }

                if (!empty($stepToolCalls)) {
                    // --- Tool call branch (streaming) ---
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

                    // Add assistant message with tool calls
                    $conversation->addMessage('assistant', $stepContent ?: null, $stepToolCalls);

                    // Add tool results
                    foreach ($stepToolCalls as $i => $call) {
                        $toolName = $call['function']['name'] ?? 'unknown';
                        $toolCallId = $call['id'] ?? "call_{$i}";
                        $result = $toolResults[$i] ?? json_encode(['error' => 'Tool execution failed']);
                        $conversation->addMessage('tool', $result, null, $toolCallId, $toolName);
                    }

                    $totalToolCalls = array_merge($totalToolCalls, $stepToolCalls);

                    // Fire AgentStep event
                    $stepEvent = new AgentStep(
                        $sessionId,
                        $step,
                        $messages,
                        $responseData,
                        $stepToolCalls,
                        $toolResults
                    );
                    Event::trigger($stepEvent);

                    // Continue loop for next step
                    continue;
                } else {
                    // --- Text response branch: done ---
                    $finalResult = $stepContent;
                    $conversation->addMessage('assistant', $finalResult);

                    // Fire AgentStep event
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
            // Log agent execution
            $this->logExecution($agentContext, $finalResult, $error);

            // Store memory
            if (!$error && $memoryEnabled && $memory !== null) {
                try {
                    $memory->store($sessionId, 'last_result', $finalResult, ['agent_result']);
                    $memory->store($sessionId, 'last_task', $message, ['agent_result']);
                    $memory->store($sessionId, 'step_count', $agentContext->getStepCount(), ['agent_meta']);
                } catch (\Throwable $e) {
                    Log::warning("[WiseAgent] Memory store failed: {$e->getMessage()}");
                }
            }

            // Fire AgentComplete event
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

            // Yield final done event
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
     * Execute tool calls in sequence
     *
     * Each tool call fires ToolExecute events for plugin interception.
     *
     * @param ToolRegistry $registry    Tool registry
     * @param array        $toolCalls   Tool calls to execute
     * @param int          $maxPerStep  Maximum tool executions per step
     */
    protected function executeTools(ToolRegistry $registry, array $toolCalls, int $maxPerStep = 5): array
    {
        $results = [];
        $count = 0;

        foreach ($toolCalls as $call) {
            // Enforce max tool executions per step
            if ($count >= $maxPerStep) {
                $results[] = json_encode(['error' => "Maximum tool executions per step ({$maxPerStep}) reached"]);
                Log::warning("[WiseAgent] Tool execution limit reached: {$maxPerStep}");
                break;
            }

            $toolName = $call['function']['name'] ?? 'unknown';
            $arguments = json_decode($call['function']['arguments'] ?? '{}', true) ?: [];

            // Fire BEFORE event
            $beforeEvent = new ToolExecute(ToolExecute::PHASE_BEFORE, $toolName, $arguments);
            Event::trigger($beforeEvent);

            if ($beforeEvent->skip) {
                $results[] = $beforeEvent->mockResult ?? json_encode(['skipped' => true]);
                $count++;
                continue;
            }

            // Execute tool
            $result = $registry->execute($toolName, $beforeEvent->arguments);

            // Fire AFTER event
            $afterEvent = new ToolExecute(ToolExecute::PHASE_AFTER, $toolName, $arguments, $result);
            Event::trigger($afterEvent);

            $results[] = $afterEvent->result ?? $result;
            $count++;
        }

        return $results;
    }

    /**
     * Dispatch agent asynchronously via queue
     *
     * @return string Job ID
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
            'tool_names'  => [], // Will be resolved from agent
        ];

        $jobId = uniqid('ai_agent_', true);

        // Use think-queue
        \think\facade\Queue::connection($queueConnection)->push($jobClass, $jobData);

        return $jobId;
    }

    /**
     * Log agent execution to database
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
            // Non-critical: log failure shouldn't break agent
            Log::warning("[WiseAgent] Failed to log execution: {$e->getMessage()}");
        }
    }

    /**
     * Get the user context for testing / DI
     */
    public function getUserContext(): UserContextInterface
    {
        return $this->userContext;
    }
}
