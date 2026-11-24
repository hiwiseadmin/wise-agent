<?php
declare(strict_types=1);

namespace wise\agent\memory\session;

use think\facade\Db;
use wise\support\context\ContextualLogger;
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
        $this->table = 'ai_sessions';
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
            Db::name($this->table)->insert([
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
            ContextualLogger::warning("[WiseAgent] SessionManager::create() DB error: {$e->getMessage()}");
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
            $session = Db::name($this->table)
                ->where('session_id', $sessionId)
                ->find();

            if (!$session) {
                ContextualLogger::info("[WiseAgent] SessionManager::resume() — session {$sessionId} not found in database");
                return null;
            }

            Db::name($this->table)
                ->where('session_id', $sessionId)
                ->update(['update_time' => date('Y-m-d H:i:s')]);
        } catch (\Throwable $e) {
            ContextualLogger::error("[WiseAgent] SessionManager::resume() exception for session {$sessionId}: {$e->getMessage()}");
            return null;
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
            return Db::name($this->table)
                ->where('session_id', $sessionId)
                ->update([
                    'title'      => $title,
                    'update_time' => date('Y-m-d H:i:s'),
                ]) > 0;
        } catch (\Throwable $e) {
            ContextualLogger::warning("[WiseAgent] SessionManager::rename() error: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * 归档对话会话
     */
    public function archive(string $sessionId): bool
    {
        try {
            return Db::name($this->table)
                ->where('session_id', $sessionId)
                ->update(['status' => 0]) > 0;
        } catch (\Throwable $e) {
            ContextualLogger::warning("[WiseAgent] SessionManager::archive() error: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * 删除对话会话及其消息
     */
    public function delete(string $sessionId): bool
    {
        try {
            $messagesTable = 'ai_messages';

            Db::name($messagesTable)->where('session_id', $sessionId)->delete();
            Db::name($this->table)->where('session_id', $sessionId)->delete();

            return true;
        } catch (\Throwable $e) {
            ContextualLogger::warning("[WiseAgent] SessionManager::delete() error: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * 获取当前用户的会话列表
     */
    public function getUserSessions(int $limit = 20, int $offset = 0): array
    {
        try {
            return Db::name($this->table)
                ->where('user_type', $this->userContext->getUserType())
                ->where('user_id', $this->userContext->getUserId())
                ->order('update_time', 'desc')
                ->limit($offset, $limit)
                ->select()
                ->toArray();
        } catch (\Throwable $e) {
            ContextualLogger::warning("[WiseAgent] SessionManager::getUserSessions() error: {$e->getMessage()}");
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

    /**
     * 检查会话是否属于当前用户
     *
     * @param string $sessionId 会话 ID
     * @return bool
     */
    public function isOwnedByCurrentUser(string $sessionId): bool
    {
        try {
            $session = Db::name($this->table)
                ->where('session_id', $sessionId)
                ->find();

            if (!$session) {
                return false;
            }

            return $session['user_type'] === $this->userContext->getUserType()
                && (int)$session['user_id'] === $this->userContext->getUserId();
        } catch (\Throwable $e) {
            ContextualLogger::warning("[WiseAgent] SessionManager::isOwnedByCurrentUser() error: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * 检查会话是否存在中断的任务
     *
     * 中断判定条件（任一满足即视为中断）：
     *   1. ai_agent_logs 中存在状态为 running/timeout 且心跳超时的记录
     *   2. ai_messages 中最后一条消息是 user（Agent 未响应）
     *
     * @param string $sessionId 会话 ID
     * @return array {interrupted: bool, reason: string, last_tracking_id: ?string, last_status: ?string}
     */
    public function isInterrupted(string $sessionId): array
    {
        $result = [
            'interrupted'       => false,
            'reason'            => '',
            'last_tracking_id'  => null,
            'last_status'       => null,
        ];

        try {
            $logsTable = 'ai_agent_logs';
            $runningLog = Db::name($logsTable)
                ->where('session_id', $sessionId)
                ->whereIn('status', [1])
                ->order('create_time', 'desc')
                ->find();

            if ($runningLog) {
                $heartbeat = $runningLog['heartbeat'] ?? null;
                $isStale = $heartbeat && (time() - strtotime($heartbeat) > 600);

                if ($isStale || !$heartbeat) {
                    $result['interrupted']      = true;
                    $result['reason']           = 'Agent execution appears stuck (no heartbeat)';
                    $result['last_tracking_id'] = $runningLog['tracking_id'] ?? null;
                    $result['last_status']      = 'running';
                    return $result;
                }
            }

            $timeoutLog = Db::name($logsTable)
                ->where('session_id', $sessionId)
                ->where('status', 4)
                ->order('create_time', 'desc')
                ->find();

            if ($timeoutLog) {
                $result['interrupted']      = true;
                $result['reason']           = 'Agent execution timed out';
                $result['last_tracking_id'] = $timeoutLog['tracking_id'] ?? null;
                $result['last_status']      = 'timeout';
                return $result;
            }

            $msgTable = 'ai_messages';
            $lastMsg = Db::name($msgTable)
                ->where('session_id', $sessionId)
                ->order('id', 'desc')
                ->find();

            if ($lastMsg && ($lastMsg['role'] ?? '') === 'user') {
                $afterCount = Db::name($msgTable)
                    ->where('session_id', $sessionId)
                    ->where('id', '>', $lastMsg['id'])
                    ->whereIn('role', ['assistant', 'tool'])
                    ->count();

                if ($afterCount === 0) {
                    $result['interrupted'] = true;
                    $result['reason']      = 'Last message from user has no response';
                    $result['last_status'] = 'no_response';
                }
            }
        } catch (\Throwable $e) {
            ContextualLogger::warning("[WiseAgent] SessionManager::isInterrupted() error: {$e->getMessage()}");
        }

        return $result;
    }

    /**
     * 获取当前用户所有中断的会话
     *
     * @return array
     */
    public function getInterruptedSessions(): array
    {
        $interrupted = [];

        try {
            $logsTable = 'ai_agent_logs';

            $staleRunning = Db::name($this->table)
                ->alias('s')
                ->join("{$logsTable} l", 's.session_id = l.session_id')
                ->where('s.user_type', $this->userContext->getUserType())
                ->where('s.user_id', $this->userContext->getUserId())
                ->whereIn('l.status', [1, 4])
                ->where(function ($query) {
                    $cutoff = date('Y-m-d H:i:s', time() - 600);
                    $query->where('l.heartbeat', '<', $cutoff)
                        ->whereOr('l.heartbeat', null);
                })
                ->field(['s.session_id', 's.title', 's.update_time', 'l.status as log_status', 'l.tracking_id'])
                ->order('s.update_time', 'desc')
                ->select()
                ->toArray();

            foreach ($staleRunning as $row) {
                $interrupted[] = [
                    'session_id'   => $row['session_id'] ?? '',
                    'title'        => $row['title'] ?? '',
                    'reason'       => ($row['log_status'] ?? 1) == 4 ? 'Task timed out' : 'Task appears stuck',
                    'tracking_id'  => $row['tracking_id'] ?? null,
                    'update_time'  => $row['update_time'] ?? '',
                ];
            }
        } catch (\Throwable $e) {
            ContextualLogger::warning("[WiseAgent] SessionManager::getInterruptedSessions() error: {$e->getMessage()}");
        }

        return $interrupted;
    }
}
