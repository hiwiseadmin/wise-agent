<?php
declare(strict_types=1);

namespace wise\agent\menu;

/**
 * 控制器动作结果归一化（A1-3）
 *
 * 把控制器动作的**任意**返回形态归一化为与 A4 `ConsoleResult` 同核的「观察」契约：
 *
 * ```json
 * { "code":0, "msg":"操作成功", "structured":true,
 *   "data":{"total":12,"list":[...]}, "text":"", "truncated":false,
 *   "request_id":"...", "timestamp":1760000000,
 *   "controller":"app\\admin\\controller\\Menu", "action":"getList", "duration_ms":46 }
 * ```
 *
 * 归一化规则（对应 `CrudAction` 的实际返回形态）：
 *
 * | 控制器返回 | structured | data | text |
 * |---|---|---|---|
 * | `$this->success()/error()`（`{code,msg,data,request_id,timestamp}`） | true | `data` | `''` |
 * | `json($rows)`（裸数组） | true | 去掉壳键后的数组 | `''` |
 * | 标量 / `null` | true | 原值 | `''` |
 * | `$this->fetch()`（HTML 字符串） | false | `{html_length,hint}` | **不回传正文**，只给一行提示 |
 * | 对象（非 Response） | false | `null` | JSON 化后的文本 |
 *
 * 三条必须遵守的约定：
 *   1. **HTML 不外传**：`index/add/edit/preview` 的 HTML 可达数百 KB，回传给 LLM 既浪费
 *      也毫无信息量，只回长度与提示；
 *   2. **异常也吐 JSON**：任何失败都返回同核结构，绝不把裸异常抛给 Agent 循环；
 *   3. **不透传 `debug`**：框架 `JsonResponse` 在 debug 下会带 `debug` 字段，此处只取
 *      `code/msg/data/request_id/timestamp`，不带内部调试信息。
 */
final class MenuActionResult
{
    /** 失败环节：入参校验 */
    public const STAGE_ARGUMENTS = 'arguments';

    /** 失败环节：动作不可执行（HTML 动作 / 未知动作） */
    public const STAGE_ACTION = 'action';

    /** 失败环节：护栏拒绝（A1-4 使用） */
    public const STAGE_GUARD = 'guard';

    /** 失败环节：执行期异常 */
    public const STAGE_EXECUTION = 'execution';

    /** 文本模式默认截断长度 */
    public const DEFAULT_MAX_TEXT = 4000;

    /**
     * 归一化控制器动作的返回值
     *
     * @param mixed $response 控制器动作返回值
     * @param array<string, mixed> $context controller / action / menu_path / duration_ms / max_text_length
     * @return array<string, mixed>
     */
    public static function normalize(mixed $response, array $context = []): array
    {
        $maxText = (int) ($context['max_text_length'] ?? self::DEFAULT_MAX_TEXT);
        $base    = self::base($context);

        if ($response instanceof \think\Response) {
            $data   = $response->getData();
            $isJson = $response instanceof \think\response\Json;

            if ($isJson || is_array($data)) {
                return self::fromPayload(is_array($data) ? $data : ['data' => $data], $base);
            }

            return self::textResult(
                is_scalar($data) ? (string) $data : '',
                $base,
                $maxText,
                'response'
            );
        }

        if (is_array($response)) {
            return self::fromPayload($response, $base);
        }

        if (is_string($response)) {
            return self::textResult($response, $base, $maxText);
        }

        if ($response === null || is_scalar($response)) {
            return self::fromPayload([], $base, $response);
        }

        $encoded = json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return self::textResult(is_string($encoded) ? $encoded : '', $base, $maxText, 'object');
    }

    /**
     * 构造失败结果（入参非法 / 动作不可执行 / 容器不可用 / 护栏拒绝）
     *
     * @param array<string, mixed> $extra 额外字段（如 stage / duration_ms）
     * @return array<string, mixed>
     */
    public static function failure(string $message, int $code = 1, array $extra = []): array
    {
        return array_merge([
            'code'       => $code,
            'msg'        => $message,
            'structured' => false,
            'data'       => null,
            'text'       => $message,
            'truncated'  => false,
        ], $extra);
    }

    /**
     * 由异常构造失败结果（永远返回同核结构，不向上抛）
     *
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public static function fromException(\Throwable $e, array $context = []): array
    {
        return self::failure(
            $e->getMessage() !== '' ? $e->getMessage() : get_class($e),
            1,
            array_merge(self::base($context), [
                'stage'      => (string) ($context['stage'] ?? self::STAGE_EXECUTION),
                'error_type' => get_class($e),
            ])
        );
    }

    /**
     * 文本截断（返回文本 + 是否被截断）
     *
     * @return array{0: string, 1: bool}
     */
    public static function truncate(string $text, int $maxLength): array
    {
        if ($maxLength <= 0 || self::length($text) <= $maxLength) {
            return [$text, false];
        }

        return [self::substr($text, 0, $maxLength) . "\n… [已截断，原文 " . self::length($text) . ' 字]', true];
    }

    /**
     * 公共字段载体（controller / action / menu_path / duration_ms）
     *
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private static function base(array $context): array
    {
        $base = [];

        foreach (['controller', 'action', 'menu_path'] as $key) {
            if (!empty($context[$key])) {
                $base[$key] = $context[$key];
            }
        }

        $base['duration_ms'] = (int) ($context['duration_ms'] ?? 0);

        return $base;
    }

    /**
     * 结构化负载 → 观察契约
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $base
     * @return array<string, mixed>
     */
    private static function fromPayload(array $payload, array $base, mixed $scalarData = null): array
    {
        $hasCode = array_key_exists('code', $payload);
        $code    = $hasCode ? (int) $payload['code'] : 0;
        $msg     = isset($payload['msg']) ? (string) $payload['msg'] : ($code === 0 ? 'ok' : 'error');

        if (array_key_exists('data', $payload)) {
            $data = $payload['data'];
        } elseif ($payload === []) {
            $data = $scalarData;
        } else {
            $data = $payload;
            unset($data['code'], $data['msg'], $data['request_id'], $data['timestamp'], $data['debug']);
        }

        $result = array_merge($base, [
            'code'       => $code,
            'msg'        => $msg,
            'structured' => true,
            'data'       => $data,
            'text'       => '',
            'truncated'  => false,
        ]);

        foreach (['request_id', 'timestamp'] as $key) {
            if (array_key_exists($key, $payload)) {
                $result[$key] = $payload[$key];
            }
        }

        return $result;
    }

    /**
     * 文本 / HTML → 观察契约
     *
     * @param array<string, mixed> $base
     * @return array<string, mixed>
     */
    private static function textResult(string $text, array $base, int $maxText, string $kind = ''): array
    {
        if (self::looksLikeHtml($text)) {
            return array_merge($base, [
                'code'       => 0,
                'msg'        => 'html',
                'structured' => false,
                'data'       => [
                    'html_length' => self::length($text),
                    'hint'        => '该动作返回 HTML 页面（列表页/表单页），不适合 AI 消费；如需数据请改用 getList。',
                ],
                'text'       => sprintf('[HTML 页面，长度 %d，未回传正文]', self::length($text)),
                'truncated'  => false,
            ]);
        }

        [$cut, $truncated] = self::truncate($text, $maxText);

        $result = array_merge($base, [
            'code'       => 0,
            'msg'        => $kind !== '' ? $kind : 'text',
            'structured' => false,
            'data'       => null,
            'text'       => $cut,
            'truncated'  => $truncated,
        ]);

        return $result;
    }

    private static function looksLikeHtml(string $text): bool
    {
        if ($text === '') {
            return false;
        }

        return (bool) preg_match('/<\s*(!doctype|html|head|body|div|table|form|script)\b/i', $text);
    }

    private static function length(string $text): int
    {
        return function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
    }

    private static function substr(string $text, int $start, int $length): string
    {
        return function_exists('mb_substr') ? mb_substr($text, $start, $length) : substr($text, $start, $length);
    }
}
