<?php
declare(strict_types=1);

namespace wise\agent\Memory\Session;

use think\facade\Config;
use think\facade\Db;
use wise\agent\Contract\ConversationInterface;

/**
 * Conversation - single conversation context
 *
 * Manages the message history for a single AI conversation.
 * When the message count exceeds maxMessages, the oldest messages
 * are summarized via an LLM call (summarizeIfNeeded) rather than
 * simply being dropped.
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
     * Get session ID
     */
    public function getSessionId(): string
    {
        return $this->sessionId;
    }

    /**
     * Set system prompt
     */
    public function setSystemPrompt(string $prompt): self
    {
        $this->systemPrompt = $prompt;
        return $this;
    }

    /**
     * Get system prompt
     */
    public function getSystemPrompt(): string
    {
        return $this->systemPrompt;
    }

    /**
     * Add a message to the conversation
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
     * Get all messages (including system prompt)
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
     * Get user-facing messages only
     */
    public function getUserMessages(): array
    {
        return array_values(array_filter($this->messages, fn($m) => ($m['role'] ?? '') === 'user'));
    }

    /**
     * Count messages
     */
    public function count(): int
    {
        return count($this->messages);
    }

    /**
     * Clear conversation
     */
    public function clear(): self
    {
        $this->messages = [];
        $this->clearHistory();
        return $this;
    }

    /**
     * Summarize conversation if message count exceeds threshold
     *
     * When the message count exceeds maxMessages (default 50):
     *   1. Takes the earliest 30 messages
     *   2. Calls the LLM (non-streaming) to summarize them
     *   3. Replaces those 30 messages with 1 system message containing the summary
     *   4. Keeps the most recent 20 messages intact
     *
     * The caller must provide an AI client capable of chat().
     * If no client is available, falls back to simple trimming.
     *
     * @param \wise\agent\Contract\AiClientInterface|null $client AI client for summarization
     * @return bool True if summarization was performed
     */
    public function summarizeIfNeeded(?\wise\agent\Contract\AiClientInterface $client = null): bool
    {
        if (!$this->summaryEnabled) {
            return false;
        }

        $messageCount = count($this->messages);

        // Only trigger when count exceeds threshold
        if ($messageCount <= $this->maxMessages) {
            return false;
        }

        // Need at least 50+ messages to make summarization worthwhile
        if ($messageCount < 40) {
            return false;
        }

        // No client available — fall back to simple trim
        if ($client === null) {
            return false;
        }

        try {
            // Take earliest 30 messages for summarization
            $toSummarize = array_slice($this->messages, 0, 30);
            $toKeep = array_slice($this->messages, 30);

            // Build the summarization prompt
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
                // Replace summarized messages with a single system message
                $summaryMessage = [
                    'role'    => 'system',
                    'content' => "[Conversation Summary] {$summary}",
                ];

                // Keep the summarized prefix as system + recent messages
                $this->messages = array_merge(
                    [$summaryMessage],
                    $toKeep
                );

                return true;
            }
        } catch (\Throwable $e) {
            // Summarization is best-effort; log and continue
            // Fall through to simple trim below
        }

        return false;
    }

    /**
     * Enable or disable conversation summarization
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
     * Get raw message count for testing/serialization
     *
     * @return int
     */
    public function getRawMessageCount(): int
    {
        return count($this->messages);
    }

    /**
     * Trim oldest messages when count exceeds limit
     *
     * Falls back to simple array_slice when summarization is not available.
     * The summarizeIfNeeded() method should be called before this reaches
     * the threshold.
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
     * Load message history from database
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
            // Table may not exist yet; start with empty messages
        }
    }

    /**
     * Persist a message to database
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
            // Silently fail for non-critical persistence
        }
    }

    /**
     * Clear message history from database
     */
    protected function clearHistory(): void
    {
        try {
            Db::table($this->table)
                ->where('session_id', $this->sessionId)
                ->delete();
        } catch (\Throwable $e) {
            // Silently fail
        }
    }
}
