<?php
declare(strict_types=1);

namespace wise\agent\exception;

/**
 * Provider 相关异常（API key 缺失、连接错误等）
 *
 * 错误码：ErrorCode::AI_PROVIDER_ERROR (80001)
 */
class ProviderException extends AiException
{
    protected function defaultCode(): int
    {
        return ErrorCode::AI_PROVIDER_ERROR->value;
    }
}
