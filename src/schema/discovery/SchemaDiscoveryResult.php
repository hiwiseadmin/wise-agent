<?php
declare(strict_types=1);

namespace wise\agent\schema\discovery;

use wise\agent\schema\dto\PluginSchemaDTO;
use wise\agent\schema\capability\CapabilityResult;

/**
 * Schema 发现结果
 *
 * 记录一次完整的插件 Schema 发现流水线的产出和统计。
 * 由 PluginSchemaDiscovery::discover() 返回。
 */
class SchemaDiscoveryResult
{
    /** @var string 插件标识 */
    public $pluginIdent = '';

    /** @var PluginSchemaDTO|null 内省结果 */
    public $schema = null;

    /** @var int 发现的表数量 */
    public $tableCount = 0;

    /** @var int 自动生成的工具数 */
    public $autoToolCount = 0;

    /** @var int 业务能力工具数 */
    public $capabilityToolCount = 0;

    /** @var int 自定义工具数 */
    public $customToolCount = 0;

    /** @var int 注册的总工具数 */
    public $toolCount = 0;

    /** @var int 注册的 Pipeline 数 */
    public $pipelineCount = 0;

    /** @var int 自动生成的 Skill 数 */
    public $autoSkillCount = 0;

    /** @var array 工具生成统计 */
    public $toolStats = [];

    /** @var CapabilityResult[] 已解析的业务能力 */
    public $capabilities = [];

    /** @var string[] 错误信息 */
    public $errors = [];

    /**
     * @param string $pluginIdent
     */
    public function __construct(string $pluginIdent)
    {
        $this->pluginIdent = $pluginIdent;
    }

    /**
     * 发现是否成功
     *
     * @return bool
     */
    public function isSuccess(): bool
    {
        return $this->schema !== null;
    }

    /**
     * 添加错误
     *
     * @param string $step
     * @param string $message
     */
    public function addError(string $step, string $message): void
    {
        $this->errors[] = "[{$step}] {$message}";
    }

    /**
     * 获取摘要信息
     *
     * @return array
     */
    public function getSummary(): array
    {
        return [
            'plugin'              => $this->pluginIdent,
            'tables'              => $this->tableCount,
            'auto_tools'          => $this->autoToolCount,
            'capability_tools'    => $this->capabilityToolCount,
            'custom_tools'        => $this->customToolCount,
            'total_tools'         => $this->toolCount,
            'pipelines'           => $this->pipelineCount,
            'auto_skills'         => $this->autoSkillCount,
            'capabilities'        => array_keys($this->capabilities),
            'has_errors'          => !empty($this->errors),
        ];
    }

    /**
     * 转为数组
     *
     * @return array
     */
    public function toArray(): array
    {
        return $this->getSummary();
    }
}
