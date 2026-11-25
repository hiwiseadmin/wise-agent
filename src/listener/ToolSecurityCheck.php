<?php
declare(strict_types=1);

namespace wise\agent\listener;

use wise\support\context\ContextualLogger;
use wise\agent\event\ToolExecute;
use wise\agent\security\ApprovalManager;
use wise\agent\security\ApprovalOutcome;
use wise\agent\security\ApprovalPolicy;
use wise\agent\security\ApprovalRequirement;
use wise\agent\security\AuthDecision;
use wise\agent\security\CapabilityAuthorizer;
use wise\agent\tool\ToolRegistry;

/**
 * 工具安全检查监听器
 *
 * 订阅：ToolExecute（BEFORE 阶段）
 *
 * 职责（两件事，且顺序固定）：
 *   1. **权限**：委托 `CapabilityAuthorizer`（事件策略 → wise-auth RBAC，超管直通）
 *   2. **审批**：权限通过后，由 `ApprovalPolicy` 判定是否需要人工确认 / 审批
 *      （按访问类型 + 风险等级 + 能力标识三级规则配置）
 *
 * 权限与审批解耦：权限解决「能不能调用」（一次性授予），
 * 审批解决「这一次调用是否需要人工介入」（按次）。
 */
class ToolSecurityCheck
{
    protected CapabilityAuthorizer $authorizer;
    protected ApprovalPolicy $approvalPolicy;
    protected ApprovalManager $approvalManager;

    public function __construct(
        ?CapabilityAuthorizer $authorizer = null,
        ?ApprovalPolicy $approvalPolicy = null,
        ?ApprovalManager $approvalManager = null
    ) {
        $this->authorizer      = $authorizer ?? new CapabilityAuthorizer();
        $this->approvalPolicy  = $approvalPolicy ?? new ApprovalPolicy();
        $this->approvalManager = $approvalManager ?? new ApprovalManager();
    }

    public function handle(ToolExecute $event): void
    {
        if ($event->phase !== ToolExecute::PHASE_BEFORE) {
            return;
        }

        $tool = $this->resolveTool($event->toolName);

        if ($tool === null) {
            $this->blockUnknown($event);

            return;
        }

        $descriptor = $this->authorizer->describeTool($tool, $event->toolName);

        if ($descriptor->requiresPermission()) {
            $decision = $this->authorizer->authorizeTool($tool, $event->arguments, $event->toolName);

            if (!$decision->allowed) {
                $this->blockPermission($event, $decision);

                return;
            }

            ContextualLogger::channel('ai')->info(
                "[ToolSecurity] Tool authorized by {$decision->decidedBy}: "
                . "{$event->toolName} ({$descriptor->permission}, access={$descriptor->access})"
            );
        }

        $requirement = $this->approvalPolicy->decideForTool($tool, $descriptor);

        if (!$requirement->required()) {
            return;
        }

        $outcome = $this->approvalManager->gate($descriptor, $requirement, $event->arguments, [
            'user_id'   => $this->authorizer->currentUserId(),
            'user_type' => $this->authorizer->currentUserType(),
        ]);

        if ($outcome->blocked()) {
            $this->blockApproval($event, $requirement, $outcome);

            return;
        }

        if ($outcome->status === ApprovalOutcome::APPROVED) {
            ContextualLogger::channel('ai')->info(
                "[ToolSecurity] Tool '{$event->toolName}' allowed by approval {$outcome->ident}"
            );
        }
    }

    /**
     * 解析工具实例
     *
     * 优先全局注册表；找不到时回落 `wise-agent.tools` 配置（Skill 执行使用隔离
     * ToolRegistry，config-only 注册的工具不在全局表中，若不回落会被跳过权限校验）。
     */
    protected function resolveTool(string $name): ?\wise\agent\contract\ToolInterface
    {
        $tool = ToolRegistry::instance()->normalize($name);

        if ($tool !== null) {
            return $tool;
        }

        $configured = (array) config('wise-agent.tools', []);
        $class      = $configured[$name]['class'] ?? null;

        if (is_string($class) && class_exists($class)) {
            try {
                $instance = app($class);
            } catch (\Throwable) {
                try {
                    $instance = new $class();
                } catch (\Throwable) {
                    return null;
                }
            }

            if ($instance instanceof \wise\agent\contract\ToolInterface) {
                return $instance;
            }
        }

        return null;
    }

    /**
     * 工具无法解析：按安全失败关闭，拒绝执行
     */
    protected function blockUnknown(ToolExecute $event): void
    {
        $event->skip       = true;
        $event->mockResult = json_encode([
            'error'  => 'Tool security check failed: tool not found',
            'tool'   => $event->toolName,
            'reason' => 'tool_not_resolved',
            'source' => 'security',
        ], JSON_UNESCAPED_UNICODE);

        try {
            ContextualLogger::channel('ai')->warning(
                "[ToolSecurity] Blocked unknown tool '{$event->toolName}' (fail-closed)"
            );
        } catch (\Throwable) {
        }
    }

    /**
     * 权限不足：拦截并写入可读的拒绝结果
     */
    protected function blockPermission(ToolExecute $event, AuthDecision $decision): void
    {
        $event->skip       = true;
        $event->mockResult = json_encode([
            'error'  => 'Permission denied: ' . $decision->permission,
            'tool'   => $event->toolName,
            'reason' => $decision->reason,
            'source' => $decision->decidedBy,
            'access' => $decision->access,
        ], JSON_UNESCAPED_UNICODE);

        ContextualLogger::channel('ai')->warning(
            "[ToolSecurity] Blocked tool '{$event->toolName}' ({$decision->permission}) "
            . "by {$decision->decidedBy}: {$decision->reason}"
        );
    }

    /**
     * 需要人工确认 / 审批：拦截并给出结构化的 required_action 与审批单信息
     */
    protected function blockApproval(
        ToolExecute $event,
        ApprovalRequirement $requirement,
        ApprovalOutcome $outcome
    ): void {
        $event->skip       = true;
        $event->mockResult = json_encode([
            'error'           => $outcome->status === ApprovalOutcome::REJECTED
                ? "Tool '{$event->toolName}' approval was rejected"
                : ($requirement->mode === ApprovalRequirement::WORKFLOW
                    ? "Tool '{$event->toolName}' requires approval before execution"
                    : "Tool '{$event->toolName}' requires explicit user confirmation before execution"),
            'required_action' => $outcome->status === ApprovalOutcome::REJECTED ? 'rejected' : $requirement->mode,
            'tool_name'       => $event->toolName,
            'approval_rule'   => $requirement->rule,
            'approval_status' => $outcome->status,
            'approval_id'     => $outcome->approvalId,
            'approval_ident'  => $outcome->ident,
            'reason'          => $outcome->reason !== '' ? $outcome->reason : $requirement->reason,
        ], JSON_UNESCAPED_UNICODE);

        ContextualLogger::channel('ai')->warning(
            "[ToolSecurity] Blocked tool '{$event->toolName}' ({$outcome->status}, rule={$requirement->rule}): "
            . $outcome->reason
        );
    }
}
