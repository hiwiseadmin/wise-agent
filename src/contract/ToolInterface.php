<?php
declare(strict_types=1);

namespace wise\agent\contract;

/**
 * 工具接口
 *
 * 定义 Agent 工具（Function Calling）的契约。
 * 插件通过实现此接口来添加自定义工具。
 */
interface ToolInterface
{
    /**
     * 获取工具名称（唯一标识）
     */
    public function getName(): string;

    /**
     * 获取工具描述（展示给 LLM）
     */
    public function getDescription(): string;

    /**
     * 获取参数 schema（JSON Schema 格式，展示给 LLM）
     */
    public function getParameters(): array;

    /**
     * 执行工具
     *
     * @param array $arguments 来自 LLM 的参数
     * @return string 结果文本
     */
    public function execute(array $arguments): string;

    /**
     * 获取权限标识（空字符串表示无限制）
     */
    public function getPermission(): string;

    /**
     * 工具执行前是否需要确认
     *
     * 这是「工具作者」给出的**下限**：返回 true 表示无论审批策略如何都必须确认。
     * 系统还会在权限通过后按 `security.approval` 审批策略判定是否额外需要确认 / 审批，
     * 两者取更严格者（详见 wise\agent\security\ApprovalPolicy）。
     */
    public function requireConfirmation(): bool;

    /**
     * 访问类型（权限分类：read | write | execute | admin）
     *
     * 决定权限树中的归类与「类别通配」授权（如「全部只读能力」ai.access.read.*），
     * 是「按读 / 写分类授权」的元数据基础。未知/危险的工具应保守返回 execute。
     *
     * @see \wise\agent\security\CapabilityAccess
     */
    public function getAccess(): string;

    /**
     * 风险等级（low | medium | high）
     *
     * 与访问类型正交，用于审批策略：高危能力可被策略要求额外确认或走审批流。
     * 建议：只读且无副作用 → low；可恢复的写入 → medium；不可逆 / 触碰资金与外部系统 → high。
     */
    public function getRiskLevel(): string;
}
