<?php
declare(strict_types=1);

namespace wise\agent\Client;

/**
 * Feature constants for AiClientInterface::supports()
 *
 * Provides documented constants for feature queries instead of
 * magic strings. Use these constants when calling supports().
 *
 * Usage:
 *   if ($client->supports(Feature::TOOLS)) { ... }
 */
final class Feature
{
    /**
     * Function Calling / Tool use support
     */
    public const TOOLS = 'tools';

    /**
     * JSON mode (structured output)
     */
    public const JSON_MODE = 'json_mode';

    /**
     * Streaming (SSE) response support
     */
    public const STREAMING = 'streaming';

    /**
     * Function calling (alias for TOOLS, some providers use different terminology)
     */
    public const FUNCTION_CALLING = 'function_calling';

    /**
     * Get all known features
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
