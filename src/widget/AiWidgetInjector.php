<?php
declare(strict_types=1);

namespace wise\agent\widget;

/**
 * AI 挂件注入器＼�
 *
 * 检测插件 `manifest.json` 存在 `ai_schema` / `ai_actions` 声明，产出「每个插件 → 注入哪些挂件」
 * 的配置。前端 **0 行代码**：有声明即注入，无声明即跳过（验收 8）。
 *
 * 四类挂件（`widget.*` 开关 + 插件声明共同决定）：
 *   - `assistant`      AI 助手入口（顶栏图标 → 居中弹窗，复用 `agentChat.init()`，有声明即注入）；
 *   - `list_filter`    列表页自然语言筛选（对接 A3 `lq_query`，需 `ai_actions` 或 `ai_schema`）；
 *   - `form_fill`      表单页 AI 智能填充（对接 A2 `form_fill`，需 `ai_schema`）；
 *   - `detail_explain` 详情页 AI 解读（需 `ai_schema`）。
 *
 * `build()` 是纯逻辑（插件声明 → 挂件配置），`pluginsLoader` 可注入（单测替身）；
 * 默认加载器独立扫描 `{root}/plugin/` 下各插件的 `manifest.json`（与 PluginAiDiscovery 同源目录）。
 *
 * @package wise\agent
 */
final class AiWidgetInjector
{
    public function __construct(
        private readonly array $options = [],
        /** 可注入的插件声明加载器（单测替身）：fn(): array<{ident:string, has_schema:bool, has_actions:bool}> */
        private readonly ?\Closure $pluginsLoader = null,
    ) {
    }

    /**
     * 生成挂件配置（每个有 AI 声明的插件一条；无声明不出现）
     *
     * @return array<string, array{assistant: bool, list_filter: bool, form_fill: bool, detail_explain: bool}>
     */
    public function build(): array
    {
        if (!(bool) $this->opt('enabled', false)) {
            return [];
        }

        $result = [];

        foreach ($this->plugins() as $plugin) {
            $ident = trim((string) ($plugin['ident'] ?? ''));
            if ($ident === '') {
                continue;
            }

            $hasSchema  = (bool) ($plugin['has_schema'] ?? false);
            $hasActions = (bool) ($plugin['has_actions'] ?? false);

            if (!$hasSchema && !$hasActions) {
                continue;
            }

            $result[$ident] = $this->widgetsFor($hasSchema, $hasActions);
        }

        return $result;
    }

    /**
     * @return array{assistant: bool, list_filter: bool, form_fill: bool, detail_explain: bool}
     */
    private function widgetsFor(bool $hasSchema, bool $hasActions): array
    {
        return [
            'assistant'      => (bool) $this->opt('assistant', true),
            'list_filter'    => ($hasSchema || $hasActions) && (bool) $this->opt('list_filter', true),
            'form_fill'      => $hasSchema && (bool) $this->opt('form_fill', true),
            'detail_explain' => $hasSchema && (bool) $this->opt('detail_explain', true),
        ];
    }

    /**
     * @return array<int, array{ident: string, has_schema: bool, has_actions: bool}>
     */
    private function plugins(): array
    {
        if ($this->pluginsLoader !== null) {
            return (array) ($this->pluginsLoader)();
        }

        try {
            if (!function_exists('app') || !is_object(app())) {
                return [];
            }
            $pluginDir = app()->getRootPath() . 'plugin';
        } catch (\Throwable) {
            return [];
        }

        if (!is_dir($pluginDir)) {
            return [];
        }

        $result = [];
        foreach (glob($pluginDir . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [] as $dir) {
            $manifestFile = $dir . DIRECTORY_SEPARATOR . 'manifest.json';
            if (!file_exists($manifestFile)) {
                continue;
            }
            $manifest = json_decode((string) file_get_contents($manifestFile), true);
            if (!is_array($manifest)) {
                continue;
            }

            $result[] = [
                'ident'       => basename($dir),
                'has_schema'  => !empty($manifest['ai_schema']),
                'has_actions' => !empty($manifest['ai_actions']),
            ];
        }

        return $result;
    }

    private function opt(string $key, mixed $default): mixed
    {
        if (array_key_exists($key, $this->options)) {
            return $this->options[$key];
        }

        try {
            return config('wise-agent.widget.' . $key, $default) ?? $default;
        } catch (\Throwable) {
            return $default;
        }
    }
}
