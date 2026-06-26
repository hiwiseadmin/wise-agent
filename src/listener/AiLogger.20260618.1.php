<?php
declare(strict_types=1);

namespace wise\agent\Listener;

use think\facade\Log;
use wise\agent\Event\AgentComplete;
use wise\agent\Event\AgentError;
use wise\agent\Event\AgentStart;
use wise\agent\Event\AgentStep;
use wise\agent\Event\AiRequest;
use wise\agent\Event\AiResponse;

/**
 * AI logger listener
 *
 * Logs all AI events for auditing and debugging.
 * Subscribes to: AgentStart, AgentStep, AgentComplete, AgentError, AiRequest, AiResponse
 */
class AiLogger
{
    protected bool $enabled;

    public function __construct()
    {
        $this->enabled = (bool) config('wise-agent.log.enabled', true);
    }

    /**
     * Handle an AI event
     *
     * @param object $event The event object
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
