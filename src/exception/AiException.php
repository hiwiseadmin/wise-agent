<?php
declare(strict_types=1);

namespace wise\agent\exception;

/**
 * AI 基础异常类
 */
class AiException extends \RuntimeException
{
    protected int $statusCode = 500;
    protected string $errorCode = 'AI_ERROR';
    protected array $data = [];

    public function __construct(string $message = '', int $code = 0, ?\Throwable $previous = null, array $data = [])
    {
        parent::__construct($message, $code, $previous);
        $this->data = $data;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getData(): array
    {
        return $this->data;
    }

    public function toArray(): array
    {
        return [
            'code'    => $this->statusCode,
            'error'   => $this->errorCode,
            'message' => $this->getMessage(),
            'data'    => $this->data,
        ];
    }
}
