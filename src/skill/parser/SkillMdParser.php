<?php
declare(strict_types=1);

namespace wise\agent\skill\parser;

/**
 * 轻量实现，不依赖外部 YAML 库。
 *
 * ```
 * ---
 * name: skill.ident
 * description: 描述
 * category: content
 * parameters:
 *   param_name:
 *     type: string
 *     description: 参数描述
 *     required: true
 *     default: 默认值
 * ---
 *
 * # System Prompt
 * 系统提示词正文...
 * ```
 */
class SkillMdParser
{
    /** 解析结果：原始 frontmatter 键值对 */
    protected array $raw = [];

    /** 解析结果：Markdown 正文（system prompt） */
    protected string $body = '';

    /** 解析错误信息 */
    protected array $errors = [];

    /** bool 值的别名映射 */
    protected const BOOL_TRUE  = ['true', 'yes', 'on', '1'];
    protected const BOOL_FALSE = ['false', 'no', 'off', '0', ''];

    /**
     * @param string $filePath 文件路径
     * @return array{raw: array, body: string, errors: array}
     */
    public function parseFile(string $filePath): array
    {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            return $this->error("文件不存在或不可读: {$filePath}");
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            return $this->error("无法读取文件: {$filePath}");
        }

        return $this->parse($content);
    }

    /**
     * @param string $content
     * @return array{raw: array, body: string, errors: array}
     */
    public function parse(string $content): array
    {
        $this->raw    = [];
        $this->body   = '';
        $this->errors = [];

        $parts = $this->extractFrontmatter($content);
        if ($parts === null) {
            return $this->error('未找到有效的 YAML frontmatter（--- 包围的元数据块）');
        }

        [$frontmatter, $this->body] = $parts;

        $this->raw = $this->parseYamlLines($frontmatter);

        $this->validateRequired();

        $this->normalizeParameters();

        return [
            'raw'    => $this->raw,
            'body'   => trim($this->body),
            'errors' => $this->errors,
        ];
    }

    /**
     * 从内容中提取 YAML frontmatter
     *
     * @return array{string, string}|null [frontmatter, body]
     */
    protected function extractFrontmatter(string $content): ?array
    {
        if (!preg_match('/^---\s*\n/', $content)) {
            return null;
        }

        $content = preg_replace('/^---\s*\n/', '', $content, 1);

        $pos = strpos($content, "\n---");
        if ($pos === false) {
            $pos = strpos($content, "---\n");
        }
        if ($pos === false) {
            return null;
        }

        $frontmatter = substr($content, 0, $pos);
        $body = substr($content, $pos);

        $body = preg_replace('/^---\s*\n?/', '', $body, 1);

        return [trim($frontmatter), trim($body)];
    }

    /**
     * 逐行解析简单的 YAML（不支持复杂嵌套和引用）
     *
     * 使用基于缩进层级的方法来处理嵌套结构。
     * 例如：
     *   parameters:          ← level 0
     *     topic:             ← level 1
     *       type: string     ← level 2
     */
    protected function parseYamlLines(string $yaml): array
    {
        $lines = explode("\n", $yaml);
        $result = [];
        $stack = [&$result];
        $indents = [0];
        $keys = [''];

        foreach ($lines as $line) {
            if (trim($line) === '' || preg_match('/^\s*#/', $line)) {
                continue;
            }

            $indent = $this->getIndent($line);
            $trimmed = trim($line);

            while (count($indents) > 1 && $indent <= end($indents)) {
                array_pop($stack);
                array_pop($indents);
                array_pop($keys);
            }

            if (preg_match('/^\s*-\s+(.*)$/', $trimmed, $matches)) {
                $item = $this->stripQuotes(trim($matches[1]));
                $currentArr = &$stack[count($stack) - 1];
                $currentKey = $keys[count($keys) - 1];

                if ($currentKey !== '' && isset($currentArr[$currentKey]) && is_array($currentArr[$currentKey])) {
                    $currentArr[$currentKey][] = $this->castValue($item);
                } else {
                    $currentArr[] = $item;
                }
                continue;
            }

            if (preg_match('/^([a-zA-Z_][\w]*)\s*:\s*(.*)$/', $trimmed, $matches)) {
                $key   = $matches[1];
                $value = trim($matches[2]);

                $currentArr = &$stack[count($stack) - 1];

                if ($value === '') {
                    $currentArr[$key] = [];
                    $stack[] = &$currentArr[$key];
                    $indents[] = $indent;
                    $keys[] = $key;
                } elseif ($value === '[]') {
                    $currentArr[$key] = [];
                } else {
                    $currentArr[$key] = $this->castValue($value);
                }
                continue;
            }
        }

        return $result;
    }

    /**
     * 计算行缩进（空格数）
     */
    protected function getIndent(string $line): int
    {
        $matches = [];
        if (preg_match('/^(\s*)/', $line, $matches)) {
            return strlen($matches[1]);
        }
        return 0;
    }

    /**
     * 类型转换
     */
    protected function castValue(string $value): mixed
    {
        $lower = strtolower($value);

        if (in_array($lower, self::BOOL_TRUE, true)) {
            return true;
        }
        if (in_array($lower, self::BOOL_FALSE, true)) {
            return false;
        }

        if ($lower === 'null' || $lower === '~') {
            return null;
        }

        if (preg_match('/^-?\d+$/', $value)) {
            return (int) $value;
        }

        if (preg_match('/^-?\d+\.\d+$/', $value)) {
            return (float) $value;
        }

        return $this->stripQuotes($value);
    }

    /**
     * 去除字符串引号
     */
    protected function stripQuotes(string $value): string
    {
        if ((str_starts_with($value, '"') && str_ends_with($value, '"'))
            || (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
            return substr($value, 1, -1);
        }
        return $value;
    }

    /**
     * 校验必填字段
     */
    protected function validateRequired(): void
    {
        $required = ['name', 'description', 'category'];
        foreach ($required as $field) {
            if (!isset($this->raw[$field]) || $this->raw[$field] === '') {
                $this->errors[] = "缺少必填字段: {$field}";
            }
        }

        if (isset($this->raw['name'])) {
            if (!preg_match('/^[\w]+\.[\w._-]+$/', $this->raw['name'])) {
                $this->errors[] = "name 格式无效（应为 category.identifier）: {$this->raw['name']}";
            }
        }
    }

    /**
     * 规范化 parameters 字段为 JSON Schema 格式
     */
    protected function normalizeParameters(): void
    {
        if (!isset($this->raw['parameters']) || !is_array($this->raw['parameters'])) {
            $this->raw['input_schema'] = [
                'type'       => 'object',
                'properties' => [],
            ];
            return;
        }

        $schema = [
            'type'       => 'object',
            'properties' => [],
        ];
        $required = [];

        foreach ($this->raw['parameters'] as $name => $config) {
            if (!is_array($config)) {
                $config = ['type' => 'string', 'description' => (string) $config];
            }

            $prop = [
                'type'        => $config['type'] ?? 'string',
                'description' => $config['description'] ?? $config['label'] ?? $name,
            ];

            if (array_key_exists('default', $config)) {
                $prop['default'] = $config['default'];
            }

            if (isset($config['enum']) && is_array($config['enum'])) {
                $prop['enum'] = $config['enum'];
            }

            if (isset($config['minimum'])) {
                $prop['minimum'] = (int) $config['minimum'];
            }
            if (isset($config['maximum'])) {
                $prop['maximum'] = (int) $config['maximum'];
            }

            $schema['properties'][$name] = $prop;

            if (!empty($config['required'])) {
                $required[] = $name;
            }
        }

        if (!empty($required)) {
            $schema['required'] = $required;
        }

        $this->raw['input_schema'] = $schema;
    }

    /**
     * 获取 raw 数据
     */
    public function getRaw(): array
    {
        return $this->raw;
    }

    /**
     * 获取 body（system prompt）
     */
    public function getBody(): string
    {
        return $this->body;
    }

    /**
     * 获取错误信息
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * 是否解析成功
     */
    public function isValid(): bool
    {
        return empty($this->errors);
    }

    /**
     * 返回错误结果
     */
    protected function error(string $message): array
    {
        $this->errors[] = $message;
        return [
            'raw'    => $this->raw,
            'body'   => '',
            'errors' => $this->errors,
        ];
    }
}
