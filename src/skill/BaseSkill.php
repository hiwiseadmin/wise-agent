<?php
declare(strict_types=1);

namespace wise\agent\skill;

use wise\agent\security\CapabilityAccess;
use wise\agent\skill\contract\SkillInterface;

/**
 * Skill 基类
 *
 * 提供默认实现，子类只需覆写 getName/getDescription/getCategory/
 * getInputSchema/getSystemPrompt 等核心方法。
 *
 * 权限分类（访问类型）：
 *   - getPermission() 默认 `ai.skill.{name}`（细粒度权限标识）
 *   - getAccess() 默认按 getCategory() 推断：
 *       content / data → read（只产出文本，不改数据）
 *       code           → write
 *       business       → execute
 *       其它 / 未知    → execute（保守）
 *   - 需要精确控制时覆写 getAccess() / getRiskLevel()
 */
abstract class BaseSkill implements SkillInterface
{
    /**
     * @inheritDoc
     */
    public function getPermission(): string
    {
        return CapabilityAccess::SKILL_PREFIX . '.' . $this->getName();
    }

    /**
     * @inheritDoc
     */
    public function getAccess(): string
    {
        return CapabilityAccess::forSkillCategory($this->getCategory());
    }

    /**
     * @inheritDoc
     */
    public function getRiskLevel(): string
    {
        return CapabilityAccess::riskForSkillCategory($this->getCategory());
    }

    /**
     * 渲染 system prompt，替换占位符
     *
     * @param array $input 输入参数
     * @return string 渲染后的 system prompt
     */
    public function renderSystemPrompt(array $input = []): string
    {
        $prompt = $this->getSystemPrompt();

        foreach ($input as $key => $value) {
            $placeholder = '{' . $key . '}';
            if (strpos($prompt, $placeholder) !== false) {
                $displayValue = is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : (string) $value;
                $prompt = str_replace($placeholder, $displayValue, $prompt);
            }
        }

        return $prompt;
    }

    /**
     * 构建用户消息
     *
     * 将 input 参数转换为发送给 LLM 的用户消息。
     *
     * @param array $input 输入参数
     * @return string
     */
    public function buildUserMessage(array $input): string
    {
        if (isset($input['message'])) {
            return (string) $input['message'];
        }
        if (isset($input['query'])) {
            return (string) $input['query'];
        }
        if (isset($input['prompt'])) {
            return (string) $input['prompt'];
        }

        $schema = $this->getInputSchema();
        $properties = $schema['properties'] ?? [];
        $required = $schema['required'] ?? [];

        $parts = [];
        foreach ($properties as $key => $prop) {
            if (isset($input[$key]) && $input[$key] !== '' && $input[$key] !== null) {
                $label = $prop['description'] ?? $prop['title'] ?? $key;
                $value = is_array($input[$key])
                    ? json_encode($input[$key], JSON_UNESCAPED_UNICODE)
                    : (string) $input[$key];
                $parts[] = "{$label}: {$value}";
            }
        }

        return $this->getDescription() . "\n\n" . implode("\n", $parts);
    }

    /**
     * 校验输入参数
     *
     * @param array $input
     * @return array ['valid' => bool, 'errors' => string[]]
     */
    public function validateInput(array $input): array
    {
        $schema = $this->getInputSchema();
        $errors = [];
        $required = $schema['required'] ?? [];

        foreach ($required as $field) {
            if (!isset($input[$field]) || $input[$field] === '' || $input[$field] === null) {
                $prop = $schema['properties'][$field] ?? [];
                $label = $prop['description'] ?? $prop['title'] ?? $field;
                $errors[] = "缺少必填参数: {$label}";
            }
        }

        $properties = $schema['properties'] ?? [];
        foreach ($properties as $key => $prop) {
            if (isset($input[$key]) && isset($prop['enum'])) {
                if (!in_array($input[$key], $prop['enum'], true)) {
                    $errors[] = "参数 {$key} 值无效，可选: " . implode(', ', $prop['enum']);
                }
            }
        }

        return ['valid' => empty($errors), 'errors' => $errors];
    }


    public function getOutputFormat(): string
    {
        return 'text';
    }

    public function getDefaultTools(): array
    {
        return [];
    }

    public function getDefaultOptions(): array
    {
        return [
            'temperature' => 0.7,
            'max_tokens'  => 4096,
        ];
    }

    public function getSource(): string
    {
        return 'builtin';
    }
}
