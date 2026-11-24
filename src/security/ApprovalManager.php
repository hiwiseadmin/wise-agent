<?php
declare(strict_types=1);

namespace wise\agent\security;

use think\facade\Db;
use think\facade\Event;
use wise\support\context\ContextualLogger;
use wise\agent\event\ApprovalDecided;
use wise\agent\event\ApprovalRequested;
use wise\agent\model\AiApproval;
use wise\agent\workflow\ApprovalWorkflowBridge;

/**
 * AI 能力审批管理器
 *
 * 职责：审批单的「申请 → 可用判定 → 原子消费 → 批准/驳回/撤回 → 失效清理」全生命周期。
 *
 * ## 放行语义（重要）
 *
 * 批准后**不重放**被拦截的历史调用——重放副作用型调用既危险又缺少会话上下文。
 * 正确流程是：批准 → 申请人（或 AI 会话）**重新发起**同一能力调用 →
 * 此时命中可用审批单并**原子消费一次**，本次放行。
 *
 * ## 匹配维度
 *
 *   申请人（user_type + user_id）+ 能力类型 + 权限标识
 *   可选：调用参数指纹（`approval.request.scope = arguments` 时）
 *
 * ## 消费的并发安全
 *
 * 采用「带条件的原子 UPDATE + 影响行数校验」：只有 `status=approved AND uses_left>0
 * AND 未过期` 的那一次更新才会成功，避免并发下同一张审批单被多次放行。
 *
 * ## 复用能力
 *
 *   - wise-workflow（可选）：为每张审批单建一个 `ai_approval:{id}` 实例，
 *     记录状态流转历史；未安装时审批单表自身即状态机，功能不受影响
 *   - wise-notification（可选）：向 `approval.notify_user_ids` 推送站内信；
 *     未安装时仅记录日志并触发事件
 *   - 事件：`ApprovalRequested` / `ApprovalDecided`——业务方可自行接入通知与工单
 */
class ApprovalManager
{
    /** 参数范围：整张单只认「申请人 + 能力」 */
    public const SCOPE_CAPABILITY = 'capability';

    /** 参数范围：还需调用参数完全一致 */
    public const SCOPE_ARGUMENTS = 'arguments';

    /** wise-workflow 桥接（懒建，全部交互收敛在其中） */
    protected ?ApprovalWorkflowBridge $workflowBridge = null;

    /**
     * 审批闸门：可用审批单直接消费放行，否则创建（或复用）审批单并阻断
     *
     * @param CapabilityDescriptor $descriptor  能力描述
     * @param ApprovalRequirement  $requirement 审批要求（由 ApprovalPolicy 产出）
     * @param array                $arguments   调用参数（用于快照与参数级匹配）
     * @param array                $context     ['user_id','user_type','session_id']
     */
    public function gate(
        CapabilityDescriptor $descriptor,
        ApprovalRequirement $requirement,
        array $arguments = [],
        array $context = []
    ): ApprovalOutcome {
        if (!$requirement->required()) {
            return ApprovalOutcome::notRequired();
        }

        $userId   = (int) ($context['user_id'] ?? 0);
        $userType = (string) ($context['user_type'] ?? 'admin');

        if ($userId <= 0) {
            ContextualLogger::channel('ai')->info(
                "[Approval] Skip approval for {$descriptor->permission}: no logged-in user (system context)"
            );

            return ApprovalOutcome::notRequired();
        }

        $usable = $this->findUsable($descriptor, $userId, $userType, $arguments);

        if ($usable !== null && $this->consume((int) $usable['id'])) {
            ContextualLogger::channel('ai')->info(
                "[Approval] Consumed approval {$usable['ident']} for {$descriptor->permission} "
                . "(user #{$userId})"
            );

            return ApprovalOutcome::approved(
                (int) $usable['id'],
                (string) $usable['ident'],
                $requirement->mode,
                $requirement->rule
            );
        }

        $cooldown = (int) config('wise-agent.security.approval.request.reject_cooldown', 300);
        $rejected = $cooldown > 0
            ? $this->findRecentlyRejected($descriptor, $userId, $userType, $arguments, $cooldown)
            : null;

        if ($rejected !== null) {
            return ApprovalOutcome::rejected(
                (int) $rejected['id'],
                (string) $rejected['ident'],
                $requirement->mode,
                $requirement->rule,
                "申请 {$rejected['ident']} 已被驳回"
                    . ($rejected['decision_note'] !== '' ? '：' . $rejected['decision_note'] : '')
                    . "，请 {$cooldown} 秒后重新申请或联系管理员"
            );
        }

        $pending = $this->findPending($descriptor, $userId, $userType, $arguments);

        if ($pending !== null) {
            return ApprovalOutcome::pending(
                (int) $pending['id'],
                (string) $pending['ident'],
                $requirement->mode,
                $requirement->rule,
                "审批单 {$pending['ident']} 待审批中，批准后请重新发起该调用"
            );
        }

        $id    = $this->request($descriptor, $requirement, $arguments, $userId, $userType, $context);
        $model = $this->find($id);

        return ApprovalOutcome::pending(
            $id,
            (string) ($model['ident'] ?? ''),
            $requirement->mode,
            $requirement->rule,
            ($requirement->reason !== '' ? $requirement->reason . '；' : '')
                . '已生成审批单 ' . ($model['ident'] ?? '') . '，批准后请重新发起该调用'
        );
    }


    /**
     * 创建审批单
     *
     * @return int 审批单 ID
     */
    public function request(
        CapabilityDescriptor $descriptor,
        ApprovalRequirement $requirement,
        array $arguments = [],
        int $userId = 0,
        string $userType = 'admin',
        array $context = []
    ): int {
        $ttl      = (int) config('wise-agent.security.approval.request.ttl', 900);
        $maxUses  = max(1, (int) config('wise-agent.security.approval.request.max_uses', 1));
        $ident    = $this->generateIdent();

        $approval = AiApproval::create([
            'ident'           => $ident,
            'capability_kind' => $descriptor->kind,
            'capability_name' => $descriptor->name,
            'permission'      => $descriptor->permission,
            'access'          => $descriptor->access,
            'risk'            => $descriptor->risk,
            'mode'            => $requirement->mode,
            'rule'            => $requirement->rule,
            'reason'          => $this->summarize($requirement->reason, 255),
            'arguments'       => json_encode($this->snapshotArguments($arguments), JSON_UNESCAPED_UNICODE),
            'arguments_hash'  => $this->hashArguments($descriptor, $arguments),
            'requester_id'    => $userId,
            'requester_type'  => $userType,
            'session_id'      => (string) ($context['session_id'] ?? ''),
            'status'          => AiApproval::STATUS_PENDING,
            'uses_total'      => $maxUses,
            'uses_left'       => $maxUses,
            'expire_time'     => $ttl > 0 ? date('Y-m-d H:i:s', time() + $ttl) : null,
        ]);

        $id = (int) $approval->id;

        $subject = $this->workflow()->start($id, $descriptor->permission, $requirement->mode);

        if ($subject !== '') {
            AiApproval::where('id', $id)->update(['workflow_subject' => $subject]);
            $approval->setAttr('workflow_subject', $subject);
        }

        ContextualLogger::channel('ai')->info(
            "[Approval] Requested {$ident} for {$descriptor->permission} by user #{$userId} "
            . "(mode={$requirement->mode}, rule={$requirement->rule})"
        );

        $this->dispatchRequested($approval, $requirement);

        return $id;
    }


    /**
     * 批准
     *
     * @return array{ok:bool, message:string, data:array}
     */
    public function approve(int $id, int $deciderId = 0, string $note = '', string $deciderType = 'admin'): array
    {
        return $this->decide($id, AiApproval::STATUS_APPROVED, $deciderId, $note, $deciderType);
    }

    /**
     * 驳回
     *
     * @return array{ok:bool, message:string, data:array}
     */
    public function reject(int $id, int $deciderId = 0, string $note = '', string $deciderType = 'admin'): array
    {
        return $this->decide($id, AiApproval::STATUS_REJECTED, $deciderId, $note, $deciderType);
    }

    /**
     * 撤回（申请人主动放弃）
     *
     * @return array{ok:bool, message:string, data:array}
     */
    public function cancel(int $id, int $operatorId = 0, string $note = '', string $operatorType = 'admin'): array
    {
        return $this->decide($id, AiApproval::STATUS_CANCELLED, $operatorId, $note, $operatorType);
    }

    /**
     * 决策主流程（批准 / 驳回 / 撤回）
     *
     * @return array{ok:bool, message:string, data:array}
     */
    protected function decide(
        int $id,
        string $decision,
        int $operatorId,
        string $note,
        string $operatorType
    ): array {
        $approval = AiApproval::find($id);

        if ($approval === null) {
            return ['ok' => false, 'message' => '审批单不存在', 'data' => []];
        }

        $status = (string) $approval->getAttr('status');

        if ($this->isExpired($approval) && $status === AiApproval::STATUS_PENDING) {
            $approval->save(['status' => AiApproval::STATUS_EXPIRED]);
            $this->transitionWorkflow((string) $approval->getAttr('workflow_subject'), 'cancel');

            return ['ok' => false, 'message' => '审批单已过期', 'data' => $approval->toArray()];
        }

        if ($status !== AiApproval::STATUS_PENDING) {
            return [
                'ok'      => false,
                'message' => '审批单当前状态为「' . AiApproval::statusLabel($status) . '」，不可重复决策',
                'data'    => $approval->toArray(),
            ];
        }

        $workflowResult = $this->workflow()->decide(
            (string) $approval->getAttr('workflow_subject'),
            $decision,
            $operatorId,
            $note
        );

        if ($workflowResult['handled'] && !$workflowResult['completed']) {
            $approval->save([
                'decision_note' => $this->summarize($note, 200),
                'decider_id'    => $operatorId,
                'decider_type'  => $operatorType,
            ]);

            ContextualLogger::channel('ai')->info(
                "[Approval] {$approval->getAttr('ident')} vote recorded by user #{$operatorId}"
                . " ({$workflowResult['pending']} pending)"
            );

            return [
                'ok'      => true,
                'message' => $workflowResult['message'] !== '' ? $workflowResult['message'] : '已记录审批意见',
                'data'    => $approval->toArray(),
            ];
        }

        $approval->save([
            'status'        => $decision,
            'decider_id'    => $operatorId,
            'decider_type'  => $operatorType,
            'decision_note' => $this->summarize($note, 255),
            'decided_at'    => date('Y-m-d H:i:s'),
        ]);

        ContextualLogger::channel('ai')->info(
            "[Approval] {$approval->getAttr('ident')} {$decision} by user #{$operatorId}"
        );

        $this->dispatchDecided($approval, $decision, $operatorId, $note);

        return [
            'ok'      => true,
            'message' => '操作成功',
            'data'    => $approval->toArray(),
        ];
    }


    /**
     * 查询审批单
     */
    public function find(int $id): ?array
    {
        $model = AiApproval::find($id);

        return $model ? $model->toArray() : null;
    }

    /**
     * 按单号查询
     */
    public function findByIdent(string $ident): ?array
    {
        $model = AiApproval::where('ident', $ident)->find();

        return $model ? $model->toArray() : null;
    }

    /**
     * 分页查询（供后台审批界面）
     *
     * @param array $filter status/keyword/kind/access/risk/requester_id
     * @return array{total:int, rows:array}
     */
    public function paginate(array $filter = [], int $page = 1, int $limit = 15): array
    {
        $query = AiApproval::order('id', 'desc');

        $status = (string) ($filter['status'] ?? '');
        if ($status !== '') {
            $query->where('status', $status);
        }

        foreach (['kind' => 'capability_kind', 'access' => 'access', 'risk' => 'risk'] as $key => $field) {
            $value = (string) ($filter[$key] ?? '');
            if ($value !== '') {
                $query->where($field, $value);
            }
        }

        $requesterId = (int) ($filter['requester_id'] ?? 0);
        if ($requesterId > 0) {
            $query->where('requester_id', $requesterId);
        }

        $keyword = trim((string) ($filter['keyword'] ?? ''));
        if ($keyword !== '') {
            $escaped = addcslashes($keyword, '%_\\');
            $query->where('ident|capability_name|permission|requester_id', 'like', '%' . $escaped . '%');
        }

        $total = (clone $query)->count();
        $rows  = $query->page(max(1, $page), max(1, $limit))->select()->toArray();

        $rows = $this->decorate($rows);

        return ['total' => (int) $total, 'rows' => $rows];
    }

    /**
     * 申请人视角的审批单列表（供对话内审批卡片）
     *
     * 返回两部分：
     *   - `active`  待审批 / 已批准待使用 —— 需要用户关注（可能一键批准）
     *   - `recent`  最近已决策 —— 用于把「已驳回 / 已用尽」的结果回显到对话里
     *
     * @return array{active:array, recent:array}
     */
    public function listForRequester(int $userId, string $userType = 'admin', int $limit = 10): array
    {
        if ($userId <= 0) {
            return ['active' => [], 'recent' => []];
        }

        $this->expireStale();

        $base = fn() => AiApproval::where('requester_id', $userId)
            ->where('requester_type', $userType);

        $active = $base()
            ->whereIn('status', [AiApproval::STATUS_PENDING, AiApproval::STATUS_APPROVED])
            ->order('id', 'desc')
            ->limit(max(1, $limit))
            ->select()
            ->toArray();

        $recent = $base()
            ->whereIn('status', [
                AiApproval::STATUS_REJECTED,
                AiApproval::STATUS_CONSUMED,
                AiApproval::STATUS_CANCELLED,
                AiApproval::STATUS_EXPIRED,
            ])
            ->order('id', 'desc')
            ->limit(5)
            ->select()
            ->toArray();

        return [
            'active' => $this->decorate($active),
            'recent' => $this->decorate($recent),
        ];
    }

    /**
     * 按 ID / 单号定位审批单
     */
    public function locate(int|string $key): ?array
    {
        if (is_int($key) || ctype_digit((string) $key)) {
            return $this->find((int) $key);
        }

        return $this->findByIdent((string) $key);
    }

    /**
     * 状态统计（供审批中心角标 / 概览）
     *
     * @return array<string, int>
     */
    public function stats(): array
    {
        $this->expireStale();

        $rows  = AiApproval::field('status, COUNT(*) AS num')->group('status')->select()->toArray();
        $stats = array_fill_keys(array_keys(AiApproval::statusLabels()), 0);

        foreach ($rows as $row) {
            $stats[(string) $row['status']] = (int) $row['num'];
        }

        return $stats;
    }

    /**
     * 批量失效过期审批单
     *
     * @return int 失效数量
     */
    public function expireStale(): int
    {
        $now = date('Y-m-d H:i:s');

        try {
            return (int) AiApproval::whereIn('status', [AiApproval::STATUS_PENDING, AiApproval::STATUS_APPROVED])
                ->whereNotNull('expire_time')
                ->where('expire_time', '<=', $now)
                ->update(['status' => AiApproval::STATUS_EXPIRED, 'update_time' => $now]);
        } catch (\Throwable $e) {
            ContextualLogger::channel('ai')->warning('[Approval] expireStale failed: ' . $e->getMessage());

            return 0;
        }
    }


    /**
     * 查找可用审批单（已批准、未过期、仍有剩余次数、维度匹配）
     */
    protected function findUsable(
        CapabilityDescriptor $descriptor,
        int $userId,
        string $userType,
        array $arguments
    ): ?array {
        $query = AiApproval::where('status', AiApproval::STATUS_APPROVED)
            ->where('uses_left', '>', 0)
            ->where('requester_id', $userId)
            ->where('requester_type', $userType)
            ->where('capability_kind', $descriptor->kind)
            ->where('permission', $descriptor->permission)
            ->whereRaw('(expire_time IS NULL OR expire_time > ?)', [date('Y-m-d H:i:s')]);

        if ($this->scope() === self::SCOPE_ARGUMENTS) {
            $query->where('arguments_hash', $this->hashArguments($descriptor, $arguments));
        }

        $model = $query->order('id', 'asc')->find();

        return $model ? $model->toArray() : null;
    }

    /**
     * 查找待审批单（复用，避免重复申请）
     */
    protected function findPending(
        CapabilityDescriptor $descriptor,
        int $userId,
        string $userType,
        array $arguments
    ): ?array {
        $query = AiApproval::where('status', AiApproval::STATUS_PENDING)
            ->where('requester_id', $userId)
            ->where('requester_type', $userType)
            ->where('capability_kind', $descriptor->kind)
            ->where('permission', $descriptor->permission);

        if ($this->scope() === self::SCOPE_ARGUMENTS) {
            $query->where('arguments_hash', $this->hashArguments($descriptor, $arguments));
        }

        $model = $query->order('id', 'desc')->find();

        return $model ? $model->toArray() : null;
    }

    /**
     * 查找冷却期内被驳回的审批单
     */
    protected function findRecentlyRejected(
        CapabilityDescriptor $descriptor,
        int $userId,
        string $userType,
        array $arguments,
        int $cooldown
    ): ?array {
        $query = AiApproval::where('status', AiApproval::STATUS_REJECTED)
            ->where('requester_id', $userId)
            ->where('requester_type', $userType)
            ->where('capability_kind', $descriptor->kind)
            ->where('permission', $descriptor->permission)
            ->where('decided_at', '>=', date('Y-m-d H:i:s', time() - max(1, $cooldown)));

        if ($this->scope() === self::SCOPE_ARGUMENTS) {
            $query->where('arguments_hash', $this->hashArguments($descriptor, $arguments));
        }

        $model = $query->order('id', 'desc')->find();

        return $model ? $model->toArray() : null;
    }

    /**
     * 原子消费一次审批单
     *
     * 通过「条件更新 + 影响行数」保证并发安全：同一张单不会被并发放行两次。
     *
     * @return bool 是否消费成功
     */
    public function consume(int $id): bool
    {
        if ($id <= 0) {
            return false;
        }

        try {
            $affected = AiApproval::where('id', $id)
                ->where('status', AiApproval::STATUS_APPROVED)
                ->where('uses_left', '>', 0)
                ->whereRaw('(expire_time IS NULL OR expire_time > ?)', [date('Y-m-d H:i:s')])
                ->update([
                    'uses_left'   => Db::raw('uses_left - 1'),
                    'update_time' => date('Y-m-d H:i:s'),
                ]);

            if ((int) $affected !== 1) {
                return false;
            }

            $left = (int) AiApproval::where('id', $id)->value('uses_left');

            if ($left <= 0) {
                AiApproval::where('id', $id)->update([
                    'status'      => AiApproval::STATUS_CONSUMED,
                    'update_time' => date('Y-m-d H:i:s'),
                ]);
            }

            return true;
        } catch (\Throwable $e) {
            ContextualLogger::channel('ai')->error('[Approval] consume failed: ' . $e->getMessage());

            return false;
        }
    }


    /**
     * 参数匹配范围
     */
    protected function scope(): string
    {
        $scope = (string) config('wise-agent.security.approval.request.scope', self::SCOPE_CAPABILITY);

        return $scope === self::SCOPE_ARGUMENTS ? self::SCOPE_ARGUMENTS : self::SCOPE_CAPABILITY;
    }

    /**
     * 生成审批单号：AIA + 年月日时分秒 + 4 位随机
     */
    protected function generateIdent(): string
    {
        return 'AIA' . date('YmdHis') . strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));
    }

    /**
     * 参数指纹（键排序后哈希，保证同参数稳定匹配）
     */
    protected function hashArguments(CapabilityDescriptor $descriptor, array $arguments): string
    {
        $normalized = $this->normalize($arguments);

        return md5($descriptor->kind . '|' . $descriptor->permission . '|' . json_encode($normalized, JSON_UNESCAPED_UNICODE));
    }

    /**
     * 参数快照（脱敏 + 截断），仅用于人工审批时了解上下文
     */
    protected function snapshotArguments(array $arguments, int $limit = 2000): array
    {
        $sensitive = ['password', 'passwd', 'secret', 'token', 'access_token', 'api_key', 'private_key'];

        $walk = function ($value, string $key = '') use (&$walk, $sensitive) {
            if ($key !== '' && in_array(strtolower($key), $sensitive, true)) {
                return '***';
            }

            if (is_array($value)) {
                $result = [];
                foreach ($value as $k => $v) {
                    $result[$k] = $walk($v, (string) $k);
                }

                return $result;
            }

            if (is_string($value)) {
                return mb_substr($value, 0, 500);
            }

            return $value;
        };

        $snapshot = json_encode($walk($arguments), JSON_UNESCAPED_UNICODE) ?: '{}';

        if (strlen($snapshot) > $limit) {
            return ['_truncated' => mb_substr($snapshot, 0, $limit)];
        }

        return json_decode($snapshot, true) ?: [];
    }

    /**
     * 递归排序数组键，保证哈希稳定
     */
    protected function normalize(array $data): array
    {
        ksort($data);

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->normalize($value);
            }
        }

        return $data;
    }

    /**
     * 判断是否已过期
     */
    protected function isExpired(AiApproval $approval): bool
    {
        $expire = $approval->getAttr('expire_time');

        return !empty($expire) && strtotime((string) $expire) <= time();
    }

    /**
     * 截断文本
     */
    protected function summarize(string $text, int $limit): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');

        return mb_substr($text, 0, $limit);
    }

    /**
     * 补充展示字段（状态标签、申请人姓名、参数预览）
     *
     * @param array $rows 审批单数组列表
     */
    protected function decorate(array $rows): array
    {
        if ($rows === []) {
            return $rows;
        }

        $names = $this->resolveAdminNames(array_column($rows, 'requester_id'));

        foreach ($rows as &$row) {
            $row['status_label']   = AiApproval::statusLabel((string) ($row['status'] ?? ''));
            $row['kind_label']     = ($row['capability_kind'] ?? '') === 'skill' ? '技能' : '工具';
            $row['requester_name'] = $names[(int) ($row['requester_id'] ?? 0)] ?? ('#' . ($row['requester_id'] ?? 0));
            $row['arguments_text'] = (string) ($row['arguments'] ?? '');
        }

        return $rows;
    }

    /**
     * 批量解析申请人姓名（避免 N+1）
     *
     * @param array $ids
     * @return array<int, string>
     */
    protected function resolveAdminNames(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

        if ($ids === []) {
            return [];
        }

        $modelClass = (string) config('wise-auth.user_models.admin', 'app\admin\model\Admin');

        if (!class_exists($modelClass)) {
            return [];
        }

        try {
            return $modelClass::whereIn('id', $ids)->column('username', 'id');
        } catch (\Throwable) {
            return [];
        }
    }


    /**
     * 触发「已申请」事件 + 可选通知
     */
    protected function dispatchRequested(AiApproval $approval, ApprovalRequirement $requirement): void
    {
        $event = new ApprovalRequested(
            (int) $approval->id,
            (string) $approval->getAttr('ident'),
            (string) $approval->getAttr('capability_kind'),
            (string) $approval->getAttr('capability_name'),
            (string) $approval->getAttr('permission'),
            (string) $approval->getAttr('access'),
            (string) $approval->getAttr('risk'),
            $requirement->mode,
            $requirement->rule,
            $requirement->reason,
            (int) $approval->getAttr('requester_id'),
            (string) $approval->getAttr('requester_type')
        );

        try {
            Event::trigger($event);
        } catch (\Throwable $e) {
            ContextualLogger::channel('ai')->warning('[Approval] ApprovalRequested listener error: ' . $e->getMessage());
        }

        $this->notifyApprovers($approval, $requirement);
    }

    /**
     * 触发「已决策」事件
     */
    protected function dispatchDecided(AiApproval $approval, string $decision, int $deciderId, string $note): void
    {
        $event = new ApprovalDecided(
            (int) $approval->id,
            (string) $approval->getAttr('ident'),
            $decision,
            (string) $approval->getAttr('capability_name'),
            (string) $approval->getAttr('permission'),
            (int) $approval->getAttr('requester_id'),
            $deciderId,
            $note
        );

        try {
            Event::trigger($event);
        } catch (\Throwable $e) {
            ContextualLogger::channel('ai')->warning('[Approval] ApprovalDecided listener error: ' . $e->getMessage());
        }

        $this->notifyRequester($approval, $decision, $note);
    }

    /**
     * wise-workflow 桥接（全部工作流交互收敛在此，未安装时静默降级）
     */
    protected function workflow(): ApprovalWorkflowBridge
    {
        return $this->workflowBridge ??= new ApprovalWorkflowBridge();
    }

    /**
     * 审批单的流转历史（供审批详情展示；wise-workflow 缺失时返回空数组）
     *
     * @return array<int, array>
     */
    public function workflowHistory(string $subject, int $limit = 20): array
    {
        return $this->workflow()->history($subject, $limit);
    }

    /**
     * 会签剩余待办票数
     */
    public function pendingVotes(string $subject): int
    {
        return $this->workflow()->pendingVotes($subject);
    }

    /**
     * 通知审批人（wise-notification 未安装时仅记录日志）
     *
     * 审批人来源优先级：
     *   1. `security.approval.notify_user_ids`（显式配置）
     *   2. `security.approval.workflow.approvers`（会签名单）
     *   3. 拥有 `admin.ai_approval.approve` 权限的用户（按角色反查）
     *   4. 超级管理员
     */
    protected function notifyApprovers(AiApproval $approval, ApprovalRequirement $requirement): void
    {
        $ids = $this->resolveApprovers();

        if ($ids === []) {
            return;
        }

        $content = sprintf(
            '审批单 %s：%s「%s」需要%s（申请人 #%s，规则 %s）',
            (string) $approval->getAttr('ident'),
            ($approval->getAttr('capability_kind') === 'skill' ? '技能' : '工具'),
            (string) $approval->getAttr('capability_name'),
            $requirement->mode === ApprovalRequirement::WORKFLOW ? '审批' : '确认',
            (string) $approval->getAttr('requester_id'),
            $requirement->rule
        );

        $this->notify('AI 能力审批待处理', $content, $ids, [
            'approval_id' => (int) $approval->id,
            'ident'       => (string) $approval->getAttr('ident'),
            'scene'       => 'approval_requested',
        ]);
    }

    /**
     * 决策结果回执给申请人
     */
    protected function notifyRequester(AiApproval $approval, string $decision, string $note): void
    {
        $requesterId = (int) $approval->getAttr('requester_id');

        if ($requesterId <= 0) {
            return;
        }

        $label   = match ($decision) {
            AiApproval::STATUS_APPROVED  => '已批准',
            AiApproval::STATUS_REJECTED  => '已驳回',
            AiApproval::STATUS_CANCELLED => '已撤回',
            default                      => '已更新',
        };
        $content = sprintf(
            '你的审批单 %s（%s「%s」）%s%s。批准后请重新发起该操作。',
            (string) $approval->getAttr('ident'),
            ($approval->getAttr('capability_kind') === 'skill' ? '技能' : '工具'),
            (string) $approval->getAttr('capability_name'),
            $label,
            $note !== '' ? '，备注：' . $note : ''
        );

        $this->notify('AI 能力审批结果', $content, [$requesterId], [
            'approval_id' => (int) $approval->id,
            'ident'       => (string) $approval->getAttr('ident'),
            'scene'       => 'approval_decided',
        ]);
    }

    /**
     * 解析审批人用户 ID 列表
     *
     * @return int[]
     */
    protected function resolveApprovers(): array
    {
        $configured = array_filter(array_map('intval', array_merge(
            (array) config('wise-agent.security.approval.notify_user_ids', []),
            (array) config('wise-agent.security.approval.workflow.approvers', [])
        )));

        if ($configured !== []) {
            return array_values(array_unique($configured));
        }

        $byPermission = $this->usersWithPermission('admin.ai_approval.approve');

        if ($byPermission !== []) {
            return $byPermission;
        }

        return array_filter(array_map('intval', (array) config('wise-auth.super_admin_ids', [])));
    }

    /**
     * 反查拥有某权限的用户（wise-auth 未安装 / 查询失败时返回空数组）
     *
     * @return int[]
     */
    protected function usersWithPermission(string $permission): array
    {
        if (!class_exists(\wise\auth\facade\Rbac::class)) {
            return [];
        }

        try {
            $permissionIds = Db::name('auth_permissions')
                ->where('ident', $permission)
                ->where('status', 1)
                ->column('id');

            if ($permissionIds === []) {
                return [];
            }

            $roleIds = Db::name('auth_role_permission')
                ->whereIn('permission_id', $permissionIds)
                ->column('role_id');

            if ($roleIds === []) {
                return [];
            }

            $userModels = (array) config('wise-auth.user_models', []);
            $userType   = (string) (array_key_first($userModels) ?: 'admin');

            return array_map('intval', \wise\auth\model\UserRole::whereIn('role_id', $roleIds)
                ->where('user_type', $userType)
                ->column('user_id'));
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * 发送站内通知（wise-notification 缺失时仅记日志，不影响审批流程）
     *
     * @param int[] $ids
     */
    protected function notify(string $title, string $content, array $ids, array $data = []): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

        if ($ids === []) {
            return;
        }

        if (!class_exists(\wise\notification\facade\Notification::class)
            || !interface_exists(\wise\notification\contract\Notifiable::class)) {
            ContextualLogger::channel('ai')->info(
                "[Approval] 通知跳过（未安装 wise-notification）：{$title} → #" . implode(', #', $ids)
            );

            return;
        }

        $modelClass = (string) config('wise-auth.user_models.admin', 'app\admin\model\Admin');

        if (!class_exists($modelClass)) {
            return;
        }

        try {
            $messageClass = \wise\notification\contract\Message::class;

            foreach ($ids as $uid) {
                $user = $modelClass::find($uid);

                if ($user === null || !$user instanceof \wise\notification\contract\Notifiable) {
                    continue;
                }

                \wise\notification\facade\Notification::channel('database')
                    ->sync()
                    ->send($user, new $messageClass($title, $content, $data));
            }
        } catch (\Throwable $e) {
            ContextualLogger::channel('ai')->warning('[Approval] notify failed: ' . $e->getMessage());
        }
    }
}
