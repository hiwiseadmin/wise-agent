<?php
declare(strict_types=1);

namespace wise\agent\test;

use PHPUnit\Framework\TestCase;
use wise\agent\support\MarkdownRenderer;

/**
 * 终端 Markdown 渲染测试
 *
 * 覆盖的是「模型实际会写、终端实际会难看」的那几类语法：
 * 标题、粗体（含被行内代码打断的粗体）、列表、表格（CJK 对齐）、代码块。
 */
class MarkdownRendererTest extends TestCase
{
    private function render(string $markdown, bool $ansi = false): string
    {
        return (new MarkdownRenderer($ansi))->render($markdown);
    }

    public function testHeadingIsRenderedWithRule(): void
    {
        $out = $this->render("# 标题\n\n正文");

        $this->assertStringNotContainsString('#', $out);
        $this->assertStringContainsString('标题', $out);
        $this->assertStringContainsString('─', $out);
    }

    public function testBoldIsStrippedInPlainMode(): void
    {
        $this->assertSame('结论：没有待执行的迁移。', $this->render('**结论**：没有待执行的迁移。'));
    }

    /**
     * 模型高频写法：粗体里包着行内代码。按反引号切段会拆散 `**`，必须仍然生效。
     */
    public function testBoldWrappingInlineCode(): void
    {
        $out = $this->render('在包中，**没有 `read` 档命令**，最低是 write。');

        $this->assertStringNotContainsString('**', $out);
        $this->assertStringNotContainsString('`', $out);
        $this->assertStringContainsString('没有 read 档命令', $out);
    }

    public function testAnsiModeAddsEscapes(): void
    {
        $out = $this->render('**粗体**', true);

        $this->assertStringContainsString("\033[1m", $out);
        $this->assertStringEndsWith("\033[0m", $out);
    }

    public function testUnorderedListUsesBullet(): void
    {
        $out = $this->render("- 第一项\n- 第二项");

        $this->assertStringContainsString('• 第一项', $out);
        $this->assertStringNotContainsString('- 第一项', $out);
    }

    /**
     * 表格必须按终端列宽（CJK 双宽）对齐，否则中文表格一眼就是歪的。
     */
    public function testTableColumnsAreWidthAligned(): void
    {
        $out   = $this->render("| 项目 | 数值 |\n|---|---|\n| 迁移路径 | 20 |\n| 待执行 | 0 |");
        $lines = explode("\n", $out);

        $widths = array_map(
            static fn(string $line): int => mb_strwidth($line, 'UTF-8'),
            array_values(array_filter($lines, static fn(string $l): bool => $l !== ''))
        );

        $this->assertCount(1, array_unique($widths), '表格每行可见宽度必须一致：' . $out);
    }

    public function testCodeBlockKeepsContentVerbatim(): void
    {
        $out = $this->render("```bash\nphp think migrate:run\n```");

        $this->assertStringContainsString('php think migrate:run', $out);
        $this->assertStringContainsString('bash', $out);
        $this->assertStringNotContainsString('```', $out);
    }

    public function testBlockquoteGetsBar(): void
    {
        $out = $this->render('> 高风险操作');

        $this->assertStringContainsString('│ 高风险操作', $out);
        $this->assertStringNotContainsString('> 高风险操作', $out);
    }

    public function testLinksKeepLabelAndUrl(): void
    {
        $out = $this->render('参考[文档](https://example.com/doc)。');

        $this->assertStringContainsString('文档', $out);
        $this->assertStringContainsString('https://example.com/doc', $out);
        $this->assertStringNotContainsString('](', $out);
    }

    public function testListContinuationIsIndented(): void
    {
        $out = $this->render("1. 第一步\n说明文字\n2. 第二步");

        $this->assertStringContainsString('   说明文字', $out);
    }
}
