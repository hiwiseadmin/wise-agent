<?php
declare(strict_types=1);

namespace wise\agent\client;

/**
 * Token 与成本估算器（B4-6）
 *
 * 为什么需要
 * ----------
 * 上线前必须能回答「这次调用大概花多少钱」。此前契约里没有
 * `listModels` / `countTokens` / `estimateCost`，导致：
 *   - 后台无法在下拉框里列出可用模型，只能手填模型名；
 *   - 配额（`QuotaGuard`）只能按调用次数算，无法按 token 预算；
 *   - 用户看不到成本预估，账单页只能「事后对账」。
 *
 * 精度声明（重要）
 * ----------------
 * 这里**不做真实分词**。精确计数需要各家的 tokenizer（tiktoken 等），
 * 引入 PHP 侧实现或子进程调用都不划算。采用业界通行的启发式：
 *   - CJK 字符按 1 token/字（中文实际约 1~1.5 字/token，偏保守）；
 *   - 其余字符按 4 字符/token；
 *   - 每条消息叠加固定开销（role / 分隔符）。
 * 结论：**估算值，用于预算与展示，不得用于计费结算**。真实用量一律以
 * Provider 返回的 `usage` 为准（`chat()` 已经把它透传出来）。
 *
 * @package wise\agent\client
 */
final class TokenEstimator
{
    /** 每条消息的结构开销（role 标记、分隔符等） */
    private const MESSAGE_OVERHEAD = 4;

    /** 每条消息内容之后的固定开销 */
    private const MESSAGE_TAIL = 3;

    /**
     * 估算 token 数
     *
     * @param string|array $input 纯文本，或消息数组 [['role'=>..,'content'=>..], ...]
     */
    public static function count(string|array $input): int
    {
        if (is_string($input)) {
            return self::countText($input);
        }

        $total = 0;

        foreach ($input as $message) {
            if (is_string($message)) {
                $total += self::countText($message);
                continue;
            }

            if (!is_array($message)) {
                continue;
            }

            $total += self::MESSAGE_OVERHEAD;
            $total += self::countText((string) ($message['role'] ?? ''));
            $total += self::countText(self::flattenContent($message['content'] ?? ''));
            $total += self::MESSAGE_TAIL;
        }

        return $total;
    }

    /**
     * 估算「工具 schema」占用的 input token
     *
     * 为什么单独一个方法：{@see self::count()} 只按**消息**（role/content）计数，
     * 直接把 `ToolRegistry::getToolSchemas()` 的产物传进去只会算到每条 7 token 的
     * 结构开销（name / description / parameters 全被忽略），低估一个数量级。
     * 工具 schema 是本项目 prompt 的**大头**（每条 `cmd_*` 描述 + 参数 Schema），
     * 必须单独按 JSON 文本估算。
     *
     * @param array<int, array<string, mixed>> $tools getToolSchemas() 的产物
     */
    public static function countTools(array $tools): int
    {
        $total = 0;

        foreach ($tools as $tool) {
            $json  = json_encode($tool, JSON_UNESCAPED_UNICODE);
            $total += self::countText(is_string($json) ? $json : '');
            $total += 6;
        }

        return $total;
    }

    /**
     * 估算成本（单位：该 Provider 的计价货币，OpenAI / DeepSeek 均为美元）
     *
     * @param array<string, array{input:float, output:float}> $pricing 每 1M token 单价
     */
    public static function cost(
        array $pricing,
        string $model,
        int $promptTokens,
        int $completionTokens
    ): float {
        $price = $pricing[$model] ?? null;

        if ($price === null) {
            return 0.0;
        }

        return ($promptTokens / 1_000_000) * (float) $price['input']
            + ($completionTokens / 1_000_000) * (float) $price['output'];
    }

    /**
     * 文本 token 估算
     */
    private static function countText(string $text): int
    {
        if ($text === '') {
            return 0;
        }

        $cjk = preg_match_all('/[\x{4E00}-\x{9FFF}\x{3040}-\x{30FF}\x{AC00}-\x{D7AF}]/u', $text, $m);

        if ($cjk === false) {
            return (int) ceil(strlen($text) / 4);
        }

        $rest = (int) ceil((mb_strlen($text) - $cjk) / 4);

        return $cjk + $rest;
    }

    /**
     * 多模态 content（数组形式）折叠为文本长度参考
     */
    private static function flattenContent(mixed $content): string
    {
        if (is_string($content)) {
            return $content;
        }

        if (!is_array($content)) {
            return '';
        }

        $buffer = '';

        foreach ($content as $part) {
            if (is_string($part)) {
                $buffer .= $part;
                continue;
            }

            if (is_array($part) && isset($part['text'])) {
                $buffer .= (string) $part['text'];
            }
        }

        return $buffer;
    }
}
