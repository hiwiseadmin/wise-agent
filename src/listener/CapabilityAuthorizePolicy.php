<?php
declare(strict_types=1);

namespace wise\agent\listener;

use wise\support\context\ContextualLogger;
use wise\agent\event\CapabilityAuthorize;

/**
 * 能力授权策略监听器（声明式）
 *
 * 订阅：ToolAuthorize（工具）/ SkillAuthorize（技能）
 *
 * 这是 wise-agent 内置的「配置驱动」授权策略，实现与插件 / 业务包解耦的灵活授权：
 *   - 未命中任何规则 → 不表态，交由 CapabilityAuthorizer 回落 wise-auth RBAC（超管直通）
 *   - 命中 deny     → 硬拒绝，且不会再走 RBAC 兜底
 *   - 命中 allow    → 直接放行（无需为该能力配置角色权限）
 *
 * 配置位置：config/wise-agent.php → security.tool_authorize
 *
 * ```php
 * 'tool_authorize' => [
 *     // 允许清单：能力名（orchestrate_step / content.translate）、权限标识（ai.tool.db_query）
 *     //          或类别（access:read / access:write），支持 * 后缀通配
 *     'allow' => ['access:read'],
 *     // 拒绝清单：优先级高于允许清单与 RBAC
 *     'deny'  => ['access:admin', 'ai.skill.smartstock.stock_out'],
 *     // 未登录会话是否放行（默认 false）
 *     'allow_anonymous' => false,
 * ],
 * ```
 *
 * 规则写法：
 *   | 写法                        | 含义                          |
 *   |----------------------------|-------------------------------|
 *   | `*`                        | 匹配所有能力                   |
 *   | `orchestrate_step`         | 精确匹配能力名                 |
 *   | `ai.tool.db_query`         | 精确匹配权限标识               |
 *   | `ai.skill.*`               | 前缀通配（权限标识前缀）        |
 *   | `access:read`              | 按访问类别匹配（读/写/执行/管理）|
 *
 * 其它包 / 插件若需要完全自定义策略（如按 arguments 细粒度判定、调用外部鉴权服务），
 * 只需注册自己的授权事件监听器即可，无需修改本包：
 *
 * ```php
 * Event::listen(\wise\agent\event\ToolAuthorize::class, function (ToolAuthorize $e) {
 *     if ($e->toolName === 'orchestrate_step'
 *         && ($e->arguments['skill_name'] ?? '') === 'smartstock.stock_list') {
 *         $e->deny('库存数据禁止通过 AI 查询', 'custom_policy');
 *     }
 * });
 * ```
 */
class CapabilityAuthorizePolicy
{
    /**
     * 按访问类别匹配的规则前缀，如 access:write
     */
    protected const ACCESS_RULE_PREFIX = 'access:';

    public function handle(CapabilityAuthorize $event): void
    {
        $deny  = (array) config('wise-agent.security.tool_authorize.deny', []);
        $allow = (array) config('wise-agent.security.tool_authorize.allow', []);

        if ($this->matches($event, $deny)) {
            $event->deny(
                "能力 '{$event->getCapabilityName()}'（{$event->permission}）"
                    . '命中 security.tool_authorize.deny 拒绝清单',
                'deny_list'
            );

            return;
        }

        if ($this->matches($event, $allow)) {
            $event->allow('allow_list');

            return;
        }

        if (($event->userId ?? 0) <= 0
            && (bool) config('wise-agent.security.tool_authorize.allow_anonymous', false)) {
            $event->allow('anonymous');

            return;
        }

        ContextualLogger::channel('ai')->debug(
            "[CapabilityAuthorizePolicy] No rule matched for {$event->getCapabilityKind()} "
            . "'{$event->getCapabilityName()}', delegating to RBAC"
        );
    }

    /**
     * 判定规则是否命中当前能力
     *
     * @param CapabilityAuthorize $event
     * @param array               $patterns 规则列表
     */
    protected function matches(CapabilityAuthorize $event, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (!is_string($pattern) || $pattern === '') {
                continue;
            }

            if ($pattern === '*') {
                return true;
            }

            if (str_starts_with($pattern, self::ACCESS_RULE_PREFIX)) {
                if (strtolower(substr($pattern, strlen(self::ACCESS_RULE_PREFIX))) === $event->access) {
                    return true;
                }

                continue;
            }

            if ($pattern === $event->getCapabilityName() || $pattern === $event->permission) {
                return true;
            }

            if (str_ends_with($pattern, '*')
                && $event->permission !== ''
                && str_starts_with($event->permission, rtrim($pattern, '*'))) {
                return true;
            }
        }

        return false;
    }
}
