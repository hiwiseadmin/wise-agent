<?php
declare(strict_types=1);

namespace wise\agent\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Argument;
use think\console\input\Option;
use think\console\Output;
use think\console\output\Formatter;
use think\facade\Config;
use wise\agent\agent\AgentManager;
use wise\agent\agent\SimpleAgent;
use wise\agent\client\FakeClient;
use wise\agent\contract\AgentInterface;
use wise\agent\client\TokenEstimator;
use wise\agent\console\ConsoleCommandToolProvider;
use wise\agent\support\MarkdownRenderer;
use wise\agent\tool\FakeEchoTool;
use wise\agent\tool\ToolRegistry;

/**
 * 交互式 / 单次对话命令
 *
 * 用法：
 *   cd src
 *   composer dump-autoload
 *
 *   # 单次（真实 LLM，需配置 .env 的 API key 且可联网）
 *   php think wise:agent:chat "用一句话解释什么是 RAG" --real
 *
 *   # 交互模式（多轮，真实 LLM）
 *   # 注意：Windows 真实控制台默认会被拦截（输入法交付的字节不可靠），
 *   #       确认终端可用后加 --interactive 才进入；否则请用参数 / --file / 管道。
 *   php think wise:agent:chat --real --interactive
 *
 *   # 多轮带记忆（需要已配置 database/redis，否则会告警并降级为无记忆）
 *   php think wise:agent:chat --real --memory
 *
 *   # 让 Agent 真正能执行 `php think` 命令（A4 控制台内省，默认关闭）
 *   php think wise:agent:chat "查看待执行的数据库迁移" --real --tools
 *   php think wise:agent:chat --real --tools
 *
 *   # 无 --real：使用 FakeClient，零依赖快速验证流程
 *   php think wise:agent:chat "hello"            # 单次假链路
 *   php think wise:agent:chat                    # 交互假链路
 *
 *   # 交互式中文字符被控制台弄坏时：把问题写进 UTF-8 文件，完全绕开控制台输入
 *   php think wise:agent:chat --real --tools --file=question.txt
 *
 *   # 模型输出默认按 Markdown 渲染（标题/列表/表格/代码块）；要原始文本加 --raw
 *   php think wise:agent:chat --real --raw "把结论用表格给我"
 *
 *   # 只预览「将发送给模型的请求」，不调用模型（调提示词 / 查工具是否下发）
 *   php think wise:agent:chat --dry-run --tools "查看待执行的数据库迁移"
 *   php think wise:agent:chat --dry-run --raw --tools "…" > payload.json   # 原始 JSON
 *
 * 工具面说明：
 *   - 不带 --tools 时，Agent 只能对话 + 使用全局已注册工具（如编排/菜单查询），
 *     「执行数据库迁移命令」这类诉求会被模型如实拒绝——因为控制台命令内省默认关闭
 *     （wise-agent.console.enabled=false，见 AgentServiceProvider::initializeConsoleTools()）。
 *   - 带 --tools 时，本命令临时打开内省并把 `php think` 命令注册为 cmd_* 工具，
 *     **白名单/拒绝清单仍然生效**（wise-agent.console.allow / deny），
 *     例如 migrate:run、migrate:rollback 默认在 deny 中，模型依旧无法执行。
 *
 * 中文输入（Windows）：
 *   - 交互模式下若「屏幕上打的中文」与「Agent 理解的内容」不一致（例如收到一串
 *     无关字符），那是控制台代码页在交付字节前就把内容转坏了，PHP 侧无法还原；
 *   - 本命令会在 Windows 真实控制台里自动把代码页切到 65001（UTF-8，退出还原），
 *     管道 / 重定向输入则按 UTF-8 → GB18030 兜底解码；
 *   - 仍然异常时改用参数形式，完全绕开控制台输入：
 *       php think wise:agent:chat --real --tools "菜单同步命令是什么"
 */
class Chat extends Command
{
    /** stdin 句柄（复用以避免每轮交互泄漏描述符） */
    private $stdin = null;

    /** 是否已提示过「控制台字节按 GB18030 解码」（只提醒一次） */
    private bool $inputConverted = false;

    /** 是否对模型输出做 Markdown 渲染着色 */
    private bool $ansi = false;

    protected function configure()
    {
        $this->setName('wise:agent:chat')
            ->setDescription('Interactive / single-turn chat with the agent. Use --real for a real LLM; --tools to expose php-think commands; --memory enables multi-turn context (needs DB).')
            ->addArgument('message', Argument::OPTIONAL, 'Single message to send (omit to enter interactive mode)')
            ->addOption('real', 'r', Option::VALUE_NONE, 'Use the real configured LLM provider instead of FakeClient')
            ->addOption('memory', 'm', Option::VALUE_NONE, 'Enable memory (multi-turn context). Requires a configured database.')
            ->addOption('tools', 't', Option::VALUE_NONE, 'Expose php-think console commands as AI tools (cmd_*). Allow/deny lists still apply.')
            ->addOption('file', 'f', Option::VALUE_REQUIRED, 'Read the single message from a UTF-8 file (bypasses console input; use when CJK input gets garbled).')
            ->addOption('raw', null, Option::VALUE_NONE, 'Print the model output as-is (do not render Markdown).')
            ->addOption('interactive', 'i', Option::VALUE_NONE, 'Force interactive mode on Windows consoles (CJK input may be corrupted by the terminal).')
            ->addOption('dry-run', null, Option::VALUE_NONE, 'Preview the exact request (messages + tool schemas + token estimate) without calling the model.');
    }

    protected function execute(Input $input, Output $output): int
    {
        $real    = (bool) $input->getOption('real');
        $memory  = (bool) $input->getOption('memory');
        $tools   = (bool) $input->getOption('tools');
        $raw     = (bool) $input->getOption('raw');
        $dryRun  = (bool) $input->getOption('dry-run');
        $forceInteractive = (bool) $input->getOption('interactive');

        $this->ansi = $this->supportsAnsi($input);
        if ($this->ansi) {
            $output->setDecorated(true);
        }
        $message = $input->getArgument('message');
        if (is_string($message)) {
            $message = $this->toUtf8($message);
        }

        $file = $input->getOption('file');
        if (is_string($file) && $file !== '') {
            $content = is_file($file) ? file_get_contents($file) : false;
            if ($content === false) {
                $output->writeln("<error>[error] cannot read message file: {$file}</error>");
                return 1;
            }
            $message = $this->toUtf8(rtrim($content, "\r\n"));
        }

        if ($message === null && !$forceInteractive) {
            $blocked = $this->interactiveBlocker();
            if ($blocked !== '') {
                $output->writeln($blocked);
                return 1;
            }
        }

        $agentConfig = (array) Config::get('wise-agent.agent', []);
        $agentConfig['enable_memory'] = $memory;
        Config::set(['agent' => $agentConfig], 'wise-agent');

        $agent = new SimpleAgent();
        $agent->withMemory($memory);

        $quiet = $dryRun && $raw;

        $consoleNote = $this->setupConsole();
        if ($consoleNote !== '' && !$quiet) {
            $output->writeln($consoleNote);
        }

        if ($real) {
            $provider = Config::get('wise-agent.default', 'deepseek');
            if (!$quiet) {
                $output->writeln("<info>[real] provider = {$provider}</info>");
            }
        } else {
            $agent->setClient(new FakeClient());
            $agent->addTool(FakeEchoTool::class, 'echo_tool');
            if (!$quiet) {
                $output->writeln('<info>[fake] FakeClient：没有模型推理能力，只回放脚本化响应（第 1 步调用 echo_tool，第 2 步返回固定文案）</info>');
                $output->writeln('<comment>[fake] 真实对话请加 --real；当前模式仅用于验证链路（工具分发 / 编码 / Markdown 渲染）</comment>');
            }
        }

        if ($tools) {
            $note = $this->enableConsoleTools();
            if (!$quiet) {
                $output->writeln($note);
            }
        } elseif (!$quiet) {
            $output->writeln('<comment>[tools] disabled. Add --tools to let the agent run `php think` commands.</comment>');
        }

        if ($message === null) {
            $output->writeln('<info>Interactive mode. Type "quit" or "exit" to leave.</info>');
            $output->writeln('');
            while (true) {
                $line = $this->readLine($output);
                if ($line === null || $line === 'quit' || $line === 'exit') {
                    break;
                }
                if ($line === '' || $this->isBlank($line)) {
                    continue;
                }
                $output->writeln('<comment>You:</comment> ' . Formatter::escape($line));
                if ($dryRun) {
                    $this->printPreview($output, $agent, $line, $raw);
                    $output->writeln('');
                    continue;
                }
                $reply = $agent->run($line);
                $this->printReply($output, $reply, $raw);
                $output->writeln('');
            }
            $output->writeln('<info>Bye.</info>');
            return 0;
        }

        if (!$quiet) {
            $output->writeln('<comment>You:</comment> ' . Formatter::escape($message));
        }

        if ($dryRun) {
            $this->printPreview($output, $agent, $message, $raw);
            return 0;
        }

        $reply = $agent->run($message);
        $this->printReply($output, $reply, $raw);
        return 0;
    }

    /**
     * --dry-run：打印「本次将会发送给模型」的请求，不调用模型
     *
     * 走 {@see AgentManager::preview()}（与 run() 共用同一套上下文构建），因此
     *   - messages 里能看到**真实**的 system prompt、注入的上下文、记忆片段；
     *   - tools 里能看到本轮下发的全部 function schema（含 `cmd_*` 命令工具及其风险级）；
     * 与真正发送的内容一致。适合调试提示词、确认「模型为什么看不到某个工具」。
     */
    private function printPreview(Output $output, AgentInterface $agent, string $message, bool $raw = false): void
    {
        try {
            $preview = app()->make(AgentManager::class)->preview($agent, $message);
        } catch (\Throwable $e) {
            $output->writeln('<error>[dry-run] 构建请求失败：' . $e->getMessage() . '</error>');
            return;
        }

        if ($raw) {
            $output->writeln(Formatter::escape((string) json_encode(
                $preview,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
            )));
            return;
        }

        $messages = (array) ($preview['messages'] ?? []);
        $tools    = (array) ($preview['tools'] ?? []);
        $options  = (array) ($preview['options'] ?? []);

        $msgTokens  = TokenEstimator::count($messages);
        $toolTokens = TokenEstimator::countTools($tools);

        $output->writeln(sprintf(
            '<info>[dry-run]</info> provider=%s model=%s | messages=%d tools=%d | input tokens≈%d（messages≈%d + tools≈%d）',
            (string) ($preview['provider'] ?? '?'),
            (string) ($preview['model'] ?? '?'),
            count($messages),
            count($tools),
            $msgTokens + $toolTokens,
            $msgTokens,
            $toolTokens
        ));
        $output->writeln(sprintf(
            '<comment>[dry-run]</comment> temperature=%s max_tokens=%s max_steps=%s session=%s',
            (string) ($options['temperature'] ?? '?'),
            (string) ($options['max_tokens'] ?? '?'),
            (string) ($options['max_steps'] ?? '?'),
            (string) ($options['session_id'] ?? '?')
        ));
        $output->writeln('<comment>[dry-run] 未发送任何请求；去掉 --dry-run 即真正执行（加 --raw 输出原始 JSON）</comment>');
        $output->writeln('');

        $output->writeln('<info>── messages (' . count($messages) . ') ──</info>');
        foreach ($messages as $index => $msg) {
            $role    = (string) ($msg['role'] ?? '?');
            $content = $msg['content'] ?? '';
            $content = is_string($content) ? $content : (string) json_encode($content, JSON_UNESCAPED_UNICODE);
            $calls   = (array) ($msg['tool_calls'] ?? []);

            $head = sprintf('#%d [%s] %d chars', $index + 1, $role, mb_strlen($content, 'UTF-8'));
            if ($calls !== []) {
                $names = array_map(
                    static fn(array $call): string => (string) ($call['function']['name'] ?? '?'),
                    $calls
                );
                $head .= ' + tool_calls(' . implode(', ', $names) . ')';
            }

            $output->writeln('<comment>' . Formatter::escape($head) . '</comment>');
            $output->writeln('    ' . Formatter::escape($this->shorten($content)));
        }

        $output->writeln('');
        $output->writeln('<info>── tools (' . count($tools) . ') ──</info>');

        if ($tools === []) {
            $output->writeln('    (无；加 --tools 可把 php think 命令内省为 cmd_* 工具)');
            return;
        }

        foreach ($tools as $tool) {
            $function = (array) ($tool['function'] ?? []);
            $name     = (string) ($function['name'] ?? '?');
            $desc     = $this->shorten((string) ($function['description'] ?? ''), 100);
            $keys     = array_keys((array) ($function['parameters']['properties'] ?? []));
            $risk     = $this->toolRisk($agent, $name);

            $output->writeln(sprintf(
                '    %s%s  %s  <comment>~%d tok</comment>',
                Formatter::escape($name),
                $risk !== '' ? ' [' . $risk . ']' : '',
                $keys === [] ? '(无参数)' : '(参数: ' . implode(', ', $keys) . ')',
                TokenEstimator::countTools([$tool])
            ));
            $output->writeln('        <comment>' . Formatter::escape($desc) . '</comment>');
        }

        $output->writeln('');
        $output->writeln('<comment>[dry-run] 注意：以上是**单轮**请求。每轮都会重发全部 tools + 历史消息，</comment>');
        $output->writeln('<comment>          多轮对话的 input token 逐轮累加（Provider 侧前缀缓存命中可降费，但用量仍计入）。</comment>');
    }

    /**
     * 命令工具的治理风险级（read / write / admin），非命令工具返回空串
     */
    private function toolRisk(AgentInterface $agent, string $name): string
    {
        try {
            $tool = $agent->getTools()->get($name);

            if ($tool instanceof \wise\agent\console\ConsoleCommandTool) {
                return $tool->getMeta()->risk;
            }
        } catch (\Throwable) {
        }

        return '';
    }

    /**
     * 压成单行并截断（预览用）
     */
    private function shorten(string $text, int $limit = 400): string
    {
        $text = trim($text);

        if ($text === '') {
            return '(空)';
        }

        $length = mb_strlen($text, 'UTF-8');
        $flat   = (string) preg_replace('/\s+/u', ' ', $text);

        if ($length <= $limit) {
            return $flat;
        }

        return mb_substr($flat, 0, $limit, 'UTF-8') . ' …(共 ' . $length . ' 字)';
    }

    /**
     * 输出模型回复（默认按 Markdown 渲染）
     *
     * 为什么不能直接 `writeln($reply)`：
     *   1. 模型输出是 Markdown，直接打印会满屏 `**`/`|`/``` 噪声；
     *   2. think 的 Output 会把 `<xxx>` 当**样式标签**解析并吞掉，
     *      模型输出里一旦出现 `<T>`、`<div>` 就会被吃掉/串色，故逐行 escape。
     */
    private function printReply(Output $output, string $reply, bool $raw = false): void
    {
        $output->writeln('<info>Agent:</info>');

        if (trim($reply) === '') {
            $output->writeln('<comment>(empty reply)</comment>');
            return;
        }

        $text = $raw
            ? $reply
            : (new MarkdownRenderer($this->ansi))->render($reply);

        foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $text)) as $line) {
            $output->writeln(Formatter::escape($line));
        }
    }

    /**
     * 当前终端能否输出 ANSI 颜色
     *
     * Windows 上 conhost 默认不解析 VT 序列，需要显式开启（sapi_windows_vt100_support），
     * 否则会打印出 `[1m` 之类的垃圾；管道 / 重定向时一律关闭。
     */
    private function supportsAnsi(Input $input): bool
    {
        if ($input->hasParameterOption('--no-ansi') || getenv('NO_COLOR') !== false) {
            return false;
        }

        if ($input->hasParameterOption('--ansi')) {
            return true;
        }

        if (!defined('STDOUT')) {
            return false;
        }

        if (function_exists('sapi_windows_vt100_support')) {
            return (bool) @sapi_windows_vt100_support(STDOUT, true);
        }

        return function_exists('stream_isatty') && @stream_isatty(STDOUT);
    }

    /**
     * 临时打开控制台命令内省并注册 cmd_* 工具
     *
     * 只改本次进程内的配置（CLI 生命周期结束即失效），不落盘、不影响 HTTP 进程。
     * 注册后仍受 ConsoleCommandGuard 的三层护栏约束：
     *   1. allow / deny 白名单（migrate:run 等默认拒绝）
     *   2. 权限（ai.cmd.* → ToolSecurityCheck → RBAC）
     *   3. 确认/审批（CLI 无登录用户时按系统上下文跳过审批，故高风险命令保持 deny）
     */
    private function enableConsoleTools(): string
    {
        $consoleConfig = (array) Config::get('wise-agent.console', []);
        $consoleConfig['enabled'] = true;

        $consoleConfig['deny'] = array_values(array_unique(array_merge(
            (array) ($consoleConfig['deny'] ?? []),
            ['wise:agent:chat', 'wise:agent:mcp']
        )));

        Config::set(['console' => $consoleConfig], 'wise-agent');

        $count = (new ConsoleCommandToolProvider())->registerTo();

        foreach (['cmd_wise_agent_chat', 'cmd_wise_agent_mcp'] as $blocking) {
            ToolRegistry::instance()->unregister($blocking);
        }

        if ((bool) Config::get('wise-agent.console.two_stage', false)) {
            return "<info>[tools] two-stage mode: cmd_search + cmd_run exposed（{$count} 条命令已注册，其中 "
                . max(0, $count - 2) . " 条为隐藏工具）</info>";
        }

        return "<info>[tools] {$count} console command tool(s) registered (allow/deny lists still apply)</info>";
    }

    /**
     * 从标准输入读取一行（Windows / Unix CLI 通用）
     *
     * 句柄复用：原先每轮 `fopen('php://stdin')` 且从不 fclose，
     * 长时间交互会持续泄漏文件描述符。
     */
    private function readLine(Output $output): ?string
    {
        $output->write('<info>You > </info>');

        if ($this->stdin === null) {
            $this->stdin = fopen('php://stdin', 'r');
        }
        if ($this->stdin === false) {
            return null;
        }

        $line = fgets($this->stdin);
        if ($line === false) {
            if (!feof($this->stdin)) {
                $output->writeln('');
                $output->writeln('<error>[warn] stdin read failed (no EOF). Console may be unable to deliver multibyte input.</error>');
                $output->writeln('<comment>       请改用参数形式：php think wise:agent:chat "你的问题"（或 --file=msg.txt）</comment>');
            }
            return null;
        }

        $raw = rtrim($line, "\r\n");
        if ($raw === '') {
            return '';
        }

        $text = $this->toUtf8($raw);

        if ($this->isBlank($text)) {
            $output->writeln('');
            $output->writeln('<error>[warn] 输入不含可见字符，已忽略。raw hex=' . bin2hex(substr($raw, 0, 48)) . '</error>');
            $output->writeln('<comment>       这通常是控制台/输入法把中文转坏了，请改用参数形式（或 --file=msg.txt）。</comment>');
            return '';
        }

        if ($text !== $raw && !$this->inputConverted) {
            $this->inputConverted = true;
            $output->writeln('');
            $output->writeln('<comment>[input] stdin bytes decoded as GB18030 → UTF-8</comment>');
        }

        return $text;
    }

    /**
     * 是否「视觉上为空」（只含空白 / 零宽 / 控制字符）
     *
     * `trim()` 只认 ASCII 空白，像 U+200B、U+FEFF、U+00A0 这类字符会原样留下，
     * 于是「看起来什么都没输入」的消息照样会被发给模型。
     */
    private function isBlank(string $text): bool
    {
        $stripped = preg_replace('/[\s\p{Z}\p{Cc}\p{Cf}]+/u', '', $text);

        return $stripped === null ? trim($text) === '' : $stripped === '';
    }

    /**
     * 交互模式前置拦截：返回提示文案（空串 = 允许进入交互模式）
     *
     * 只拦「Windows + 真实控制台」这一种组合：
     *   - 非 Windows：控制台走系统自身的宽字符/UTF-8 通道，不干预；
     *   - 管道 / 重定向（`echo ... | php think ...`、`< file`）：字节由调用方决定，
     *     已由 {@see self::toUtf8()} 做 UTF-8 → GB18030 兜底，照常可用。
     *
     * 为什么选择「先拦截、再放行」而不是「进不去再说」：实测 Windows 控制台 + 中文输入法
     * （PowerShell 5.1 / 7、conhost、部分 IDE 内置终端）都会在交付字节前把中文转坏，
     * 且损坏方式不可预测（变成无关字符、或直接读成空）。这类损坏发生在 PHP 之前，
     * 无法还原，用户只会看到「模型答非所问」。直接给可复制的替代命令，比让用户
     * 反复试错、白烧模型调用划算。
     */
    private function interactiveBlocker(?bool $isTty = null): string
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            return '';
        }

        $isTty ??= defined('STDIN') && function_exists('stream_isatty') && @stream_isatty(STDIN);
        if (!$isTty) {
            return '';
        }

        return <<<'TXT'
<comment>[interactive] 已跳过交互模式。</comment>
  Windows 控制台的输入法交付字节不可靠：中文可能被转成无关字符，或直接读成空，
  PHP 侧无法还原（PowerShell 5.1 / 7、conhost、部分 IDE 内置终端均复现过）。
  请改用下列任一方式（都能正确处理中文）：
      php think wise:agent:chat --real --tools "你的问题"
      php think wise:agent:chat --real --tools --file=question.txt
      echo "你的问题" | php think wise:agent:chat --real --tools
  若确认终端一切正常，可强制进入交互模式：加 --interactive
TXT;
    }

    /**
     * 把输入控制台切到 UTF-8（仅 Windows + 真实控制台）
     *
     * 为什么需要：Windows 控制台给 `fgets(STDIN)` 的是**按当前代码页转换后的字节**。
     * cp936 下一个中文字符是 2 字节，而输入法组合、PSReadLine、老 conhost 的转换
     * 都可能在交付前把字节弄错 —— 表现就是「屏幕上打的是中文，程序收到的是别的字」，
     * 这种损坏在 PHP 侧无法还原（信息已经丢了）。切到 65001 让控制台直接交付 UTF-8
     * 是该问题的标准解法，也顺带保证输出（UTF-8）不变形。
     *
     * 只处理真实控制台：管道 / 重定向（`< file`、`cmd | php`）保持调用方字节原样，
     * 交给 {@see self::toUtf8()} 的 GB18030 兜底处理。
     *
     * @return string 提示文案（无需切换时返回空串）
     */
    private function setupConsole(): string
    {
        if (PHP_OS_FAMILY !== 'Windows'
            || !function_exists('sapi_windows_cp_get')
            || !function_exists('sapi_windows_cp_set')) {
            return '';
        }

        if (defined('STDIN') && function_exists('stream_isatty') && !stream_isatty(STDIN)) {
            return '';
        }

        $previous = (int) sapi_windows_cp_get();
        if ($previous === 65001) {
            return '';
        }

        if (!sapi_windows_cp_set(65001)) {
            return '';
        }

        register_shutdown_function(static function () use ($previous): void {
            @sapi_windows_cp_set($previous);
        });

        return "<comment>[console] code page {$previous} → 65001 (UTF-8), restored on exit</comment>";
    }

    /**
     * 把控制台输入归一化为 UTF-8，并去掉 Windows 管道的 BOM
     *
     * 为什么必须有这一步：`fgets(STDIN)` 拿到的是**原始字节**，Windows 控制台会按
     * 当前代码页（简体中文默认 cp936/GBK）输出中文。GBK 字节不是合法 UTF-8，
     * 一路传到 `json_encode()` 会返回 `false`（Malformed UTF-8 characters），
     * 而 HttpClient 把 `false` 直接塞进 `CURLOPT_POSTFIELDS` → 上游收到**空请求体**，
     * 报 `400 Failed to parse the request body as JSON: EOF while parsing a value at line 1 column 0`。
     * 这个报错完全看不出「是中文编码问题」，因此在这里就地修掉。
     *
     * 另外 PowerShell 5.1 / cmd 管道会带 UTF-8 BOM（`\xEF\xBB\xBF`，可能重复多个），
     * 会把首条消息污染成 `﻿﻿hello`，一并剥离。
     */
    private function toUtf8(string $text): string
    {
        if ($text === '') {
            return $text;
        }

        if (!mb_check_encoding($text, 'UTF-8')) {
            if (PHP_OS_FAMILY === 'Windows' && function_exists('mb_convert_encoding')) {
                $converted = @mb_convert_encoding($text, 'UTF-8', 'GB18030');
                if (is_string($converted) && $converted !== '' && mb_check_encoding($converted, 'UTF-8')) {
                    $text = $converted;
                }
            }

            if (!mb_check_encoding($text, 'UTF-8')) {
                $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
            }
        }

        return (string) preg_replace('/^(?:\xEF\xBB\xBF)+/', '', $text);
    }
}
