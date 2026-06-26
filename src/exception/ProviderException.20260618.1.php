<?php
declare(strict_types=1);

namespace wise\agent\Exception;

/**
 * Provider-related exception (API key missing, connection error, etc.)
 */
class ProviderException extends AiException
{
    protected string $errorCode = 'AI_PROVIDER_ERROR';

    public function __construct(string $message = '', int $code = 0, ?\Throwable $previous = null, array $data = [])
    {
        parent::__construct($message, $code, $previous, $data);
    }
}
