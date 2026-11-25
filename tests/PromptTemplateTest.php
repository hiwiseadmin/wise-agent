<?php
declare(strict_types=1);

namespace wise\agent\test;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use wise\agent\prompt\PromptTemplate;
use wise\agent\prompt\PromptTemplateManager;

/**
 * B4-7：Prompt 模板（占位符 + 角色绑定）
 */
class PromptTemplateTest extends TestCase
{
    public function testInterpolatesPlaceholders(): void
    {
        $template = PromptTemplate::fromArray('t', [
            'system'    => '你是 {{product}} 的助手，用 {{ language }} 回答。',
            'variables' => [],
        ]);

        $system = $template->renderSystem(['product' => 'WiseAdmin', 'language' => '中文']);

        $this->assertSame('你是 WiseAdmin 的助手，用 中文 回答。', $system);
    }

    public function testDefaultValueIsUsedWhenVariableMissing(): void
    {
        $template = PromptTemplate::fromArray('t', [
            'system'    => '产品：{{product}}',
            'variables' => ['product' => ['default' => '本系统']],
        ]);

        $this->assertSame('产品：本系统', $template->renderSystem());
    }

    public function testRequiredVariableMissingThrows(): void
    {
        $template = PromptTemplate::fromArray('t', [
            'system'    => '用户：{{name}}',
            'variables' => ['name' => ['required' => true]],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/missing required variable/i');

        $template->render();
    }

    public function testMissingDetectsRequiredWithoutRendering(): void
    {
        $template = PromptTemplate::fromArray('t', [
            'system'    => '{{a}} {{b}}',
            'variables' => [
                'a' => ['required' => true],
                'b' => ['default' => 'x'],
            ],
        ]);

        $this->assertSame(['a'], $template->missing());
        $this->assertSame([], $template->missing(['a' => 'v']));
    }

    public function testUndeclaredPlaceholderIsLeftIntact(): void
    {
        $template = PromptTemplate::fromArray('t', ['system' => '值：{{unknown}}']);

        $this->assertSame('值：{{unknown}}', $template->renderSystem());
    }

    public function testRoleBoundMessagesArePreserved(): void
    {
        $template = PromptTemplate::fromArray('t', [
            'messages' => [
                ['role' => 'system', 'content' => 'sys'],
                ['role' => 'user', 'content' => '示例问题'],
                ['role' => 'assistant', 'content' => '示例回答'],
            ],
        ]);

        $messages = $template->render();

        $this->assertCount(3, $messages);
        $this->assertSame(['system', 'user', 'assistant'], array_column($messages, 'role'));
    }

    public function testManagerShipsBuiltinTemplates(): void
    {
        $manager = new PromptTemplateManager();

        $this->assertTrue($manager->has('chat.system'));
        $this->assertTrue($manager->has('chat.tool_guard'));
        $this->assertNotEmpty($manager->renderSystem('chat.system'));
    }

    public function testManagerConfigOverridesBuiltin(): void
    {
        $manager = new PromptTemplateManager([
            'chat.system' => ['system' => '来自配置的 prompt'],
        ]);

        $this->assertSame('来自配置的 prompt', $manager->renderSystem('chat.system'));
    }

    public function testUnknownTemplateThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new PromptTemplateManager())->render('nope');
    }

    public function testRuntimeRegistration(): void
    {
        $manager = new PromptTemplateManager();
        $manager->register(PromptTemplate::fromArray('plugin.demo', ['system' => '插件模板']));

        $this->assertTrue($manager->has('plugin.demo'));
        $this->assertSame('插件模板', $manager->renderSystem('plugin.demo'));
    }

    public function testCatalogListsMetadata(): void
    {
        $catalog = (new PromptTemplateManager())->catalog();

        $ids = array_column($catalog, 'id');

        $this->assertContains('chat.system', $ids);
        $this->assertSame($ids, array_values(array_unique($ids)));
    }
}
