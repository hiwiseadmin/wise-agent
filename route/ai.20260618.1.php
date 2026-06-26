<?php
use think\facade\Route;

/**
 * AI Controller routes
 * 
 * Copy this file to your project's route directory (e.g., app/admin/route/)
 * and register it via Route::group or the application's route loader.
 * 
 * Recommended middleware: \wise\auth\middleware\RbacMiddleware
 * 
 * Usage in your route file:
 *   require __DIR__ . '/path/to/wise-agent/route/ai.php';
 * 
 * Or manually register:
 *   Route::group('admin/ai', function () {
 *       require base_path('vendor/wiseadmin/wise-agent/route/ai.php');
 *   })->middleware(\wise\auth\middleware\RbacMiddleware::class);
 */

Route::group('ai', function () {

    // Config management routes
    Route::get('config', '\wise\agent\controller\AiController@config');
    Route::post('config/save', '\wise\agent\controller\AiController@saveConfig');
    Route::post('config/test', '\wise\agent\controller\AiController@testConnection');
    Route::get('providers', '\wise\agent\controller\AiController@getProviders');
    Route::get('models', '\wise\agent\controller\AiController@getModels');

    // Session management routes
    Route::get('sessions', '\wise\agent\controller\AiController@sessions');
    Route::get('session/detail', '\wise\agent\controller\AiController@sessionDetail');
    Route::post('session/create', '\wise\agent\controller\AiController@createSession');
    Route::post('session/rename', '\wise\agent\controller\AiController@renameSession');
    Route::post('session/delete', '\wise\agent\controller\AiController@deleteSession');
    Route::post('session/clear', '\wise\agent\controller\AiController@clearSession');

    // Chat routes
    Route::post('chat/stream', '\wise\agent\controller\AiController@chatStream');
    Route::post('chat/stop', '\wise\agent\controller\AiController@stopChat');

    // Page entry routes
    Route::get('chat-page', '\wise\agent\controller\AiController@chatPage');
    Route::get('config-page', '\wise\agent\controller\AiController@configPage');

})->allowCrossDomain();
