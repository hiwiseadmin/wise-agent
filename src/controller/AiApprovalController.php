<?php
declare(strict_types=1);

namespace wise\agent\controller;

use think\facade\View;
use wise\agent\model\AiApproval as AiApprovalModel;
use wise\agent\security\ApprovalManager;

/**
 * AI 能力审批中心 —— 后台页面（包内控制器，复用框架包壳）
 *
 * AI 工具 / 技能调用被审批策略拦截时生成审批单（ws_ai_approvals），
 * 本控制器提供审批单的查看与决策能力。
 *
 * 正规链路（与 wise-health-check 一致）：
 *  - 控制器继承宿主 {@see \app\admin\controller\BaseController}，复用后台
 *    通用模板变量（$wssite / $wsconfig / $wsslots / $wsFeatureModules / CSP nonce）；
 *  - initialize() 中 ViewScope::activate('wise-agent')，使 $this->fetch() 的相对
 *    模板名兜底到本包 view/ 目录（宿主 app/admin/view 仍可放同名文件覆盖）；
 *  - 视图 {extend name="__WISE_VIEW__/base"} 复用框架「包共用壳」
 *    （extend/wise/view/base.html），与后台应用一致；
 *  - 前端 JS 由 wise-bootstrap 按 wsconfig.js 以 ESM 加载包内模块。
 *
 * 宿主依赖由 RouteRegistrar 在注册路由前用 class_exists 守卫，脱离 WiseAdmin
 * 宿主时不注册这些路由（见 wise\agent\RouteRegistrar）。
 *
 * 权限：URL 与菜单声明（AiMenuProvider）派生的 admin.ai_approval.*
 * 一一对应，由 admin 应用全局 RBAC 中间件统一校验，控制器内不重复判断。
 */
class AiApprovalController extends \app\admin\controller\BaseController
{
    /** 视图 scope（视图驱动据此把相对模板名兜底到本包 view/ 目录） */
    protected const VIEW_SCOPE = 'wise-agent';

    /**
     * 前端控制器模块路径
     *
     * 相对 wise-bootstrap.js 解析（其位于 /statics/modules/），故 '../plugins/...'
     * 即 /statics/plugins/...。约定：默认导出对象含与当前 action 同名的方法。
     */
    protected const JS_MODULE = '../plugins/wise-agent/js/ai_approval';

    protected ApprovalManager $manager;

    /**
     * 初始化：绑定包内视图与前端资源
     */
    protected function initialize()
    {
        parent::initialize();

        try {
            if (class_exists(\app\common\view\ViewScope::class)) {
                \app\common\view\ViewScope::activate(self::VIEW_SCOPE);
            } else {
                $this->request->view_scope = self::VIEW_SCOPE;
            }
        } catch (\Throwable $e) {
        }

        $this->manager = new ApprovalManager();
    }

    /**
     * 审批中心首页
     */
    public function index()
    {
        View::assign('wsconfig', array_merge(
            (array) View::get('wsconfig'),
            ['js' => self::JS_MODULE]
        ));

        return $this->fetch('admin/ai_approval/index');
    }

    /**
     * 审批单列表（分页）
     */
    public function getList()
    {
        $result = $this->manager->paginate(
            [
                'status'       => $this->request->param('status/s', ''),
                'keyword'      => $this->request->param('keyword/s', ''),
                'kind'         => $this->request->param('kind/s', ''),
                'access'       => $this->request->param('access/s', ''),
                'risk'         => $this->request->param('risk/s', ''),
                'requester_id' => $this->request->param('requester_id/d', 0),
            ],
            $this->request->param('page/d', 1),
            $this->request->param('limit/d', 15)
        );

        return $this->success('ok', [
            'total' => $result['total'],
            'list'  => $result['rows'],
        ]);
    }

    /**
     * 审批单详情（弹层 iframe 内页）
     *
     * 一并展示 wise-workflow 的流转历史与会签进度（未安装/无实例时为空）。
     */
    public function preview()
    {
        $id       = $this->request->param('id/d', 0);
        $approval = $this->manager->find($id);

        if ($approval === null) {
            return $this->error('审批单不存在');
        }

        $subject = (string) ($approval['workflow_subject'] ?? '');

        $approval['status_label']     = AiApprovalModel::statusLabel((string) $approval['status']);
        $approval['arguments_pretty'] = $this->prettyArguments($approval['arguments'] ?? '');
        $approval['workflow_history'] = $subject !== '' ? $this->manager->workflowHistory($subject, 20) : [];
        $approval['pending_votes']    = $subject !== '' ? $this->manager->pendingVotes($subject) : 0;

        return $this->assign([
            'approval'        => $approval,
            'workflowHistory' => $approval['workflow_history'],
        ])->fetch('admin/ai_approval/preview');
    }

    /**
     * 状态统计
     */
    public function stat()
    {
        return $this->success('ok', $this->manager->stats());
    }

    /**
     * 批准
     */
    public function approve()
    {
        $result = $this->manager->approve(
            $this->request->param('id/d', 0),
            $this->operatorId(),
            $this->request->param('note/s', '')
        );

        return $result['ok'] ? $this->success('已批准') : $this->error($result['message']);
    }

    /**
     * 驳回
     */
    public function reject()
    {
        $result = $this->manager->reject(
            $this->request->param('id/d', 0),
            $this->operatorId(),
            $this->request->param('note/s', '')
        );

        return $result['ok'] ? $this->success('已驳回') : $this->error($result['message']);
    }

    /**
     * 撤回
     */
    public function cancel()
    {
        $result = $this->manager->cancel(
            $this->request->param('id/d', 0),
            $this->operatorId(),
            $this->request->param('note/s', '')
        );

        return $result['ok'] ? $this->success('已撤回') : $this->error($result['message']);
    }

    /**
     * 手工清理过期审批单
     */
    public function expire()
    {
        $count = $this->manager->expireStale();

        return $this->success("已失效 {$count} 条");
    }


    /**
     * 当前登录管理员 ID（未登录返回 0）
     */
    protected function operatorId(): int
    {
        return (int) ($this->currentAdminId() ?? 0);
    }

    /**
     * 参数快照美化（供详情弹层展示）
     */
    protected function prettyArguments(string $raw): string
    {
        if ($raw === '') {
            return '';
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded)
            ? (string) json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
            : $raw;
    }
}
