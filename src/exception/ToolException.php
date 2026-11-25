<?php
declare(strict_types=1);

namespace wise\agent\exception;

/**
 * 工具执行异常
 *
 * 错误码：ErrorCode::AI_TOOL_ERROR (80002)
 */
class ToolException extends AiException
{
    protected function defaultCode(): int
    {
        return ErrorCode::AI_TOOL_ERROR->value;
    }
}
