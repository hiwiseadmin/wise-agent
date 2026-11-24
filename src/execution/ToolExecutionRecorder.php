<?php
declare(strict_types=1);

namespace wise\agent\execution;

use think\facade\Db;
use wise\support\context\ContextualLogger;
use wise\support\context\RequestContext;

/**
 * 工具级审计记录器（B2-4）
 *
 * 把每一次工具调用落成 `ai_agent_logs` 的一行，`job_type = 'tool'` 与
 * agent / pipeline 级记录区分。三条执行链路（agent / mcp / http）共用，
 * 因此审计口径天然一致 —— 这是"统一执行入口"带来的直接收益。
 *
 * 落库字段：
 *   tool_name        调用了哪个工具
 *   capability       能力标识（权限点），便于按能力统计与追溯
 *   entry_source     入口来源（agent / mcp / http），用于区分"从哪被调用"
 *   arguments_digest 参数指纹 sha256 —— **只存指纹不存明文**，避免密码/令牌入库
 *   approval_id      关联审批单（若有）
 *   status / error / duration / user_* / create_time
 *
 * 设计约定：
 *   - **绝不因审计失败影响业务**：整段 try/catch，异常只记日志；
 *   - 结果文本截断存储，避免大响应撑爆行；
 *   - session_id 为表非空列，工具级记录无会话时用 '-' 占位。
 */
final class ToolExecutionRecorder
{
    /** 结果文本落库上限（字符） */
    private const RESULT_LIMIT = 2000;

    /** 状态：完成 */
    public const STATUS_DONE = 2;

    /** 状态：失败 */
    public const STATUS_FAILED = 3;

    /**
     * 记录一次工具调用
     *
     * @param array{
     *   tool:string, capability?:string, source?:string, arguments?:array,
     *   ok?:bool, blocked?:bool, reason?:string, content?:string,
     *   duration_ms?:float, approval_id?:int|null, session_id?:string
     * } $payload
     */
    public static function record(array $payload): void
    {
        try {
            $tool   = (string) ($payload['tool'] ?? 'unknown');
            $blocked = (bool) ($payload['blocked'] ?? false);
            $ok      = (bool) ($payload['ok'] ?? false);

            $arguments = is_array($payload['arguments'] ?? null) ? $payload['arguments'] : [];

            $status = $ok && !$blocked ? self::STATUS_DONE : self::STATUS_FAILED;
            $error  = $blocked
                ? (string) ($payload['reason'] ?? 'blocked')
                : ($ok ? null : (string) ($payload['reason'] ?? 'failed'));

            $result = (string) ($payload['content'] ?? '');
            if (mb_strlen($result) > self::RESULT_LIMIT) {
                $result = mb_substr($result, 0, self::RESULT_LIMIT) . '…[truncated]';
            }

            Db::name('ai_agent_logs')->insert([
                'session_id'       => self::clip((string) ($payload['session_id'] ?? ''), 64) ?: '-',
                'user_type'        => self::clip(RequestContext::getGuard(), 32) ?: 'admin',
                'user_id'          => (int) (RequestContext::getUserId() ?? 0),
                'tracking_id'      => bin2hex(random_bytes(16)),
                'agent_type'       => 'tool',
                'job_type'         => 'tool',
                'tool_name'        => self::clip($tool, 128),
                'capability'       => self::clip((string) ($payload['capability'] ?? ''), 191) ?: null,
                'entry_source'     => self::clip((string) ($payload['source'] ?? 'unknown'), 32) ?: null,
                'arguments_digest' => $arguments === []
                    ? null
                    : hash('sha256', (string) json_encode($arguments, JSON_UNESCAPED_UNICODE)),
                'approval_id'      => isset($payload['approval_id']) && $payload['approval_id'] !== null
                    ? (int) $payload['approval_id']
                    : null,
                'task'             => $tool,
                'result'           => $result !== '' ? $result : null,
                'steps'            => 1,
                'duration'         => round(((float) ($payload['duration_ms'] ?? 0)) / 1000, 4),
                'status'           => $status,
                'error'            => $error,
                'create_time'      => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            ContextualLogger::warning('[tool-audit] 写入 ai_agent_logs 失败：' . $e->getMessage(), [
                'tool' => (string) ($payload['tool'] ?? 'unknown'),
            ]);
        }
    }

    private static function clip(string $value, int $length): string
    {
        return mb_strlen($value) > $length ? mb_substr($value, 0, $length) : $value;
    }
}
