<?php
declare(strict_types=1);

namespace wise\agent\security;

use wise\agent\contract\ToolInterface;
use wise\agent\skill\contract\SkillInterface;

/**
 * 审批策略（access / risk / 具体能力 三级规则）
 *
 * ## 为什么需要它
 *
 * 「写操作需要确认/审批」这类诉求不应该散落在每个能力的 `requireConfirmation()` 里
 * （历史状态：只读的 `db_query` 要确认、编排入口 `orchestrate_step` 反而不用确认），
 * 而应该由统一策略按「访问类型 + 风险等级 + 能力标识」集中配置。
 *
 * ## 规则优先级（首个命中即生效）
 *
 *   1. `capabilities` —— 按能力标识精确 / 前缀通配匹配（最细，用于例外）
 *   2. `risk`         —— 按风险等级（low / medium / high）
 *   3. `access`       —— 按访问类型（read / write / execute / admin）
 *   4. `default`      —— 兜底
 *
 * 能力自身给出的下限会被并入：`requireConfirmation() === true` 时至少为 `confirm`
 * （策略只能加严，不能放宽能力的自身要求）。
 *
 * ## 配置示例（config/wise-agent.php）
 *
 * ```php
 * 'security' => [
 *     'approval' => [
 *         'default' => 'none',
 *         'rules' => [
 *             // 高危能力一律需要确认
 *             'risk'         => ['high' => 'confirm'],
 *             // 只读能力免确认，写入能力需确认，管理能力走审批流
 *             'access'       => ['read' => 'none', 'write' => 'confirm', 'admin' => 'workflow'],
 *             // 例外：删除类自动工具改走审批流；敏感技能直接拒绝执行由 deny 清单负责
 *             'capabilities' => [
 *                 'plugin:*.auto.delete.*' => 'workflow',
 *             ],
 *         ],
 *     ],
 * ],
 * ```
 *
 * 模式取值：`none`（直接执行）| `confirm`（需人工确认）| `workflow`（需提交审批）。
 * 其中 `workflow` 的真实审批落库与 wise-workflow 集成见后续阶段；
 * 当前实现会以「需要审批」为由安全阻断（fail-closed），不会静默放行。
 */
class ApprovalPolicy
{
    /**
     * 判定工具的审批要求
     *
     * @param ToolInterface        $tool       工具实例（用于读取自身确认下限）
     * @param CapabilityDescriptor $descriptor 能力描述符
     */
    public function decideForTool(ToolInterface $tool, CapabilityDescriptor $descriptor): ApprovalRequirement
    {
        $requirement = $this->resolve($descriptor);

        if ($tool->requireConfirmation() && $requirement->mode !== ApprovalRequirement::WORKFLOW) {
            return ApprovalRequirement::confirm(
                $requirement->required() ? $requirement->rule : 'capability:self_confirm',
                '该能力自身声明需要确认'
                    . ($requirement->reason !== '' ? '（' . $requirement->reason . '）' : '')
            );
        }

        return $requirement;
    }

    /**
     * 判定 Skill 的审批要求
     *
     * Skill 无 `requireConfirmation()`，完全由策略决定（默认不额外拦截）。
     */
    public function decideForSkill(SkillInterface $skill, CapabilityDescriptor $descriptor): ApprovalRequirement
    {
        return $this->resolve($descriptor);
    }

    /**
     * 按描述符解析审批要求（三级规则 + 兜底）
     */
    public function resolve(CapabilityDescriptor $descriptor): ApprovalRequirement
    {
        $config = (array) config('wise-agent.security.approval', []);

        if (isset($config['enabled']) && !$config['enabled']) {
            return ApprovalRequirement::none('disabled', '审批策略已关闭（security.approval.enabled=false）');
        }

        $rules = (array) ($config['rules'] ?? []);

        $mode = $this->matchCapabilityRule($descriptor, (array) ($rules['capabilities'] ?? []));
        if ($mode !== null) {
            return $this->make($mode, 'capability:' . $descriptor->permission, $descriptor);
        }

        $mode = $this->matchValueRule($descriptor->risk, (array) ($rules['risk'] ?? []));
        if ($mode !== null) {
            return $this->make($mode, 'risk:' . $descriptor->risk, $descriptor);
        }

        $mode = $this->matchValueRule($descriptor->access, (array) ($rules['access'] ?? []));
        if ($mode !== null) {
            return $this->make($mode, 'access:' . $descriptor->access, $descriptor);
        }

        return $this->make((string) ($config['default'] ?? ApprovalRequirement::NONE), 'default', $descriptor);
    }

    /**
     * 能力标识规则匹配（精确优先，其次最长前缀通配）
     *
     * @param array<string, string> $rules ident => mode
     */
    protected function matchCapabilityRule(CapabilityDescriptor $descriptor, array $rules): ?string
    {
        $ident = $descriptor->permission;

        if ($ident === '') {
            return null;
        }

        if (isset($rules[$ident])) {
            return (string) $rules[$ident];
        }

        $matched = null;
        $longest = -1;

        foreach ($rules as $pattern => $mode) {
            if (!is_string($pattern) || !str_ends_with($pattern, '*')) {
                continue;
            }

            $prefix = rtrim($pattern, '*');
            if (str_starts_with($ident, $prefix) && strlen($prefix) > $longest) {
                $longest = strlen($prefix);
                $matched = (string) $mode;
            }
        }

        return $matched;
    }

    /**
     * 值规则匹配（如 risk=high / access=write）
     *
     * @param array<string, string> $rules key => mode
     */
    protected function matchValueRule(string $value, array $rules): ?string
    {
        if ($value === '' || !isset($rules[$value])) {
            return null;
        }

        return (string) $rules[$value];
    }

    /**
     * 构造审批要求（附带人类可读说明）
     */
    protected function make(string $mode, string $rule, CapabilityDescriptor $descriptor): ApprovalRequirement
    {
        $mode = in_array($mode, [
            ApprovalRequirement::NONE,
            ApprovalRequirement::CONFIRM,
            ApprovalRequirement::WORKFLOW,
        ], true) ? $mode : ApprovalRequirement::NONE;

        if ($mode === ApprovalRequirement::NONE) {
            return ApprovalRequirement::none($rule, '审批策略判定无需人工介入');
        }

        $kindLabel = $descriptor->isSkill() ? '技能' : '工具';
        $modeLabel = $mode === ApprovalRequirement::WORKFLOW ? '审批' : '确认';

        return new ApprovalRequirement(
            $mode,
            $rule,
            "{$kindLabel} '{$descriptor->name}'（"
                . CapabilityAccess::label($descriptor->access) . '，风险 '
                . $descriptor->risk . "）按策略 {$rule} 需要{$modeLabel}"
        );
    }
}
