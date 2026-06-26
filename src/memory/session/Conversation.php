<?php
declare(strict_types=1);

namespace wise\agent\memory\session;

use think\facade\Config;
use think\facade\Db;
use wise\agent\contract\ConversationInterface;

/**
 * Conversation - 单次对话上下文
 *
 * 管理单次 AI 对话的消息历史记录。
 * 当消息数量超过 maxMessages 时，最旧的消息
 * 会通过 LLM 调用进行摘要（summarizeIfNeeded），
 * 而不是简单地丢弃。
 */
class Conversation implements ConversationInterface
{
    protected string $sessionId;
    protected array $messages = [];
    protected string $systemPrompt;
    protected int $maxMessages;
    protected string $table;
    protected bool $summaryEnabled = true;

    public function __construct(string $sessionId, array $options = [])
    {
        $this->sessionId    = $sessionId;
        $this->systemPrompt = $options['system_prompt'] ?? Config::get('wise-agent.agent.system_prompt', 'You are the WiseAdmin AI assistant.');
        $this->maxMessages  = $options['max_messages'] ?? 50;
        $this->table        = Config::get('wise-agent.tables.messages', 'ai_messages');
        $this->summaryEnabled = $options['enable_summary'] ?? true;

        $this->loadHistory();
    }

    /**
     * 获取会话 ID
     */
    public function getSessionId(): string
    {
        return $this->sessionId;
    }

    /**
     * 设置系统 prompt
     */
    public function setSystemPrompt(string $prompt): self
    {
        $this->systemPrompt = $prompt;
        return $this;
    }

    /**
     * 获取系统 prompt
     */
    public function getSystemPrompt(): string
    {
        return $this->systemPrompt;
    }

    /**
     * 向对话中添加一条消息
     */
    public function addMessage(string $role, ?string $content, ?array $toolCalls = null, ?string $toolCallId = null, ?string $toolName = null): self
    {
        $message = ['role' => $role];

        if ($content !== null) {
            $message['content'] = $content;
        }

        if ($toolCalls !== null) {
            $message['tool_calls'] = $toolCalls;
        }

        if ($toolCallId !== null) {
            $message['tool_call_id'] = $toolCallId;
        }

        if ($toolName !== null) {
            $message['name'] = $toolName;
        }

        $this->messages[] = $message;
        $this->persistMessage($role, $content, $toolCalls, $toolCallId, $toolName);
        $this->trimIfNeeded();

        return $this;
    }

    /**
     * 获取所有消息（包含系统 prompt）
     */
    public function getMessages(): array
    {
        $all = [];

        if (!empty($this->systemPrompt)) {
            $all[] = ['role' => 'system', 'content' => $this->systemPrompt];
        }

        return array_merge($all, $this->messages);
    }

    /**
     * 仅获取用户可见的消息
     */
    public function getUserMessages(): array
    {
        return array_values(array_filter($this->messages, fn($m) => ($m['role'] ?? '') === 'user'));
    }

    /**
     * 统计消息数量
     */
    public function count(): int
    {
        return count($this->messages);
    }

    /**
     * 清空对话
     */
    public function clear(): self
    {
        $this->messages = [];
        $this->clearHistory();
        return $this;
    }

    /**
     * 当消息数量超过阈值时对对话进行摘要
     *
     * 当消息数量超过 maxMessages（默认 50）时：
     *   1. 取最早的 30 条消息
     *   2. 调用 LLM（非流式）对其进行摘要
     *   3. 用 1 条包含摘要的系统消息替换这 30 条消息
     *   4. 保留最近的 20 条消息不动
     *
     * 调用方必须提供一个能够进行 chat() 的 AI 客户端。
     * 如果没有可用的客户端，则回退到简单的裁剪。
     *
     * @param \wise\agent\contract\AiClientInterface|null $client 用于摘要的 AI 客户端
     * @return bool 是否执行了摘要
     */
    public function summarizeIfNeeded(?\wise\agent\contract\AiClientInterface $client = null): bool
    {
        if (!$this->summaryEnabled) {
            return false;
        }

        $messageCount = count($this->messages);

        // 仅在超过阈值时触发
        if ($messageCount <= $this->maxMessages) {
            return false;
        }

        // 至少需要 40+ 条消息才值得进行摘要
        if ($messageCount < 40) {
            return false;
        }

        // 没有可用的客户端 — 回退到简单的裁剪
        if ($client === null) {
            return false;
        }

        try {
            // 取最早的 30 条消息用于摘要
            $toSummarize = array_slice($this->messages, 0, 30);
            $toKeep = array_slice($this->messages, 30);

            // 构建摘要 prompt
            $historyText = '';
            foreach ($toSummarize as $msg) {
                $role = $msg['role'] ?? 'unknown';
                $content = $msg['content'] ?? '';
                if (!empty($content)) {
                    $historyText .= "[{$role}]: {$content}\n";
                }
            }

            if (empty(trim($historyText))) {
                return false;
            }

            $summarizePrompt = "Please summarize the following conversation history in a concise paragraph, preserving key decisions, user preferences, and important context:\n\n{$historyText}";

            $response = $client->chat([
                [
                    'role'    => 'system',
                    'content' => 'You are a conversation summarizer. Create a concise summary that preserves all key information.'
                ],
                [
                    'role'    => 'user',
                    'content' => $summarizePrompt,
                ],
            ], [], ['max_tokens' => 500, 'temperature' => 0.3]);

            $summary = $response['content'] ?? '';

            if (!empty($summary)) {
                // 用一条系统消息替换已摘要的消息
                $summaryMessage = [
                    'role'    => 'system',
                    'content' => "[Conversation Summary] {$summary}",
                ];

                // 保留摘要前缀作为系统消息 + 最近的消息
                $this->messages = array_merge(
                    [$summaryMessage],
                    $toKeep
                );

                return true;
            }
        } catch (\Throwable $e) {
            // 摘要是尽力而为的；记录日志并继续
            // 回退到下面的简单裁剪
        }

        return false;
    }

    /**
     * 启用或禁用对话摘要
     *
     * @param bool $enabled
     * @return self
     */
    public function setSummaryEnabled(bool $enabled): self
    {
        $this->summaryEnabled = $enabled;
        return $this;
    }

    /**
     * 获取原始消息数量，用于测试/序列化
     *
     * @return int
     */
    public function getRawMessageCount(): int
    {
        return count($this->messages);
    }

    /**
     * 当消息数量超过限制时，裁剪最旧的消息
     *
     * 当摘要不可用时，回退到简单的 array_slice。
     * summarizeIfNeeded() 方法应在达到阈值之前被调用。
     */
    protected function trimIfNeeded(): void
    {
        if (count($this->messages) <= $this->maxMessages) {
            return;
        }

        $trimCount = count($this->messages) - $this->maxMessages;
        $this->messages = array_slice($this->messages, $trimCount);
    }

    /**
     * 从数据库加载消息历史记录
     */
    protected function loadHistory(): void
    {
        try {
            $rows = Db::table($this->table)
                ->where('session_id', $this->sessionId)
                ->order('id', 'asc')
                ->select()
                ->toArray();

            foreach ($rows as $row) {
                $message = ['role' => $row['role']];

                if (!empty($row['content'])) {
                    $message['content'] = $row['content'];
                }

                if (!empty($row['tool_calls'])) {
                    $calls = json_decode($row['tool_calls'], true);
                    if (is_array($calls)) {
                        $message['tool_calls'] = $calls;
                    }
                }

                if (!empty($row['tool_call_id'])) {
                    $message['tool_call_id'] = $row['tool_call_id'];
                }

                if (!empty($row['tool_name'])) {
                    $message['name'] = $row['tool_name'];
                }

                $this->messages[] = $message;
            }
        } catch (\Throwable $e) {
            // 表可能还不存在；以空消息列表开始
        }
    }

    /**
     * 将消息持久化到数据库
     */
    protected function persistMessage(string $role, ?string $content, ?array $toolCalls, ?string $toolCallId, ?string $toolName): void
    {
        try {
            Db::table($this->table)->insert([
                'session_id'   => $this->sessionId,
                'role'         => $role,
                'content'      => $content,
                'tool_calls'   => $toolCalls ? json_encode($toolCalls, JSON_UNESCAPED_UNICODE) : null,
                'tool_call_id' => $toolCallId,
                'tool_name'    => $toolName,
                'create_time'   => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // 非关键持久化，静默失败
        }
    }

    /**
     * 从数据库清空消息历史记录
     */
    protected function clearHistory(): void
    {
        try {
            Db::table($this->table)
                ->where('session_id', $this->sessionId)
                ->delete();
        } catch (\Throwable $e) {
            // 静默失败
        }
    }
}
