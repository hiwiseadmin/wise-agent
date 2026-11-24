<?php
declare(strict_types=1);

namespace wise\agent\schedule;

use think\facade\Config;
use think\facade\Db;
use wise\support\context\ContextualLogger;

/**
 * AI 任务调度器
 *
 * 管理定时 AI 任务的注册和执行。
 * 支持四种调度类型：cron / interval / event / once
 *
 * 使用方式：
 *   $scheduler = new AiTaskScheduler($pipelineEngine, $skillManager, $agentManager);
 *   $scheduler->register(new AiTask(...));
 *   $scheduler->executeNow('task_id');
 */
class AiTaskScheduler
{
    /** @var array<string, AiTask> */
    protected array $tasks = [];

    protected $pipelineEngine;
    protected $skillManager;
    protected $agentManager;

    public function __construct(
        $pipelineEngine = null,
        $skillManager = null,
        $agentManager = null
    ) {
        $this->pipelineEngine = $pipelineEngine;
        $this->skillManager   = $skillManager;
        $this->agentManager   = $agentManager;
    }

    /**
     * 注册定时任务（同时持久化到数据库）
     */
    public function register(AiTask $task): void
    {
        $this->tasks[$task->id] = $task;
        $this->persistTask($task);
    }

    /**
     * 获取任务
     */
    public function get(string $id): ?AiTask
    {
        return $this->tasks[$id] ?? null;
    }

    /**
     * 列出所有任务
     *
     * @return AiTask[]
     */
    public function list(): array
    {
        return array_values($this->tasks);
    }

    /**
     * 按来源列出
     */
    public function listBySource(string $source): array
    {
        return array_filter($this->tasks, fn($t) => $t->source === $source);
    }

    /**
     * 立即执行一次任务
     *
     * @param string $id    任务 ID
     * @param array  $extraInput 额外输入参数（合并到任务的 input）
     * @return mixed 执行结果
     */
    public function executeNow(string $id, array $extraInput = []): mixed
    {
        $task = $this->get($id);
        if (!$task) {
            throw new \RuntimeException("AI 任务 '{$id}' 未找到");
        }

        if (!$task->enabled) {
            throw new \RuntimeException("AI 任务 '{$id}' 已暂停");
        }

        $input = array_merge($task->input, $extraInput);

        $result = null;
        switch ($task->type) {
            case 'pipeline':
                if (!$this->pipelineEngine) {
                    throw new \RuntimeException('PipelineEngine 未初始化');
                }
                $result = $this->pipelineEngine->run($task->target, $input);
                break;

            case 'skill':
                if (!$this->skillManager) {
                    throw new \RuntimeException('SkillManager 未初始化');
                }
                $result = $this->skillManager->execute($task->target, $input);
                break;

            case 'chat':
                if (!$this->agentManager) {
                    throw new \RuntimeException('AgentManager 未初始化');
                }
                $message = $input['message'] ?? $task->name;
                $result = $this->agentManager->chat($message);
                break;

            default:
                throw new \RuntimeException("不支持的任务类型: {$task->type}");
        }

        $task->lastRun = date('Y-m-d H:i:s');
        $task->runCount++;
        $task->nextRun = $this->calculateNextRun($task);
        $this->updateTaskRunState($task);

        ContextualLogger::info("[AiTask] Executed: {$task->id} ({$task->type}: {$task->target})");

        return $result;
    }

    /**
     * 执行到期任务（由外部 cron 或 think-queue 触发）
     *
     * @return array 执行结果列表
     */
    public function runDueTasks(): array
    {
        $results = [];
        $now = time();

        foreach ($this->tasks as $task) {
            if (!$task->enabled) {
                continue;
            }

            if ($this->isDue($task, $now)) {
                try {
                    $results[$task->id] = $this->executeNow($task->id);
                } catch (\Throwable $e) {
                    ContextualLogger::error("[AiTask] Failed: {$task->id} - {$e->getMessage()}");
                    $results[$task->id] = ['error' => $e->getMessage()];
                }
            }
        }

        return $results;
    }

    /**
     * 判断任务是否到期
     */
    protected function isDue(AiTask $task, int $now): bool
    {
        if (!$task->nextRun) {
            $task->nextRun = $this->calculateNextRun($task);
            return false;
        }

        return strtotime($task->nextRun) <= $now;
    }

    /**
     * 计算下次执行时间
     */
    protected function calculateNextRun(AiTask $task): ?string
    {
        $type = $task->getScheduleType();

        switch ($type) {
            case 'cron':
                return $this->nextCronTime($task->schedule);

            case 'interval':
                $seconds = $task->getIntervalSeconds();
                return date('Y-m-d H:i:s', time() + $seconds);

            case 'once':
                $time = str_replace('once:', '', $task->schedule);
                return date('Y-m-d H:i:s', strtotime($time));

            default:
                return null;
        }
    }

    /**
     * 简单 cron 时间计算
     */
    protected function nextCronTime(string $cronExpr): ?string
    {
        $parts = preg_split('/\s+/', trim($cronExpr));
        if (count($parts) !== 5) {
            return null;
        }

        [$minute, $hour, $day, $month, $weekday] = $parts;

        $now = time();
        $next = $now + 60;

        if ($minute === '*' || str_starts_with($minute, '*/')) {
            return date('Y-m-d H:00:00', $next + 3600);
        }

        if (is_numeric($minute) && is_numeric($hour)) {
            $target = strtotime(date("Y-m-d {$hour}:{$minute}:00"));
            if ($target <= $now) {
                $target = strtotime("+1 day", $target);
            }
            return date('Y-m-d H:i:s', $target);
        }

        return date('Y-m-d H:i:s', $next + 3600);
    }

    /**
     * 任务数量
     */
    public function count(): int
    {
        return count($this->tasks);
    }


    /**
     * 持久化任务定义到数据库（INSERT ON DUPLICATE KEY UPDATE）
     */
    protected function persistTask(AiTask $task): void
    {
        try {
            $table = 'ai_tasks';
            $now = date('Y-m-d H:i:s');

            $data = [
                'task_id'     => $task->id,
                'name'        => $task->name,
                'type'        => $task->type,
                'target'      => $task->target,
                'input'       => json_encode($task->input, JSON_UNESCAPED_UNICODE),
                'schedule'    => $task->schedule,
                'source'      => $task->source ?? 'builtin',
                'status'      => $task->enabled ? 'active' : 'paused',
                'last_run'    => $task->lastRun,
                'next_run'    => $task->nextRun,
                'run_count'   => $task->runCount,
                'update_time' => $now,
            ];

            $exists = Db::name($table)->where('task_id', $task->id)->find();

            if ($exists) {
                unset($data['task_id']);
                Db::name($table)->where('task_id', $task->id)->update($data);
            } else {
                $data['create_time'] = $now;
                Db::name($table)->insert($data);
            }
        } catch (\Throwable $e) {
            ContextualLogger::warning("[AiTaskScheduler] Failed to persist task '{$task->id}': {$e->getMessage()}");
        }
    }

    /**
     * 更新任务运行状态到数据库
     */
    protected function updateTaskRunState(AiTask $task): void
    {
        try {
            $table = 'ai_tasks';

            Db::name($table)
                ->where('task_id', $task->id)
                ->update([
                    'last_run'    => $task->lastRun,
                    'next_run'    => $task->nextRun,
                    'run_count'   => $task->runCount,
                    'status'      => $task->enabled ? 'active' : 'paused',
                    'update_time' => date('Y-m-d H:i:s'),
                ]);
        } catch (\Throwable $e) {
            ContextualLogger::warning("[AiTaskScheduler] Failed to update run state for '{$task->id}': {$e->getMessage()}");
        }
    }

    /**
     * 从数据库恢复所有任务
     *
     * 在服务器重启后调用，恢复任务定义和运行状态。
     *
     * @return int 恢复的任务数量
     */
    public function loadFromDatabase(): int
    {
        try {
            $table = 'ai_tasks';
            $rows = Db::name($table)
                ->where('status', '<>', 'disabled')
                ->select()
                ->toArray();

            $count = 0;
            foreach ($rows as $row) {
                $taskId = $row['task_id'] ?? '';

                if (empty($taskId) || isset($this->tasks[$taskId])) {
                    continue;
                }

                $task = new AiTask(
                    $taskId,
                    $row['name'] ?? '',
                    $row['type'] ?? 'pipeline',
                    $row['target'] ?? '',
                    $row['schedule'] ?? '',
                    json_decode($row['input'] ?? '{}', true) ?: [],
                    $row['source'] ?? 'builtin'
                );

                $task->enabled  = ($row['status'] ?? 'active') === 'active';
                $task->lastRun  = $row['last_run'] ?? null;
                $task->nextRun  = $row['next_run'] ?? null;
                $task->runCount = (int) ($row['run_count'] ?? 0);

                $this->tasks[$taskId] = $task;
                $count++;
            }

            ContextualLogger::info("[AiTaskScheduler] Loaded {$count} tasks from database");

            return $count;
        } catch (\Throwable $e) {
            ContextualLogger::warning("[AiTaskScheduler] Failed to load tasks from database: {$e->getMessage()}");
            return 0;
        }
    }

    /**
     * 更新任务启用/暂停状态到数据库
     */
    protected function updateTaskStatus(AiTask $task): void
    {
        try {
            $table = 'ai_tasks';

            Db::name($table)
                ->where('task_id', $task->id)
                ->update([
                    'status'      => $task->enabled ? 'active' : 'paused',
                    'update_time' => date('Y-m-d H:i:s'),
                ]);
        } catch (\Throwable $e) {
            ContextualLogger::warning("[AiTaskScheduler] Failed to update status for '{$task->id}': {$e->getMessage()}");
        }
    }

    /**
     * 暂停任务（内存 + 数据库）
     */
    public function pause(string $id): void
    {
        if (isset($this->tasks[$id])) {
            $this->tasks[$id]->enabled = false;
            $this->updateTaskStatus($this->tasks[$id]);
        }
    }

    /**
     * 恢复任务（内存 + 数据库）
     */
    public function resume(string $id): void
    {
        if (isset($this->tasks[$id])) {
            $this->tasks[$id]->enabled = true;
            $this->updateTaskStatus($this->tasks[$id]);
        }
    }

    /**
     * 移除任务（内存 + 数据库）
     */
    public function remove(string $id): void
    {
        if (isset($this->tasks[$id])) {
            unset($this->tasks[$id]);

            try {
                $table = 'ai_tasks';
                Db::name($table)->where('task_id', $id)->delete();
            } catch (\Throwable $e) {
                ContextualLogger::warning("[AiTaskScheduler] Failed to delete task '{$id}': {$e->getMessage()}");
            }
        }
    }
}
