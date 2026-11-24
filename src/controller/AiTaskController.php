<?php
declare(strict_types=1);

namespace wise\agent\controller;

use think\facade\View;
use app\common\view\ViewScope;

/**
 * AI 任务中心页面＼�
 *
 * 只负责渲染列表页与详情页（iframe 弹层）；查询数据走 `admin` 组的
 * {@see TaskController}（task/list、task/get、task/stat，控制器层做
 * `admin.ai_task.view` 校验）。
 *
 * 页面权限由菜单声明派生（`admin.ai_task.index`），由 admin 应用全局 RBAC
 * 中间件统一校验，控制器内不重复判断（与 {@see AiApprovalController} 一致）。
 */
class AiTaskController extends \app\admin\controller\BaseController
{
    protected const VIEW_SCOPE = 'wise-agent';
    protected const JS_MODULE  = '../plugins/wise-agent/js/ai_task';

    protected function initialize()
    {
        parent::initialize();
        ViewScope::activate(self::VIEW_SCOPE);
    }

    /**
     * 列表页
     */
    public function index()
    {
        $this->assign('js_module', self::JS_MODULE);
        return View::fetch('wise-agent@admin/ai_task/index');
    }

    /**
     * 详情页（iframe 弹层，tracking_id 由查询串传入）
     */
    public function detail()
    {
        $this->assign('js_module', self::JS_MODULE);
        $this->assign('tracking_id', $this->request->param('tracking_id/s', ''));
        return View::fetch('wise-agent@admin/ai_task/detail');
    }
}
