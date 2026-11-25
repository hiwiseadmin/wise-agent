<?php
declare(strict_types=1);

namespace wise\agent\prompt;

use InvalidArgumentException;

/**
 * Prompt 模板（B4-7）
 *
 * 解决的问题
 * ----------
 * 改造前提示词散落在各控制器 / 服务里当字符串字面量拼接：
 *   - 改一句话要改代码、发版本；
 *   - 同一段 system prompt 在多个入口复制粘贴，逐渐不一致；
 *   - 无法回答"当前生效的提示词是什么"（交付审计时会被问到）。
 *
 * 本类把提示词变成**数据**：模板 + 占位符 + 角色绑定。
 *
 * 设计要点
 * --------
 * 1. **角色绑定**：模板是一组消息（system / user / assistant），不是一段字符串。
 *    多轮场景（few-shot）可以直接表达，而不用在外面拼。
 * 2. **占位符**：`{{ var }}`（允许空格）。用双花括号而非单花括号，避免与
 *    JSON、代码片段里的 `{` 冲突。
 * 3. **变量声明**：可声明 `required` 与 `default`。必填缺失直接抛异常，
 *    而不是悄悄替换成空串 —— 空 system prompt 导致的模型行为异常极难排查。
 *
 * @package wise\agent\prompt
 */
final class PromptTemplate
{
    /** 占位符：{{ name }} / {{name}}，允许点号分段 */
    private const PLACEHOLDER = '/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/';

    /**
     * @param string $id          唯一标识（如 chat.system）
     * @param string $name        展示名
     * @param string $description  用途说明（会展示在后台，便于非开发者理解）
     * @param array  $messages    [['role'=>'system','content'=>'...'], ...]
     * @param array  $variables   ['name' => ['required'=>bool, 'default'=>'', 'description'=>'']]
     * @param array  $options     建议的调用参数（model / temperature / max_tokens）
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name = '',
        public readonly string $description = '',
        public readonly array $messages = [],
        public readonly array $variables = [],
        public readonly array $options = []
    ) {
        if (trim($this->id) === '') {
            throw new InvalidArgumentException('Prompt template id must not be empty');
        }
    }

    /**
     * 从配置数组构建
     *
     * @param array<string, mixed> $definition
     */
    public static function fromArray(string $id, array $definition): self
    {
        $messages = [];

        foreach (['system', 'user', 'assistant'] as $role) {
            if (isset($definition[$role]) && is_string($definition[$role])) {
                $messages[] = ['role' => $role, 'content' => $definition[$role]];
            }
        }

        if (isset($definition['messages']) && is_array($definition['messages'])) {
            $messages = [];

            foreach ($definition['messages'] as $message) {
                if (!is_array($message) || !isset($message['content'])) {
                    continue;
                }

                $messages[] = [
                    'role'    => (string) ($message['role'] ?? 'user'),
                    'content' => (string) $message['content'],
                ];
            }
        }

        return new self(
            id:          $id,
            name:        (string) ($definition['name'] ?? $id),
            description: (string) ($definition['description'] ?? ''),
            messages:    $messages,
            variables:   is_array($definition['variables'] ?? null) ? $definition['variables'] : [],
            options:     is_array($definition['options'] ?? null) ? $definition['options'] : []
        );
    }

    /**
     * 渲染为消息数组
     *
     * @param array<string, mixed> $vars
     * @return array<int, array{role:string, content:string}>
     *
     * @throws InvalidArgumentException 必填变量缺失
     */
    public function render(array $vars = []): array
    {
        $missing  = [];
        $resolved = $this->resolveVariables($vars, $missing);

        if ($missing !== []) {
            throw new InvalidArgumentException(sprintf(
                'Prompt template [%s] missing required variable(s): %s',
                $this->id,
                implode(', ', $missing)
            ));
        }

        $messages = [];

        foreach ($this->messages as $message) {
            $messages[] = [
                'role'    => (string) $message['role'],
                'content' => self::interpolate((string) $message['content'], $resolved),
            ];
        }

        return $messages;
    }

    /**
     * 渲染后取所有 system 消息拼接（多数调用方只需要这一句）
     *
     * @param array<string, mixed> $vars
     */
    public function renderSystem(array $vars = []): string
    {
        $parts = [];

        foreach ($this->render($vars) as $message) {
            if ($message['role'] === 'system') {
                $parts[] = $message['content'];
            }
        }

        return implode("\n\n", $parts);
    }

    /**
     * 校验变量是否齐备（不渲染，供后台"试填"用）
     *
     * @param array<string, mixed> $vars
     * @return string[] 缺失的必填变量名
     */
    public function missing(array $vars = []): array
    {
        $missing = [];
        $this->resolveVariables($vars, $missing);

        return $missing;
    }

    /**
     * 合并默认值并找出缺失的必填项
     *
     * @param array<string, mixed> $vars
     * @param string[]             $missing
     * @return array<string, string>
     */
    private function resolveVariables(array $vars, array &$missing): array
    {
        $resolved = [];

        foreach ($this->variables as $name => $definition) {
            $definition = is_array($definition) ? $definition : [];
            $required   = (bool) ($definition['required'] ?? false);
            $default    = (string) ($definition['default'] ?? '');

            if (array_key_exists($name, $vars) && $vars[$name] !== '' && $vars[$name] !== null) {
                $resolved[$name] = (string) $vars[$name];
                continue;
            }

            if ($required && $default === '') {
                $missing[] = (string) $name;
                continue;
            }

            $resolved[$name] = $default;
        }

        foreach ($vars as $name => $value) {
            if (!array_key_exists($name, $resolved) && $value !== null && !is_array($value)) {
                $resolved[(string) $name] = (string) $value;
            }
        }

        return $resolved;
    }

    /**
     * 占位符替换
     *
     * 未声明的占位符**保持原样**：宁可让模型看到 `{{foo}}` 暴露配置错误，
     * 也不要静默替换为空串——后者会生成一条语义残缺的提示词，问题被掩盖。
     *
     * @param array<string, string> $vars
     */
    private static function interpolate(string $text, array $vars): string
    {
        return (string) preg_replace_callback(
            self::PLACEHOLDER,
            static fn(array $m): string => array_key_exists($m[1], $vars) ? $vars[$m[1]] : $m[0],
            $text
        );
    }
}
