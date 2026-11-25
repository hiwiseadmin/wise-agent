<?php
declare(strict_types=1);

namespace wise\agent\controller;

use think\facade\Config;
use wise\agent\widget\AiWidgetInjector;
use wise\support\traits\JsonResponse;

/**
 * AI 挂件配置接口＼�
 *
 * 前端 `ai-widget.js` 在 `init()` 时拉取本接口，获得「总开关 + 四类挂件开关 +
 * 插件级 AI 声明」，据此按页面类型挂载对应挂件。返回内容不敏感（仅开关/声明），
 * 因此不做权限校验。
 */
class WidgetController
{
    use JsonResponse;

    public function config(): \think\response\Json
    {
        $enabled = (bool) Config::get('wise-agent.widget.enabled', false);

        return $this->success('ok', [
            'enabled'        => $enabled,
            'assistant'      => (bool) Config::get('wise-agent.widget.assistant', true),
            'list_filter'    => (bool) Config::get('wise-agent.widget.list_filter', true),
            'form_fill'      => (bool) Config::get('wise-agent.widget.form_fill', true),
            'detail_explain' => (bool) Config::get('wise-agent.widget.detail_explain', true),
            'plugins'        => $enabled ? (new AiWidgetInjector())->build() : [],
        ]);
    }
}
