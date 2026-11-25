<?php
declare(strict_types=1);

namespace wise\agent\controller;

use think\facade\View;
use app\common\view\ViewScope;

/**
 * AI 会话管理页（B4-8）
 *
 * 只渲染页面；数据查询走 `admin` 组的 {@see SessionController}
 * （控制器层校验 `admin.ai_session.view` / `.delete`）。
 *
 * 页面权限由菜单声明派生（`admin.ai_session.index`），由 admin 应用全局
 * RBAC 中间件统一校验，控制器内不重复判断（与 {@see AiTaskController} 一致）。
 */
class AiSessionController extends \app\admin\controller\BaseController
{
    protected const VIEW_SCOPE = 'wise-agent';
    protected const JS_MODULE  = '../plugins/wise-agent/js/ai_session';

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

        return View::fetch('wise-agent@admin/ai_session/index');
    }

    /**
     * 会话详情页（iframe 弹层）
     */
    public function detail()
    {
        $this->assign('js_module', self::JS_MODULE);
        $this->assign('session_id', (string) $this->request->param('session_id/s', ''));

        return View::fetch('wise-agent@admin/ai_session/detail');
    }
}
