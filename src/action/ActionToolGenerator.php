<?php
declare(strict_types=1);

namespace wise\agent\action;

use wise\agent\security\CapabilityAccess;
use wise\agent\tool\ToolRegistry;

/**
 * 声明式业务动作生成器
 *
 * 读取插件 manifest.json 中的 ai_actions 配置，
 * 为每个动作生成一个 ActionTool，注册到 ToolRegistry。
 *
 * 插件通过 ai_actions 将已有的 service 方法暴露为 AI 可调用工具，
 * 无需编写任何 Tool 类，把"写一个 Tool 类（~40 行）"降为"6 行 JSON 声明"。
 */
class ActionToolGenerator
{
    /** @var string 插件标识 */
    private string $pluginIdent;

    /** @var array ai_actions 配置 [name => config] */
    private array $actions;

    /** @var ActionTool[] 已生成的工具 */
    private array $generatedTools = [];

    /**
     * @param string $pluginIdent 插件标识
     * @param array  $actions     ai_actions 配置
     */
    public function __construct(string $pluginIdent, array $actions)
    {
        $this->pluginIdent = $pluginIdent;
        $this->actions     = $actions;
    }

    /**
     * 生成所有 ActionTool
     *
     * @return ActionTool[]
     */
    public function generate(): array
    {
        $this->generatedTools = [];

        foreach ($this->actions as $name => $config) {
            if (!is_array($config)) {
                continue;
            }

            $tool = $this->makeTool((string) $name, $config);
            if ($tool !== null) {
                $this->generatedTools[] = $tool;
            }
        }

        return $this->generatedTools;
    }

    /**
     * 生成并注册到 ToolRegistry
     *
     * @param ToolRegistry $registry  目标注册表
     * @param string       $namespace 命名空间（如 plugin:smartstock）
     * @return int 注册的工具数量
     */
    public function registerTo(ToolRegistry $registry, string $namespace): int
    {
        if (empty($this->generatedTools)) {
            $this->generate();
        }

        $count = 0;
        foreach ($this->generatedTools as $tool) {
            $registry->register($tool, $tool->getName(), $namespace);
            $count++;
        }

        return $count;
    }

    /**
     * 获取已生成工具
     *
     * @return ActionTool[]
     */
    public function getGeneratedTools(): array
    {
        return $this->generatedTools;
    }

    /**
     * 从单个动作配置构建 ActionTool
     *
     * @param string $name
     * @param array  $config
     * @return ActionTool|null 配置无效时返回 null
     */
    private function makeTool(string $name, array $config): ?ActionTool
    {
        $method = $config['method'] ?? null;
        if (!is_string($method) || trim($method) === '') {
            return null;
        }

        $targetClass = str_contains($method, '@') ? explode('@', $method, 2)[0] : '';
        if ($targetClass !== '' && str_contains(strtolower($targetClass), '\\controller\\')) {
            try {
                \wise\support\context\ContextualLogger::warning(
                    '[ai_actions] 控制器方法不应通过 ai_actions 暴露，请改用 #[AgentAction]',
                    ['action' => $name, 'method' => $method]
                );
            } catch (\Throwable) {
            }

            return null;
        }

        $description = (string) ($config['description'] ?? $name);
        $params      = is_array($config['params'] ?? null) ? $config['params'] : [];
        $confirm     = array_key_exists('confirm', $config)
            ? (bool) $config['confirm']
            : true;
        $permission  = (string) ($config['permission'] ?? ('plugin:' . $this->pluginIdent . '.action.' . $name));
        $access      = (string) ($config['access'] ?? CapabilityAccess::WRITE);
        $risk        = (string) ($config['risk'] ?? CapabilityAccess::RISK_MEDIUM);

        return new ActionTool($name, $description, $method, $params, $confirm, $permission, $access, $risk);
    }
}
