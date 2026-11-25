<?php
declare(strict_types=1);

namespace wise\agent\exception;

use wise\support\exception\WiseException;

/**
 * AI 基础异常类
 *
 * 错误码：ErrorCode::AI_ERROR (80000)，子类通过 defaultCode() 覆盖
 * HTTP：$code 参数保留 HTTP 码覆盖语义（0=用 $statusCode 默认 500）
 */
class AiException extends WiseException
{
    protected int $statusCode = 500;

    /**
     * @param string          $message  异常消息
     * @param int             $code     HTTP 状态码覆盖（0=默认 $statusCode）
     * @param \Throwable|null $previous 前置异常
     * @param array           $data     附加数据（上下文）
     */
    public function __construct(string $message = '', int $code = 0, ?\Throwable $previous = null, array $data = [])
    {
        $httpCode = $code > 0 ? $code : $this->statusCode;
        parent::__construct($this->defaultCode(), $message, $httpCode, $previous, $data);
    }

    /**
     * 业务错误码（子类覆盖以定制）
     */
    protected function defaultCode(): int
    {
        return ErrorCode::AI_ERROR->value;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getData(): array
    {
        return $this->getContext();
    }
}
