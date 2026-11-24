<?php
declare(strict_types=1);

namespace wise\agent\console;

use wise\support\tools\CommandTool;

/**
 * 命令结果归一化器＼�
 *
 * 无论命令是否支持 `--json`，都归一化为**同一结构**，让 Agent 的「观察」
 * 处理逻辑与 A1（HTTP 控制器动作，`{code,msg,data}`）保持一致：
 *
 * ```json
 * {
 *   "code": 0,              // 0 成功；非 0 取命令 exit_code / 命令自带 code / CommandTool 错误码
 *   "msg": "ok",            // 摘要
 *   "structured": true,     // true = 命令原生 JSON；false = 文本归一化
 *   "data": { },            // structured=true 时为命令 JSON 的 data（或整个 payload）
 *   "text": "...",          // 归一化文本（已去 ANSI 颜色码）
 *   "truncated": false,     // 输出是否被 max_output_length 截断
 *   "exit_code": 0,
 *   "duration_ms": 830
 * }
 * ```
 *
 * 必须处理的 5 个坑＼�：
 *   1. JSON 纯净性 → 允许 stdout 前置脏行，用「首 `{` / 末 `}` 切片」容错；
 *   2. 大输出截断   → JSON 截断即非法，标记 `truncated` 并回退文本；
 *   3. 异常也吐 JSON → CommandTool 的失败结果同样被归一化（不向上抛）；
 *   4. option 冲突   → 此处不处理（由命令侧约定 --quiet 优先）；
 *   5. 注入去重      → `supportsJson` 由内省决定，本类只负责解析。
 */
final class ConsoleResult
{
    /**
     * 归一化 CommandTool 执行结果为统一「观察」契约
     *
     * @param ConsoleCommandMeta $meta            命令元数据
     * @param array              $result          CommandTool::execute() 原始返回
     * @param int                $maxOutputLength 与执行时一致的截断阈值（0 = 不判断）
     */
    public static function normalize(ConsoleCommandMeta $meta, array $result, int $maxOutputLength = 0): array
    {
        $exitCode  = (int) ($result['exit_code'] ?? 0);
        $raw       = (string) ($result['output'] ?? '');
        $errorCode = (int) ($result['error_code'] ?? CommandTool::ERROR_NONE);
        $success   = (bool) ($result['success'] ?? ($errorCode === CommandTool::ERROR_NONE));
        $duration  = (int) round(((float) ($result['execution_time'] ?? 0)) * 1000);

        $truncated  = self::isTruncated($raw, $maxOutputLength);
        $structured = false;
        $data       = null;
        $text       = '';
        $code       = null;
        $msg        = '';

        if ($meta->supportsJson) {
            $payload = self::decodeJson($raw);
            if ($payload !== null) {
                $structured = true;
                $code = isset($payload['code']) ? (int) $payload['code'] : $exitCode;
                $msg  = isset($payload['msg']) ? (string) $payload['msg'] : '';
                $data = $payload['data'] ?? $payload;
            }
        }

        if (!$structured) {
            $text = self::stripAnsi($raw);
            $code = $exitCode;
        }

        if ((int) $code === 0 && (!$success || $errorCode !== CommandTool::ERROR_NONE)) {
            $code = $errorCode === CommandTool::ERROR_NONE ? 1 : $errorCode;
        }

        $code = (int) $code;

        if ($msg === '') {
            $msg = $code === 0
                ? 'ok'
                : (string) ($result['message'] ?? 'command failed');
        }

        return [
            'code'        => $code,
            'msg'         => $msg,
            'structured'  => $structured,
            'data'        => $data,
            'text'        => $text,
            'truncated'   => $truncated,
            'exit_code'   => $exitCode,
            'duration_ms' => $duration,
            'command'     => (string) ($result['command'] ?? $meta->name),
        ];
    }

    /**
     * 构造失败结果（护栏拒绝 / 参数非法 / 执行器异常时使用）
     *
     * 保证 Agent 侧永远拿到结构化 JSON，而非未格式化错误栈＼�。
     */
    public static function failure(string $message, int $code = 1, array $extra = []): array
    {
        return array_merge([
            'code'        => $code,
            'msg'         => $message,
            'structured'  => false,
            'data'        => null,
            'text'        => $message,
            'truncated'   => false,
            'exit_code'   => $code,
            'duration_ms' => 0,
        ], $extra);
    }

    /**
     * 从 stdout 中容错提取 JSON
     *
     * 命令可能先输出 PHP warning / 提示行，再输出 JSON；
     * 因此先尝试整体解析，失败则用「首个 `{` 到最后一个 `}`」切片重试。
     * JSON 被截断时切片解析必然失败 → 返回 null（由调用方回退文本 + truncated 标记）。
     *
     * @return array<string, mixed>|null
     */
    public static function decodeJson(string $raw): ?array
    {
        $raw = self::stripAnsi($raw);
        $raw = trim($raw);

        if ($raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        $start = strpos($raw, '{');
        $end   = strrpos($raw, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $decoded = json_decode(substr($raw, $start, $end - $start + 1), true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * 去除 ANSI 颜色码 / 控制字符（文本归一化）
     */
    public static function stripAnsi(string $text): string
    {
        $text = (string) preg_replace('/\x1B\[[0-9;?]*[ -\/]*[@-~]/', '', $text);
        $text = (string) preg_replace('/\x1B[@-Z\\\\-_]/', '', $text);

        return $text;
    }

    /**
     * 是否被截断
     *
     * 双判定：(1) CommandTool 的截断标记；(2) 输出长度触顶 max_output_length。
     */
    public static function isTruncated(string $output, int $maxOutputLength = 0): bool
    {
        if (str_contains($output, '[输出被截断')) {
            return true;
        }

        return $maxOutputLength > 0 && strlen($output) >= $maxOutputLength;
    }
}
