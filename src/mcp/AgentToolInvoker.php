<?php
declare(strict_types=1);

namespace wise\agent\mcp;

use think\facade\Event;
use wise\agent\event\ToolExecute;
use wise\agent\execution\ToolExecutionRecorder;
use wise\mcp\contract\ToolInvokerInterface;
use wise\mcp\dto\ToolDefinition;
use wise\support\context\ContextualLogger;

/**
 * MCP 工具执行器（安装 wise-agent 时接管）
 *
 * 存在的意义
 * ----------
 * 改造前 MCP 的 `tools/call` 直接调 `$tool->call()`，完全绕过 `ToolExecute`
 * 事件链 —— 而授权（CapabilityAuthorizer）、审批（ApprovalManager）、
 * 沙箱（SandboxGuard）、审计（closure / ExecutionTracker）全部挂在那条链上。
 * 结果就是：同一份能力，经 HTTP 调用有 RBAC，经 MCP 调用只有一个
 * `allow_write` 布尔开关。
 *
 * 本类把 MCP 调用接回事件链，从而与 Agent / HTTP 两条链路取得一致：
 *
 *   BEFORE 事件 → ToolSecurityCheck（授权 + 审批）→ SandboxGuard（沙箱）
 *   —— 任一监听器置 `skip` 即视为拦截，`mockResult` 作为对外文案
 *   执行
 *   AFTER 事件  → 审计 / 闭环 / 用量统计；`result` 可被监听器覆盖
 *
 * 与 HandlerToolInvoker 的关系
 * ----------------------------
 * 二者实现同一契约，前者是"有完整安全链路"，后者是"未装 wise-agent 时的降级"。
 * 由 wise-mcp 从容器解析，解析不到即回落，因此不构成硬依赖。
 *
 * @package wise\agent\mcp
 */
final class AgentToolInvoker implements ToolInvokerInterface
{
    public function invoke(ToolDefinition $tool, array $arguments, array $context = []): array
    {
        $name = (string) ($context['tool'] ?? '');

        if ($name === '') {
            $schema = $tool->toMcpArray();
            $name   = (string) ($schema['name'] ?? 'unknown');
        }

        $startedAt = microtime(true);
        $source    = (string) ($context['source'] ?? 'mcp');

        $before = new ToolExecute(ToolExecute::PHASE_BEFORE, $name, $arguments);
        Event::trigger($before);

        if ($before->skip) {
            ContextualLogger::warning('[mcp] 工具调用被安全策略拦截', [
                'tool'   => $name,
                'source' => $source,
            ]);

            return $this->finish($name, $arguments, $source, $startedAt, [
                'ok'      => false,
                'content' => '',
                'blocked' => true,
                'reason'  => '该操作未通过安全校验',
            ]);
        }

        try {
            $content = $tool->call($arguments);
        } catch (\Throwable $e) {
            ContextualLogger::error('[mcp] 工具执行失败：' . $e->getMessage(), [
                'tool'   => $name,
                'source' => $source,
            ]);

            return $this->finish($name, $arguments, $source, $startedAt, [
                'ok'      => false,
                'content' => '',
                'blocked' => false,
                'reason'  => '工具执行失败',
            ]);
        }

        $after = new ToolExecute(ToolExecute::PHASE_AFTER, $name, $arguments, $content);
        Event::trigger($after);

        if ($after->result !== null) {
            $content = (string) $after->result;
        }

        $isError = false;
        $decoded = json_decode($content, true);
        if (is_array($decoded) && array_key_exists('error', $decoded)) {
            $isError = !in_array($decoded['error'], [null, '', [], false], true);
        }

        return $this->finish($name, $arguments, $source, $startedAt, [
            'ok'      => !$isError,
            'content' => $content,
            'blocked' => false,
            'reason'  => $isError ? '工具返回错误' : '',
        ]);
    }

    /**
     * 落审计并返回结果
     *
     * 三条出口（拦截 / 异常 / 正常）统一在此记录，保证"不是每个 return 都记得写日志"。
     *
     * @param array{ok:bool, content:string, blocked:bool, reason:string} $result
     * @return array{ok:bool, content:string, blocked:bool, reason:string}
     */
    private function finish(string $name, array $arguments, string $source, float $startedAt, array $result): array
    {
        ToolExecutionRecorder::record([
            'tool'        => $name,
            'source'      => $source,
            'arguments'   => $arguments,
            'ok'          => $result['ok'],
            'blocked'     => $result['blocked'],
            'reason'      => $result['reason'],
            'content'     => $result['content'],
            'duration_ms' => (microtime(true) - $startedAt) * 1000,
        ]);

        return $result;
    }
}
