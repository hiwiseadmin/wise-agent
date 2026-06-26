<?php
declare(strict_types=1);

namespace wise\agent\Exception;

/**
 * Tool execution exception
 */
class ToolException extends AiException
{
    protected string $errorCode = 'AI_TOOL_ERROR';

    public function __construct(string $message = '', int $code = 0, ?\Throwable $previous = null, array $data = [])
    {
        parent::__construct($message, $code, $previous, $data);
    }
}
