<?php
declare(strict_types=1);

namespace wise\agent\cost;

use wise\support\rate\RateLimiter;

/**
 * 配额守门＼�
 *
 * 在「开始一次 Agent 执行」这一层做**预算守门**（不是精确扣费——精确值由事后记账 +
 * 日汇总体现）。四个维度，任一超限即拒绝：
 *   - user   `user:{type}:{id}`        权重 = 预估 token，限额 `per_user_daily_tokens`
 *   - model  `model:{provider}:{model}` 权重 = 1（calls），限额 `per_model_daily_calls`
 *   - role   `role:{ident}`            权重 = 预估 token，限额 `per_role_daily_tokens`
 *   - plugin `plugin:{ident}`          权重 = 预估 token，限额 `per_plugin_daily_tokens`
 *
 *   - 配额 **fail-closed**（默认 `fail_mode=closed`）：拿不到额度 ⇒ 拒绝（成本闸门）；
 *   - 限流类可另配 `fail_mode=open`（Redis 抖动 ⇒ 放行，保护措施不成为单点故障）。
 *
 * `enforce=false` 时**只观测不拒绝**（仍返回 quota 信息，供调用方记审计/日志）。
 *
 * @package wise\agent
 */
final class QuotaGuard
{
    public function __construct(
        private readonly array $config = [],
        /** 可注入的 hit 回调（单测用替身 store 模拟故障），签名 fn(string $key, int $limit, int $weight): int */
        private readonly ?\Closure $hitFn = null,
    ) {
    }

    /**
     * 检查是否允许开始一次执行
     *
     * @param array<string, mixed> $context user_type/user_id/roles/plugin/provider/model/weight
     * @return array{allowed: bool, reason?: string, quota?: array{scope: string, key: string, used: int, limit: int, reset_at: string}}
     */
    public function check(array $context): array
    {
        if (!(bool) ($this->config['enabled'] ?? false)) {
            return ['allowed' => true];
        }

        $enforce = (bool) ($this->config['enforce'] ?? false);
        $weight  = max(1, (int) ($context['weight'] ?? 0));

        $exceeded = $this->tryDimension(
            'user',
            'user:' . (string) ($context['user_type'] ?? '') . ':' . (int) ($context['user_id'] ?? 0),
            (int) ($this->config['per_user_daily_tokens'] ?? 0),
            $weight
        );
        if ($exceeded !== null) {
            return $this->finalize($exceeded, $enforce);
        }

        $exceeded = $this->tryDimension(
            'model',
            'model:' . (string) ($context['provider'] ?? '') . ':' . (string) ($context['model'] ?? ''),
            (int) ($this->config['per_model_daily_calls'] ?? 0),
            1
        );
        if ($exceeded !== null) {
            return $this->finalize($exceeded, $enforce);
        }

        $roleLimit = (int) ($this->config['per_role_daily_tokens'] ?? 0);
        if ($roleLimit > 0) {
            foreach ((array) ($context['roles'] ?? []) as $role) {
                $exceeded = $this->tryDimension('role', 'role:' . (string) $role, $roleLimit, $weight);
                if ($exceeded !== null) {
                    return $this->finalize($exceeded, $enforce);
                }
            }
        }

        $plugin = (string) ($context['plugin'] ?? '');
        $pluginLimit = (int) ($this->config['per_plugin_daily_tokens'] ?? 0);
        if ($pluginLimit > 0 && $plugin !== '') {
            $exceeded = $this->tryDimension('plugin', 'plugin:' . $plugin, $pluginLimit, $weight);
            if ($exceeded !== null) {
                return $this->finalize($exceeded, $enforce);
            }
        }

        return ['allowed' => true];
    }

    /**
     * 单个维度的检查；超限/存储不可用（fail-closed）时返回 quota，否则 null
     *
     * @return array{reason: string, quota: array{scope: string, key: string, used: int, limit: int, reset_at: string}}|null
     */
    private function tryDimension(string $scope, string $key, int $limit, int $weight): ?array
    {
        if ($limit <= 0) {
            return null;
        }

        $resetAt = date('Y-m-d 00:00:00', strtotime('+1 day'));

        try {
            $remaining = $this->hit($key, $limit, $weight);

            if ($remaining <= 0) {
                return [
                    'reason' => 'quota_exceeded',
                    'quota'  => [
                        'scope'    => $scope,
                        'key'      => $key,
                        'used'     => $limit - $remaining,
                        'limit'    => $limit,
                        'reset_at' => $resetAt,
                    ],
                ];
            }

            return null;
        } catch (\Throwable) {
            if ($this->failClosed()) {
                return [
                    'reason' => 'store_unavailable',
                    'quota'  => [
                        'scope'    => $scope,
                        'key'      => $key,
                        'used'     => 0,
                        'limit'    => $limit,
                        'reset_at' => $resetAt,
                    ],
                ];
            }

            return null;
        }
    }

    /**
     * @param array{reason: string, quota: array} $exceeded
     * @return array{allowed: bool, reason: string, quota: array}
     */
    private function finalize(array $exceeded, bool $enforce): array
    {
        if ($enforce) {
            return [
                'allowed' => false,
                'reason'  => $exceeded['reason'],
                'quota'   => $exceeded['quota'],
            ];
        }

        return [
            'allowed' => true,
            'reason'  => 'observed',
            'quota'   => $exceeded['quota'],
        ];
    }

    private function hit(string $key, int $limit, int $weight): int
    {
        if ($this->hitFn !== null) {
            return (int) ($this->hitFn)($key, $limit, $weight);
        }

        $store = (string) ($this->config['store'] ?? 'redis');

        return RateLimiter::for($limit, 86400)
            ->by($key)
            ->via($store)
            ->hit($weight);
    }

    private function failClosed(): bool
    {
        return (string) ($this->config['fail_mode'] ?? 'closed') !== 'open';
    }
}
