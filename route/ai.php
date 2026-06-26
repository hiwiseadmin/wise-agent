<?php
use think\facade\Route;

/**
 * AI 控制器路由
 * 
 * 将此文件复制到项目的 route 目录（如 app/admin/route/），
 * 并通过 Route::group 或应用程序的路由加载器注册。
 * 
 * 推荐中间件：\wise\auth\middleware\RbacMiddleware
 * 
 * 在路由文件中的用法：
 *   require __DIR__ . '/path/to/wise-agent/route/ai.php';
 * 
 * 或手动注册：
 *   Route::group('admin/ai', function () {
 *       require base_path('vendor/wiseadmin/wise-agent/route/ai.php');
 *   })->middleware(\wise\auth\middleware\RbacMiddleware::class);
 */

Route::group('ai', function () {

    // 配置管理路由
    Route::get('config', '\wise\agent\controller\AiController@config');
    Route::post('config/save', '\wise\agent\controller\AiController@saveConfig');
    Route::post('config/test', '\wise\agent\controller\AiController@testConnection');
    Route::get('providers', '\wise\agent\controller\AiController@getProviders');
    Route::get('models', '\wise\agent\controller\AiController@getModels');

    // 会话管理路由
    Route::get('sessions', '\wise\agent\controller\AiController@sessions');
    Route::get('session/detail', '\wise\agent\controller\AiController@sessionDetail');
    Route::post('session/create', '\wise\agent\controller\AiController@createSession');
    Route::post('session/rename', '\wise\agent\controller\AiController@renameSession');
    Route::post('session/delete', '\wise\agent\controller\AiController@deleteSession');
    Route::post('session/clear', '\wise\agent\controller\AiController@clearSession');

    // 聊天路由
    Route::post('chat/stream', '\wise\agent\controller\AiController@chatStream');
    Route::post('chat/stop', '\wise\agent\controller\AiController@stopChat');

    // 页面入口路由
    Route::get('chat-page', '\wise\agent\controller\AiController@chatPage');
    Route::get('config-page', '\wise\agent\controller\AiController@configPage');

})->allowCrossDomain();
