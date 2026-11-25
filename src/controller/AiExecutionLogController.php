<?php
declare(strict_types=1);

namespace wise\agent\controller;

use think\facade\View;
use app\common\view\ViewScope;

/**
 * AI 执行日志页（B4-8）
 *
 * 只渲染页面；数据查询走 `admin` 组的 {@see ExecutionLogController}
 * （控制器层校验 `admin.ai_execution_log.view`）。
 *
 * 展示的是**工具级**审计记录（`ai_agent_logs.job_type = tool`）：
 * 谁、从哪个入口调用、调了哪个能力、是否被拦截、耗时多久。
 * 与「任务中心」（任务级轨迹）互为补充，不是重复页面。
 */
class AiExecutionLogController extends \app\admin\controller\BaseController
{
    protected const VIEW_SCOPE = 'wise-agent';
    protected const JS_MODULE  = '../plugins/wise-agent/js/ai_execution_log';

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

        return View::fetch('wise-agent@admin/ai_execution_log/index');
    }

    /**
     * 详情页（iframe 弹层，tracking_id 由查询串传入）
     */
    public function detail()
    {
        $this->assign('js_module', self::JS_MODULE);
        $this->assign('tracking_id', (string) $this->request->param('tracking_id/s', ''));

        return View::fetch('wise-agent@admin/ai_execution_log/detail');
    }
}
