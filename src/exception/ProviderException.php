<?php
declare(strict_types=1);

namespace wise\agent\exception;

/**
 * Provider 相关异常（API key 缺失、连接错误等）
 */
class ProviderException extends AiException
{
    protected string $errorCode = 'AI_PROVIDER_ERROR';

    public function __construct(string $message = '', int $code = 0, ?\Throwable $previous = null, array $data = [])
    {
        parent::__construct($message, $code, $previous, $data);
    }
}
