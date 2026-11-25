<?php
declare(strict_types=1);

namespace wise\agent\trigger;

use think\facade\Event;
use wise\support\context\ContextualLogger;

/**
 * AI 触发器注册器
 *
 * 将插件 manifest.json 中的 ai_triggers 声明翻译为：
 *   - schedule 触发 → 注册为 AiTask（持久化到数据库，重启后恢复）
 *   - event 触发     → 注册 Event::listen 监听器（事件触发即执行 AI 动作）
 *
 * manifest 示例：
 * {
 *     "ai_triggers": [
 *         { "on": "schedule", "cron": "0 9 * * 1",
 *           "do": { "pipeline": "smartstock.weekly_report" } },
 *         { "on": "plugin.smartstock.stock_low",
 *           "do": { "skill": "smartstock.restock_advice" } }
 *     ]
 * }
 *
 * do 字段支持三种动作：pipeline / skill / chat（优先级按此顺序）。
 */
class TriggerRegistrar
{
    /** @var mixed 惰性解析的依赖 */
    protected $scheduler = null;
    protected $skillManager = null;
    protected $pipelineEngine = null;
    protected $agentManager = null;

    /**
     * 注册全部触发器（schedule + event）
     *
     * @param string $pluginIdent 插件标识
     * @param array  $triggers    ai_triggers 配置
     * @return int 成功注册的数量
     */
    public function register(string $pluginIdent, array $triggers): int
    {
        $count = 0;
        foreach ($triggers as $trigger) {
            if (!is_array($trigger)) {
                continue;
            }
            if ($this->registerOne($pluginIdent, $trigger)) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * 仅注册 event 触发器（供每请求 boot 阶段从缓存恢复）
     *
     * schedule 触发器由 AiTaskScheduler::loadFromDatabase() 负责恢复，
     * 此处只处理 event 类型，避免每请求重复写库。
     *
     * @param string $pluginIdent 插件标识
     * @param array  $triggers    ai_triggers 配置
     * @return int 注册的 event 触发器数量
     */
    public function registerEvents(string $pluginIdent, array $triggers): int
    {
        $count = 0;
        foreach ($triggers as $trigger) {
            if (!is_array($trigger)) {
                continue;
            }

            $on = (string) ($trigger['on'] ?? '');
            if ($on === '' || $on === 'schedule') {
                continue;
            }

            $do = $trigger['do'] ?? [];
            [$type, $target] = $this->resolveAction($do);
            if ($target === null) {
                continue;
            }

            $this->registerEvent($on, $type, $target, is_array($do['input'] ?? null) ? $do['input'] : []);
            $count++;
        }
        return $count;
    }

    /**
     * 注册单个触发器
     */
    private function registerOne(string $pluginIdent, array $trigger): bool
    {
        $on = (string) ($trigger['on'] ?? '');
        $do = $trigger['do'] ?? [];
        if ($on === '' || !is_array($do)) {
            return false;
        }

        [$type, $target] = $this->resolveAction($do);
        if ($target === null) {
            return false;
        }

        $input = is_array($do['input'] ?? null) ? $do['input'] : [];

        if ($on === 'schedule') {
            $cron = (string) ($trigger['cron'] ?? ($trigger['schedule'] ?? ''));
            if ($cron === '') {
                return false;
            }
            return $this->registerSchedule($pluginIdent, $trigger, $type, $target, $input, $cron);
        }

        $this->registerEvent($on, $type, $target, $input);
        return true;
    }

    /**
     * 解析 AI 动作类型与目标
     *
     * @return array{0: ?string, 1: ?string} [type, target]
     */
    private function resolveAction(array $do): array
    {
        foreach (['pipeline', 'skill', 'chat'] as $type) {
            if (!empty($do[$type]) && is_string($do[$type])) {
                return [$type, $do[$type]];
            }
        }
        return [null, null];
    }

    /**
     * 注册 schedule 触发器为 AiTask
     */
    private function registerSchedule(string $pluginIdent, array $trigger, string $type, string $target, array $input, string $cron): bool
    {
        $scheduler = $this->getScheduler();
        if ($scheduler === null) {
            return false;
        }

        $id     = (string) ($trigger['id'] ?? ($pluginIdent . '.cron.' . $type . '.' . $target));
        $name   = (string) ($trigger['name'] ?? ($trigger['description'] ?? $id));
        $source = 'plugin:' . $pluginIdent;

        try {
            $scheduler->register(new \wise\agent\schedule\AiTask($id, $name, $type, $target, $cron, $input, $source));
            return true;
        } catch (\Throwable $e) {
            ContextualLogger::warning("[TriggerRegistrar] schedule register failed [{$id}]: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * 注册 event 触发器监听器
     */
    private function registerEvent(string $eventName, string $type, string $target, array $input): void
    {
        try {
            Event::listen($eventName, function () use ($type, $target, $input) {
                $this->dispatch($type, $target, $input);
            });
        } catch (\Throwable $e) {
            ContextualLogger::warning("[TriggerRegistrar] event listen failed [{$eventName}]: {$e->getMessage()}");
        }
    }

    /**
     * 执行 AI 动作
     */
    protected function dispatch(string $type, string $target, array $input): void
    {
        try {
            switch ($type) {
                case 'pipeline':
                    $engine = $this->getPipelineEngine();
                    if ($engine !== null) {
                        $engine->run($target, $input);
                    }
                    break;
                case 'skill':
                    $manager = $this->getSkillManager();
                    if ($manager !== null) {
                        $manager->execute($target, $input);
                    }
                    break;
                case 'chat':
                    $manager = $this->getAgentManager();
                    if ($manager !== null) {
                        $manager->chat($input['message'] ?? $target);
                    }
                    break;
            }
        } catch (\Throwable $e) {
            ContextualLogger::error("[TriggerRegistrar] dispatch failed [{$type}:{$target}]: {$e->getMessage()}");
        }
    }


    protected function getScheduler()
    {
        if ($this->scheduler !== null) {
            return $this->scheduler;
        }
        if (!function_exists('app')) {
            return null;
        }
        try {
            return $this->scheduler = app()->make(\wise\agent\schedule\AiTaskScheduler::class);
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected function getSkillManager()
    {
        if ($this->skillManager !== null) {
            return $this->skillManager;
        }
        if (!function_exists('app')) {
            return null;
        }
        try {
            return $this->skillManager = app()->make(\wise\agent\skill\SkillManager::class);
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected function getPipelineEngine()
    {
        if ($this->pipelineEngine !== null) {
            return $this->pipelineEngine;
        }
        if (!function_exists('app')) {
            return null;
        }
        try {
            return $this->pipelineEngine = app()->make(\wise\agent\pipeline\PipelineEngine::class);
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected function getAgentManager()
    {
        if ($this->agentManager !== null) {
            return $this->agentManager;
        }
        if (!function_exists('app')) {
            return null;
        }
        try {
            return $this->agentManager = app()->make(\wise\agent\agent\AgentManager::class);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
