<?php
declare(strict_types=1);

namespace wise\agent\memory\session;

use think\facade\Db;
use think\facade\Log;
use wise\agent\contract\UserContextInterface;

/**
 * 会话管理器
 *
 * 管理 AI 对话会话的生命周期（创建、恢复、归档）。
 */
class SessionManager
{
    protected string $table;
    protected int $sessionTtl;
    protected UserContextInterface $userContext;

    public function __construct(?UserContextInterface $userContext = null)
    {
        $this->table = config('wise-agent.tables.sessions', 'ai_sessions');
        $this->sessionTtl = (int) config('wise-agent.agent.session_ttl', 86400);
        $this->userContext = $userContext ?? new SessionUserContext();
    }

    /**
     * 创建新的对话会话
     *
     * @param array $options 选项（agent_type、title、metadata 等）
     */
    public function create(array $options = []): Conversation
    {
        $sessionId = $this->generateSessionId();
        $agentType = $options['agent_type'] ?? 'default';
        $title = $options['title'] ?? '';
        $metadata = $options['metadata'] ?? [];

        try {
            Db::table($this->table)->insert([
                'session_id'    => $sessionId,
                'user_type'     => $this->userContext->getUserType(),
                'user_id'       => $this->userContext->getUserId(),
                'agent_type'    => $agentType,
                'title'         => $title,
                'status'        => 1,
                'message_count' => 0,
                'total_tokens'  => 0,
                'metadata'      => $metadata ? json_encode($metadata) : null,
                'create_time'    => date('Y-m-d H:i:s'),
                'update_time'    => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // 表可能尚不存在；在无不持久化的情况下继续
            Log::warning("[WiseAgent] SessionManager::create() DB error: {$e->getMessage()}");
        }

        return new Conversation($sessionId, [
            'system_prompt' => $options['system_prompt'] ?? null,
        ]);
    }

    /**
     * 恢复现有对话会话
     */
    public function resume(string $sessionId): ?Conversation
    {
        try {
            $session = Db::table($this->table)
                ->where('session_id', $sessionId)
                ->find();

            if (!$session) {
                Log::info("[WiseAgent] SessionManager::resume() — session {$sessionId} not found in database");
                return null;
            }

            // 更新最后活动时间
            Db::table($this->table)
                ->where('session_id', $sessionId)
                ->update(['update_time' => date('Y-m-d H:i:s')]);
        } catch (\Throwable $e) {
            // 区分表缺失和记录缺失
            Log::error("[WiseAgent] SessionManager::resume() exception for session {$sessionId}: {$e->getMessage()}");
            // 即使数据库不可用也返回一个会话
        }

        return new Conversation($sessionId);
    }

    /**
     * 重命名对话会话
     *
     * @param string $sessionId 会话 ID
     * @param string $title     新标题
     * @return bool
     */
    public function rename(string $sessionId, string $title): bool
    {
        try {
            return Db::table($this->table)
                ->where('session_id', $sessionId)
                ->update([
                    'title'      => $title,
                    'update_time' => date('Y-m-d H:i:s'),
                ]) > 0;
        } catch (\Throwable $e) {
            Log::warning("[WiseAgent] SessionManager::rename() error: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * 归档对话会话
     */
    public function archive(string $sessionId): bool
    {
        try {
            return Db::table($this->table)
                ->where('session_id', $sessionId)
                ->update(['status' => 0]) > 0;
        } catch (\Throwable $e) {
            Log::warning("[WiseAgent] SessionManager::archive() error: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * 删除对话会话及其消息
     */
    public function delete(string $sessionId): bool
    {
        try {
            $messagesTable = config('wise-agent.tables.messages', 'ai_messages');

            Db::table($messagesTable)->where('session_id', $sessionId)->delete();
            Db::table($this->table)->where('session_id', $sessionId)->delete();

            return true;
        } catch (\Throwable $e) {
            Log::warning("[WiseAgent] SessionManager::delete() error: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * 获取当前用户的会话列表
     */
    public function getUserSessions(int $limit = 20, int $offset = 0): array
    {
        try {
            return Db::table($this->table)
                ->where('user_type', $this->userContext->getUserType())
                ->where('user_id', $this->userContext->getUserId())
                ->order('update_time', 'desc')
                ->limit($limit, $offset)
                ->select()
                ->toArray();
        } catch (\Throwable $e) {
            Log::warning("[WiseAgent] SessionManager::getUserSessions() error: {$e->getMessage()}");
            return [];
        }
    }

    /**
     * 生成唯一会话 ID
     */
    protected function generateSessionId(): string
    {
        return bin2hex(random_bytes(16)) . '_' . dechex(time());
    }

    /**
     * 获取用户上下文（用于依赖注入 / 测试）
     */
    public function getUserContext(): UserContextInterface
    {
        return $this->userContext;
    }
}
