<?php
declare(strict_types=1);

namespace wise\agent\plan;

/**
 * 审批/权限拦截信号识别＼�
 *
 * 计划里的写步骤**走既有工具**，因此它被 `ToolSecurityCheck` 拦截时，
 * 拿到的不是异常，而是一段**结构化 JSON**（`src/listener/ToolSecurityCheck.php:161-186`）：
 *
 * ```json
 * {
 *   "error": "Tool 'x' requires approval before execution",
 *   "required_action": "workflow" | "confirm" | "rejected",
 *   "tool_name": "x",
 *   "approval_rule": "access:write",
 *   "approval_status": "pending" | "rejected",
 *   "approval_id": 42,
 *   "approval_ident": "AP20261016...",
 *   "reason": "..."
 * }
 * ```
 *
 * 权限不足则是另一种结构（`blockPermission()`，`:141-156`）：`error` 以
 * `Permission denied:` 开头并带 `source`/`access`。
 *
 * ## 三类信号的处置（差别很重要）
 *
 * | 信号 | 含义 | 计划执行器的处置 |
 * |---|---|---|
 * | `pending` | 等待人工确认/审批 | **暂停**（`paused` + `blocked_pending_approval`），**不算失败、不消耗重试** |
 * | `rejected` | 人工已驳回（或冷却期内） | **终止计划** —— 人工的否定不应被「自动改道」绕过 |
 * | `permission_denied` | 无权限 | 该步失败（业务性失败，不是等人） |
 */
final class PlanApprovalSignal
{
    public const KIND_NONE              = 'none';
    public const KIND_PENDING           = 'pending';
    public const KIND_REJECTED          = 'rejected';
    public const KIND_PERMISSION_DENIED = 'permission_denied';

    /**
     * 解析工具输出里的拦截信号
     *
     * @return array{kind:string, approval_id:?int, ident:string, mode:string, reason:string, tool:string}
     */
    public static function of(mixed $output): array
    {
        $empty = [
            'kind'        => self::KIND_NONE,
            'approval_id' => null,
            'ident'       => '',
            'mode'        => '',
            'reason'      => '',
            'tool'        => '',
        ];

        $decoded = self::decode($output);

        if (!is_array($decoded)) {
            return $empty;
        }

        $action = strtolower(trim((string) ($decoded['required_action'] ?? '')));
        $status = strtolower(trim((string) ($decoded['approval_status'] ?? '')));

        if ($action !== '' || $status !== '') {
            $kind = ($action === 'rejected' || $status === 'rejected')
                ? self::KIND_REJECTED
                : self::KIND_PENDING;

            return [
                'kind'        => $kind,
                'approval_id' => isset($decoded['approval_id']) ? (int) $decoded['approval_id'] : null,
                'ident'       => (string) ($decoded['approval_ident'] ?? ''),
                'mode'        => $action,
                'reason'      => (string) ($decoded['reason'] ?? $decoded['error'] ?? ''),
                'tool'        => (string) ($decoded['tool_name'] ?? ''),
            ];
        }

        $error = (string) ($decoded['error'] ?? '');

        if (stripos($error, 'Permission denied') === 0 || (isset($decoded['source']) && isset($decoded['access']))) {
            return [
                'kind'        => self::KIND_PERMISSION_DENIED,
                'approval_id' => null,
                'ident'       => '',
                'mode'        => (string) ($decoded['access'] ?? ''),
                'reason'      => $error !== '' ? $error : 'permission denied',
                'tool'        => (string) ($decoded['tool'] ?? ''),
            ];
        }

        return $empty;
    }

    /**
     * 是否「等人」信号（执行器据此暂停而**不计失败**）
     */
    public static function isPending(mixed $output): bool
    {
        return self::of($output)['kind'] === self::KIND_PENDING;
    }

    private static function decode(mixed $output): mixed
    {
        if (!is_string($output)) {
            return $output;
        }

        $decoded = json_decode($output, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $output;
    }
}
