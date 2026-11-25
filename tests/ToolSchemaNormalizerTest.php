<?php
declare(strict_types=1);

namespace wise\agent\test;

use PHPUnit\Framework\TestCase;
use wise\agent\support\ToolSchemaNormalizer;

/**
 * Function Calling schema 归一化测试
 *
 * 背景：无参工具的 `properties` 若是空数组，`json_encode()` 会输出 `[]`，
 * OpenAI / DeepSeek 要求 object，会以 400 拒绝**整批工具**。
 */
class ToolSchemaNormalizerTest extends TestCase
{
    public function testEmptyPropertiesBecomesObject(): void
    {
        $parameters = ToolSchemaNormalizer::parameters(['type' => 'object', 'properties' => []]);

        $this->assertInstanceOf(\stdClass::class, $parameters['properties']);
        $this->assertStringContainsString('"properties":{}', (string) json_encode($parameters));
    }

    public function testMissingParametersBecomesEmptyObjectSchema(): void
    {
        $parameters = ToolSchemaNormalizer::parameters(null);

        $this->assertSame('object', $parameters['type']);
        $this->assertStringContainsString('"properties":{}', (string) json_encode($parameters));
    }

    public function testNonEmptyPropertiesUntouched(): void
    {
        $original = [
            'type'       => 'object',
            'properties' => ['query' => ['type' => 'string']],
            'required'   => ['query'],
        ];

        $this->assertSame($original, ToolSchemaNormalizer::parameters($original));
    }

    public function testToolsNormalizesEachFunction(): void
    {
        $tools = ToolSchemaNormalizer::tools([
            ['type' => 'function', 'function' => ['name' => 'noop', 'parameters' => ['type' => 'object', 'properties' => []]]],
            ['type' => 'function', 'function' => ['name' => 'echo', 'parameters' => ['type' => 'object', 'properties' => ['text' => ['type' => 'string']]]]],
        ]);

        $this->assertStringContainsString('"properties":{}', (string) json_encode($tools[0]));
        $this->assertStringContainsString('"text"', (string) json_encode($tools[1]));
        $this->assertSame('noop', $tools[0]['function']['name']);
    }
}
