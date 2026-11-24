<?php
declare(strict_types=1);

namespace wise\agent\console;

use think\facade\Event;
use wise\agent\event\ToolExecute;
use wise\agent\security\CapabilityAccess;
use wise\agent\tool\BaseTool;

/**
 * 命令执行工具（两段式工具面的第二段）
 *
 * 用法（LLM 侧）：
 * ```
 * cmd_search({"keyword":"migrate"})                       → 拿到命令名与参数名
 * cmd_run({"command":"migrate:status","arguments":{}})    → 执行
 * ```
 *
 * 安全设计（与原「一条命令一个工具」**完全等价**，不放大权限面）：
 *   1. 只接受**已通过白名单**的命令（构造时传入的 metas 就是过滤后的结果）；
 *   2. 参数仍由 {@see ConsoleCommandTool::buildCmdline()} 做白名单映射 + 危险字符净化，
 *      绝不拼接用户输入成 shell；
 *   3. 执行前**手动触发目标命令工具的 ToolExecute 事件**——`ToolSecurityCheck`
 *      按 `ai.cmd.{name}` 做权限校验、按 `ApprovalPolicy` 做审批/确认，
 *      `SandboxGuard` 也照常生效；被拦时直接返回拦截结果（不执行）；
 *   4. 执行后触发 AFTER 事件，审计 / 闭环监听器照常记录。
 *
 * 之所以要「手动触发」：安全链路是按**工具名**（`cmd_xxx`）查注册表的，
 * 而两段式下 `cmd_xxx` 被 hidden 注册（仍在注册表里，只是不下发给 LLM），
 * 因此用目标工具的 `cmd_xxx` 名字发事件，拿到的权限标识与审批语义与原方案一致。
 */
final class ConsoleCommandRunTool extends BaseTool
{
    /** LLM 侧可见的工具名 */
    public const NAME = 'cmd_run';

    /**
     * @param ConsoleCommandGuard                    $guard    白名单/风险护栏
     * @param array<string, ConsoleCommandMeta>      $metas    已通过白名单的命令（key = 命令名）
     * @param callable|null                          $executor 执行器覆盖（单测注入；signature 同 ConsoleCommandTool）
     */
    public function __construct(
        private readonly ConsoleCommandGuard $guard,
        private readonly array $metas,
        private $executor = null
    ) {
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public function getDescription(): string
    {
        return '执行一条 php think 命令（命令名必须先用 cmd_search 查到）。'
            . 'arguments 是参数键值对，键为 cmd_search 返回的 params[].name，'
            . '布尔开关传 true，位置参数按名字传值。返回统一 JSON：'
            . '{code,msg,structured,data,text,truncated,exit_code,duration_ms}。';
    }

    public function getParameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'command'   => [
                    'type'        => 'string',
                    'description' => '命令名（不带 php think 前缀），例如 migrate:status',
                ],
                'arguments' => [
                    'type'        => 'object',
                    'description' => '参数字典，例如 {"force": true, "limit": 10}；没有参数则传 {}',
                ],
            ],
            'required'   => ['command'],
        ];
    }

    public function getAccess(): string
    {
        return CapabilityAccess::EXECUTE;
    }

    public function getRiskLevel(): string
    {
        return CapabilityAccess::RISK_MEDIUM;
    }

    public function execute(array $arguments): string
    {
        $command = trim((string) ($arguments['command'] ?? ''));

        if ($command === '') {
            return $this->failure('缺少 command 参数；先用 cmd_search 查命令名');
        }

        $meta = $this->metas[$command] ?? null;

        if ($meta === null) {
            return $this->failure(
                "命令 '{$command}' 不存在或未通过白名单（wise-agent.console.allow / deny）；请先用 cmd_search 检索"
            );
        }

        $params = $arguments['arguments'] ?? [];
        if (is_string($params)) {
            $decoded = json_decode($params, true);
            $params  = is_array($decoded) ? $decoded : [];
        }
        $params = is_array($params) ? $params : [];

        $target = new ConsoleCommandTool($meta, $this->guard, $this->executor);

        $before = new ToolExecute(ToolExecute::PHASE_BEFORE, $target->getName(), $params);
        $this->fire($before);

        if ($before->skip) {
            return (string) ($before->mockResult ?? $this->failure('命令被安全策略拦截'));
        }

        try {
            $result = $target->execute($before->arguments);
        } catch (\Throwable $e) {
            return $this->failure("执行失败：{$e->getMessage()}", [
                'command' => $meta->name,
                'stage'   => 'execution',
            ]);
        }

        $after = new ToolExecute(ToolExecute::PHASE_AFTER, $target->getName(), $before->arguments, $result);
        $this->fire($after);

        return (string) ($after->result ?? $result);
    }

    /**
     * 触发事件链（无容器环境如单测时静默跳过）
     *
     * 生产环境一定存在容器；单测只验证参数映射与白名单，不依赖事件系统。
     */
    private function fire(ToolExecute $event): void
    {
        try {
            Event::trigger($event);
        } catch (\Throwable) {
        }
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function failure(string $message, array $extra = []): string
    {
        return (string) json_encode(array_merge([
            'code'    => 1,
            'msg'     => $message,
            'command' => null,
        ], $extra), JSON_UNESCAPED_UNICODE);
    }
}
