<?php
declare(strict_types=1);

namespace wise\agent\controller;

use wise\support\context\ContextualLogger;
use wise\support\traits\JsonResponse;
use wise\agent\contract\UserContextInterface;
use wise\agent\model\AiApproval;
use wise\agent\security\ApprovalManager;

/**
 * AI 能力审批 —— 对话内审批 API
 *
 * 供 AI 助手聊天面板展示「待审批卡片」并一键批准 / 驳回，与后台
 * `/admin/ai_approval/*` 审批中心共用同一套审批单与决策逻辑。
 *
 * 端点（prefix = admin/ai）：
 *   GET  /admin/ai/approval/pending   当前用户的审批单（待处理 + 最近结果）
 *   POST /admin/ai/approval/decide    决策（approve / reject / cancel）
 *
 * ## 审批权判定（与后台审批中心一致）
 *
 *   1. 审批单必须处于 `pending`
 *   2. `confirm` 模式 + 申请人本人 → 允许（**自查确认**：用户确认自己要执行的操作）
 *   3. 其余情况需要后台菜单派生的审批权限：
 *      `admin.ai_approval.approve` / `.reject` / `.cancel`（超级管理员直通）
 *
 * 说明：`workflow` 模式的会签由 wise-workflow 的 TaskManager 判定票数，
 * 票数未满时本次只记录意见、审批单保持 pending（返回 message 告知剩余人数）。
 */
class ApprovalController
{
    use JsonResponse;

    /** 决策 → 所需权限标识 */
    protected const DECISION_PERMISSIONS = [
        'approve' => 'admin.ai_approval.approve',
        'reject'  => 'admin.ai_approval.reject',
        'cancel'  => 'admin.ai_approval.cancel',
    ];

    protected ApprovalManager $manager;

    protected \think\Request $request;

    public function __construct()
    {
        $this->request = request();
        $this->manager = new ApprovalManager();
    }

    /**
     * 当前用户的审批单列表
     *
     * GET /admin/ai/approval/pending
     *
     * 返回 active（待审批 / 已批准待使用）与 recent（最近结果），
     * 每条附带 `can_decide` / `is_self` / `pending_votes`，供前端渲染按钮可用性。
     *
     * @return \think\response\Json
     */
    public function pending(): \think\response\Json
    {
        [$userId, $userType] = $this->currentUser();

        if ($userId <= 0) {
            return $this->success('ok', ['active' => [], 'recent' => []]);
        }

        try {
            $result = $this->manager->listForRequester($userId, $userType, 10);

            foreach (['active', 'recent'] as $group) {
                $result[$group] = array_map(
                    fn(array $row) => $this->decorateRow($row, $userId, $userType),
                    $result[$group]
                );
            }

            return $this->success('ok', $result);
        } catch (\Throwable $e) {
            ContextualLogger::channel('ai')->error('[Approval] pending list error: ' . $e->getMessage());

            return $this->error('加载审批单失败');
        }
    }

    /**
     * 审批决策
     *
     * POST /admin/ai/approval/decide
     *   { "id": 12, "decision": "approve", "note": "同意" }
     *
     * 也支持用单号定位：{ "ident": "AIA20260929XXXX", ... }
     *
     * @return \think\response\Json
     */
    public function decide(): \think\response\Json
    {
        $key      = $this->request->post('id', $this->request->post('ident', ''));
        $decision = strtolower(trim((string) $this->request->post('decision', '')));
        $note     = (string) $this->request->post('note', '');

        if ($key === '' || $key === null) {
            return $this->error('缺少审批单 id 或 ident');
        }

        if (!isset(self::DECISION_PERMISSIONS[$decision])) {
            return $this->error('不支持的决策类型：' . $decision);
        }

        [$userId, $userType] = $this->currentUser();

        if ($userId <= 0) {
            return $this->error('当前会话未登录，无法处理审批单');
        }

        $approval = $this->manager->locate(is_string($key) && !ctype_digit($key) ? $key : (int) $key);

        if ($approval === null) {
            return $this->error('审批单不存在');
        }

        [$allowed, $reason] = $this->canDecide($approval, $decision, $userId, $userType);

        if (!$allowed) {
            return $this->error($reason);
        }

        $id = (int) $approval['id'];

        $isSelfConfirm = $this->isSelfConfirm($approval, $userId, $userType);
        $action        = ($isSelfConfirm && $decision === 'reject') ? 'cancel' : $decision;

        try {
            $result = $this->manager->{$action}($id, $userId, $note, $userType);
        } catch (\Throwable $e) {
            ContextualLogger::channel('ai')->error('[Approval] decide error: ' . $e->getMessage());

            return $this->error('审批处理失败，请稍后重试');
        }

        $fresh = $this->manager->find($id) ?? $approval;

        return $result['ok']
            ? $this->success($result['message'] ?: '操作成功', $this->decorateRow($fresh, $userId, $userType))
            : $this->error($result['message']);
    }


    /**
     * 当前用户 [id, type]
     *
     * @return array{0:int, 1:string}
     */
    protected function currentUser(): array
    {
        try {
            $context = app(UserContextInterface::class);

            return [(int) $context->getUserId(), (string) $context->getUserType()];
        } catch (\Throwable) {
            return [0, 'admin'];
        }
    }

    /**
     * 判断审批权
     *
     * @return array{0:bool, 1:string} [是否允许, 拒绝原因]
     */
    protected function canDecide(array $approval, string $decision, int $userId, string $userType): array
    {
        if ((string) ($approval['status'] ?? '') !== AiApproval::STATUS_PENDING) {
            return [false, '审批单当前状态为「' . AiApproval::statusLabel((string) ($approval['status'] ?? '')) . '」，不可重复决策'];
        }

        if ($this->isSelfConfirm($approval, $userId, $userType)) {
            return [true, ''];
        }

        $permission = self::DECISION_PERMISSIONS[$decision];

        if ($this->hasPermission($userId, $userType, $permission)) {
            return [true, ''];
        }

        return [false, "无权处理该审批单（需要 {$permission} 权限，或由申请人自行确认）"];
    }

    /**
     * 是否「申请人自查确认」场景：confirm 模式 + 本人
     */
    protected function isSelfConfirm(array $approval, int $userId, string $userType): bool
    {
        return (string) ($approval['mode'] ?? '') === 'confirm'
            && (int) ($approval['requester_id'] ?? 0) === $userId
            && (string) ($approval['requester_type'] ?? 'admin') === $userType;
    }

    /**
     * RBAC 权限判定（wise-auth 不可用时一律拒绝，fail-closed）
     */
    protected function hasPermission(int $userId, string $userType, string $permission): bool
    {
        if ($userId <= 0 || !class_exists(\wise\auth\facade\Rbac::class)) {
            return false;
        }

        try {
            if (\wise\auth\facade\Rbac::isSuperAdmin($userId, $userType)) {
                return true;
            }

            return (bool) \wise\auth\facade\Rbac::check($userId, $userType, $permission);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * 补全前端所需字段
     */
    protected function decorateRow(array $row, int $userId, string $userType): array
    {
        $subject = (string) ($row['workflow_subject'] ?? '');

        $row['is_self']       = (int) ($row['requester_id'] ?? 0) === $userId
            && (string) ($row['requester_type'] ?? 'admin') === $userType;
        $row['self_confirm']  = $this->isSelfConfirm($row, $userId, $userType);
        $row['can_approve']   = ($row['status'] ?? '') === AiApproval::STATUS_PENDING
            && $this->hasPermission($userId, $userType, 'admin.ai_approval.approve');
        $row['can_reject']    = ($row['status'] ?? '') === AiApproval::STATUS_PENDING
            && $this->hasPermission($userId, $userType, 'admin.ai_approval.reject');
        $row['can_cancel']    = ($row['status'] ?? '') === AiApproval::STATUS_APPROVED
            && ($row['is_self'] || $this->hasPermission($userId, $userType, 'admin.ai_approval.cancel'));

        $row['pending_votes'] = $subject !== '' ? $this->manager->pendingVotes($subject) : 0;

        $row['decidable'] = ($row['status'] ?? '') === AiApproval::STATUS_PENDING
            && ($row['self_confirm'] || $row['can_approve'] || $row['can_reject']);

        return $row;
    }
}
