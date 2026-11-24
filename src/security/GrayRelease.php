<?php
declare(strict_types=1);

namespace wise\agent\security;

/**
 * 灰度放量判定器＼�
 *
 * 语义：能力 `X` 对「谁」开放写操作。**未放量 ⇒ 自动降级为沙箱预演（不是拒绝）**，
 * 让「新能力默认只读试运行」与「按角色逐步放量」是同一个机制。
 *
 * 判定顺序＼�粗到细、先严后宽，越靠后越"降级"）：
 *   `tool_authorize.deny → RBAC → 审批 → gray → sandbox → 执行`
 * 本类只负责 gray 这一层：
 *   1. `enabled=false` ⇒ allowed（灰度关闭，不拦截）
 *   2. `exempt_roles` 命中 ⇒ allowed（超管始终放量，避免管理员锁死）
 *   3. 命中规则（能力前缀，**最长匹配优先**，`*` 兜底）：
 *      a. 显式 `denied` ⇒ denied
 *      b. `users` 命中 ⇒ allowed
 *      c. `roles` 命中 ⇒ allowed
 *      d. `percent` 确定性哈希 ⇒ allowed
 *      e. 否则 ⇒ sandbox（未放量，降级为预演）
 *
 * ## 确定性哈希＼�灰度最常踩的坑）
 * - ❌ `mt_rand() < percent`：同用户同能力时好时坏，无法复现；
 * - ✅ `crc32("userType:userId:capability") % 100 < percent`：同人同能力同百分比结果恒定；
 *   调整百分比只会**单向放量**（0→20→50 时原放量的仍在放量），即「单调放量」。
 *
 * @package wise\agent
 */
final class GrayRelease
{
    public function __construct(
        private readonly array $config = [],
    ) {
    }

    /**
     * 判定某个能力对某用户是否放量
     *
     * @param string   $capability 能力名（如 `menu.action.host.user.delete`）
     * @param string   $userType   用户类型（如 `admin`）
     * @param int      $userId     用户 ID
     * @param string[] $roles      用户角色标识列表（slug），由调用方解析传入
     * @return array{action: string, reason: string, rule: string} action ∈ allowed|denied|sandbox
     */
    public function decide(string $capability, string $userType, int $userId, array $roles): array
    {
        if (!(bool) ($this->config['enabled'] ?? false)) {
            return ['action' => 'allowed', 'reason' => 'gray_disabled', 'rule' => ''];
        }

        $exempt = (array) ($this->config['exempt_roles'] ?? []);
        if (array_intersect($roles, $exempt)) {
            return ['action' => 'allowed', 'reason' => 'exempt_role', 'rule' => ''];
        }

        $rule = $this->matchRule($capability);

        if ($rule === null) {
            return ['action' => 'sandbox', 'reason' => 'no_rule', 'rule' => ''];
        }

        $ruleKey = $rule['key'];
        $cfg     = $rule['config'];

        $denied = (array) ($cfg['denied'] ?? []);
        if (in_array($capability, $denied, true)) {
            return ['action' => 'denied', 'reason' => 'explicit_deny', 'rule' => $ruleKey];
        }

        $users = (array) ($cfg['users'] ?? []);
        if (in_array($userId, $users, true)) {
            return ['action' => 'allowed', 'reason' => 'explicit_user', 'rule' => $ruleKey];
        }

        $ruleRoles = (array) ($cfg['roles'] ?? []);
        if (array_intersect($roles, $ruleRoles)) {
            return ['action' => 'allowed', 'reason' => 'explicit_role', 'rule' => $ruleKey];
        }

        $percent = (int) ($cfg['percent'] ?? 0);
        if ($this->hashPercent($userType, $userId, $capability, $percent)) {
            return ['action' => 'allowed', 'reason' => 'percent', 'rule' => $ruleKey];
        }

        return ['action' => 'sandbox', 'reason' => 'not_released', 'rule' => $ruleKey];
    }

    /**
     * 确定性哈希（单调放量）
     */
    private function hashPercent(string $userType, int $userId, string $capability, int $percent): bool
    {
        if ($percent <= 0) {
            return false;
        }
        if ($percent >= 100) {
            return true;
        }

        $hash = abs(crc32("{$userType}:{$userId}:{$capability}"));

        return ($hash % 100) < $percent;
    }

    /**
     * 能力前缀匹配（最长匹配优先，`*` 兜底）
     *
     * @return array{key: string, config: array<string, mixed>}|null
     */
    private function matchRule(string $capability): ?array
    {
        $rules   = (array) ($this->config['rules'] ?? []);
        $bestKey = null;
        $bestLen = -1;

        foreach ($rules as $prefix => $ruleConfig) {
            $prefix  = (string) $prefix;
            $pattern = rtrim($prefix, '*');

            if ($pattern !== '' && !str_starts_with($capability, $pattern)) {
                continue;
            }

            $len = strlen($pattern);
            if ($len > $bestLen) {
                $bestLen = $len;
                $bestKey = $prefix;
            }
        }

        if ($bestKey === null) {
            return null;
        }

        return [
            'key'    => $bestKey,
            'config' => is_array($rules[$bestKey] ?? null) ? $rules[$bestKey] : [],
        ];
    }
}
