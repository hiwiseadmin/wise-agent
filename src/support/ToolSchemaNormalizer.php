<?php
declare(strict_types=1);

namespace wise\agent\support;

/**
 * Function Calling schema 归一化（单一事实来源）
 *
 * 无参数工具的 Schema 形如 `['type' => 'object', 'properties' => []]`。
 * PHP 的 `json_encode()` 会把空数组编码成 `[]`，而 OpenAI / DeepSeek 要求
 * `parameters.properties` 是 **object**，于是整次请求被 400 拒绝：
 *   `Invalid schema for function 'xxx': [] is not of type "object"`
 * ——注意失败的是**整批工具**：只要有一个无参工具，其它工具也一起不可用。
 *
 * 因此「真正下发给 Provider 之前」必须把空 properties 转成 `stdClass`（编码为 `{}`）。
 * 这个转换同时被两处使用，避免各自实现而漂移：
 *   - {@see \wise\agent\client\OpenAiClient::formatTools()}：真实请求
 *   - {@see \wise\agent\agent\AgentManager::preview()}：`--dry-run` 预览
 */
final class ToolSchemaNormalizer
{
    /**
     * 归一化单个 function 的 parameters
     *
     * @param mixed $parameters 原始 Schema（通常是数组；缺失时为 null）
     */
    public static function parameters(mixed $parameters): array
    {
        if (!is_array($parameters) || $parameters === []) {
            return ['type' => 'object', 'properties' => new \stdClass()];
        }

        if (array_key_exists('properties', $parameters)
            && is_array($parameters['properties'])
            && $parameters['properties'] === []) {
            $parameters['properties'] = new \stdClass();
        }

        return $parameters;
    }

    /**
     * 归一化一批工具定义（ToolRegistry::getToolSchemas() 的产物）
     *
     * @param array<int, array<string, mixed>> $tools
     * @return array<int, array<string, mixed>>
     */
    public static function tools(array $tools): array
    {
        foreach ($tools as $index => $tool) {
            if (!isset($tool['function']) || !is_array($tool['function'])) {
                continue;
            }

            $tools[$index]['function']['parameters'] = self::parameters(
                $tool['function']['parameters'] ?? []
            );
        }

        return $tools;
    }
}
