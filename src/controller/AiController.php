<?php
declare(strict_types=1);

namespace wise\agent\controller;

use app\common\controller\BaseController;
use think\facade\Log;
use wise\agent\agent\AgentManager;
use wise\agent\agent\SimpleAgent;
use wise\agent\client\AiClientFactory;
use wise\agent\config\AiConfigManager;
use wise\agent\memory\session\SessionManager;

/**
 * AI 控制器
 *
 * 提供 AI 聊天功能的 REST API 端点：
 *   - 会话管理（列出、创建、删除、重命名、清空）
 *   - SSE 流式聊天
 *   - 配置管理
 *   - Provider/Model 发现
 *
 * 使用 BaseController 实现 RBAC 中间件和 JsonResponse trait。
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
     * 初始化
     */
    protected function _initialize(): void
    {
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

        $result = $this->sessionManager->rename($sessionId, $title);

        return $result ? $this->success('Session renamed') : $this->error('Failed to rename session');
    }

    /**
     * @internal 由 sessionDetail() 调用，用于获取会话的消息列表
     *
     * GET /admin/ai/session/messages
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

        // 设置 SSE 响应头
        header('Content-Type: text/event-stream');
        header('X-Accel-Buffering: no');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('Access-Control-Allow-Origin: *');

        // 禁用输出缓冲
        if (ob_get_level()) {
            ob_end_clean();
        }

        try {
            $agent = $this->buildAgent($sessionId, $provider);
            $manager = new AgentManager();
            $stream = $manager->runStream($agent, $message);

            foreach ($stream as $event) {
                $this->sseEmit($event['type'], $event);

                // 将输出刷新到客户端
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

        // 各 Provider 的常用模型列表
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
            'api_key'     => $this->request->post('api_key', ''),
            'api_base'    => $this->request->post('api_base', ''),
            'model'       => $this->request->post('model', ''),
            'max_tokens'  => (int) $this->request->post('max_tokens', 4096),
            'temperature' => (float) $this->request->post('temperature', 0.7),
            'is_active'   => (int) $this->request->post('is_active', 1),
        ];

        $result = $this->configManager->save($provider, $data);

        // 配置变更后清除客户端缓存
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
        } catch (\Throwable $e) {
            Log::error("[AiController] Connection test failed for '{$provider}': {$e->getMessage()}");
            return $this->error("Connection test failed: {$e->getMessage()}");
        }
    }

    /**
     * AI 聊天页面入口
     *
     * GET /admin/ai/chat-page
     *
     * 渲染三栏聊天界面。静态 HTML 模板从 public/statics 中读取，
     * 模板常量会被替换。
     *
     * @return string
     */
    public function chatPage(): string
    {
        return $this->renderStaticPage('chat');
    }

    /**
     * AI 配置页面入口
     *
     * GET /admin/ai/config-page
     *
     * 渲染 Provider 配置界面。静态 HTML 模板从 public/statics 中读取，
     * 模板常量会被替换。
     *
     * @return string
     */
    public function configPage(): string
    {
        return $this->renderStaticPage('config');
    }

    /**
     * 从 wise-agent 插件 statics 目录渲染静态 HTML 页面
     *
     * 读取 HTML 文件，替换模板常量并返回结果。
     *
     * @param string $name 页面名称（不含 .html 扩展名）
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

        // 替换模板常量
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

        try {
            \think\facade\Cache::set("ai_stop_{$sessionId}", true, 60);

            return $this->success('Stop signal sent');
        } catch (\Throwable $e) {
            Log::error("[AiController] stopChat error: {$e->getMessage()}");
            return $this->error("Stop failed: {$e->getMessage()}");
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
     * 发送一个 Server-Sent Event
     *
     * @param string $event 事件名称
     * @param array  $data  事件数据
     * @return void
     */
    protected function sseEmit(string $event, array $data): void
    {
        $payload = json_encode($data, JSON_UNESCAPED_UNICODE);
        echo "event: {$event}\n";
        echo "data: {$payload}\n\n";
    }
}
