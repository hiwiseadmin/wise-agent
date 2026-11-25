<?php
declare(strict_types=1);

namespace wise\agent\client;

/**
 * AiClientInterface::supports() 的功能常量
 *
 * 提供有文档说明的常量用于功能查询，
 * 而非直接使用魔术字符串。调用 supports() 时请使用这些常量。
 *
 * 用法：
 *   if ($client->supports(Feature::TOOLS)) { ... }
 */
final class Feature
{
    /**
     * Function Calling / 工具使用支持
     */
    public const TOOLS = 'tools';

    /**
     * JSON 模式（结构化输出）
     */
    public const JSON_MODE = 'json_mode';

    /**
     * 流式（SSE）响应支持
     */
    public const STREAMING = 'streaming';

    /**
     * Function Calling（TOOLS 的别名，部分 Provider 使用不同术语）
     */
    public const FUNCTION_CALLING = 'function_calling';

    /**
     * 获取所有已知功能
     *
     * @return string[]
     */
    public static function all(): array
    {
        return [
            self::TOOLS,
            self::JSON_MODE,
            self::STREAMING,
            self::FUNCTION_CALLING,
        ];
    }
}
