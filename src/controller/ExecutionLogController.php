<?php
declare(strict_types=1);

namespace wise\agent\controller;

use think\facade\Db;
use wise\support\traits\JsonResponse;

/**
 * AI 执行日志查询 API（B4-8）
 *
 * 数据来源：`ai_agent_logs`。
 *
 * 与「任务中心」的区别
 * --------------------
 * 任务中心（`TaskController`）看的是**一次任务**的执行轨迹（`job_type=agent|pipeline|plan|skill`）；
 * 本页看的是**每一次工具调用**（`job_type=tool`），这是 B2-4 引入的审计事实：
 * 谁、从哪个入口（agent/mcp/http）、调了哪个能力、参数指纹、结果状态、耗时。
 *
 * 两者共用一张表，用 `job_type` 分流 —— 这也是为什么本页能直接回答
 * "某次写操作为什么没被拦住"。
 *
 * 权限：控制器层显式校验 `admin.ai_execution_log.view`（fail-closed）。
 *
 * @package wise\agent\controller
 */
class ExecutionLogController
{
    use JsonResponse;

    private const PERMISSION_VIEW = 'admin.ai_execution_log.view';

    private const TABLE = 'ai_agent_logs';

    protected \think\Request $request;

    public function __construct()
    {
        $this->request = request();
    }

    /**
     * 工具调用执行日志列表
     */
    public function getList(): \think\response\Json
    {
        if ($this->denied() !== null) {
            return $this->error('无权限查看 AI 执行日志', [], 1);
        }

        [$page, $limit] = $this->paginateParams();
        $filter = $this->filter();

        $query = Db::name(self::TABLE)->where($filter);

        $total = (int) Db::name(self::TABLE)->where($filter)->count();

        $rows = $query->field(
            'id, tracking_id, tool_name, capability, entry_source, status, error, duration, '
            . 'user_type, user_id, approval_id, create_time'
        )
            ->order('id', 'desc')
            ->page($page, $limit)
            ->select()
            ->toArray();

        return $this->success('ok', [
            'rows'  => $rows,
            'total' => $total,
        ]);
    }

    /**
     * 单条执行日志详情（含结果与参数指纹）
     */
    public function get(): \think\response\Json
    {
        if ($this->denied() !== null) {
            return $this->error('无权限查看 AI 执行日志', [], 1);
        }

        $trackingId = trim((string) $this->request->param('tracking_id/s', ''));
        if ($trackingId === '') {
            return $this->error('缺少 tracking_id', [], 1);
        }

        $row = Db::name(self::TABLE)->where('tracking_id', $trackingId)->find();

        if (!$row) {
            return $this->error('记录不存在', [], 1);
        }

        return $this->success('ok', $row);
    }

    /**
     * 统计（按状态 / 入口来源），供列表页顶部概览
     */
    public function stat(): \think\response\Json
    {
        if ($this->denied() !== null) {
            return $this->error('无权限查看 AI 执行日志', [], 1);
        }

        $filter = $this->filter();

        $byStatus = Db::name(self::TABLE)
            ->where($filter)
            ->field('status, COUNT(*) AS cnt')
            ->group('status')
            ->select()
            ->toArray();

        $bySource = Db::name(self::TABLE)
            ->where($filter)
            ->field('entry_source, COUNT(*) AS cnt')
            ->group('entry_source')
            ->select()
            ->toArray();

        return $this->success('ok', [
            'total'     => (int) Db::name(self::TABLE)->where($filter)->count(),
            'by_status' => $byStatus,
            'by_source' => $bySource,
        ]);
    }

    /**
     * 过滤条件
     *
     * 默认只列工具级记录（`job_type = tool`），与任务中心互不干扰。
     *
     * @return array<string, mixed>
     */
    private function filter(): array
    {
        $filter = ['job_type' => 'tool'];

        $status = $this->request->param('status');
        if ($status !== null && $status !== '') {
            $filter['status'] = (int) $status;
        }

        $source = trim((string) $this->request->param('entry_source/s', ''));
        if ($source !== '') {
            $filter['entry_source'] = $source;
        }

        $tool = trim((string) $this->request->param('tool_name/s', ''));
        if ($tool !== '') {
            $filter['tool_name'] = $tool;
        }

        return $filter;
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
     */
    private function denied(): ?string
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

            return $rbac->checkAny($adminId, 'admin', [self::PERMISSION_VIEW]) ? null : 'forbidden';
        } catch (\Throwable $e) {
            return 'auth_error';
        }
    }
}
