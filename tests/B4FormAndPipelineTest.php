<?php
declare(strict_types=1);

namespace wise\agent\test;

use PHPUnit\Framework\TestCase;
use wise\agent\form\FormGenerateTool;
use wise\agent\form\FormSchemaToolProvider;
use wise\agent\pipeline\PipelineStep;

/**
 * B4-4 / B4-5 回归
 *
 * - B4-4：确认 `form_generate` 确实已实现并会按开关注册（此前注释与代码不符，
 *   曾被误记为"未实现"）。
 * - B4-5：确认 `parallel` 字段能被正确解析与序列化（批次执行的入口条件）。
 */
class B4FormAndPipelineTest extends TestCase
{
    public function testFormGenerateToolIsRegisteredWhenEnabled(): void
    {
        $provider = new FormSchemaToolProvider([
            'enabled' => true,
            'tools'   => ['generate' => true],
        ]);

        $names = array_map(static fn($tool) => $tool->getName(), $provider->getTools());

        $this->assertContains('form_generate', $names, 'form_generate 应已实现并按开关注册');
    }

    public function testFormGenerateToolIsAbsentWhenDisabled(): void
    {
        $provider = new FormSchemaToolProvider([
            'enabled' => true,
            'tools'   => ['generate' => false],
        ]);

        $names = array_map(static fn($tool) => $tool->getName(), $provider->getTools());

        $this->assertNotContains('form_generate', $names);
    }

    public function testFormGenerateToolDefaultsToNonWriting(): void
    {
        $tool = new FormGenerateTool([]);

        $this->assertSame('never', $tool->writeMode());
    }

    public function testPipelineStepParsesParallelFlag(): void
    {
        $step = PipelineStep::fromArray([
            'skill'    => 'demo',
            'parallel' => true,
        ]);

        $this->assertTrue($step->parallel);
        $this->assertTrue($step->toArray()['parallel']);
    }

    public function testPipelineStepDefaultsToSerial(): void
    {
        $step = PipelineStep::fromArray(['skill' => 'demo']);

        $this->assertFalse($step->parallel, '未声明 parallel 时必须按串行处理');
    }

    public function testParallelFlagSurvivesRoundTrip(): void
    {
        $step = PipelineStep::fromArray([
            'skill'         => 'demo',
            'output_key'    => 'out',
            'on_error'      => 'skip',
            'parallel'      => true,
        ]);

        $restored = PipelineStep::fromArray($step->toArray());

        $this->assertTrue($restored->parallel);
        $this->assertSame('out', $restored->outputKey);
        $this->assertSame('skip', $restored->onError);
    }
}
