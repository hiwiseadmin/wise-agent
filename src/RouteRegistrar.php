<?php

declare(strict_types=1);

namespace wise\agent;

use think\facade\Route;
use wise\support\router\RouteRegistrar as BaseRouteRegistrar;

/**
 * WiseAgent 路由注册器（配置驱动）
 *
 * 由 AgentServiceProvider::boot() 调用 RouteRegistrar::register() 注册。
 * 前缀 / 中间件 / 限流通过 config('wise-agent.web') 配置：
 *
 * ```php
 * 'web' => [
 *     'enable' => true,
 *     'prefix' => 'admin/ai',
 *     'groups' => [
 *         'admin' => [
 *             'enable' => true,
 *             'middleware' => [
 *                 \wise\auth\middleware\RbacMiddleware::class,   // 建议默认挂 RBAC
 *             ],
 *         ],
 *     ],
 * ],
 * ```
 *
 * ⚠️ 安全提示：所有端点涉及 AI 配置管理、API Key 存储和会话数据，
 * 默认配置未挂中间件时，宿主应通过 config('wise-agent.web.groups.admin.middleware')
 * 显式挂载 RBAC 中间件保护。
 *
 * @package wise\agent
 */
class RouteRegistrar extends BaseRouteRegistrar
{
    protected string $configKey = 'wise-agent';

    protected function define(array $config): void
    {
        $this->group('admin', function () {
            Route::get('config', '\wise\agent\controller\AiController@config');
            Route::post('config/save', '\wise\agent\controller\AiController@saveConfig');
            Route::post('config/test', '\wise\agent\controller\AiController@testConnection');
            Route::get('providers', '\wise\agent\controller\AiController@getProviders');
            Route::get('models', '\wise\agent\controller\AiController@getModels');

            Route::get('sessions', '\wise\agent\controller\AiController@sessions');
            Route::get('session/detail', '\wise\agent\controller\AiController@sessionDetail');
            Route::post('session/create', '\wise\agent\controller\AiController@createSession');
            Route::post('session/rename', '\wise\agent\controller\AiController@renameSession');
            Route::post('session/delete', '\wise\agent\controller\AiController@deleteSession');
            Route::post('session/clear', '\wise\agent\controller\AiController@clearSession');

            Route::post('chat/stream', '\wise\agent\controller\AiController@chatStream');
            Route::post('chat/stop', '\wise\agent\controller\AiController@stopChat');
            Route::post('chat/resume', '\wise\agent\controller\AiController@resumeChat');

            Route::get('task/status', '\wise\agent\controller\AiController@taskStatus');
            Route::get('session/interrupted-status', '\wise\agent\controller\AiController@interruptedStatus');

            if ((bool) config('wise-agent.tasks.enabled', false)) {
                Route::get('task/list', '\wise\agent\controller\TaskController@getList');
                Route::get('task/get', '\wise\agent\controller\TaskController@get');
                Route::get('task/stat', '\wise\agent\controller\TaskController@stat');
            }

            Route::get('billing/summary', '\wise\agent\controller\BillingController@summary');

            if (class_exists(\app\admin\controller\BaseController::class)) {
                Route::get('billing/index', '\wise\agent\controller\AiBillingController@index');
            }

            Route::get('session/list', '\wise\agent\controller\SessionController@getList');
            Route::get('session/get', '\wise\agent\controller\SessionController@get');
            Route::post('session/delete', '\wise\agent\controller\SessionController@delete');

            Route::get('execution-log/list', '\wise\agent\controller\ExecutionLogController@getList');
            Route::get('execution-log/get', '\wise\agent\controller\ExecutionLogController@get');
            Route::get('execution-log/stat', '\wise\agent\controller\ExecutionLogController@stat');

            Route::get('widget/config', '\wise\agent\controller\WidgetController@config');

            Route::get('approval/pending', '\wise\agent\controller\ApprovalController@pending');
            Route::post('approval/decide', '\wise\agent\controller\ApprovalController@decide');

            Route::get('tools', '\wise\agent\controller\AiController@getTools');
            Route::get('workflow-nodes', '\wise\agent\controller\AiController@getWorkflowNodes');
            Route::get('adapters', '\wise\agent\controller\AiController@getAdapters');
            Route::post('tool/call', '\wise\agent\controller\AiController@callTool');

        });

        if (!class_exists(\app\admin\controller\BaseController::class)) {
            return;
        }

        $this->group('admin_approval', function () {
            Route::get('index', '\wise\agent\controller\AiApprovalController@index');
            Route::get('getList', '\wise\agent\controller\AiApprovalController@getList');
            Route::get('preview', '\wise\agent\controller\AiApprovalController@preview');
            Route::get('stat', '\wise\agent\controller\AiApprovalController@stat');
            Route::post('approve', '\wise\agent\controller\AiApprovalController@approve');
            Route::post('reject', '\wise\agent\controller\AiApprovalController@reject');
            Route::post('cancel', '\wise\agent\controller\AiApprovalController@cancel');
            Route::post('expire', '\wise\agent\controller\AiApprovalController@expire');
        });

        if ((bool) config('wise-agent.tasks.enabled', false)) {
            $this->group('admin_task', function () {
                Route::get('index', '\wise\agent\controller\AiTaskController@index');
                Route::get('detail', '\wise\agent\controller\AiTaskController@detail');
            });
        }

        $this->group('admin_chat', function () {
            Route::get('index', '\wise\agent\controller\AiChatController@index');
        });

        $this->group('admin_config', function () {
            Route::get('index', '\wise\agent\controller\AiConfigController@index');
        });

        $this->group('admin_session', function () {
            Route::get('index', '\wise\agent\controller\AiSessionController@index');
            Route::get('detail', '\wise\agent\controller\AiSessionController@detail');
        });

        $this->group('admin_execution_log', function () {
            Route::get('index', '\wise\agent\controller\AiExecutionLogController@index');
            Route::get('detail', '\wise\agent\controller\AiExecutionLogController@detail');
        });
    }
}
