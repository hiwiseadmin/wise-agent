<?php
declare(strict_types=1);

namespace wise\agent\controller;

use wise\agent\task\TaskQueryService;
use wise\support\traits\JsonResponse;

/**
 * AI 任务中心查询 API＼�
 *
 * 三个只读接口：`task/list`（分页列表）、`task/get`（详情）、`task/stat`（统计），
 * 全部落在 `admin/ai` 组，与既有的 `task/status`（单条）同组同中间件。
 *
 * 自包含控制器（与 {@see AiController} 一致，不依赖宿主 BaseController）。
 *
 * 权限：本组默认不挂 RBAC 中间件（配置留空），故在**控制器层**显式校验
 * `admin.ai_task.view`（wise-auth 不可用 / 未登录 / 无权限 ⇒ fail-closed），
 * 即使实例忘了配中间件，任务中心也不会裸奔。无权限返回 `code=1` 且不泄漏任何任务内容。
 */
class TaskController
{
    use JsonResponse;

    private const PERMISSION_VIEW = 'admin.ai_task.view';

    protected \think\Request $request;

    protected TaskQueryService $query;

    public function __construct()
    {
        $this->request = request();
        $this->query   = new TaskQueryService();
    }

    /**
     * 分页列表（过滤 + 排序 + 分页）
     *
     * 分页参数同时接受 `page/limit`（既有控制器约定）与 `pageSize/pageNumber`（Wise.table 约定）。
     */
    public function getList(): \think\response\Json
    {
        if (!$this->assertCanView()) {
            return $this->error('无权限查看任务中心', [], 1);
        }

        $filter = $this->buildFilter();

        if ($this->request->has('pageSize') || $this->request->has('pageNumber')) {
            $page  = $this->request->param('pageNumber/d', 1);
            $limit = $this->request->param('pageSize/d', 15);
        } else {
            $page  = $this->request->param('page/d', 1);
            $limit = $this->request->param('limit/d', 15);
        }

        $result = $this->query->paginate($filter, $page, $limit);

        return $this->success('ok', [
            'rows'  => $result['rows'],
            'total' => $result['total'],
        ]);
    }

    /**
     * 单任务详情（事实层 + 三附）
     */
    public function get(): \think\response\Json
    {
        if (!$this->assertCanView()) {
            return $this->error('无权限查看任务中心', [], 1);
        }

        $trackingId = $this->request->param('tracking_id/s', '');

        return $this->success('ok', $this->query->detail($trackingId));
    }

    /**
     * 统计（按状态 / 执行体 / 天）
     */
    public function stat(): \think\response\Json
    {
        if (!$this->assertCanView()) {
            return $this->error('无权限查看任务中心', [], 1);
        }

        return $this->success('ok', $this->query->stats($this->buildFilter()));
    }


    /**
     * 权限校验（fail-closed：未登录 / wise-auth 不可用 / 无权限 ⇒ false）
     */
    protected function assertCanView(): bool
    {
        $userId = $this->currentAdminId();

        if ($userId <= 0 || !class_exists(\wise\auth\facade\Rbac::class)) {
            return false;
        }

        try {
            if (\wise\auth\facade\Rbac::isSuperAdmin($userId, 'admin')) {
                return true;
            }

            return (bool) \wise\auth\facade\Rbac::check($userId, 'admin', self::PERMISSION_VIEW);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * 当前后台管理员 ID（脱离宿主 / 未登录 ⇒ 0）
     */
    protected function currentAdminId(): int
    {
        try {
            if (class_exists(\app\admin\facade\CurrentAdmin::class)) {
                return (int) \app\admin\facade\CurrentAdmin::getAdminId();
            }
        } catch (\Throwable) {
        }

        return 0;
    }

    /**
     * 从请求构造过滤条件（`status`/`user_id` 因 0 是合法值，用 `has` + 空值判断而非空串判断）
     *
     * @return array<string, mixed>
     */
    protected function buildFilter(): array
    {
        $filter = [];

        foreach (['status', 'user_id'] as $key) {
            if ($this->request->has($key)) {
                $value = $this->request->param($key);
                if ($value !== null && $value !== '') {
                    $filter[$key] = ($key === 'status' && str_contains((string) $value, ','))
                        ? array_map('intval', explode(',', (string) $value))
                        : (int) $value;
                }
            }
        }

        foreach (['job_type', 'user_type', 'session_id', 'provider', 'model', 'from', 'to', 'keyword', 'sort'] as $key) {
            $value = $this->request->param($key);
            if ($value !== null && $value !== '') {
                $filter[$key] = (string) $value;
            }
        }

        return $filter;
    }
}
