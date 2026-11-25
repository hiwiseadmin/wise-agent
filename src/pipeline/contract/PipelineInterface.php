<?php
declare(strict_types=1);

namespace wise\agent\pipeline\contract;

/**
 * 流水线接口
 *
 * Pipeline 将多个 Skill 串联成自动化工作流。
 * 与 Agent Run Loop 的区别：
 * - Agent: 开放式，自主决策下一步
 * - Pipeline: 确定性，步骤顺序预定义
 */
interface PipelineInterface
{
    /**
     * 获取流水线名称
     */
    public function getName(): string;

    /**
     * 获取流水线描述
     */
    public function getDescription(): string;

    /**
     * 获取步骤定义列表
     *
     * @return PipelineStep[]
     */
    public function getSteps(): array;

    /**
     * 获取触发方式
     *
     * @return string[] 'manual' | 'schedule' | 'event'
     */
    public function getTriggers(): array;

    /**
     * 获取来源
     */
    public function getSource(): string;
}
