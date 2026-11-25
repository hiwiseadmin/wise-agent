<?php
declare(strict_types=1);

namespace wise\agent\pipeline;

use think\facade\Config;
use think\facade\Db;
use think\facade\Event;
use wise\support\context\ContextualLogger;
use wise\agent\bridge\NodeRegistry;
use wise\agent\contract\WorkflowContext;
use wise\agent\execution\ExecutionTracker;
use wise\agent\pipeline\contract\PipelineInterface;
use wise\agent\pipeline\event\PipelineComplete;
use wise\agent\pipeline\event\PipelineError;
use wise\agent\pipeline\event\PipelineStart;
use wise\agent\pipeline\event\PipelineStepComplete;
use wise\agent\skill\SkillManager;

/**
 * 流水线执行引擎
 *
 * 按顺序执行 Pipeline 的每个步骤，每个步骤调用对应的 Skill。
 * 支持变量引用、条件执行、失败重试、同步+异步双模式。
 *
 * 变量引用语法：
 *   $input.xxx       → 从流水线初始输入取值
 *   $steps.{key}.xxx → 从上一步输出取值
 */
class PipelineEngine
{
    protected PipelineRegistry $registry;
    protected SkillManager $skillManager;

    public function __construct(PipelineRegistry $registry, SkillManager $skillManager)
    {
        $this->registry = $registry;
        $this->skillManager = $skillManager;
    }

    /**
     * 同步执行流水线
     *
     * @param string    $pipelineName  流水线名称
     * @param array     $input         初始输入参数
     * @param ?callable $onProgress    进度回调 function(int $step, string $status, array $data)
     * @param int       $existingRunId 已有运行记录 ID（队列重放时传入，避免重复创建记录）
     * @return PipelineResult
     */
    public function run(string $pipelineName, array $input = [], ?callable $onProgress = null, int $existingRunId = 0): PipelineResult
    {
        $startTime = microtime(true);
        $pipeline = $this->registry->get($pipelineName);

        if (!$pipeline) {
            $errorMsg = "流水线 '{$pipelineName}' 未找到";
            if ($existingRunId <= 0) {
                $this->recordPipelineRun($pipelineName, $input, 'failed', $errorMsg, null, 0);
            }
            return (new PipelineResult($pipelineName))->withError($errorMsg);
        }

        $steps = $pipeline->getSteps();
        if (empty($steps)) {
            $errorMsg = "流水线 '{$pipelineName}' 没有定义步骤";
            if ($existingRunId <= 0) {
                $this->recordPipelineRun($pipelineName, $input, 'failed', $errorMsg, null, 0);
            }
            return (new PipelineResult($pipelineName))->withError($errorMsg);
        }

        $result = new PipelineResult($pipelineName, 'running');
        $stepsOutput = [];
        $totalTokens = 0;

        $runId = $existingRunId > 0 ? $existingRunId
            : $this->recordPipelineRun($pipelineName, $input, 'running', null, null, 0);

        Event::trigger(new PipelineStart(
            $pipelineName,
            $input,
            array_map(fn($s) => $s->toArray(), $steps)
        ));

        try {
            $index     = 0;
            $stepCount = count($steps);

            $ordered = array_values($steps);

            while ($index < $stepCount) {
                $step = $ordered[$index];

                if ($step->parallel) {
                    $batch = [];

                    while ($index < $stepCount && $ordered[$index]->parallel) {
                        $batch[] = [$index, $ordered[$index]];
                        $index++;
                    }

                    $batchState  = $stepsOutput;
                    $batchFailed = false;

                    foreach ($batch as [$batchIndex, $batchStep]) {
                        $outcome = $this->executeStep(
                            $batchStep,
                            $batchIndex + 1,
                            $input,
                            $batchState,
                            $pipelineName,
                            $onProgress
                        );

                        if ($outcome['outputKey'] !== '') {
                            $stepsOutput[$outcome['outputKey']] = $outcome['output'];
                        }

                        $result->withStepResult($batchStep->skillName, $batchIndex + 1, $outcome['status'], $outcome['output']);

                        $batchFailed = $batchFailed || $outcome['failed'];
                    }

                    if ($batchFailed) {
                        break;
                    }

                    continue;
                }

                $outcome = $this->executeStep($step, $index + 1, $input, $stepsOutput, $pipelineName, $onProgress);

                if ($outcome['outputKey'] !== '') {
                    $stepsOutput[$outcome['outputKey']] = $outcome['output'];
                }

                $result->withStepResult($step->skillName, $index + 1, $outcome['status'], $outcome['output']);

                if ($outcome['failed']) {
                    break;
                }

                $index++;
            }

            $result->withStatus('completed');

        } catch (\Throwable $e) {
            $result->withError($e->getMessage());
            Event::trigger(new PipelineError($pipelineName, $result->getStepResults() ? count($result->getStepResults()) : 0, '', $e->getMessage()));
        }

        $result->withDuration(microtime(true) - $startTime);
        $result->withFinalOutput($stepsOutput);

        $duration = round($result->getDuration(), 3);
        $finalStatus = $result->isCompleted() ? 'completed' : 'failed';

        $this->updatePipelineRun($runId, $finalStatus, [
            'output'         => json_encode($stepsOutput, JSON_UNESCAPED_UNICODE),
            'steps_result'   => json_encode($result->getStepResults(), JSON_UNESCAPED_UNICODE),
            'total_duration' => $duration,
            'total_tokens'   => $totalTokens,
            'error'          => $finalStatus === 'failed' ? $result->getError() : null,
        ]);

        if ($result->isCompleted()) {
            Event::trigger(new PipelineComplete($pipelineName, $stepsOutput));
        }

        return $result;
    }

    /**
     * 执行单个步骤（串行步骤与并行批次共用）
     *
     * 抽出来的目的：并行批次需要「用同一份上下文快照执行多个步骤」，
     * 如果两个分支各写一遍条件判断 / 重试 / 事件，迟早会漂移。
     *
     * @param array<string, string> $stepsOutput 可见的步骤输出（并行批次传入的是快照）
     * @return array{outputKey:string, output:string, status:string, failed:bool}
     */
    protected function executeStep(
        PipelineStep $step,
        int $stepNum,
        array $input,
        array $stepsOutput,
        string $pipelineName,
        ?callable $onProgress
    ): array {
        if ($step->condition) {
            if (!$this->evaluateCondition($step->condition, $input, $stepsOutput)) {
                if ($onProgress) {
                    $onProgress($stepNum, 'skipped', ['reason' => 'condition not met']);
                }

                return ['outputKey' => '', 'output' => '', 'status' => 'skipped', 'failed' => false];
            }
        }

        $stepInput = $this->resolveInputMapping($step->inputMapping, $input, $stepsOutput);

        $stepOutput = '';
        $stepStatus = 'completed';
        $attempts   = $step->retries + 1;

        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            try {
                if ($onProgress) {
                    $onProgress($stepNum, 'running', ['skill' => $step->skillName]);
                }

                if ($this->isPluginNode($step->skillName)) {
                    $stepOutput = $this->executePluginNode($step->skillName, $stepInput, $stepsOutput, $pipelineName, $stepNum);
                } else {
                    $skillResult = $this->skillManager->execute($step->skillName, $stepInput);
                    $stepOutput  = $skillResult->getOutput();
                }
                $stepStatus = 'completed';

                break;
            } catch (\Throwable $e) {
                if ($attempt < $attempts - 1) {
                    ContextualLogger::warning("[Pipeline] Step {$stepNum} attempt " . ($attempt + 1) . " failed, retrying: {$e->getMessage()}");
                    \wise\support\runtime\Runtime::sleep(0.5);
                    continue;
                }

                $stepOutput = $e->getMessage();
                $stepStatus = 'failed';

                if ($step->onError === 'skip') {
                    $stepStatus = 'skipped';
                } elseif ($step->onError === 'fail') {
                    Event::trigger(new PipelineError($pipelineName, $stepNum, $step->skillName, $e->getMessage()));
                    throw $e;
                }
            }
        }

        $outputKey = $step->outputKey ?: $step->skillName;
        $output    = is_string($stepOutput) ? $stepOutput : json_encode($stepOutput, JSON_UNESCAPED_UNICODE);

        Event::trigger(new PipelineStepComplete(
            $pipelineName,
            $stepNum,
            $step->skillName,
            $stepOutput,
            $stepStatus
        ));

        if ($onProgress) {
            $onProgress($stepNum, $stepStatus, ['output' => mb_substr((string) $stepOutput, 0, 200)]);
        }

        return [
            'outputKey' => $outputKey,
            'output'    => (string) $output,
            'status'    => $stepStatus,
            'failed'    => $stepStatus === 'failed',
        ];
    }

    /**
     * 异步执行流水线（通过 think-queue）
     *
     * @param string $pipelineName
     * @param array  $input
     * @return string 队列任务 ID
     */
    public function runAsync(string $pipelineName, array $input = []): string
    {
        $runId = $this->recordPipelineRun($pipelineName, $input, 'running', null, null, 0);
        $trackingId = 'pipe_' . $runId;

        $jobData = [
            'pipeline'    => $pipelineName,
            'input'       => $input,
            'job_id'      => (string) $runId,
            'run_id'      => $runId,
            'tracking_id' => $trackingId,
            'session_id'  => $input['session_id'] ?? '',
        ];

        \think\facade\Queue::push(\wise\agent\job\PipelineJob::class, $jobData);

        ContextualLogger::info("[Pipeline] Dispatched async pipeline [{$trackingId}] for '{$pipelineName}'");

        return $trackingId;
    }

    /**
     * 解析输入映射中的变量引用
     */
    protected function resolveInputMapping(array $mapping, array $input, array $stepsOutput): array
    {
        $resolved = [];

        foreach ($mapping as $key => $value) {
            if (is_string($value)) {
                $resolved[$key] = $this->resolveValue($value, $input, $stepsOutput);
            } elseif (is_array($value)) {
                $resolved[$key] = $this->resolveInputMapping($value, $input, $stepsOutput);
            } else {
                $resolved[$key] = $value;
            }
        }

        return $resolved;
    }

    /**
     * 解析单个变量引用值
     *
     * $input.xxx       → $input['xxx']
     * $steps.key.xxx   → $stepsOutput['key']['xxx'] 或 $stepsOutput['key'] 字符串中包含'xxx'
     */
    protected function resolveValue(string $value, array $input, array $stepsOutput): mixed
    {
        if (preg_match('/^\$input\.(.+)$/', $value, $m)) {
            $path = explode('.', $m[1]);
            return $this->getByPath($input, $path);
        }

        if (preg_match('/^\$steps\.(\w+)\.(.+)$/', $value, $m)) {
            $stepKey = $m[1];
            $fieldPath = explode('.', $m[2]);

            if (isset($stepsOutput[$stepKey])) {
                $stepData = $stepsOutput[$stepKey];
                if (is_string($stepData)) {
                    $decoded = json_decode($stepData, true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                        return $this->getByPath($decoded, $fieldPath);
                    }
                }
                if (is_array($stepData)) {
                    return $this->getByPath($stepData, $fieldPath);
                }
                return $stepData;
            }
        }

        if (strpos($value, '{$') !== false) {
            $resolved = preg_replace_callback('/\{\$input\.(\w+)\}/', function ($m) use ($input) {
                return $input[$m[1]] ?? '';
            }, $value);
            $resolved = preg_replace_callback('/\{\$steps\.(\w+)\}/', function ($m) use ($stepsOutput) {
                $val = $stepsOutput[$m[1]] ?? '';
                return is_array($val) ? json_encode($val, JSON_UNESCAPED_UNICODE) : (string) $val;
            }, $resolved);
            return $resolved;
        }

        return $value;
    }

    /**
     * 按路径获取嵌套数组值
     */
    protected function getByPath(array $data, array $path): mixed
    {
        $current = $data;
        foreach ($path as $key) {
            if (is_array($current) && isset($current[$key])) {
                $current = $current[$key];
            } else {
                return null;
            }
        }
        return $current;
    }

    /**
     * 评估条件表达式
     */
    protected function evaluateCondition(string $condition, array $input, array $stepsOutput): bool
    {
        if (preg_match('/^\$steps\.(\w+)\s*!=\s*null$/', $condition, $m)) {
            return isset($stepsOutput[$m[1]]) && $stepsOutput[$m[1]] !== null;
        }

        if (preg_match('/^\$steps\.(\w+)\s*==\s*null$/', $condition, $m)) {
            return !isset($stepsOutput[$m[1]]) || $stepsOutput[$m[1]] === null;
        }

        if (preg_match('/^\$steps\.(\w+)\s*!=\s*[\"\']\s*[\"\']$/', $condition, $m)) {
            return !empty($stepsOutput[$m[1]] ?? null);
        }

        if (preg_match('/^\$steps\.(\w+)\s*==\s*[\"\'](.+)[\"\']$/', $condition, $m)) {
            return ($stepsOutput[$m[1]] ?? '') === $m[2];
        }

        if (preg_match('/^\$input\.(\w+)\s*==\s*[\"\'](.+)[\"\']$/', $condition, $m)) {
            return ($input[$m[1]] ?? '') === $m[2];
        }

        return !empty(trim($condition));
    }

    /**
     * 获取流水线详情
     */
    public function getPipeline(string $name): ?PipelineInterface
    {
        return $this->registry->get($name);
    }

    /**
     * 创建 Pipeline 运行记录
     *
     * @param string      $pipelineName 流水线名称
     * @param array       $input        输入参数
     * @param string      $status       状态: running/completed/failed/cancelled
     * @param string|null $error        错误信息
     * @param string|null $sessionId    关联会话 ID
     * @param int         $totalTokens  总 Token 消耗
     * @return int 运行记录 ID（自增主键）或 0（失败时）
     */
    protected function recordPipelineRun(
        string $pipelineName,
        array $input,
        string $status = 'running',
        ?string $error = null,
        ?string $sessionId = null,
        int $totalTokens = 0
    ): int {
        try {
            $table = 'ai_pipeline_runs';
            $now = date('Y-m-d H:i:s');

            $id = Db::name($table)->insertGetId([
                'pipeline_name' => $pipelineName,
                'session_id'    => $sessionId,
                'input'         => json_encode($input, JSON_UNESCAPED_UNICODE),
                'status'        => $status,
                'total_tokens'  => $totalTokens,
                'error'         => $error,
                'source'        => 'api',
                'create_time'   => $now,
                'update_time'   => $now,
            ]);

            return (int) $id;
        } catch (\Throwable $e) {
            ContextualLogger::warning("[PipelineEngine] Failed to record pipeline run: {$e->getMessage()}");
            return 0;
        }
    }

    /**
     * 更新 Pipeline 运行记录
     *
     * @param int    $runId  运行记录 ID（自增主键）
     * @param string $status 新状态
     * @param array  $data   额外数据 (output, steps_result, total_duration, total_tokens, error)
     */
    protected function updatePipelineRun(int $runId, string $status, array $data = []): void
    {
        if ($runId <= 0) {
            return;
        }

        try {
            $table = 'ai_pipeline_runs';
            $update = [
                'status'      => $status,
                'update_time' => date('Y-m-d H:i:s'),
            ];

            if (isset($data['output'])) {
                $update['output'] = $data['output'];
            }
            if (isset($data['steps_result'])) {
                $update['steps_result'] = $data['steps_result'];
            }
            if (isset($data['total_duration'])) {
                $update['total_duration'] = $data['total_duration'];
            }
            if (isset($data['total_tokens'])) {
                $update['total_tokens'] = $data['total_tokens'];
            }
            if (isset($data['error'])) {
                $update['error'] = $data['error'];
            }

            Db::name($table)->where('id', $runId)->update($update);
        } catch (\Throwable $e) {
            ContextualLogger::warning("[PipelineEngine] Failed to update pipeline run [{$runId}]: {$e->getMessage()}");
        }
    }

    /**
     * 检查步骤是否为插件节点
     *
     * 插件节点格式：'{namespace}:{nodeType}'
     * 例：'plugin:demo:http_request'
     *
     * @param string $skillName 步骤的 skillName
     * @return bool
     */
    protected function isPluginNode(string $skillName): bool
    {
        if (strpos($skillName, ':') === false) {
            return false;
        }

        $parts = explode(':', $skillName, 2);
        $namespace = $parts[0];

        $nodeRegistry = NodeRegistry::instance();
        return $nodeRegistry->hasProvider($namespace);
    }

    /**
     * 执行插件节点
     *
     * @param string $fullType      完整节点类型（含命名空间）
     * @param array  $stepInput    步骤输入
     * @param array  $stepsOutput  上游步骤输出
     * @param string $pipelineName 流水线名称
     * @param int    $stepNum      步骤编号
     * @return string 步骤输出（字符串格式，与 SkillResult::getOutput() 一致）
     */
    protected function executePluginNode(string $fullType, array $stepInput, array $stepsOutput, string $pipelineName, int $stepNum): string
    {
        $nodeRegistry = NodeRegistry::instance();

        $context = new WorkflowContext(
            $pipelineName,
            uniqid('exec_', true),
            $stepNum,
            array_merge($stepInput, ['steps' => $stepsOutput])
        );

        $config = $stepInput['_config'] ?? [];

        $result = $nodeRegistry->runNode($fullType, $config, $stepInput, $context);

        if ($result['error'] !== null) {
            throw new \RuntimeException("Plugin node execution failed: {$result['error']}");
        }

        return is_string($result['data']) ? $result['data'] : json_encode($result['data'], JSON_UNESCAPED_UNICODE);
    }
}
