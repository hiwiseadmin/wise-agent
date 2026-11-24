<?php
declare(strict_types=1);

namespace wise\agent\test;

use PHPUnit\Framework\TestCase;
use wise\agent\widget\AiWidgetInjector;

class AiWidgetInjectorTest extends TestCase
{
    public function testBuildGeneratesConfigForPluginsWithDeclarations(): void
    {
        $injector = new AiWidgetInjector(
            ['enabled' => true],
            fn () => [
                ['ident' => 'smartstock', 'has_schema' => true, 'has_actions' => true],
                ['ident' => 'cms', 'has_schema' => true, 'has_actions' => false],
            ]
        );

        $config = $injector->build();

        $this->assertArrayHasKey('smartstock', $config);
        $this->assertArrayHasKey('cms', $config);

        $this->assertTrue($config['smartstock']['assistant']);
        $this->assertTrue($config['smartstock']['list_filter']);
        $this->assertTrue($config['smartstock']['form_fill']);
        $this->assertTrue($config['smartstock']['detail_explain']);

        $this->assertTrue($config['cms']['assistant']);
        $this->assertTrue($config['cms']['form_fill']);
        $this->assertTrue($config['cms']['detail_explain']);
    }

    public function testBuildSkipsPluginsWithoutDeclarations(): void
    {
        $injector = new AiWidgetInjector(
            ['enabled' => true],
            fn () => [
                ['ident' => 'has_ai', 'has_schema' => true, 'has_actions' => false],
                ['ident' => 'no_ai', 'has_schema' => false, 'has_actions' => false],
            ]
        );

        $config = $injector->build();

        $this->assertArrayHasKey('has_ai', $config);
        $this->assertArrayNotHasKey('no_ai', $config, '无 AI 声明 ⇒ 不注入');
    }

    public function testBuildDisabledReturnsEmpty(): void
    {
        $injector = new AiWidgetInjector(
            ['enabled' => false],
            fn () => [['ident' => 'x', 'has_schema' => true, 'has_actions' => true]]
        );

        $this->assertSame([], $injector->build());
    }

    public function testListFilterEnabledByActionsWithoutSchema(): void
    {
        $injector = new AiWidgetInjector(
            ['enabled' => true],
            fn () => [['ident' => 'only_actions', 'has_schema' => false, 'has_actions' => true]]
        );

        $config = $injector->build();

        $this->assertTrue($config['only_actions']['list_filter'], '有 ai_actions 即有列表筛选');
        $this->assertFalse($config['only_actions']['form_fill'], '无 ai_schema 则无表单填充');
        $this->assertFalse($config['only_actions']['detail_explain'], '无 ai_schema 则无详情解读');
    }

    public function testWidgetSwitchesGateSpecificWidgets(): void
    {
        $injector = new AiWidgetInjector(
            ['enabled' => true, 'assistant' => false, 'form_fill' => false],
            fn () => [['ident' => 'x', 'has_schema' => true, 'has_actions' => true]]
        );

        $config = $injector->build();

        $this->assertFalse($config['x']['assistant'], 'assistant 开关关闭');
        $this->assertFalse($config['x']['form_fill'], 'form_fill 开关关闭');
        $this->assertTrue($config['x']['list_filter']);
    }
}
