<?php
declare(strict_types=1);

namespace wise\agent\skill;

use think\facade\Config;
use wise\support\context\ContextualLogger;
use wise\agent\agent\AgentManager;
use wise\agent\agent\SimpleAgent;
use wise\agent\contract\ToolInterface;
use wise\agent\security\ApprovalManager;
use wise\agent\security\ApprovalPolicy;
use wise\agent\security\ApprovalRequirement;
use wise\agent\security\CapabilityAuthorizer;
use wise\agent\skill\contract\SkillInterface;
use wise\agent\skill\exception\SkillException;
use wise\agent\tool\ToolRegistry;

/**
 * Skill 执行引擎
 *
 * 负责 Skill 的执行：查找 Skill → 校验输入 → 创建临时 Agent →
 * 注入 system_prompt → 注册 tools → 执行 → 返回结构化结果。
 *
 * 内部委托给 AgentManager 的 Run Loop，复用现有的 Agent + Tool + Event 基础设施。
 */
class SkillManager
{
    protected SkillRegistry $registry;
    protected AgentManager $agentManager;
    protected array $config;

    /** 能力授权判定器（Tool / Skill 共用同一套判定） */
    protected ?CapabilityAuthorizer $authorizer = null;

    /** 审批策略（access / risk / 能力标识三级规则） */
    protected ?ApprovalPolicy $approvalPolicy = null;

    /** 审批管理器（审批单申请 / 消费 / 决策） */
    protected ?ApprovalManager $approvalManager = null;

    public function __construct(
        SkillRegistry $registry,
        AgentManager $agentManager,
        array $config = [],
        ?CapabilityAuthorizer $authorizer = null,
        ?ApprovalPolicy $approvalPolicy = null,
        ?ApprovalManager $approvalManager = null
    ) {
        $this->registry = $registry;
        $this->agentManager = $agentManager;
        $this->authorizer = $authorizer;
        $this->approvalPolicy = $approvalPolicy;
        $this->approvalManager = $approvalManager;
        $this->config = array_merge([
            'default_provider' => Config::get('wise-agent.default', 'openai'),
            'max_steps'        => Config::get('wise-agent.agent.max_steps', 10),
            'max_tokens'       => Config::get('wise-agent.agent.max_tokens_per_step', 4096),
            'enable_memory'    => false,
        ], $config);
    }

    /**
     * 执行一个 Skill（同步模式）
     *
     * @param string $skillName  Skill 名称
     * @param array  $input      输入参数
     * @param array  $options    执行选项覆盖 (provider, temperature, max_tokens, tools, system_prompt_extra)
     * @return SkillResult
     * @throws SkillException
     */
    public function execute(string $skillName, array $input = [], array $options = []): SkillResult
    {
        $startTime = microtime(true);

        $skill = $this->registry->get($skillName);
        if ($skill === null) {
            throw new SkillException("Skill '{$skillName}' 未找到", $skillName);
        }

        $this->authorize($skill, $input);

        $validation = $skill->validateInput($input);
        if (!$validation['valid']) {
            throw new SkillException(
                "Skill '{$skillName}' 参数校验失败: " . implode('; ', $validation['errors']),
                $skillName
            );
        }

        try {
            $agent = $this->createAgentForSkill($skill, $input, $options);

            $userMessage = $options['user_message'] ?? $skill->buildUserMessage($input);

            $result = $this->agentManager->run($agent, $userMessage);

            $duration = microtime(true) - $startTime;

            $usage = [];

            return new SkillResult(
                $skillName,
                $result,
                $usage,
                $duration,
                1,
                [
                    'provider' => $agent->getProviderName(),
                    'input'    => $input,
                ]
            );

        } catch (\Throwable $e) {
            if ($e instanceof SkillException) {
                throw $e;
            }
            throw new SkillException(
                "Skill '{$skillName}' 执行失败: {$e->getMessage()}",
                $skillName,
                0,
                $e
            );
        }
    }

    /**
     * 流式执行一个 Skill
     *
     * @param string $skillName
     * @param array  $input
     * @param array  $options
     * @return \Generator 生成流式事件数组
     * @throws SkillException
     */
    public function executeStream(string $skillName, array $input = [], array $options = []): \Generator
    {
        $skill = $this->registry->get($skillName);
        if ($skill === null) {
            throw new SkillException("Skill '{$skillName}' 未找到", $skillName);
        }

        $this->authorize($skill, $input);

        $validation = $skill->validateInput($input);
        if (!$validation['valid']) {
            throw new SkillException(
                "Skill '{$skillName}' 参数校验失败: " . implode('; ', $validation['errors']),
                $skillName
            );
        }

        $agent = $this->createAgentForSkill($skill, $input, $options);

        $userMessage = $options['user_message'] ?? $skill->buildUserMessage($input);

        yield from $this->agentManager->runStream($agent, $userMessage, $options);
    }

    /**
     * 获取 Skill 的输入 schema（用于前端表单渲染）
     *
     * @param string $skillName
     * @return array|null
     */
    public function getInputSchema(string $skillName): ?array
    {
        $skill = $this->registry->get($skillName);
        if ($skill === null) {
            return null;
        }
        return $skill->getInputSchema();
    }

    /**
     * 获取所有可用 Skill 的概览
     */
    public function getAvailableSkills(): array
    {
        return $this->registry->getOverview();
    }

    /**
     * 注入授权判定器（测试 / 自定义装配用）
     */
    public function setAuthorizer(CapabilityAuthorizer $authorizer): void
    {
        $this->authorizer = $authorizer;
    }

    /**
     * 注入审批策略（测试 / 自定义装配用）
     */
    public function setApprovalPolicy(ApprovalPolicy $approvalPolicy): void
    {
        $this->approvalPolicy = $approvalPolicy;
    }

    /**
     * 注入审批管理器（测试 / 自定义装配用）
     */
    public function setApprovalManager(ApprovalManager $approvalManager): void
    {
        $this->approvalManager = $approvalManager;
    }

    /**
     * Skill 权限 + 审批校验
     *
     * 所有 Skill 执行入口（同步 / 流式）都必须经过此方法：
     *   1. **权限**：交由 `CapabilityAuthorizer`（授权事件 → wise-auth RBAC，超管直通 + 类别通配）
     *   2. **审批**：权限通过后由 `ApprovalPolicy` 判定是否需要人工确认 / 审批
     *
     * @throws SkillException 无权限或需要人工介入时抛出
     */
    protected function authorize(SkillInterface $skill, array $input): void
    {
        $authorizer = $this->resolveAuthorizer();
        $descriptor = $authorizer->describeSkill($skill);

        $decision = $authorizer->authorizeSkill($skill, $input);

        if (!$decision->allowed) {
            ContextualLogger::channel('ai')->warning(
                "[SkillManager] Blocked Skill '{$skill->getName()}' by {$decision->decidedBy}: {$decision->reason}"
            );

            throw new SkillException(
                "Skill '{$skill->getName()}' 权限校验未通过：{$decision->reason}",
                $skill->getName()
            );
        }

        ContextualLogger::channel('ai')->info(
            "[SkillManager] Skill authorized by {$decision->decidedBy}: "
            . "{$skill->getName()} (access={$descriptor->access}, permission={$descriptor->permission})"
        );

        $requirement = $this->resolveApprovalPolicy()->decideForSkill($skill, $descriptor);

        if (!$requirement->required()) {
            return;
        }

        $outcome = $this->resolveApprovalManager()->gate($descriptor, $requirement, $input, [
            'user_id'   => $authorizer->currentUserId(),
            'user_type' => $authorizer->currentUserType(),
        ]);

        if (!$outcome->blocked()) {
            ContextualLogger::channel('ai')->info(
                "[SkillManager] Skill '{$skill->getName()}' allowed by approval {$outcome->ident}"
            );

            return;
        }

        ContextualLogger::channel('ai')->warning(
            "[SkillManager] Blocked Skill '{$skill->getName()}' ({$outcome->status}, rule={$requirement->rule}): "
            . $outcome->reason
        );

        throw new SkillException(
            "Skill '{$skill->getName()}' 需要"
                . ($requirement->mode === ApprovalRequirement::WORKFLOW ? '审批' : '人工确认')
                . '后方可执行：' . $outcome->reason,
            $skill->getName()
        );
    }

    /**
     * 获取授权判定器（优先使用注入实例，否则从容器解析）
     */
    protected function resolveAuthorizer(): CapabilityAuthorizer
    {
        if ($this->authorizer !== null) {
            return $this->authorizer;
        }

        try {
            $this->authorizer = app(CapabilityAuthorizer::class);
        } catch (\Throwable) {
            $this->authorizer = new CapabilityAuthorizer();
        }

        return $this->authorizer;
    }

    /**
     * 获取审批策略（优先使用注入实例，否则从容器解析）
     */
    protected function resolveApprovalPolicy(): ApprovalPolicy
    {
        if ($this->approvalPolicy !== null) {
            return $this->approvalPolicy;
        }

        try {
            $this->approvalPolicy = app(ApprovalPolicy::class);
        } catch (\Throwable) {
            $this->approvalPolicy = new ApprovalPolicy();
        }

        return $this->approvalPolicy;
    }

    /**
     * 获取审批管理器（优先使用注入实例，否则从容器解析）
     */
    protected function resolveApprovalManager(): ApprovalManager
    {
        if ($this->approvalManager !== null) {
            return $this->approvalManager;
        }

        try {
            $this->approvalManager = app(ApprovalManager::class);
        } catch (\Throwable) {
            $this->approvalManager = new ApprovalManager();
        }

        return $this->approvalManager;
    }

    /**
     * 为 Skill 执行创建临时 Agent
     *
     * @param SkillInterface $skill
     * @param array $input
     * @param array $options
     * @return SimpleAgent
     */
    protected function createAgentForSkill(SkillInterface $skill, array $input, array $options): SimpleAgent
    {
        $provider = $options['provider'] ?? $this->config['default_provider'];

        $agent = new SimpleAgent(null, [
            'provider'       => $provider,
            'enable_memory'  => $this->config['enable_memory'] ?? false,
            'temperature'    => $options['temperature'] ?? $skill->getDefaultOptions()['temperature'] ?? 0.7,
            'max_tokens_per_step' => $options['max_tokens'] ?? $skill->getDefaultOptions()['max_tokens'] ?? $this->config['max_tokens'],
            'max_steps'      => $options['max_steps'] ?? $this->config['max_steps'],
        ]);

        $systemPrompt = $skill->renderSystemPrompt($input);
        if (!empty($options['system_prompt_extra'])) {
            $systemPrompt .= "\n\n" . $options['system_prompt_extra'];
        }
        $agent->setSystemPrompt($systemPrompt);

        $toolNames = $options['tools'] ?? $skill->getDefaultTools();
        if (!empty($toolNames)) {
            $isolatedTools = ToolRegistry::newInstance();

            $configuredTools = Config::get('wise-agent.tools', []);
            foreach ($toolNames as $toolName) {
                $globalTool = ToolRegistry::instance()->normalize($toolName);
                if ($globalTool !== null) {
                    $isolatedTools->register($globalTool, $toolName);
                    continue;
                }
                if (isset($configuredTools[$toolName])) {
                    $toolConfig = $configuredTools[$toolName];
                    $class = $toolConfig['class'] ?? null;
                    if ($class && class_exists($class)) {
                        $instance = new $class();
                        if ($instance instanceof ToolInterface) {
                            $isolatedTools->register($instance, $toolName);
                        }
                    }
                }
            }

            $agent->setToolRegistry($isolatedTools);
        }

        return $agent;
    }
}
