<?php
declare(strict_types=1);

namespace wise\agent\console;

use wise\support\tools\CommandTool;

/**
 * 单条 think 命令的 AI 工具＼�
 *
 * 一个命令 = 一个工具，参数 Schema 由命令自身元数据推导（{@see ConsoleCommandMeta::toJsonSchema()}）。
 *
 * 执行链路：
 * ```
 * LLM function call
 *   → ConsoleCommandGuard::assertAllowed()   第1/2层：白名单 + 权限（权限标识经 ToolRegistry 进入 RBAC 树）
 *   → buildCmdline()                          只做白名单参数映射，绝不拼接用户输入
 *   → CommandTool::execute('php think ...')   第4层：子进程 + 超时 + 输出截断 + 危险检测
 * ```
 *
 * 为什么不就地进程内调用� HTTP / 队列进程的插件 boot 状态、
 * 容器绑定、常驻内存互相污染（`php think` 子进程天然隔离）。
 *
 * **执行方法（`console.method`，默认 `auto`）**：
 *   - `auto` → POSIX 用 `proc_open`（支持 timeout / proc_terminate）；Windows 交给
 *     CommandTool 自选（exec 优先）。
 *   - 原因（实测）：PHP 在 Windows 上对 `proc_open` 管道做 `stream_select` 不可靠，
 *     命令输出超过一次 `fread`（8KB）后 select 不再报告可读 → 子进程写满管道被阻塞
 *     → `proc_terminate` 只能杀掉 cmd.exe 外壳、`proc_close` 会一直等待孙进程结束。
 *     对 `php think list` 这类大输出命令会表现为长时间卡住。
 *   - 因此 Windows 下默认不强制 `proc_open`（改为自动，走 exec）；如需硬超时，
 *     显式配置 `'method' => 'proc_open'` 并接受上述平台限制。
 */
final class ConsoleCommandTool extends \wise\agent\tool\BaseTool
{
    /** 输入中禁止出现的 shell 元字符（多重防御，即使 escapeshellarg / CommandTool::checkSecurity 已兜底） */
    private const FORBIDDEN_SEQUENCES = ['|', ';', '>', '<', '&', '`', "\n", "\r", '$(', '${'];

    /** Windows 额外禁止：%VAR% / !VAR! 展开绕过 escapeshellarg */
    private const FORBIDDEN_SEQUENCES_WINDOWS = ['%', '!'];

    /**
     * @param ConsoleCommandMeta  $meta     命令元数据
     * @param ConsoleCommandGuard $guard    安全护栏
     * @param callable|null       $executor 执行器（默认 CommandTool；测试可注入）
     *                                      signature: fn(string $cmdline, array $options): array
     */
    public function __construct(
        private ConsoleCommandMeta $meta,
        private ConsoleCommandGuard $guard,
        private $executor = null,
    ) {
    }

    /**
     * LLM Function Calling 名称：think 命令名含 `:` 不合法，统一归一化为 `cmd_...`
     */
    public function getName(): string
    {
        return $this->meta->getToolName();
    }

    public function getDescription(): string
    {
        $desc = trim($this->meta->description);

        $lines = [
            "执行框架控制台命令 `{$this->meta->name}`（来源：{$this->meta->source}，风险：{$this->meta->risk}）。",
        ];

        if ($desc !== '') {
            $lines[] = $desc;
        }

        $help = $this->meta->helpSummary((int) $this->config('console.help_max_length', 300));
        if ($help !== '') {
            if ($desc !== '' && str_starts_with($help, $desc)) {
                $help = trim(substr($help, strlen($desc)));
            }
            if ($help !== '') {
                $lines[] = '帮助：' . $help;
            }
        }

        $example = $this->meta->usageExample();
        if ($example !== '') {
            $lines[] = '示例参数：' . $example;
        }

        $lines[] = '返回统一 JSON：{code,msg,structured,data,text,truncated,exit_code,duration_ms}；'
            . 'structured=true 时读 data，否则读 text。';

        return implode(' ', $lines);
    }

    public function getParameters(): array
    {
        return $this->meta->toJsonSchema((bool) $this->config('console.schema_inference', true));
    }

    public function getPermission(): string
    {
        return $this->meta->getPermission();
    }

    /**
     * 写类 / 管理类命令必须确认（第 3 层）
     */
    public function requireConfirmation(): bool
    {
        return $this->guard->requireConfirmation($this->meta);
    }

    public function getAccess(): string
    {
        return $this->guard->accessOf($this->meta);
    }

    public function getRiskLevel(): string
    {
        return $this->guard->riskLevelOf($this->meta);
    }

    public function getMeta(): ConsoleCommandMeta
    {
        return $this->meta;
    }

    public function execute(array $arguments): string
    {
        try {
            $this->guard->assertAllowed($this->meta);
        } catch (\Throwable $e) {
            return $this->encode(ConsoleResult::failure($e->getMessage(), 1, [
                'command' => $this->meta->name,
                'stage'   => 'guard',
            ]));
        }

        try {
            $cmdline = $this->buildCmdline($arguments);
        } catch (\Throwable $e) {
            return $this->encode(ConsoleResult::failure('Invalid arguments: ' . $e->getMessage(), 1, [
                'command' => $this->meta->name,
                'stage'   => 'arguments',
            ]));
        }

        $timeout = (int) $this->config('console.timeout', 60);
        $maxOut  = (int) $this->config('console.max_output_length', 65536);

        $options = [
            'timeout'           => $timeout > 0 ? $timeout : 60,
            'max_output_length' => $maxOut > 0 ? $maxOut : 65536,
            'default_method'    => $this->resolveMethod(),
            'log_execution'     => true,
        ];

        try {
            $result = $this->runCommand($cmdline, $options);
        } catch (\Throwable $e) {
            return $this->encode(ConsoleResult::failure('Command execution failed: ' . $e->getMessage(), 1, [
                'command' => $this->meta->name,
                'stage'   => 'execution',
            ]));
        }

        return $this->encode(ConsoleResult::normalize($this->effectiveMeta(), $result, (int) $options['max_output_length']));
    }

    /**
     * 构造命令行＼�白名单参数映射，禁止任意 shell）
     *
     * @throws \InvalidArgumentException
     */
    public function buildCmdline(array $arguments): string
    {
        $this->assertKnownParameters($arguments);

        $positional = [];
        foreach ($this->meta->arguments as $decl) {
            $name = (string) ($decl['name'] ?? '');
            if ($name === '') {
                continue;
            }

            $value = $arguments[$name] ?? null;
            if ($value === null || $value === '' || $value === []) {
                if (!empty($decl['required'])) {
                    throw new \InvalidArgumentException("Missing required argument '{$name}'");
                }
                continue;
            }

            foreach ((array) $value as $item) {
                $positional[] = $this->sanitize((string) $item);
            }
        }

        $optionParts = [];
        foreach ($this->meta->options as $decl) {
            $name = (string) ($decl['name'] ?? '');
            if ($name === '' || $name === 'json') {
                continue;
            }
            if (!array_key_exists($name, $arguments)) {
                continue;
            }

            $value        = $arguments[$name];
            $acceptsValue = !empty($decl['value_required']) || !empty($decl['value_optional']);

            if (!$acceptsValue) {
                if ($value === true || $value === 1 || $value === '1' || $value === 'true') {
                    $optionParts[] = '--' . $name;
                }
                continue;
            }

            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            foreach ((array) $value as $item) {
                $optionParts[] = '--' . $name . '=' . $this->sanitize((string) $item);
            }
        }

        $cmd = escapeshellarg($this->phpBinary()) . ' ' . escapeshellarg($this->thinkEntry()) . ' ' . $this->meta->name;

        if ($positional !== []) {
            $cmd .= ' ' . implode(' ', $positional);
        }
        if ($optionParts !== []) {
            $cmd .= ' ' . implode(' ', $optionParts);
        }
        if ($this->effectiveMeta()->supportsJson) {
            $cmd .= ' --json';
        }

        return $cmd . ' 2>&1';
    }

    /**
     * 任意含未知参数 → 拒绝（只允许元数据中声明的参数）
     *
     * @throws \InvalidArgumentException
     */
    private function assertKnownParameters(array $arguments): void
    {
        $declared = [];
        foreach ($this->meta->arguments as $decl) {
            $declared[] = (string) ($decl['name'] ?? '');
        }
        foreach ($this->meta->options as $decl) {
            $declared[] = (string) ($decl['name'] ?? '');
        }

        foreach (array_keys($arguments) as $key) {
            if (!in_array((string) $key, $declared, true)) {
                throw new \InvalidArgumentException("Unknown parameter '{$key}'");
            }
        }
    }

    /**
     * 值净化：拒绝 shell 元字符 + escapeshellarg
     *
     * @throws \InvalidArgumentException
     */
    private function sanitize(string $value): string
    {
        $forbidden = self::FORBIDDEN_SEQUENCES;
        if (PHP_OS_FAMILY === 'Windows') {
            $forbidden = array_merge($forbidden, self::FORBIDDEN_SEQUENCES_WINDOWS);
        }

        foreach ($forbidden as $token) {
            if (str_contains($value, $token)) {
                throw new \InvalidArgumentException(
                    "Unsafe character sequence '{$token}' detected in value; the command was not executed."
                );
            }
        }

        return escapeshellarg($value);
    }

    /**
     * @param array{timeout:int,max_output_length:int,default_method:string,log_execution:bool} $options
     */
    private function runCommand(string $cmdline, array $options): array
    {
        if (is_callable($this->executor)) {
            $result = ($this->executor)($cmdline, $options);

            return is_array($result) ? $result : [];
        }

        return (new CommandTool())->execute($cmdline, $options);
    }

    /**
     * 是否启用 JSON 优先＼�
     */
    private function preferJson(): bool
    {
        return (bool) $this->config('console.prefer_json', true);
    }

    /**
     * 生效元数据：`prefer_json=false` 时强制关闭 JSON 模式（一律文本归一化）
     */
    private function effectiveMeta(): ConsoleCommandMeta
    {
        if ($this->preferJson() || !$this->meta->supportsJson) {
            return $this->meta;
        }

        return new ConsoleCommandMeta(
            name: $this->meta->name,
            description: $this->meta->description,
            source: $this->meta->source,
            risk: $this->meta->risk,
            supportsJson: false,
            arguments: $this->meta->arguments,
            options: $this->meta->options,
            allowed: $this->meta->allowed,
            help: $this->meta->help,
        );
    }

    /**
     * 解析执行方法（见类注释）
     *
     * @return string|null null = 由 CommandTool 自选
     */
    private function resolveMethod(): ?string
    {
        $configured = trim((string) $this->config('console.method', 'auto'));

        if ($configured !== '' && strtolower($configured) !== 'auto') {
            return $configured;
        }

        return CommandTool::METHOD_PROC_OPEN;
    }

    private function phpBinary(): string
    {
        return defined('PHP_BINARY') && PHP_BINARY !== '' ? PHP_BINARY : 'php';
    }

    /**
     * think 入口绝对路径（HTTP/FPM 下 CWD 通常不是应用根目录）
     */
    private function thinkEntry(): string
    {
        try {
            if (function_exists('app')) {
                $root = app()->getRootPath();
                if (is_string($root) && $root !== '' && is_file($root . 'think')) {
                    return $root . 'think';
                }
            }
        } catch (\Throwable) {
        }

        return 'think';
    }

    private function encode(array $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_UNICODE) ?: '{"code":1,"msg":"json encode failed"}';
    }

    private function config(string $key, mixed $default): mixed
    {
        if (!function_exists('config')) {
            return $default;
        }

        try {
            return config('wise-agent.' . $key, $default) ?? $default;
        } catch (\Throwable) {
            return $default;
        }
    }
}
