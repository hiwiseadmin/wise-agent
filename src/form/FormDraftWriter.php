<?php
declare(strict_types=1);

namespace wise\agent\form;

use wise\support\context\ContextualLogger;

/**
 * 草案写盘器／）
 *
 * `defineForm()` 是**校验规则与字段可写性的来源**，改它 = 改未来的权限与校验语义，
 * 影响面比改一条业务数据更大。因此写盘必须同时满足 6 项前置条件：
 *
 * | # | 条件 | 落点 |
 * |---|---|---|
 * | ① | 配置显式开启 | `form_schema.generate.write_mode = assisted` |
 * | ② | 目标文件命中白名单 | `generate.allowed_paths`（相对应用根） |
 * | ③ | 需要确认 | `generate.require_confirmation` ⇒ 调用方必须显式 `confirm:true` |
 * | ④ | 落审计（AI 标记） | {@see self::audit()}（默认写 AI 标记日志；可被审计包/插件覆写） |
 * | ⑤ | 写后语法检查，失败即回滚 | `php -l` 失败 ⇒ 用备份还原 |
 * | ⑥ | **仅改 `defineForm()` 段** | 大括号扫描定位方法体，其余字节一个不动 |
 *
 * 另有两条硬规则：
 * - **草案必须先通过三道自检**（{@see FormDraft::valid()}），否则拒绝写盘；
 * - 目标文件里**必须已存在** `defineForm()`（找不到就拒绝 —— 不为「新建控制器」开这个口子，
 *   那是 `php think wise:crud` 的职责）。
 */
class FormDraftWriter
{
    /** 只出草案，不写盘（默认） */
    public const MODE_NEVER = 'never';

    /** 满足全部前置条件才写盘 */
    public const MODE_ASSISTED = 'assisted';

    public const REFUSE_MODE            = 'write_mode_not_assisted';
    public const REFUSE_PATH            = 'path_not_allowed';
    public const REFUSE_CONFIRM         = 'confirmation_required';
    public const REFUSE_INVALID_DRAFT   = 'draft_not_valid';
    public const REFUSE_TARGET_MISSING  = 'target_file_missing';
    public const REFUSE_NO_DEFINE_FORM  = 'define_form_not_found';
    public const REFUSE_LINT            = 'syntax_check_failed';
    public const REFUSE_WRITE           = 'write_failed';

    /** @var array<string, mixed> */
    private array $options;

    private string $rootPath;

    /**
     * @param array<string, mixed> $options `generate.*` 覆盖项
     * @param string               $rootPath 应用根目录（默认容器根；离线测试须显式传入）
     */
    public function __construct(array $options = [], string $rootPath = '')
    {
        $this->options  = $options;
        $this->rootPath = $rootPath !== '' ? $rootPath : $this->detectRootPath();
    }

    /**
     * 写盘（返回同核结果，**不抛异常**）
     *
     * @return array{ok:bool, written:bool, refused:?string, reason:string, file:string, backup:?string, bytes:int, audit:array<string,mixed>, notes:array<int,array{code:string,message:string}>}
     */
    public function write(FormDraft $draft, string $targetFile, bool $confirmed = false, string $target = ''): array
    {
        $notes = [];
        $file  = $this->resolvePath($targetFile);

        $refuse = function (string $code, string $reason) use ($file, $target): array {
            $audit = $this->audit('form_generate.write_refused', [
                'target' => $target,
                'file'   => $file,
                'reason' => $code,
            ]);

            return [
                'ok'      => false,
                'written' => false,
                'refused' => $code,
                'reason'  => $reason,
                'file'    => $file,
                'backup'  => null,
                'bytes'   => 0,
                'audit'   => $audit,
                'notes'   => [],
            ];
        };

        if ($this->mode() !== self::MODE_ASSISTED) {
            return $refuse(self::REFUSE_MODE, sprintf(
                '当前 write_mode=%s；写盘需要显式配置 form_schema.generate.write_mode=assisted',
                $this->mode()
            ));
        }

        if (!$this->pathAllowed($file)) {
            return $refuse(self::REFUSE_PATH, sprintf(
                '目标文件不在 generate.allowed_paths 白名单内：%s（当前白名单：%s）',
                $file,
                implode(', ', $this->allowedPaths()) ?: '（空）'
            ));
        }

        if ((bool) $this->opt('require_confirmation', true) && !$confirmed) {
            return $refuse(self::REFUSE_CONFIRM, '需要人工确认：请带 confirm=true 重新调用（草案本身已回传，可先复核）');
        }

        if (!$draft->valid()) {
            return $refuse(self::REFUSE_INVALID_DRAFT, '草案未通过三道自检（类型合法性 / 可实例化 / 规则可生成），拒绝写盘');
        }

        if (!is_file($file)) {
            return $refuse(self::REFUSE_TARGET_MISSING, '目标文件不存在：本工具只为**已存在**的控制器改 defineForm()，新建控制器请用 php think wise:crud');
        }

        $source = (string) file_get_contents($file);

        $patched = $this->replaceDefineForm($source, $this->bodyOf($draft->code));

        if ($patched === null) {
            return $refuse(self::REFUSE_NO_DEFINE_FORM, '目标文件里找不到 defineForm() 方法，拒绝写盘（避免误伤其它代码）');
        }

        if ($patched === $source) {
            $notes[] = ['code' => 'write.noop', 'message' => '草案与现有 defineForm() 完全一致，未产生变更'];
        }

        $backup = $file . '.bak.' . date('YmdHis');

        if (!@copy($file, $backup)) {
            return $refuse(self::REFUSE_WRITE, '备份失败，未写入任何内容');
        }

        if (@file_put_contents($file, $patched) === false) {
            @unlink($backup);

            return $refuse(self::REFUSE_WRITE, '写入失败（文件不可写？）');
        }

        $lint = $this->lint($file);

        if (!$lint['ok']) {
            @copy($backup, $file);
            $notes[] = ['code' => 'write.rolled_back', 'message' => '语法检查失败，已用备份回滚：' . $lint['output']];

            $audit = $this->audit('form_generate.write_rolled_back', [
                'target' => $target,
                'file'   => $file,
                'backup' => $backup,
                'lint'   => $lint['output'],
            ]);

            return [
                'ok'      => false,
                'written' => false,
                'refused' => self::REFUSE_LINT,
                'reason'  => '写入后语法检查失败，已回滚：' . $lint['output'],
                'file'    => $file,
                'backup'  => $backup,
                'bytes'   => 0,
                'audit'   => $audit,
                'notes'   => $notes,
            ];
        }

        if ($lint['skipped'] ?? false) {
            $notes[] = ['code' => 'write.lint_skipped', 'message' => 'php -l 不可用（proc/exec 被禁用），仅做了结构自检'];
        }

        $bytes = strlen($patched);

        $audit = $this->audit('form_generate.write', [
            'target'  => $target,
            'file'    => $file,
            'backup'  => $backup,
            'bytes'   => $bytes,
            'fields'  => count($draft->specs),
        ]);

        return [
            'ok'      => true,
            'written' => true,
            'refused' => null,
            'reason'  => '已写入并备份',
            'file'    => $file,
            'backup'  => $backup,
            'bytes'   => $bytes,
            'audit'   => $audit,
            'notes'   => $notes,
        ];
    }

    /**
     * 是否处于 assisted 模式
     */
    public function mode(): string
    {
        $mode = strtolower(trim((string) $this->opt('write_mode', self::MODE_NEVER)));

        return $mode === self::MODE_ASSISTED ? self::MODE_ASSISTED : self::MODE_NEVER;
    }

    /**
     * 目标路径是否在白名单内（白名单为空 ⇒ 全部拒绝，「没配就是不许」）
     */
    public function pathAllowed(string $file): bool
    {
        $real = $this->realPath($file);

        if ($real === '') {
            return false;
        }

        foreach ($this->allowedPaths() as $allowed) {
            $dir = $this->realPath($this->resolvePath($allowed));

            if ($dir === '') {
                continue;
            }

            if ($real === $dir || str_starts_with($real, rtrim($dir, '/\\') . DIRECTORY_SEPARATOR)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 白名单（相对应用根）
     *
     * @return string[]
     */
    public function allowedPaths(): array
    {
        $paths = $this->opt('allowed_paths', []);

        if (!is_array($paths)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn(mixed $p): string => trim((string) $p),
            $paths
        ), static fn(string $p): bool => $p !== ''));
    }

    /**
     * 相对路径 → 绝对路径（不做存在性判断）
     */
    public function resolvePath(string $path): string
    {
        if ($path === '') {
            return '';
        }

        $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);

        if (preg_match('/^[A-Za-z]:\\\\/', $normalized) === 1 || str_starts_with($normalized, DIRECTORY_SEPARATOR)) {
            return $normalized;
        }

        return rtrim($this->rootPath, '/\\') . DIRECTORY_SEPARATOR . $normalized;
    }

    /**
     * 仅替换 `defineForm()` 的方法体（大括号扫描，跳过字符串与注释）
     *
     * @param string[] $bodyLines 新的方法体行（不含最外层大括号）
     * @return string|null 找不到 defineForm() 返回 null
     */
    public function replaceDefineForm(string $source, array $bodyLines): ?string
    {
        $pos = strpos($source, 'function defineForm');

        if ($pos === false) {
            return null;
        }

        $open = $this->findBodyOpenBrace($source, $pos);

        if ($open === null) {
            return null;
        }

        $close = $this->findMatchingBrace($source, $open);

        if ($close === null) {
            return null;
        }

        $eol = str_contains($source, "\r\n") ? "\r\n" : "\n";

        $body = '';
        foreach ($bodyLines as $line) {
            $body .= $line === '' ? $eol : '        ' . ltrim($line) . $eol;
        }

        return substr($source, 0, $open + 1) . $eol . $body . '    ' . substr($source, $close);
    }

    /**
     * 从草案代码片段里取出方法体行（去掉签名与最外层大括号）
     *
     * @return string[]
     */
    public function bodyOf(string $code): array
    {
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $code));
        $out   = [];
        $depth = 0;

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if (str_starts_with($trimmed, 'protected function defineForm')) {
                continue;
            }

            if ($trimmed === '{') {
                $depth++;

                continue;
            }

            if ($trimmed === '}') {
                if ($depth > 0) {
                    $depth--;

                    continue;
                }

                continue;
            }

            if ($trimmed === ''
                || str_starts_with($trimmed, '/*')
                || str_starts_with($trimmed, '*/')
                || str_starts_with($trimmed, '*')
                || str_starts_with($trimmed, '//')
            ) {
                continue;
            }

            $out[] = $trimmed;
        }

        return $out;
    }


    /**
     * 语法检查（`protected` 接缝：单测可覆写以离线模拟失败/回滚）
     *
     * @return array{ok:bool, output:string, skipped?:bool}
     */
    protected function lint(string $file): array
    {
        if (!function_exists('exec')) {
            return ['ok' => true, 'output' => '', 'skipped' => true];
        }

        $php = PHP_BINARY !== '' ? PHP_BINARY : 'php';

        try {
            $output = [];
            $code   = 0;
            @exec(sprintf('%s -l %s 2>&1', escapeshellarg($php), escapeshellarg($file)), $output, $code);
        } catch (\Throwable $e) {
            return ['ok' => true, 'output' => $e->getMessage(), 'skipped' => true];
        }

        return [
            'ok'     => $code === 0,
            'output' => trim(implode("\n", $output)),
        ];
    }

    /**
     * 审计留痕（`protected` 接缝：审计包 / 插件可覆写）
     *
     * 默认行为：记一条**带 AI 标记**的结构化日志，并返回审计元数据（随工具响应回传）。
     * 刻意**不新增独立审计表**：AI 的工具调用本身已经过 Agent 层的 `ToolExecute` 事件
     * （`before`/`after`，含工具名与参数），DB 级审计沿用既有审计包，避免出现第二个审计源。
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    protected function audit(string $action, array $payload): array
    {
        $record = array_merge([
            'action'    => $action,
            'ai_source' => 'wise-agent:form_generate',
            'actor'     => (string) $this->opt('audit_actor', 'ai'),
            'timestamp' => time(),
        ], $payload);

        try {
            ContextualLogger::info('[WiseAgent][ai-generated] ' . $action . ' ' . json_encode($record, JSON_UNESCAPED_UNICODE));
        } catch (\Throwable) {
        }

        return $record;
    }


    /**
     * 找到方法签名之后的方法体起始 `{`（跳过参数列表里的括号与字符串）
     */
    private function findBodyOpenBrace(string $source, int $from): ?int
    {
        $length = strlen($source);

        for ($i = $from; $i < $length; $i++) {
            $char = $source[$i];

            if ($char === "'" || $char === '"') {
                $i = $this->skipString($source, $i, $char);

                continue;
            }

            if ($char === '/' && ($source[$i + 1] ?? '') === '/') {
                $i = $this->skipLineComment($source, $i);

                continue;
            }

            if ($char === '/' && ($source[$i + 1] ?? '') === '*') {
                $i = $this->skipBlockComment($source, $i);

                continue;
            }

            if ($char === '{') {
                return $i;
            }
        }

        return null;
    }

    /**
     * 从 `{` 起找到匹配的 `}`（跳过字符串与注释）
     */
    private function findMatchingBrace(string $source, int $open): ?int
    {
        $length = strlen($source);
        $depth  = 0;

        for ($i = $open; $i < $length; $i++) {
            $char = $source[$i];

            if ($char === "'" || $char === '"') {
                $i = $this->skipString($source, $i, $char);

                continue;
            }

            if ($char === '/' && ($source[$i + 1] ?? '') === '/') {
                $i = $this->skipLineComment($source, $i);

                continue;
            }

            if ($char === '/' && ($source[$i + 1] ?? '') === '*') {
                $i = $this->skipBlockComment($source, $i);

                continue;
            }

            if ($char === '{') {
                $depth++;
                continue;
            }

            if ($char === '}') {
                $depth--;

                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    private function skipString(string $source, int $start, string $quote): int
    {
        $length = strlen($source);

        for ($i = $start + 1; $i < $length; $i++) {
            if ($source[$i] === '\\') {
                $i++;
                continue;
            }

            if ($source[$i] === $quote) {
                return $i;
            }
        }

        return $length;
    }

    private function skipLineComment(string $source, int $start): int
    {
        $pos = strpos($source, "\n", $start);

        return $pos === false ? strlen($source) : $pos;
    }

    private function skipBlockComment(string $source, int $start): int
    {
        $pos = strpos($source, '*/', $start + 2);

        return $pos === false ? strlen($source) : $pos + 1;
    }

    private function realPath(string $path): string
    {
        if ($path === '') {
            return '';
        }

        $real = @realpath($path);

        if ($real !== false) {
            return str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $real);
        }

        return str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    }

    private function detectRootPath(): string
    {
        if (!function_exists('app')) {
            return '';
        }

        try {
            $app = app();

            if (is_object($app) && method_exists($app, 'getRootPath')) {
                return (string) $app->getRootPath();
            }
        } catch (\Throwable) {
        }

        return '';
    }

    private function opt(string $key, mixed $default): mixed
    {
        if (array_key_exists($key, $this->options)) {
            return $this->options[$key];
        }

        if (!function_exists('config')) {
            return $default;
        }

        try {
            return config('wise-agent.form_schema.generate.' . $key, $default) ?? $default;
        } catch (\Throwable) {
            return $default;
        }
    }
}
