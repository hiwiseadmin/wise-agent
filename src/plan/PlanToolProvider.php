<?php
declare(strict_types=1);

namespace wise\agent\plan;

use wise\agent\contract\RequestContext;
use wise\agent\contract\ToolInterface;
use wise\agent\contract\ToolProviderInterface;
use wise\agent\tool\ToolRegistry;

/**
 * 计划工具提供者（B1-4）
 *
 * 命名空间：`plan`（与配置节 `wise-agent.plan` 同名）。
 *
 * | 工具 | 作用 | 读写 |
 * |---|---|---|
 * | `plan_run` | 目标 → 计划 → 执行（默认异步） | EXECUTE（写步骤各自受审批约束） |
 * | `plan_status` | 逐步状态查询 | READ |
 * | `plan_resume` | 批准后从被阻塞步续跑 | EXECUTE |
 *
 * ## 注册约定（与 A1/A2/A3/A4 同坑）
 *
 * `ToolRegistry::execute()` 按**精确键**查找，而 `getSchemas()` 用 `getName()` 下发给 LLM；
 * 因此必须用 builtin 键注册（`plan_run` / `plan_status` / `plan_resume`），
 * 否则 LLM 回调名与注册键不一致、执行时找不到工具。
 *
 * ## 当前用户是**在这里**注入的
 *
 * `ToolProviderInterface::execute()` 收到的 `RequestContext` 是 HTTP 链路里最可靠的用户来源，
 * 因此本提供者在转发前调 `withUser()`（见 {@see PlanUserContext}）——
 * 「含写步骤的计划必须由登录用户发起」这条闸门依赖它＼�。
 */
class PlanToolProvider implements ToolProviderInterface
{
    /** 提供者命名空间（逻辑分组） */
    public const NAMESPACE = 'plan';

    use PlanOptions;

    /** @var ToolInterface[]|null 显式给定的工具实例（测试/自定义装配用） */
    private ?array $tools;

    /**
     * @param array<string, mixed> $options `plan.*` 覆盖项
     * @param ToolInterface[]|null $tools   显式给定的工具实例（`null` = 按配置构造；
     *                                      单测用它注入带内存仓储的替身）
     */
    public function __construct(array $options = [], ?array $tools = null)
    {
        $this->setPlanOptions($options);
        $this->tools = $tools;
    }

    public function getNamespace(): string
    {
        return self::NAMESPACE;
    }

    public function isEnabled(): bool
    {
        return (bool) $this->planOpt('enabled', false);
    }

    /**
     * 单个工具的开关（`plan.tools.{name}`，缺省 true）
     */
    public function isToolEnabled(string $tool): bool
    {
        $tools = $this->planOpt('tools', []);

        if (!is_array($tools) || !array_key_exists($tool, $tools)) {
            return true;
        }

        return (bool) $tools[$tool];
    }

    /**
     * @return ToolInterface[]
     */
    public function getTools(): array
    {
        if (!$this->isEnabled()) {
            return [];
        }

        if ($this->tools !== null) {
            return $this->tools;
        }

        $options = $this->planOptions();
        $tools   = [];

        if ($this->isToolEnabled('run')) {
            $tools[] = new PlanRunTool($options);
        }

        if ($this->isToolEnabled('status')) {
            $tools[] = new PlanStatusTool($options);
        }

        if ($this->isToolEnabled('resume')) {
            $tools[] = new PlanResumeTool($options);
        }

        return $tools;
    }

    /**
     * 注册到 ToolRegistry（builtin 键，保证 LLM 回调名与注册键一致）
     */
    public function registerTo(?ToolRegistry $registry = null): int
    {
        $registry = $registry ?? ToolRegistry::instance();
        $count    = 0;

        foreach ($this->getTools() as $tool) {
            if (!$tool instanceof ToolInterface) {
                continue;
            }

            $registry->unregister($tool->getName());
            $registry->register($tool, $tool->getName(), 'builtin');
            $count++;
        }

        return $count;
    }

    /**
     * 按名执行（`ToolProviderInterface` 契约入口）
     *
     * @param array<string, mixed> $params
     */
    public function execute(string $toolName, array $params, RequestContext $context): mixed
    {
        foreach ($this->getTools() as $tool) {
            if ($tool->getName() !== $toolName) {
                continue;
            }

            if (method_exists($tool, 'withUser')) {
                $tool->withUser($context->getUserId(), $context->getUserType());
            }

            return $tool->execute($params);
        }

        return json_encode([
            'code' => 1,
            'msg'  => "Plan tool '{$toolName}' not found",
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return array{label:string,description:string,version:string}
     */
    public function getMetadata(): array
    {
        return [
            'label'       => 'Autonomous Plan (B1)',
            'description' => '目标导向自治任务：把业务目标拆成受校验的多步计划并执行，'
                . '执行只走既有能力（权限/审批/审计全部继承），被审批拦下则暂停、批准后续跑',
            'version'     => '1.0',
        ];
    }
}
