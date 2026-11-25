<?php
declare(strict_types=1);

namespace wise\agent\cost;

/**
 * 成本估算器＼�
 *
 * 只做**估算口径**的纯计算，不做任何 IO：
 *   cost = prompt_tokens/1000 × input单价 + completion_tokens/1000 × output单价
 *
 * 三条铁律（验收 7）：
 *  - 已定价模型：按 prompt/completion 单价**分别**计算；
 *  - 未定价模型：`priced=false` 且 `cost=0`、reason=`pricing_unknown`
 *    （**绝不按 0 价假装算过**，页面上 `unpriced_calls` 要能看到"有多少调用没价"）；
 *  - 用量缺失（部分 provider 不返回 usage）：`reason=usage_missing`，同样不算钱。
 *
 * 输入只有两个来源（事实 13，不新增采集点）：
 *  `AgentContext::getUsage()`（`{prompt_tokens, completion_tokens, total_tokens}`）与 `provider/model`。
 */
final class CostCalculator
{
    /**
     * @param array<string, mixed> $pricing 定价表：provider => model => ['input'=>每千token, 'output'=>每千token, 'currency'=>…]
     *                                       另支持顶层 `'*:default'` 兜底（未列出的模型，通常 0 价 ⇒ 不估钱）
     */
    public function __construct(
        private readonly array $pricing = [],
    ) {
    }

    /**
     * 估算一次调用的成本
     *
     * @param array<string, mixed> $usage `AgentContext::getUsage()` 结果
     * @return array{cost: float, currency: string, priced: bool, reason: string}
     */
    public function estimate(string $provider, string $model, array $usage): array
    {
        $prompt     = (int) ($usage['prompt_tokens'] ?? 0);
        $completion = (int) ($usage['completion_tokens'] ?? 0);
        $total      = (int) ($usage['total_tokens'] ?? 0);

        if ($prompt === 0 && $completion === 0 && $total === 0) {
            return $this->result(0.0, 'CNY', false, 'usage_missing');
        }

        $rate = $this->resolveRate($provider, $model);
        if ($rate === null) {
            return $this->result(0.0, 'CNY', false, 'pricing_unknown');
        }

        $inputPrice  = (float) ($rate['input'] ?? 0);
        $outputPrice = (float) ($rate['output'] ?? 0);
        $currency    = (string) ($rate['currency'] ?? 'CNY');

        if ($inputPrice <= 0.0 && $outputPrice <= 0.0) {
            return $this->result(0.0, $currency, false, 'pricing_unknown');
        }

        $cost = ($prompt / 1000.0 * $inputPrice) + ($completion / 1000.0 * $outputPrice);

        return $this->result(round($cost, 6), $currency, true, 'ok');
    }

    /**
     * 解析定价：先精确 `pricing[provider][model]`，再兜底 `pricing['*:default']`
     *
     * @return array<string, mixed>|null
     */
    private function resolveRate(string $provider, string $model): ?array
    {
        $rate = $this->pricing[$provider][$model] ?? null;
        if (is_array($rate)) {
            return $rate;
        }

        $default = $this->pricing['*:default'] ?? null;
        return is_array($default) ? $default : null;
    }

    /**
     * @return array{cost: float, currency: string, priced: bool, reason: string}
     */
    private function result(float $cost, string $currency, bool $priced, string $reason): array
    {
        return [
            'cost'     => $cost,
            'currency' => $currency,
            'priced'   => $priced,
            'reason'   => $reason,
        ];
    }
}
