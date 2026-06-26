<?php
declare(strict_types=1);

namespace wise\agent\controller;

use app\common\controller\BaseController;
use think\facade\Log;
use wise\agent\Agent\AgentManager;
use wise\agent\Agent\SimpleAgent;
use wise\agent\Client\AiClientFactory;
use wise\agent\config\AiConfigManager;
use wise\agent\Memory\Session\SessionManager;

/**
 * AI Controller
 *
 * Provides REST API endpoints for AI chat functionality:
 *   - Session management (list, create, delete, rename, clear)
 *   - SSE streaming chat
 *   - Configuration management
 *   - Provider/Model discovery
 *
 * Uses BaseController for RBAC middleware and JsonResponse trait.
 */
class AiController extends BaseController
{
    /**
     * @var array 无需登录的方法名列表
     */
    protected $noNeedLogin = [];

    /**
     * @var array 需要登录但无需鉴权的方法名列表
     */
    protected $noNeedAuth = [
        'config', 'saveConfig', 'testConnection',
        'getProviders', 'getModels',
        'sessions', 'sessionDetail',
        'createSession', 'renameSession', 'deleteSession', 'clearSession',
        'chatStream', 'stopChat',
        'chatPage', 'configPage',
    ];

    /**
     * @var SessionManager
     */
    protected $sessionManager;

    /**
     * @var AiConfigManager
     */
    protected $configManager;

    /**
     * Initialize
     */
    protected function _initialize(): void
    {
        $this->sessionManager = new SessionManager();
        $this->configManager  = new AiConfigManager();
    }

    /**
     * List AI sessions for current user
     *
     * GET /admin/ai/sessions
     *
     * @param int $page   Page number
     * @param int $limit  Items per page
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
     * Create a new AI session
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
     * Delete an AI session
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

        $result = $this->sessionManager->delete($sessionId);

        return $result ? $this->success('Session deleted') : $this->error('Failed to delete session');
    }

    /**
     * Rename an AI session
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

        $result = $this->sessionManager->rename($sessionId, $title);

        return $result ? $this->success('Session renamed') : $this->error('Failed to rename session');
    }

    /**
     * @internal Called by sessionDetail() to fetch messages for a session
     *
     * GET /admin/ai/session/messages
     *
     * @param string $sessionId Session ID
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
     * Streaming chat via SSE
     *
     * POST /admin/ai/chat/stream
     *
     * Sets SSE headers and streams AI response tokens in real-time.
     * Events: token, tool_call, tool_result, usage, error, done
     *
     * @return void
     */
    public function chatStream(): void
    {
        $message = $this->request->post('message', '');
        $sessionId = $this->request->post('session_id', '');
        $provider = $this->request->post('provider', '');

        if (empty($message)) {
            $this->sseEmit('error', ['message' => 'message is required']);
            $this->sseEmit('done', []);
            return;
        }

        // Set SSE response headers
        header('Content-Type: text/event-stream');
        header('X-Accel-Buffering: no');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('Access-Control-Allow-Origin: *');

        // Disable output buffering
        if (ob_get_level()) {
            ob_end_clean();
        }

        try {
            $agent = $this->buildAgent($sessionId, $provider);
            $manager = new AgentManager();
            $stream = $manager->runStream($agent, $message);

            foreach ($stream as $event) {
                $this->sseEmit($event['type'], $event);

                // Flush output to client
                if (ob_get_level()) {
                    ob_flush();
                }
                flush();
            }
        } catch (\Throwable $e) {
            Log::error("[AiController] ChatStream error: {$e->getMessage()}");
            $this->sseEmit('error', ['message' => "Stream error: {$e->getMessage()}"]);
            $this->sseEmit('done', []);
        }
    }

    /**
     * Get available AI providers
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
     * Get available models for a provider
     *
     * GET /admin/ai/models?provider=openai
     *
     * @param string $provider Provider name
     * @return \think\response\Json
     */
    public function getModels(string $provider = ''): \think\response\Json
    {
        if (empty($provider)) {
            $provider = $this->request->get('provider', 'openai');
        }

        // Common model lists per provider
        $modelMap = [
            'openai'   => ['gpt-4o', 'gpt-4o-mini', 'gpt-4-turbo', 'gpt-4', 'gpt-3.5-turbo'],
            'deepseek' => ['deepseek-chat', 'deepseek-reasoner'],
        ];

        $models = $modelMap[$provider] ?? ['default'];

        return $this->success('ok', $models);
    }

    /**
     * Get AI configuration
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
     * Save AI configuration
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
            'api_key'     => $this->request->post('api_key', ''),
            'api_base'    => $this->request->post('api_base', ''),
            'model'       => $this->request->post('model', ''),
            'max_tokens'  => (int) $this->request->post('max_tokens', 4096),
            'temperature' => (float) $this->request->post('temperature', 0.7),
            'is_active'   => (int) $this->request->post('is_active', 1),
        ];

        $result = $this->configManager->save($provider, $data);

        // Clear client cache after config change
        AiClientFactory::clearCache();

        return $result ? $this->success('Configuration saved') : $this->error('Failed to save configuration');
    }

    /**
     * Test AI provider connection
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
                $client = new \wise\agent\Client\DeepSeekClient($config);
            } else {
                $client = new \wise\agent\Client\OpenAiClient($config);
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
        } catch (\Throwable $e) {
            Log::error("[AiController] Connection test failed for '{$provider}': {$e->getMessage()}");
            return $this->error("Connection test failed: {$e->getMessage()}");
        }
    }

    /**
     * AI chat page entry
     *
     * GET /admin/ai/chat-page
     *
     * Renders the three-column chat interface. The static HTML template
     * is read from public/statics and template constants are replaced.
     *
     * @return string
     */
    public function chatPage(): string
    {
        return $this->renderStaticPage('chat');
    }

    /**
     * AI config page entry
     *
     * GET /admin/ai/config-page
     *
     * Renders the provider configuration interface. The static HTML
     * template is read from public/statics and template constants are replaced.
     *
     * @return string
     */
    public function configPage(): string
    {
        return $this->renderStaticPage('config');
    }

    /**
     * Render a static HTML page from the wise-agent plugin statics directory
     *
     * Reads the HTML file, replaces template constants, and returns the result.
     *
     * @param string $name Page name (without .html extension)
     * @return string
     */
    protected function renderStaticPage(string $name): string
    {
        $htmlPath = public_path() . 'statics' . DIRECTORY_SEPARATOR . 'plugins' .
                    DIRECTORY_SEPARATOR . 'wise-agent' . DIRECTORY_SEPARATOR .
                    'html' . DIRECTORY_SEPARATOR . $name . '.html';

        if (!file_exists($htmlPath)) {
            return '<html><body><h3>Page Not Found</h3><p>Template file missing: ' . htmlspecialchars($name) . '.html</p></body></html>';
        }

        $html = file_get_contents($htmlPath);
        if ($html === false) {
            return '<html><body><h3>Error</h3><p>Failed to read template file.</p></body></html>';
        }

        // Replace template constants
        $staticUrl = rtrim((string) request()->domain(), '/') . '/statics';
        $pluginsUrl = $staticUrl . '/plugins';

        $replacements = [
            '__STATIC__'  => $staticUrl,
            '__PLUGINS__' => $pluginsUrl,
            '__PUBLIC__'  => rtrim((string) request()->domain(), '/'),
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $html);
    }

    /**
     * Clear all messages in a session
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

        try {
            $conversation = $this->sessionManager->resume($sessionId);
            if ($conversation === null) {
                return $this->error('Session not found');
            }

            $conversation->clear();

            return $this->success('Session messages cleared');
        } catch (\Throwable $e) {
            Log::error("[AiController] clearSession error: {$e->getMessage()}");
            return $this->error("Clear session failed: {$e->getMessage()}");
        }
    }

    /**
     * Stop current streaming chat
     *
     * POST /admin/ai/chat/stop
     *
     * Sets a stop flag for the specified session. The streaming agent
     * loop checks this flag and terminates gracefully when set.
     * Additionally, the client closes its EventSource connection independently.
     *
     * @return \think\response\Json
     */
    public function stopChat(): \think\response\Json
    {
        $sessionId = $this->request->post('session_id', '');

        if (empty($sessionId)) {
            return $this->error('session_id is required');
        }

        try {
            \think\facade\Cache::set("ai_stop_{$sessionId}", true, 60);

            return $this->success('Stop signal sent');
        } catch (\Throwable $e) {
            Log::error("[AiController] stopChat error: {$e->getMessage()}");
            return $this->error("Stop failed: {$e->getMessage()}");
        }
    }

    /**
     * Get session detail with messages
     *
     * GET /admin/ai/session/detail?session_id=xxx
     *
     * Alias for sessionMessages().
     *
     * @return \think\response\Json
     */
    public function sessionDetail(): \think\response\Json
    {
        return $this->sessionMessages();
    }

    /**
     * Build an agent instance for chat
     *
     * @param string $sessionId Session ID (empty = create new)
     * @param string $provider  Provider name (empty = use default)
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
     * Emit a Server-Sent Event
     *
     * @param string $event Event name
     * @param array  $data  Event data
     * @return void
     */
    protected function sseEmit(string $event, array $data): void
    {
        $payload = json_encode($data, JSON_UNESCAPED_UNICODE);
        echo "event: {$event}\n";
        echo "data: {$payload}\n\n";
    }
}
