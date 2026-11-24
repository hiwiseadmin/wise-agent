<?php
declare(strict_types=1);

namespace wise\agent\exception;

use wise\support\exception\ErrorCodeInterface;

/**
 * wise-agent 业务错误码（号段 80000~89999）
 *
 * @package wise\agent\exception
 */
enum ErrorCode: int implements ErrorCodeInterface
{
    /** 通用 AI 错误 */
    case AI_ERROR          = 80000;

    /** Provider 错误（API key 缺失、连接失败等） */
    case AI_PROVIDER_ERROR = 80001;

    /** 工具执行错误 */
    case AI_TOOL_ERROR     = 80002;

    /** Skill 执行错误 */
    case AI_SKILL_ERROR    = 80003;

    public function defaultMessage(): string
    {
        return match ($this) {
            self::AI_ERROR          => 'AI 处理失败',
            self::AI_PROVIDER_ERROR => 'AI 服务提供方错误',
            self::AI_TOOL_ERROR     => 'AI 工具执行失败',
            self::AI_SKILL_ERROR    => 'AI 技能执行失败',
        };
    }
}
