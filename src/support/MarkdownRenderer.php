<?php
declare(strict_types=1);

namespace wise\agent\support;

/**
 * 终端 Markdown 渲染器（CLI 用）
 *
 * 大模型默认输出 Markdown，直接 `echo` 到控制台会满屏 `**`、`|`、``` 之类的噪声。
 * 本类把常用子集渲染成**可读的终端排版**：
 *
 *   - 标题：# / ## / ### → 加粗（h1 额外加下划线 + 分隔线）
 *   - 强调：**粗体**、*斜体*、~~删除线~~、`行内代码`、[链接](url)
 *   - 列表：- / * / + → •（保留缩进，支持嵌套）；有序列表保留序号
 *   - 引用：> → 左侧竖线
 *   - 代码块：``` / ~~~ → 上下分隔线包住，内容原样输出（可复制）
 *   - 表格：CJK 宽字符对齐（mb_strwidth），支持 :---: 对齐语法
 *   - 分隔线：--- / *** → 一条横线
 *
 * 设计要点：
 *   1. **纯函数、零依赖**：不读容器、不读配置，便于单独测试；
 *   2. `$ansi=false` 时输出纯文本（结构仍然保留：表格对齐、缩进、分隔线），
 *      用于管道 / 重定向 / `--no-ansi` 场景；
 *   3. 只做「展示」，不做 HTML 转义：调用方负责把 `<` 转义掉（think 的
 *      Output 会把 `<xxx>` 当样式标签解析，见 Chat::printReply()）。
 */
final class MarkdownRenderer
{
    /** @var array<string, string> 语义样式名 → SGR 参数 */
    private const STYLES = [
        'h1'       => '1;4',
        'h2'       => '1;36',
        'h3'       => '1',
        'bold'     => '1',
        'italic'   => '3',
        'strike'   => '9',
        'code'     => '36',
        'link'     => '4;36',
        'dim'      => '2',
        'quote'    => '2;3',
    ];

    public function __construct(private readonly bool $ansi = true)
    {
    }

    /**
     * 渲染整段 Markdown
     */
    public function render(string $markdown): string
    {
        $markdown = str_replace(["\r\n", "\r"], "\n", $markdown);
        $lines    = explode("\n", $markdown);
        $total    = count($lines);

        $out = [];

        $listPad = 0;

        for ($i = 0; $i < $total; $i++) {
            $line = rtrim($lines[$i]);

            if (trim($line) === '') {
                $listPad = 0;
                $out[]   = '';
                continue;
            }

            if (preg_match('/^\s*(`{3,}|~{3,})\s*([A-Za-z0-9_+#.-]*)\s*$/', $line, $m) === 1) {
                $fence = $m[1];
                $lang  = $m[2];
                $code  = [];
                $i++;
                while ($i < $total && !str_starts_with(ltrim($lines[$i]), $fence)) {
                    $code[] = rtrim($lines[$i]);
                    $i++;
                }
                foreach ($this->renderCodeBlock($code, $lang) as $renderLine) {
                    $out[] = $renderLine;
                }
                $listPad = 0;
                continue;
            }

            if (str_contains($line, '|')
                && $i + 1 < $total
                && preg_match('/^\s*\|?[\s:|-]*-[\s:|-]*\|?\s*$/', $lines[$i + 1]) === 1
                && str_contains($lines[$i + 1], '-')
            ) {
                $header  = $this->splitRow($line);
                $aligns  = $this->parseAlign($this->splitRow($lines[$i + 1]));
                $i += 2;
                $rows = [];
                while ($i < $total && str_contains($lines[$i], '|') && trim($lines[$i]) !== '') {
                    $rows[] = $this->splitRow($lines[$i]);
                    $i++;
                }
                $i--;
                foreach ($this->renderTable($header, $rows, $aligns) as $renderLine) {
                    $out[] = $renderLine;
                }
                $listPad = 0;
                continue;
            }

            if (preg_match('/^\s{0,3}([-*_])(\s*\1){2,}\s*$/', $line) === 1) {
                $out[]   = $this->style(str_repeat('─', 48), 'dim');
                $listPad = 0;
                continue;
            }

            if (preg_match('/^\s{0,3}(#{1,6})\s+(.*?)\s*#*\s*$/', $line, $m) === 1) {
                $level = strlen($m[1]);
                $text  = $this->inline($m[2]);
                if ($out !== [] && end($out) !== '') {
                    $out[] = '';
                }
                if ($level === 1) {
                    $out[] = $this->style($text, 'h1');
                    $out[] = $this->style(str_repeat('─', min(48, max(8, $this->visibleWidth($text)))), 'dim');
                } else {
                    $out[] = $this->style($text, $level === 2 ? 'h2' : 'h3');
                }
                $out[]   = '';
                $listPad = 0;
                continue;
            }

            if (preg_match('/^\s{0,3}>\s?(.*)$/', $line, $m) === 1) {
                $out[]   = $this->style('│ ', 'dim') . $this->style($this->inline($m[1]), 'quote');
                $listPad = 0;
                continue;
            }

            if (preg_match('/^(\s*)[-*+]\s+(.*)$/', $line, $m) === 1) {
                $listPad = mb_strwidth($m[1], 'UTF-8') + 2;
                $out[]   = $m[1] . $this->style('•', 'dim') . ' ' . $this->inline($m[2]);
                continue;
            }

            if (preg_match('/^(\s*)(\d{1,9})[.)]\s+(.*)$/', $line, $m) === 1) {
                $listPad = mb_strwidth($m[1], 'UTF-8') + mb_strwidth($m[2] . '.', 'UTF-8') + 1;
                $out[]   = $m[1] . $this->style($m[2] . '.', 'dim') . ' ' . $this->inline($m[3]);
                continue;
            }

            $out[] = ($listPad > 0 && $line === ltrim($line))
                ? str_repeat(' ', $listPad) . $this->inline($line)
                : $this->inline($line);
        }

        return $this->tidy($out);
    }

    /**
     * 代码块：上下分隔线 + 原样内容（可复制，不加行首装饰字符）
     *
     * @param string[] $code
     * @return string[]
     */
    private function renderCodeBlock(array $code, string $lang): array
    {
        $width = 32;
        foreach ($code as $codeLine) {
            $width = max($width, min(60, $this->visibleWidth($codeLine)));
        }

        $label = $lang !== '' ? ' ' . $lang . ' ' : '';
        $top = '──' . str_repeat('─', max(0, $width - mb_strwidth($label, 'UTF-8'))) . $label;

        $out   = [$this->style($top, 'dim')];
        foreach ($code as $codeLine) {
            $out[] = $this->style($codeLine, 'dim');
        }
        $out[] = $this->style(str_repeat('─', $width + 2), 'dim');

        return $out;
    }

    /**
     * 表格：ASCII 边框 + CJK 对齐（终端里 ambiguous 宽字符不可控，故不用 box-drawing）
     *
     * @param string[] $header
     * @param array<int, string[]> $rows
     * @param string[] $aligns  left|center|right
     * @return string[]
     */
    private function renderTable(array $header, array $rows, array $aligns): array
    {
        $cols   = max(1, count($header));
        $widths = array_fill(0, $cols, 0);

        $measure = function (array $cells) use (&$widths): void {
            foreach ($cells as $index => $cell) {
                if (!isset($widths[$index])) {
                    $widths[$index] = 0;
                }
                $widths[$index] = max($widths[$index], $this->visibleWidth($cell));
            }
        };

        $header = array_map(fn($cell) => $this->inline((string) $cell), $header);
        $measure($header);

        $rendered = [];
        foreach ($rows as $row) {
            $cells = [];
            for ($c = 0; $c < $cols; $c++) {
                $cells[] = $this->inline((string) ($row[$c] ?? ''));
            }
            $measure($cells);
            $rendered[] = $cells;
        }

        foreach ($widths as $index => $width) {
            $widths[$index] = max(1, $width);
        }

        $border = '+' . implode('+', array_map(fn(int $w): string => str_repeat('-', $w + 2), $widths)) . '+';

        $line = function (array $cells) use ($widths, $aligns): string {
            $parts = [];
            foreach ($widths as $index => $width) {
                $cell  = $cells[$index] ?? '';
                $align = $aligns[$index] ?? 'left';
                $parts[] = ' ' . $this->pad($cell, $width, $align) . ' ';
            }
            return '|' . implode('|', $parts) . '|';
        };

        $out   = [$this->style($border, 'dim')];
        $out[] = $line($header);
        $out[] = $this->style($border, 'dim');
        foreach ($rendered as $cells) {
            $out[] = $line($cells);
        }
        $out[] = $this->style($border, 'dim');

        return $out;
    }

    /**
     * 按可见宽度补空格（含 ANSI 的单元格用可见宽度算，不能用 strlen）
     */
    private function pad(string $text, int $width, string $align): string
    {
        $gap = $width - $this->visibleWidth($text);
        if ($gap <= 0) {
            return $text;
        }

        return match ($align) {
            'right'  => str_repeat(' ', $gap) . $text,
            'center' => str_repeat(' ', intdiv($gap, 2)) . $text . str_repeat(' ', $gap - intdiv($gap, 2)),
            default  => $text . str_repeat(' ', $gap),
        };
    }

    /**
     * 拆分表格行（支持 \| 转义）
     *
     * @return string[]
     */
    private function splitRow(string $line): array
    {
        $line = trim($line);
        $line = (string) preg_replace('/^\|/', '', $line);
        $line = (string) preg_replace('/\|$/', '', $line);

        $cells = preg_split('/(?<!\\\\)\|/', $line) ?: [];

        return array_map(
            static fn(string $cell): string => trim(str_replace('\\|', '|', $cell)),
            $cells
        );
    }

    /**
     * 解析分隔行的对齐方式
     *
     * @param string[] $cells
     * @return string[]
     */
    private function parseAlign(array $cells): array
    {
        $aligns = [];
        foreach ($cells as $cell) {
            $cell = trim($cell);
            $left  = str_starts_with($cell, ':');
            $right = str_ends_with($cell, ':');
            $aligns[] = $left && $right ? 'center' : ($right ? 'right' : 'left');
        }

        return $aligns;
    }

    /**
     * 行内格式：`code` / **粗体** / *斜体* / ~~删除~~ / [文本](url)
     */
    private function inline(string $text): string
    {
        if ($text === '') {
            return '';
        }

        $codes = [];
        $withPlaceholders = (string) preg_replace_callback(
            '/`+([^`]*)`+/',
            function (array $m) use (&$codes): string {
                $key         = "\x1A" . count($codes) . "\x1A";
                $codes[$key] = $this->style(trim($m[1]), 'code');
                return $key;
            },
            $text
        );

        $rendered = $this->inlineText($withPlaceholders);

        return $codes === [] ? $rendered : strtr($rendered, $codes);
    }

    private function inlineText(string $text): string
    {
        $text = (string) preg_replace_callback(
            '/!?\[([^\]]*)\]\(([^)\s]+)(?:\s+"[^"]*")?\)/',
            function (array $m): string {
                $label = $m[1] !== '' ? $m[1] : $m[2];
                $out   = $this->style($label, 'link');
                if ($m[1] !== '' && $m[1] !== $m[2]) {
                    $out .= $this->style(' (' . $m[2] . ')', 'dim');
                }
                return $out;
            },
            $text
        );

        $text = (string) preg_replace('/\*\*(?!\s)(.+?)(?<!\s)\*\*/s', $this->wrap('$1', 'bold'), $text);
        $text = (string) preg_replace('/__(?!\s)(.+?)(?<!\s)__/s', $this->wrap('$1', 'bold'), $text);

        $text = (string) preg_replace('/~~(.+?)~~/s', $this->wrap('$1', 'strike'), $text);

        $text = (string) preg_replace('/(?<![\*\w])\*(?!\s)([^*\n]+?)(?<!\s)\*(?!\*)/', $this->wrap('$1', 'italic'), $text);
        $text = (string) preg_replace('/(?<![\w_])_(?!\s)([^_\n]+?)(?<!\s)_(?![\w_])/', $this->wrap('$1', 'italic'), $text);

        $text = (string) preg_replace('/<\/?[A-Za-z][A-Za-z0-9-]*(\s[^<>]*)?\/?>/', '', $text);

        return $text;
    }

    /**
     * 生成 preg_replace 的替换模板（含 SGR 时 $1 前的 \ 需转义）
     */
    private function wrap(string $replacement, string $styleName): string
    {
        $code = self::STYLES[$styleName] ?? '';

        if (!$this->ansi || $code === '') {
            return $replacement;
        }

        return "\033[" . $code . 'm' . $replacement . "\033[0m";
    }

    private function style(string $text, string $styleName): string
    {
        $code = self::STYLES[$styleName] ?? '';

        if (!$this->ansi || $code === '' || $text === '') {
            return $text;
        }

        return "\033[" . $code . 'm' . $text . "\033[0m";
    }

    /**
     * 可见宽度（去掉 ANSI 后按 CJK 双宽计算）
     */
    private function visibleWidth(string $text): int
    {
        $plain = (string) preg_replace('/\033\[[0-9;]*m/', '', $text);

        return mb_strwidth($plain, 'UTF-8');
    }

    /**
     * 收尾：合并多余空行、去掉首尾空行
     *
     * @param string[] $lines
     */
    private function tidy(array $lines): string
    {
        $out      = [];
        $previous = null;

        foreach ($lines as $line) {
            $isBlank = trim((string) preg_replace('/\033\[[0-9;]*m/', '', $line)) === '';
            if ($isBlank && $previous === true) {
                continue;
            }
            $out[]    = $isBlank ? '' : $line;
            $previous = $isBlank;
        }

        while ($out !== [] && end($out) === '') {
            array_pop($out);
        }
        while ($out !== [] && $out[0] === '') {
            array_shift($out);
        }

        return implode("\n", $out);
    }
}
