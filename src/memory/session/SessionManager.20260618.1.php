<?php
declare(strict_types=1);

namespace wise\agent\Memory\Session;

use think\facade\Db;
use think\facade\Log;
use wise\agent\Contract\UserContextInterface;

/**
 * Session manager
 *
 * Manages AI conversation sessions lifecycle (create, resume, archive).
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
     * Create a new conversation session
     *
     * @param array $options Options (agent_type, title, metadata, etc.)
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
            // Table may not exist; proceed without persistence
            Log::warning("[WiseAgent] SessionManager::create() DB error: {$e->getMessage()}");
        }

        return new Conversation($sessionId, [
            'system_prompt' => $options['system_prompt'] ?? null,
        ]);
    }

    /**
     * Resume an existing conversation session
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

            // Update last activity
            Db::table($this->table)
                ->where('session_id', $sessionId)
                ->update(['update_time' => date('Y-m-d H:i:s')]);
        } catch (\Throwable $e) {
            // Distinguish between table missing and record missing
            Log::error("[WiseAgent] SessionManager::resume() exception for session {$sessionId}: {$e->getMessage()}");
            // Return a conversation even if DB is unavailable
        }

        return new Conversation($sessionId);
    }

    /**
     * Rename a conversation session
     *
     * @param string $sessionId Session ID
     * @param string $title     New title
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
     * Archive a conversation session
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
     * Delete a conversation session and its messages
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
     * Get sessions for current user
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
     * Generate a unique session ID
     */
    protected function generateSessionId(): string
    {
        return bin2hex(random_bytes(16)) . '_' . dechex(time());
    }

    /**
     * Get the user context for dependency injection / testing
     */
    public function getUserContext(): UserContextInterface
    {
        return $this->userContext;
    }
}
