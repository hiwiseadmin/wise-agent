<?php
declare(strict_types=1);

namespace wise\agent\form;

use wise\agent\agent\AgentContext;
use wise\agent\context\contract\ContextProviderInterface;

/**
 * 表单上下文注入器＼�
 *
 * 把「有哪些表单可填 + 每个目标的精简字段」注入 system 上下文，让模型**知道有哪些表单目标**，
 * 而不必把每个目标的完整目录（含枚举候选、联动、条件必填）都塞进 Prompt。
 *
 * 三层裁剪的职责划分：
 *
 * | 层 | 内容 | 归属 |
 * |---|---|---|
 * | ① 目标清单 + 精简字段（受 `max_prompt_chars`） | 本类（经 `FormSchemaToolProvider::toPrompt()`） |
 * | ② 完整目录按需拉取 | `form_fields` 工具 |
 * | ③ 枚举候选写在参数 description 里 | `form_fill` / `form_validate::getParameters()` |
 *
 * 优先级默认 26：**晚于** `SchemaContextProvider`(30) 与 `ListQueryContextProvider`(28)，
 * **早于** `BusinessCapabilityContextProvider`(25)，即「表结构 → 可查维度 → 可填表单 → 业务知识」。
 */
class FormSchemaContextProvider implements ContextProviderInterface
{
    public const SOURCE = 'builtin:form_schema';

    private FormSchemaToolProvider $tools;

    private int $priority;

    /**
     * @param array<string, mixed> $options `form_schema.*` 覆盖项
     */
    public function __construct(?FormSchemaToolProvider $tools = null, ?int $priority = null, private array $options = [])
    {
        $this->tools    = $tools ?? new FormSchemaToolProvider($options);
        $this->priority = $priority ?? max(0, (int) $this->opt('context_priority', 26));
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
            return config('wise-agent.form_schema.' . $key, $default) ?? $default;
        } catch (\Throwable) {
            return $default;
        }
    }
}
