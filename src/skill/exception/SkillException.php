<?php
declare(strict_types=1);

namespace wise\agent\skill\exception;

use wise\agent\exception\AiException;
use wise\agent\exception\ErrorCode;

/**
 * Skill 执行异常
 *
 * 错误码：ErrorCode::AI_SKILL_ERROR (80003)
 */
class SkillException extends AiException
{
    protected string $skillName;

    public function __construct(string $message, string $skillName = '', int $code = 0, ?\Throwable $previous = null)
    {
        $this->skillName = $skillName;
        parent::__construct($message, $code, $previous, ['skill_name' => $skillName]);
    }

    public function getSkillName(): string
    {
        return $this->skillName;
    }

    protected function defaultCode(): int
    {
        return ErrorCode::AI_SKILL_ERROR->value;
    }
}
