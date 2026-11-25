<?php
declare(strict_types=1);

namespace wise\agent\tool;

use think\facade\Db;
use wise\agent\contract\NodeProviderInterface;
use wise\agent\contract\WorkflowContext;

/**
 * AI 自动生成工作流节点基类
 *
 * 为插件的 Schema 生成基础的工作流节点，包括：
 *   - ConditionNode：字段条件判断
 *   - ActionNode： 记录更新
 *   - NotifyNode： 通知触发
 *
 * 子类只需定义 $config 即可。
 */
abstract class BaseAutoNode
{
    /** @var string 节点类型 */
    protected string $nodeType = '';

    /** @var string 节点名称 */
    protected string $nodeName = '';

    /** @var string 节点描述 */
    protected string $nodeDescription = '';

    /** @var string 插件标识 */
    protected string $pluginIdent = '';

    /** @var array 节点配置 */
    protected array $config = [];

    public function getType(): string
    {
        return $this->nodeType;
    }

    public function getName(): string
    {
        return $this->nodeName;
    }

    public function getDescription(): string
    {
        return $this->nodeDescription;
    }

    public function getConfigSchema(): array
    {
        return $this->config;
    }

    /**
     * 执行节点逻辑
     */
    public function execute(array $input, WorkflowContext $context): array
    {
        $result = $this->doExecute($input, $context);
        return $result;
    }

    abstract protected function doExecute(array $input, WorkflowContext $context): array;

    /**
     * 获取可序列化的节点定义（供注册表使用）
     */
    public function toDefinition(): array
    {
        return [
            'type'        => $this->nodeType,
            'name'        => $this->nodeName,
            'description' => $this->nodeDescription,
            'plugin'      => $this->pluginIdent,
            'config'      => $this->config,
        ];
    }
}

/**
 * 条件判断节点基类
 *
 * 检查输入字段是否满足条件。
 * 子类定义 $fieldName 和 $defaultOperator。
 */
abstract class BaseConditionNode extends BaseAutoNode
{
    protected string $nodeType = 'condition';
    protected string $fieldName = '';
    protected string $defaultOperator = 'equals';
    protected string $tableName = '';

    public function doExecute(array $input, WorkflowContext $context): array
    {
        $recordId = $input['record_id'] ?? $input[$this->fieldName] ?? null;
        $operator = $input['operator'] ?? $this->defaultOperator;
        $threshold = $input['threshold'] ?? null;

        if ($recordId !== null) {
            $query = Db::name($this->tableName);
            $record = $query->where('id', $recordId)->find();
            if ($record) {
                $value = $record[$this->fieldName] ?? null;
                return [
                    'result'  => $this->compare($value, $operator, $threshold),
                    'field'   => $this->fieldName,
                    'value'   => $value,
                    'context' => $record,
                ];
            }
        }

        return ['result' => false, 'error' => 'Record not found'];
    }

    private function compare($value, string $operator, $threshold): bool
    {
        switch ($operator) {
            case 'gt':     return $value > $threshold;
            case 'gte':    return $value >= $threshold;
            case 'lt':     return $value < $threshold;
            case 'lte':    return $value <= $threshold;
            case 'equals': return $value == $threshold;
            case 'not_empty': return !empty($value);
            case 'empty':  return empty($value);
            default:       return false;
        }
    }
}

/**
 * 记录更新节点基类
 *
 * 按主键更新单条记录的指定字段。
 * 子类定义 $tableName, $primaryKey, $updatableFields。
 */
abstract class BaseActionNode extends BaseAutoNode
{
    protected string $nodeType = 'action';
    protected string $tableName = '';
    protected string $primaryKey = 'id';
    protected array $updatableFields = [];

    public function doExecute(array $input, WorkflowContext $context): array
    {
        $id = $input['record_id'] ?? $input['id'] ?? null;
        if ($id === null) {
            return ['success' => false, 'error' => 'Missing record_id'];
        }

        $data = [];
        foreach ($this->updatableFields as $field) {
            if (isset($input[$field])) {
                $data[$field] = $input[$field];
            }
        }
        if (empty($data)) {
            return ['success' => false, 'error' => 'No fields to update'];
        }

        if (in_array('update_time', $this->updatableFields, true) && !isset($data['update_time'])) {
            $data['update_time'] = date('Y-m-d H:i:s');
        }

        try {
            $affected = Db::name($this->tableName)->where($this->primaryKey, $id)->update($data);
            return ['success' => true, 'affected' => $affected];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}

/**
 * 通知节点基类（占位）
 */
abstract class BaseNotifyNode extends BaseAutoNode
{
    protected string $nodeType = 'notify';
    protected string $notifyType = 'log';

    public function doExecute(array $input, WorkflowContext $context): array
    {
        return [
            'success'    => true,
            'notify_type' => $this->notifyType,
            'message'    => $input['message'] ?? "Notification from {$this->nodeName}",
        ];
    }
}
