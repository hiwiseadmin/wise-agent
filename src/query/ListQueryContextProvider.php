<?php
declare(strict_types=1);

namespace wise\agent\query;

use wise\agent\agent\AgentContext;
use wise\agent\context\contract\ContextProviderInterface;

/**
 * 把「可查询目标 + 精简维度」注入 system 上下文，让模型**知道有哪些列表可查、有哪些维度**，
 * 而不必把每个目标的完整目录（含枚举候选）都塞进 Prompt。
 *
 * 三层裁剪的职责划分：
 *
 * | 层 | 归属 |
 * |---|---|
 * | ① 目标清单 + 精简维度（受 `max_prompt_chars` 约束） | 本类（经 `ListQueryToolProvider::toPrompt()`） |
 * | ② 完整目录按需拉取 | `lq_fields` 工具 |
 * | ③ 枚举候选写在 `filters[].value` 的 description 里 | `ListQueryTool::getParameters()` |
 *
 * 优先级默认 28：**晚于** `SchemaContextProvider`(30)、**早于** `BusinessCapabilityContextProvider`(25)，
 * 即「表结构 → 可查维度 → 业务知识」的阅读顺序。
 */
class ListQueryContextProvider implements ContextProviderInterface
{
    public const SOURCE = 'builtin:list_query';

    private ListQueryToolProvider $tools;

    private int $priority;

    /**
     * @param array<string, mixed> $options `list_query.*` 覆盖项
     */
    public function __construct(?ListQueryToolProvider $tools = null, ?int $priority = null, private array $options = [])
    {
        $this->tools    = $tools ?? new ListQueryToolProvider($options);
        $this->priority = $priority ?? max(0, (int) $this->opt('context_priority', 28));
    }

    public function getPriority(): int
    {
        return $this->priority;
    }

    public function getSource(): string
    {
        return self::SOURCE;
    }

    /**
     * {@inheritDoc}
     */
    public function getContext(AgentContext $agentContext): array
    {
        if (!$this->tools->isEnabled()) {
            return [];
        }

        if (!(bool) $this->opt('inject_context', true)) {
            return [];
        }

        try {
            $prompt = $this->tools->toPrompt();
        } catch (\Throwable) {
            return [];
        }

        if (trim($prompt) === '') {
            return [];
        }

        return [
            [
                'role'    => 'system',
                'content' => $prompt,
            ],
        ];
    }

    private function opt(string $key, mixed $default): mixed
    {
        if (array_key_exists($key, $this->options)) {
            return $this->options[$key];
        }

        if (!function_exists('config')) {
            return $default;
        }

        try {
            return config('wise-agent.list_query.' . $key, $default) ?? $default;
        } catch (\Throwable) {
            return $default;
        }
    }
}
