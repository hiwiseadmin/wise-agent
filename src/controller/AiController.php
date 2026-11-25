<?php
declare(strict_types=1);

namespace wise\agent\controller;

use wise\support\context\ContextualLogger;
use wise\agent\agent\AgentManager;
use wise\agent\agent\SimpleAgent;
use wise\agent\bridge\PluginBridge;
use wise\agent\client\AiClientFactory;
use wise\agent\config\AiConfigManager;
use wise\agent\contract\RequestContext;
use wise\agent\exception\ServiceUnavailableException;
use wise\agent\execution\ExecutionTracker;
use wise\agent\http\SseResponse;
use wise\agent\memory\session\SessionManager;
use wise\support\traits\JsonResponse;

/**
 * AI 控制器
 *
 * 提供 AI 聊天功能的 REST API 端点：
 *   - 会话管理（列出、创建、删除、重命名、清空）
 *   - SSE 流式聊天
 *   - 配置管理
 *   - Provider/Model 发现
 *
 * 自包含控制器，不依赖宿主框架的 BaseController。
 * 通过路由中间件实现 RBAC 保护。
 */
class AiController
{
    use JsonResponse;

    /**
     * @var SessionManager
     */
    protected SessionManager $sessionManager;

    /**
     * @var AiConfigManager
     */
    protected AiConfigManager $configManager;

    /**
     * @var \think\Request
     */
    protected \think\Request $request;

    public function __construct()
    {
        $this->request = request();
        $this->sessionManager = new SessionManager();
        $this->configManager  = new AiConfigManager();
    }

    /**
     * 列出当前用户的 AI 会话
     *
     * GET /admin/ai/sessions
     *
     * @param int $page   页码
     * @param int $limit  每页条目数
     * @return \think\response\Json
     */
    public function sessions(int $page = 1, int $limit = 20): \think\response\Json
    {
        $offset = ($page - 1) * $limit;
        $sessions = $this->sessionManager->getUserSessions($limit, $offset);

        return $this->success('ok', [
            'list'  => $sessions,
            'page'  => $page,
            'limit' => $limit,
        ]);
    }

    /**
     * 创建新的 AI 会话
     *
     * POST /admin/ai/session/create
     *
     * @return \think\response\Json
     */
    public function createSession(): \think\response\Json
    {
        $title = $this->request->post('title', '');
        $agentType = $this->request->post('agent_type', 'simple');
        $provider = $this->request->post('provider', '');

        $options = [
            'title'      => $title ?: 'New Chat',
            'agent_type' => $agentType,
        ];

        if ($provider) {
            $options['provider'] = $provider;
        }

        $conversation = $this->sessionManager->create($options);

        return $this->success('Session created', [
            'session_id' => $conversation->getSessionId(),
            'title'      => $title ?: 'New Chat',
        ]);
    }

    /**
     * 删除一个 AI 会话
     *
     * POST /admin/ai/session/delete
     *
     * @return \think\response\Json
     */
    public function deleteSession(): \think\response\Json
    {
        $sessionId = $this->request->post('session_id', '');

        if (empty($sessionId)) {
            return $this->error('session_id is required');
        }

        if (!$this->sessionManager->isOwnedByCurrentUser($sessionId)) {
            return $this->error('Session not found');
        }

        $result = $this->sessionManager->delete($sessionId);

        return $result ? $this->success('Session deleted') : $this->error('Failed to delete session');
    }

    /**
     * 重命名一个 AI 会话
     *
     * POST /admin/ai/session/rename
     *
     * @return \think\response\Json
     */
    public function renameSession(): \think\response\Json
    {
        $sessionId = $this->request->post('session_id', '');
        $title = $this->request->post('title', '');

        if (empty($sessionId) || empty($title)) {
            return $this->error('session_id and title are required');
        }

        if (!$this->sessionManager->isOwnedByCurrentUser($sessionId)) {
            return $this->error('Session not found');
        }

        $result = $this->sessionManager->rename($sessionId, $title);

        return $result ? $this->success('Session renamed') : $this->error('Failed to rename session');
    }

    /**
     * @internal 由 sessionDetail() 调用，用于获取会话的消息列表（非路由端点）
     *
     * @param string $sessionId 会话 ID
     * @return \think\response\Json
     */
    protected function sessionMessages(string $sessionId = ''): \think\response\Json
    {
        if (empty($sessionId)) {
            $sessionId = $this->request->get('session_id', '');
        }

        if (empty($sessionId)) {
            return $this->error('session_id is required');
        }

        if (!$this->sessionManager->isOwnedByCurrentUser($sessionId)) {
            return $this->error('Session not found', [], 404);
        }

        $conversation = $this->sessionManager->resume($sessionId);

        if ($conversation === null) {
            return $this->error('Session not found', [], 404);
        }

        return $this->success('ok', [
            'session_id' => $sessionId,
            'messages'   => $conversation->getMessages(),
            'count'      => $conversation->count(),
        ]);
    }

    /**
     * 通过 SSE 进行流式聊天
     *
     * POST /admin/ai/chat/stream
     *
     * 设置 SSE 响应头并实时流式传输 AI 回复 token。
     * 事件类型：token、tool_call、tool_result、usage、error、done
     *
     * @return void
     */
    public function chatStream(): \think\Response
    {
        $message   = (string) $this->request->post('message', '');
        $sessionId = (string) $this->request->post('session_id', '');
        $provider  = (string) $this->request->post('provider', '');

        return new SseResponse(
            $this->chatStreamEvents($message, $sessionId, $provider),
            200,
            $this->sseHeaders()
        );
    }

    /**
     * 流式聊天的业务事件序列
     *
     * 异常在生成器内部转换成 error / done 事件：此时响应头已发出，
     * 再抛异常只会让连接悬挂，前端拿不到终止信号。
     *
     * @return \Generator<int, array<string, mixed>>
     */
    protected function chatStreamEvents(string $message, string $sessionId, string $provider): \Generator
    {
        if ($message === '') {
            yield ['type' => 'error', 'message' => 'message is required'];
            yield ['type' => 'done'];
            return;
        }

        try {
            $agent   = $this->buildAgent($sessionId, $provider);
            $manager = new AgentManager();

            foreach ($manager->runStream($agent, $message) as $event) {
                yield $event;
            }
        } catch (\Throwable $e) {
            ContextualLogger::error("[AiController] ChatStream error: {$e->getMessage()}", [
                'session_id' => $sessionId,
                'exception'  => get_class($e),
            ]);

            yield ['type' => 'error', 'message' => 'An error occurred while processing your request.'];
            yield ['type' => 'done'];
        }
    }

    /**
     * 获取可用的 AI Provider 列表
     *
     * GET /admin/ai/providers
     *
     * @return \think\response\Json
     */
    public function getProviders(): \think\response\Json
    {
        $providers = AiClientFactory::getProviders();

        $result = [];
        foreach ($providers as $name) {
            $config = $this->configManager->get($name);
            $result[] = [
                'name'    => $name,
                'model'   => $config['model'] ?? '',
                'active'  => (bool) ($config['is_active'] ?? true),
            ];
        }

        return $this->success('ok', $result);
    }

    /**
     * 获取指定 Provider 的可用模型列表
     *
     * GET /admin/ai/models?provider=openai
     *
     * @param string $provider Provider 名称
     * @return \think\response\Json
     */
    public function getModels(string $provider = ''): \think\response\Json
    {
        if (empty($provider)) {
            $provider = $this->request->get('provider', 'openai');
        }

        $modelMap = [
            'openai'   => ['gpt-4o', 'gpt-4o-mini', 'gpt-4-turbo', 'gpt-4', 'gpt-3.5-turbo'],
            'deepseek' => ['deepseek-chat', 'deepseek-reasoner'],
        ];

        $models = $modelMap[$provider] ?? ['default'];

        return $this->success('ok', $models);
    }

    /**
     * 获取 AI 配置
     *
     * GET /admin/ai/config
     *
     * @return \think\response\Json
     */
    public function config(): \think\response\Json
    {
        $configs = $this->configManager->getAll();

        return $this->success('ok', $configs);
    }

    /**
     * 保存 AI 配置
     *
     * POST /admin/ai/config/save
     *
     * @return \think\response\Json
     */
    public function saveConfig(): \think\response\Json
    {
        $provider = $this->request->post('provider', '');

        if (empty($provider)) {
            return $this->error('provider is required');
        }

        $data = [
            'api_base'    => $this->request->post('api_base', ''),
            'model'       => $this->request->post('model', ''),
            'max_tokens'  => (int) $this->request->post('max_tokens', 4096),
            'temperature' => (float) $this->request->post('temperature', 0.7),
            'is_active'   => (int) $this->request->post('is_active', 1),
        ];

        $apiKey = (string) $this->request->post('api_key', '');
        if ($apiKey !== '' && !str_contains($apiKey, '*')) {
            $data['api_key'] = $apiKey;
        }

        $result = $this->configManager->save($provider, $data);

        AiClientFactory::clearCache();

        return $result ? $this->success('Configuration saved') : $this->error('Failed to save configuration');
    }

    /**
     * 测试 AI Provider 连接
     *
     * POST /admin/ai/config/test
     *
     * @return \think\response\Json
     */
    public function testConnection(): \think\response\Json
    {
        $provider = $this->request->post('provider', 'openai');
        $apiKey = $this->request->post('api_key', '');
        $apiBase = $this->request->post('api_base', '');
        $model = $this->request->post('model', '');

        if (empty($apiKey)) {
            return $this->error('API key is required for testing');
        }

        try {
            $config = [
                'api_key'  => $apiKey,
                'base_url' => $apiBase ?: null,
                'model'    => $model ?: null,
                'timeout'  => 15,
                'max_retry' => 0,
            ];

            $client = null;
            if ($provider === 'deepseek') {
                $client = new \wise\agent\client\DeepSeekClient($config);
            } else {
                $client = new \wise\agent\client\OpenAiClient($config);
            }

            $response = $client->chat([
                ['role' => 'user', 'content' => 'Hi! Reply with just "OK".'],
            ], [], ['max_tokens' => 10]);

            $content = $response['content'] ?? '';
            $isOk = stripos($content, 'OK') !== false || !empty($content);

            return $this->success(
                $isOk ? 'Connection successful' : 'Connected but unexpected response',
                [
                    'response' => mb_substr($content, 0, 100),
                    'model'    => $client->getModelName(),
                ]
            );
        } catch (ServiceUnavailableException $e) {
            ContextualLogger::warning("[AiController] Connection test blocked by circuit breaker for '{$provider}'", [
                'retry_after' => $e->getRetryAfter(),
            ]);
            return $this->circuitResponse($e);
        } catch (\Throwable $e) {
            ContextualLogger::error("[AiController] Connection test failed for '{$provider}'", [
                'error' => (new \ReflectionClass($e))->getShortName() . ': ' . $e->getMessage(),
            ]);
            return $this->error('Connection test failed. Please check your API key and base URL.');
        }
    }


    /**
     * 获取所有已注册的 AI 工具（按命名空间分组）
     *
     * GET /admin/ai/tools
     *
     * 返回所有工具列表，按命名空间分组，供前端工具面板和
     * Function Calling 使用。
     *
     * @return \think\response\Json
     */
    public function getTools(): \think\response\Json
    {
        $bridge = app()->make(PluginBridge::class);
        $grouped = $bridge->getToolListGrouped();

        return $this->success('ok', [
            'groups' => $grouped,
            'total'  => array_sum(array_map(fn($g) => count($g['tools']), $grouped)),
        ]);
    }

    /**
     * 获取所有工作流节点定义
     *
     * GET /admin/ai/workflow-nodes
     *
     * 返回所有节点定义，供前端工作流设计器渲染节点面板。
     *
     * @return \think\response\Json
     */
    public function getWorkflowNodes(): \think\response\Json
    {
        $bridge = app()->make(PluginBridge::class);
        $nodes = $bridge->getWorkflowNodes();

        $grouped = [];
        foreach ($nodes as $node) {
            $group = $node['group'] ?? 'default';
            if (!isset($grouped[$group])) {
                $grouped[$group] = [
                    'group' => $group,
                    'label' => $this->getGroupLabel($group),
                    'nodes' => [],
                ];
            }
            $grouped[$group]['nodes'][] = $node;
        }

        return $this->success('ok', [
            'groups' => array_values($grouped),
            'total'  => count($nodes),
        ]);
    }

    /**
     * 获取所有模型适配器
     *
     * GET /admin/ai/adapters
     *
     * 返回所有已注册的模型适配器信息，供后台 AI 设置页面使用。
     *
     * @return \think\response\Json
     */
    public function getAdapters(): \think\response\Json
    {
        $bridge = app()->make(PluginBridge::class);
        $adapters = $bridge->getAdapters();

        return $this->success('ok', [
            'adapters' => $adapters,
            'total'    => count($adapters),
        ]);
    }

    /**
     * 手动调用工具（调试用）
     *
     * POST /admin/ai/tools/call
     *
     * 请求体：
     *   { "tool": "plugin:demo.translate", "params": { "text": "hello", "target_lang": "zh" } }
     *
     * @return \think\response\Json
     */
    public function callTool(): \think\response\Json
    {
        $toolName = $this->request->post('tool', '');
        $params   = $this->request->post('params', []);

        if (empty($toolName)) {
            return $this->error('tool name is required');
        }

        if (!is_array($params)) {
            $params = json_decode($params, true) ?? [];
        }

        $context = RequestContext::fromRequest($this->request);

        try {
            $bridge = app()->make(PluginBridge::class);
            $result = $bridge->callTool($toolName, $params, $context);

            return $this->success('Tool executed', [
                'tool'   => $toolName,
                'result' => $result,
            ]);
        } catch (\Throwable $e) {
            ContextualLogger::error("[AiController] callTool error: {$e->getMessage()}", [
                'tool'       => $toolName,
                'exception'  => get_class($e),
            ]);
            return $this->error('Tool execution failed. Please check the tool parameters and try again.');
        }
    }

    /**
     * 获取分组标签
     */
    protected function getGroupLabel(string $group): string
    {
        $labels = [
            'default'   => '基础节点',
            'ai'        => 'AI 节点',
            'data'      => '数据处理',
            'logic'     => '逻辑控制',
            'integration' => '集成',
        ];

        return $labels[$group] ?? $group;
    }

    /**
     * 清空会话中的所有消息
     *
     * POST /admin/ai/session/clear
     *
     * @return \think\response\Json
     */
    public function clearSession(): \think\response\Json
    {
        $sessionId = $this->request->post('session_id', '');

        if (empty($sessionId)) {
            return $this->error('session_id is required');
        }

        if (!$this->sessionManager->isOwnedByCurrentUser($sessionId)) {
            return $this->error('Session not found');
        }

        try {
            $conversation = $this->sessionManager->resume($sessionId);
            if ($conversation === null) {
                return $this->error('Session not found');
            }

            $conversation->clear();

            return $this->success('Session messages cleared');
        } catch (\Throwable $e) {
            ContextualLogger::error("[AiController] clearSession error: {$e->getMessage()}", [
                'session_id' => $sessionId,
                'exception'  => get_class($e),
            ]);
            return $this->error('Clear session failed. Please try again.');
        }
    }

    /**
     * 停止当前流式聊天
     *
     * POST /admin/ai/chat/stop
     *
     * 为指定会话设置停止标志。流式 Agent 循环会检查此标志，
     * 并在检测到后优雅终止。此外，客户端也会独立关闭其 EventSource 连接。
     *
     * @return \think\response\Json
     */
    public function stopChat(): \think\response\Json
    {
        $sessionId = $this->request->post('session_id', '');

        if (empty($sessionId)) {
            return $this->error('session_id is required');
        }

        if (!$this->sessionManager->isOwnedByCurrentUser($sessionId)) {
            return $this->error('Session not found', [], 404);
        }

        try {
            \think\facade\Cache::set("ai_stop_{$sessionId}", true, 60);

            return $this->success('Stop signal sent');
        } catch (\Throwable $e) {
            ContextualLogger::error("[AiController] stopChat error: {$e->getMessage()}");
            return $this->error('Stop failed. Please try again.');
        }
    }

    /**
     * 查询异步任务执行状态
     *
     * GET /admin/ai/task/status?tracking_id=xxx
     *
     * 用于轮询 dispatchAsync() 提交的异步 Agent/Pipeline 执行状态。
     * 返回 tracking_id 对应的状态、进度和结果信息。
     *
     * @param string $tracking_id 追踪 ID
     * @return \think\response\Json
     */
    public function taskStatus(string $tracking_id = ''): \think\response\Json
    {
        if (empty($tracking_id)) {
            $tracking_id = $this->request->get('tracking_id', '');
        }

        if (empty($tracking_id)) {
            return $this->error('tracking_id is required');
        }

        $userContext = $this->sessionManager->getUserContext();

        $status = ExecutionTracker::getStatusForOwner(
            $tracking_id,
            $userContext->getUserId(),
            $userContext->getUserType()
        );

        if ($status === null) {
            return $this->error('Task not found', [], 404);
        }

        return $this->success('ok', $status);
    }

    /**
     * 检查会话是否存在中断的任务
     *
     * GET /admin/ai/session/interrupted-status?session_id=xxx
     *
     * @param string $session_id 会话 ID
     * @return \think\response\Json
     */
    public function interruptedStatus(string $session_id = ''): \think\response\Json
    {
        if (empty($session_id)) {
            $session_id = $this->request->get('session_id', '');
        }

        if (empty($session_id)) {
            return $this->error('session_id is required');
        }

        if (!$this->sessionManager->isOwnedByCurrentUser($session_id)) {
            return $this->error('Session not found', [], 404);
        }

        $result = $this->sessionManager->isInterrupted($session_id);

        return $this->success('ok', $result);
    }

    /**
     * 恢复中断的 AI 对话（SSE 流式）
     *
     * POST /admin/ai/chat/resume
     *
     * 不添加新的用户消息，直接继续之前的对话上下文。
     * 适用于任务中断后点击"继续"按钮的场景。
     *
     * @return void
     */
    public function resumeChat(): \think\Response
    {
        $sessionId = (string) $this->request->post('session_id', '');

        return new SseResponse(
            $this->resumeChatEvents($sessionId),
            200,
            $this->sseHeaders()
        );
    }

    /**
     * 恢复对话的业务事件序列
     *
     * resume 本身是同步调用，这里包装成事件序列复用同一套 SSE 响应。
     *
     * @return \Generator<int, array<string, mixed>>
     */
    protected function resumeChatEvents(string $sessionId): \Generator
    {
        if ($sessionId === '') {
            yield ['type' => 'error', 'message' => 'session_id is required'];
            yield ['type' => 'done'];
            return;
        }

        if (!$this->sessionManager->isOwnedByCurrentUser($sessionId)) {
            yield ['type' => 'error', 'message' => 'Session not found'];
            yield ['type' => 'done'];
            return;
        }

        try {
            $conversation = $this->sessionManager->resume($sessionId);
            if ($conversation === null) {
                yield ['type' => 'error', 'message' => 'Session not found'];
                yield ['type' => 'done'];
                return;
            }

            $agent = new \wise\agent\agent\SimpleAgent($sessionId);
            $agent->setConversation($conversation);

            $manager = new AgentManager();
            $result  = $manager->resume($agent, 'Resume interrupted task');

            yield ['type' => 'token', 'content' => $result];
            yield ['type' => 'done', 'result' => $result, 'success' => true];
        } catch (\Throwable $e) {
            ContextualLogger::error("[AiController] ResumeChat error: {$e->getMessage()}", [
                'session_id' => $sessionId,
                'exception'  => get_class($e),
            ]);

            yield ['type' => 'error', 'message' => 'An error occurred while resuming.'];
            yield ['type' => 'done', 'success' => false, 'error' => 'Resume failed.'];
        }
    }

    /**
     * 获取会话详情及消息
     *
     * GET /admin/ai/session/detail?session_id=xxx
     *
     * sessionMessages() 的别名。
     *
     * @return \think\response\Json
     */
    public function sessionDetail(): \think\response\Json
    {
        return $this->sessionMessages();
    }

    /**
     * 构建用于聊天的 Agent 实例
     *
     * @param string $sessionId 会话 ID（为空则创建新会话）
     * @param string $provider  Provider 名称（为空则使用默认值）
     * @return SimpleAgent
     */
    protected function buildAgent(string $sessionId = '', string $provider = ''): SimpleAgent
    {
        if (empty($sessionId)) {
            $conversation = $this->sessionManager->create();
            $sessionId = $conversation->getSessionId();
        } else {
            $conversation = $this->sessionManager->resume($sessionId);
            if ($conversation === null) {
                $conversation = $this->sessionManager->create();
                $sessionId = $conversation->getSessionId();
            } elseif (!$this->sessionManager->isOwnedByCurrentUser($sessionId)) {
                ContextualLogger::warning("[AiController] Session {$sessionId} does not belong to current user, creating new session");
                $conversation = $this->sessionManager->create();
                $sessionId = $conversation->getSessionId();
            }
        }

        $options = [];
        if (!empty($provider)) {
            $options['provider'] = $provider;
        }

        $agent = new SimpleAgent($sessionId, $options);
        $agent->setConversation($conversation);

        return $agent;
    }

    /**
     * SSE 公共响应头
     *
     * 仅允许同源请求，避免任意来源消耗 AI 额度；开发环境额外放行 localhost。
     * 事件分片与刷新由 {@see SseResponse} 负责。
     *
     * @return array<string, string>
     */
    protected function sseHeaders(): array
    {
        $headers        = [];
        $allowedOrigin  = (string) $this->request->header('Origin', '');
        $appDomain      = rtrim((string) request()->domain(), '/');
        $allowedOrigins = [$appDomain];

        if (config('app.app_debug')) {
            $scheme = parse_url($appDomain, PHP_URL_SCHEME) ?: 'http';
            $allowedOrigins[] = "{$scheme}://localhost";
        }

        if ($allowedOrigin === '' || in_array($allowedOrigin, $allowedOrigins, true)) {
            $headers['Access-Control-Allow-Origin'] = $allowedOrigin !== '' ? $allowedOrigin : $appDomain;
        }

        return $headers;
    }
}
