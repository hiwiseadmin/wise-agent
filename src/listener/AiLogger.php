<?php
declare(strict_types=1);

namespace wise\agent\listener;

use think\facade\Log;
use wise\agent\event\AgentComplete;
use wise\agent\event\AgentError;
use wise\agent\event\AgentStart;
use wise\agent\event\AgentStep;
use wise\agent\event\AiRequest;
use wise\agent\event\AiResponse;

/**
 * AI 日志监听器
 *
 * 为审计和调试记录所有 AI 事件。
 * 订阅：AgentStart、AgentStep、AgentComplete、AgentError、AiRequest、AiResponse
 */
class AiLogger
{
    protected bool $enabled;

    public function __construct()
    {
        $this->enabled = (bool) config('wise-agent.log.enabled', true);
    }

    /**
     * 处理 AI 事件
     *
     * @param object $event 事件对象
     */
    public function handle(object $event): void
    {
        if (!$this->enabled) {
            return;
        }

        $channel = config('wise-agent.log.channel', 'ai');

        if ($event instanceof AgentStart) {
            Log::channel($channel)->info("[AgentStart] {$event->agentType} | {$event->sessionId}", [
                'task' => mb_substr($event->task, 0, 200),
            ]);
        } elseif ($event instanceof AgentStep) {
            $hasToolCalls = !empty($event->toolCalls);
            Log::channel($channel)->debug("[AgentStep] {$event->sessionId} step={$event->step}", [
                'has_tool_calls' => $hasToolCalls,
                'tool_count'     => count($event->toolCalls ?? []),
            ]);
        } elseif ($event instanceof AgentComplete) {
            Log::channel($channel)->info("[AgentComplete] {$event->sessionId}", [
                'steps'        => $event->totalSteps,
                'tokens'       => $event->usage['total_tokens'] ?? 0,
                'duration_sec' => round($event->duration, 2),
            ]);
        } elseif ($event instanceof AgentError) {
            Log::channel($channel)->error("[AgentError] {$event->sessionId}: {$event->exception->getMessage()}", [
                'step' => $event->step,
            ]);
        } elseif ($event instanceof AiRequest) {
            Log::channel($channel)->debug("[AiRequest] {$event->provider}/{$event->model}", [
                'message_count' => count($event->messages),
                'tool_count'    => count($event->tools),
            ]);
        } elseif ($event instanceof AiResponse) {
            $tokens = $event->response['usage']['total_tokens'] ?? 0;
            Log::channel($channel)->debug("[AiResponse] {$event->provider}/{$event->model} tokens={$tokens}");
        }
    }
}
