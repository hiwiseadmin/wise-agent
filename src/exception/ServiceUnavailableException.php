<?php
declare(strict_types=1);

namespace wise\agent\exception;

use wise\support\circuit\CircuitBreakerExceptionInterface;
use wise\support\exception\GeneralErrorCode;
use wise\support\exception\WiseException;

/**
 * 服务不可用异常（熔断触发）
 *
 * 当 wise-agent 的断路器处于 OPEN 状态时抛出，表示上游 AI 服务（OpenAI/DeepSeek）
 * 已被熔断保护拦截，短时间内不应继续发起请求。
 *
 * 携带 retry_after 秒数，供 API 层通过 JsonResponse::circuitResponse()
 * 映射为 HTTP 503 + Retry-After 头。
 *
 * 注：刻意不继承 think\exception\HttpException，避免在队列任务
 * （AiAgentJob/PipelineJob）中被框架按 HTTP 语义处理。
 *
 * 错误码：GeneralErrorCode::SERVICE_UNAVAILABLE (151)
 * HTTP：503
 */
class ServiceUnavailableException extends WiseException implements CircuitBreakerExceptionInterface
{
    /**
     * @var int 建议客户端重试的等待秒数（= 断路器冷却时间）
     */
    protected int $retryAfter;

    public function __construct(
        string $message = 'AI service is temporarily unavailable (circuit breaker open)',
        int $retryAfter = 60,
        string $serviceName = '',
        ?\Throwable $previous = null
    ) {
        $this->retryAfter = $retryAfter;
        $msg = $serviceName ? "{$message} [{$serviceName}]" : $message;
        parent::__construct(
            GeneralErrorCode::SERVICE_UNAVAILABLE->value,
            $msg,
            503,
            $previous,
            ['retry_after' => $retryAfter, 'service' => $serviceName],
        );
    }

    /**
     * 获取建议重试等待秒数
     */
    public function getRetryAfter(): int
    {
        return $this->retryAfter;
    }
}
