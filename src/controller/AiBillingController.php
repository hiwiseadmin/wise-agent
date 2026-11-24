<?php
declare(strict_types=1);

namespace wise\agent\controller;

use think\facade\View;

/**
 * AI 用量仪表盘 —— 后台页面＼�验收 10）
 *
 * 只负责渲染仪表盘页面；查询数据走 `admin` 组的 {@see BillingController}@summary
 * （`billing/summary`，控制器层做 `admin.ai_task.view` 校验）。
 *
 * 页面权限由菜单声明派生（`admin.ai.billing.index`），由 admin 应用全局 RBAC
 * 中间件统一校验，控制器内不重复判断（与 {@see AiTaskController} /
 * {@see AiApprovalController} 一致）。
 *
 * 正规链路（与 wise-health-check 一致）：
 *  - 控制器继承宿主 {@see \app\admin\controller\BaseController}，复用后台通用模板变量；
 *  - initialize() 中 {@see \app\common\view\ViewScope::activate('wise-agent')}，使
 *    $this->fetch() 的相对模板名兜底到本包 view/ 目录；
 *  - 视图 {extend name="__WISE_VIEW__/base"} 复用框架「包共用壳」；
 *  - 前端 ESM 模块由 wise-bootstrap 按 wsconfig.js 加载包内资源。
 */
class AiBillingController extends \app\admin\controller\BaseController
{
    /** 视图 scope（视图驱动据此把相对模板名兜底到本包 view/ 目录） */
    protected const VIEW_SCOPE = 'wise-agent';

    /**
     * 前端控制器模块路径（相对 wise-bootstrap.js 解析，'../plugins/...'
     * 即 /statics/plugins/...）。约定：默认导出对象含与当前 action 同名的方法。
     */
    protected const JS_MODULE = '../plugins/wise-agent/js/ai_billing';

    /**
     * 初始化：绑定包内视图与前端资源
     */
    protected function initialize()
    {
        parent::initialize();

        if (class_exists(\app\common\view\ViewScope::class)) {
            \app\common\view\ViewScope::activate(self::VIEW_SCOPE);
        }
    }

    /**
     * 仪表盘首页（趋势 + Top N + 覆盖率）
     */
    public function index()
    {
        View::assign('wsconfig', array_merge(
            (array) View::get('wsconfig'),
            ['js' => self::JS_MODULE]
        ));

        return $this->fetch('admin/ai_billing/index');
    }
}
