<?php
declare(strict_types=1);

namespace wise\agent\controller;

use think\facade\View;

/**
 * AI 设置 —— 后台页面（重构自 AiController::configPage 的静态 HTML）
 *
 * Provider 配置界面（API Key / Base URL / 模型 / 参数），并汇总插件 AI 能力。
 * 数据走 `admin` 组 {@see AiController} 的 REST API（/admin/ai/*）。
 *
 * 页面权限由菜单声明派生（admin.ai_config.index），由 admin 应用全局 RBAC
 * 中间件统一校验，控制器内不重复判断。
 *
 * 正规链路与 {@see AiApprovalController} 一致：继承宿主
 * {@see \app\admin\controller\BaseController} + ViewScope + 复用「包共用壳」。
 */
class AiConfigController extends \app\admin\controller\BaseController
{
    /** 视图 scope（视图驱动据此把相对模板名兜底到本包 view/ 目录） */
    protected const VIEW_SCOPE = 'wise-agent';

    /**
     * 前端控制器模块路径（相对 wise-bootstrap.js 解析，'../plugins/...'
     * 即 /statics/plugins/...）。约定：默认导出对象含与当前 action 同名的方法。
     */
    protected const JS_MODULE = '../plugins/wise-agent/js/ai_config';

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
     * 设置首页
     */
    public function index()
    {
        View::assign('wsconfig', array_merge(
            (array) View::get('wsconfig'),
            ['js' => self::JS_MODULE]
        ));

        return $this->fetch('admin/ai_config/index');
    }
}
