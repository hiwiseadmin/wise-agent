<?php
declare(strict_types=1);

namespace wise\agent\controller;

use think\facade\Db;
use wise\support\traits\JsonResponse;

/**
 * AI 会话管理查询 API（B4-8）
 *
 * 为什么需要
 * ----------
 * 此前后台只有 `ai_task` / `ai_approval` / `ai_billing` 三个页面，
 * **看不到"用户和 AI 聊了什么"**：出了问题无法复盘，也无法回答
 * "这个会话为什么触发了写操作"。
 *
 * 数据来源：`ai_messages`（`Conversation` 的持久化表），按 `session_id` 聚合。
 *
 * 权限：本组默认不挂 RBAC 中间件，故在**控制器层**显式校验
 * `admin.ai_session.view`（无权限一律 fail-closed），避免会话原文裸奔。
 *
 * @package wise\agent\controller
 */
class SessionController
{
    use JsonResponse;

    private const PERMISSION_VIEW   = 'admin.ai_session.view';
    private const PERMISSION_DELETE = 'admin.ai_session.delete';

    private const TABLE = 'ai_messages';

    protected \think\Request $request;

    public function __construct()
    {
        $this->request = request();
    }

    /**
     * 会话列表（按 session 聚合）
     */
    public function getList(): \think\response\Json
    {
        if ($this->denied(self::PERMISSION_VIEW) !== null) {
            return $this->error('无权限查看 AI 会话', [], 1);
        }

        [$page, $limit] = $this->paginateParams();

        $query = Db::name(self::TABLE)
            ->field('session_id, COUNT(*) AS message_count, MIN(create_time) AS started_at, MAX(create_time) AS last_active')
            ->group('session_id');

        $keyword = trim((string) $this->request->param('keyword/s', ''));
        if ($keyword !== '') {
            $query->whereLike('session_id', '%' . $keyword . '%');
        }

        $total = (int) Db::name(self::TABLE)
            ->when($keyword !== '', fn($q) => $q->whereLike('session_id', '%' . $keyword . '%'))
            ->count('DISTINCT session_id');

        $rows = $query->order('last_active', 'desc')
            ->page($page, $limit)
            ->select()
            ->toArray();

        return $this->success('ok', [
            'rows'  => $rows,
            'total' => $total,
        ]);
    }

    /**
     * 单个会话的消息明细
     */
    public function get(): \think\response\Json
    {
        if ($this->denied(self::PERMISSION_VIEW) !== null) {
            return $this->error('无权限查看 AI 会话', [], 1);
        }

        $sessionId = trim((string) $this->request->param('session_id/s', ''));
        if ($sessionId === '') {
            return $this->error('缺少 session_id', [], 1);
        }

        $messages = Db::name(self::TABLE)
            ->where('session_id', $sessionId)
            ->order('id', 'asc')
            ->select()
            ->toArray();

        return $this->success('ok', [
            'session_id' => $sessionId,
            'messages'   => $messages,
        ]);
    }

    /**
     * 删除会话（仅登录后台的管理员，且需 delete 权限）
     */
    public function delete(): \think\response\Json
    {
        if ($this->denied(self::PERMISSION_DELETE) !== null) {
            return $this->error('无权限删除 AI 会话', [], 1);
        }

        $sessionId = trim((string) $this->request->param('session_id/s', ''));
        if ($sessionId === '') {
            return $this->error('缺少 session_id', [], 1);
        }

        $deleted = Db::name(self::TABLE)->where('session_id', $sessionId)->delete();

        return $this->success('已删除', ['deleted' => (int) $deleted]);
    }

    /**
     * @return array{0:int, 1:int}
     */
    private function paginateParams(): array
    {
        if ($this->request->has('pageSize') || $this->request->has('pageNumber')) {
            return [
                max(1, (int) $this->request->param('pageNumber/d', 1)),
                min(200, max(1, (int) $this->request->param('pageSize/d', 15))),
            ];
        }

        return [
            max(1, (int) $this->request->param('page/d', 1)),
            min(200, max(1, (int) $this->request->param('limit/d', 15))),
        ];
    }

    /**
     * 权限判定（fail-closed）
     *
     * wise-auth 不可用 / 未登录 / 无权限，一律视为拒绝。
     *
     * @return string|null 拒绝原因；null 表示放行
     */
    private function denied(string $permission): ?string
    {
        try {
            if (!class_exists(\wise\auth\service\RbacManager::class) || !function_exists('auth_id')) {
                return 'auth_unavailable';
            }

            $adminId = auth_id('admin');
            if (empty($adminId)) {
                return 'unauthenticated';
            }

            $rbac = app(\wise\auth\service\RbacManager::class);

            return $rbac->checkAny($adminId, 'admin', [$permission]) ? null : 'forbidden';
        } catch (\Throwable $e) {
            return 'auth_error';
        }
    }
}
