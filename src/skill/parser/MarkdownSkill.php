<?php
declare(strict_types=1);

namespace wise\agent\skill\parser;

use wise\agent\security\CapabilityAccess;
use wise\agent\skill\contract\SkillInterface;

/**
 * 将 SkillMdParser 的解析结果包装为 SkillInterface 实现，
 * 与 PHP 类 Skill 完全兼容，对外透明。
 *
 * 权限（front-matter 可选字段）：
 *   ```yaml
 *   name: smartstock.stock_query
 *   category: business
 *   access: read            # read | write | execute | admin（缺省由分类推断）
 *   risk: low               # low | medium | high
 *   permission: ai.skill.smartstock.stock_query   # 缺省为 ai.skill.{name}
 *   ```
 */
class MarkdownSkill implements SkillInterface
{
    protected string $name;
    protected string $description;
    protected string $category;
    protected string $systemPrompt;
    protected array $inputSchema;
    protected string $outputFormat;
    protected array $defaultTools;
    protected array $defaultOptions;
    protected string $source;
    protected string $filePath;
    protected array $raw;

    /**
     * @param array  $result   SkillMdParser::parse() 的返回结果
     * @param string $filePath SKILL.md 文件路径（用于日志和调试）
     */
    public function __construct(array $result, string $filePath = '')
    {
        $this->raw      = $result['raw'];
        $this->filePath = $filePath;

        $this->name         = $this->raw['name'] ?? '';
        $this->description  = $this->raw['description'] ?? '';
        $this->category     = $this->raw['category'] ?? 'general';
        $this->systemPrompt = trim($result['body']);
        $this->inputSchema  = $this->raw['input_schema'] ?? ['type' => 'object', 'properties' => []];
        $this->outputFormat = $this->raw['output_format'] ?? 'text';
        $this->defaultTools = $this->raw['tools'] ?? [];
        $this->defaultOptions = $this->raw['options'] ?? [];
        $this->source       = $this->raw['source'] ?? $this->inferSource($filePath);

        if (!isset($this->defaultOptions['temperature'])) {
            $this->defaultOptions['temperature'] = 0.7;
        }
        if (!isset($this->defaultOptions['max_tokens'])) {
            $this->defaultOptions['max_tokens'] = 4096;
        }
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getCategory(): string
    {
        return $this->category;
    }

    public function getInputSchema(): array
    {
        return $this->inputSchema;
    }

    public function getSystemPrompt(): string
    {
        return $this->systemPrompt;
    }

    public function getOutputFormat(): string
    {
        return $this->outputFormat;
    }

    public function getDefaultTools(): array
    {
        return $this->defaultTools;
    }

    public function getDefaultOptions(): array
    {
        return $this->defaultOptions;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    /**
     * 获取原始文件路径
     */
    public function getFilePath(): string
    {
        return $this->filePath;
    }

    /**
     * 获取原始解析数据
     */
    public function getRaw(): array
    {
        return $this->raw;
    }

    /**
     * 权限标识
     *
     * front-matter 的 `permission` 优先，缺省为 `ai.skill.{name}`。
     */
    public function getPermission(): string
    {
        $permission = trim((string) ($this->raw['permission'] ?? ''));

        return $permission !== ''
            ? $permission
            : CapabilityAccess::SKILL_PREFIX . '.' . $this->name;
    }

    /**
     * 访问类型
     *
     * front-matter 的 `access` 优先，缺省按 `category` 推断
     * （content/data → read，code → write，business → execute）。
     */
    public function getAccess(): string
    {
        $access = trim((string) ($this->raw['access'] ?? ''));

        return $access !== ''
            ? CapabilityAccess::normalize($access)
            : CapabilityAccess::forSkillCategory($this->category);
    }

    /**
     * 风险等级
     *
     * front-matter 的 `risk` 优先，缺省按 `category` 推断。
     */
    public function getRiskLevel(): string
    {
        $risk = trim((string) ($this->raw['risk'] ?? ''));

        return $risk !== ''
            ? $risk
            : CapabilityAccess::riskForSkillCategory($this->category);
    }

    /**
     * 从文件路径推断来源
     */
    protected function inferSource(string $filePath): string
    {
        if (empty($filePath)) {
            return 'unknown';
        }

        if (preg_match('#plugin[/\\\\]([^/\\\\]+)[/\\\\]skills#i', $filePath, $matches)) {
            return 'plugin:' . $matches[1];
        }

        if (strpos($filePath, 'wise-agent') !== false || strpos($filePath, 'vendor') !== false) {
            return 'builtin:md';
        }

        return 'custom';
    }

    /**
     * 校验输入参数（复用 BaseSkill 的逻辑）
     */
    public function validateInput(array $input): array
    {
        $errors = [];
        $required = $this->inputSchema['required'] ?? [];

        foreach ($required as $field) {
            if (!isset($input[$field]) || $input[$field] === '' || $input[$field] === null) {
                $prop = $this->inputSchema['properties'][$field] ?? [];
                $label = $prop['description'] ?? $field;
                $errors[] = "缺少必填参数: {$label}";
            }
        }

        $properties = $this->inputSchema['properties'] ?? [];
        foreach ($properties as $key => $prop) {
            if (isset($input[$key]) && isset($prop['enum'])) {
                if (!in_array($input[$key], $prop['enum'], true)) {
                    $errors[] = "参数 {$key} 值无效，可选: " . implode(', ', $prop['enum']);
                }
            }
        }

        return ['valid' => empty($errors), 'errors' => $errors];
    }

    /**
     * 渲染 system prompt（替换占位符）
     */
    public function renderSystemPrompt(array $input = []): string
    {
        $prompt = $this->systemPrompt;

        $properties = $this->inputSchema['properties'] ?? [];
        foreach ($properties as $key => $prop) {
            if (!isset($input[$key]) && array_key_exists('default', $prop)) {
                $input[$key] = $prop['default'];
            }
        }

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

        $properties = $this->inputSchema['properties'] ?? [];
        $parts = [];
        foreach ($properties as $key => $prop) {
            if (isset($input[$key]) && $input[$key] !== '' && $input[$key] !== null) {
                $label = $prop['description'] ?? $key;
                $value = is_array($input[$key])
                    ? json_encode($input[$key], JSON_UNESCAPED_UNICODE)
                    : (string) $input[$key];
                $parts[] = "{$label}: {$value}";
            }
        }

        return $this->description . "\n\n" . implode("\n", $parts);
    }
}
