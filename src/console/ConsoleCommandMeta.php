<?php
declare(strict_types=1);

namespace wise\agent\console;

use think\console\Command;
use think\console\input\Argument;
use think\console\input\Option;

/**
 * 单条 think 控制台命令的元数据 DTO（A4）
 *
 * 由 {@see ConsoleCommandIntrospector} 从 `think\console\Command` 的
 * `getName()/getDescription()/getDefinition()` 提取，是「命令 → AI 工具」的
 * 唯一事实来源。
 *
 * 设计要点：
 *   - **单文件 DTO**：只承载数据与纯函数（JSON Schema 推导、权限标识、序列化），
 *     不依赖容器、不读写文件，便于序列化缓存与单元测试；
 *   - 数组结构可无损 round-trip（`fromCommand()` ↔ `fromArray()`），
 *     因此 HTTP 侧可直接消费 CLI `wise:agent:console-dump --json` 的产物；
 *   - `risk` / `allowed` 属于「治理结论」，由 {@see ConsoleCommandGuard} 计算后回填，
 *     不作为内省事实（内省只负责读命令自带的参数元数据）。
 */
final class ConsoleCommandMeta implements \JsonSerializable
{
    /** 风险等级：只读，无副作用 */
    public const RISK_READ = 'read';

    /** 风险等级：写库 / 生成文件 / 写缓存 */
    public const RISK_WRITE = 'write';

    /** 风险等级：系统级运维（迁移 / 清缓存 / 队列重启 / 插件启停 / 权限同步） */
    public const RISK_ADMIN = 'admin';

    /** 来源：框架内置命令 */
    public const SOURCE_FRAMEWORK = 'framework';

    /**
     * think 控制台全局选项
     *
     * `Console::getDefinition()` 会在**当前正在执行的命令**实例上 merge 这组选项
     * （实测 `wise:agent:console-dump` 的 definition 里就带着 `--quiet/--verbose/--ansi`），
     * 它们对 AI 无意义且 `--quiet` 会吞掉全部输出 → 内省时一律剔除。
     *
     * @var string[]
     */
    public const GLOBAL_OPTIONS = [
        'help', 'version', 'quiet', 'verbose', 'ansi', 'no-ansi', 'no-interaction',
    ];

    /**
     * @param string $name        命令名，如 'wise:agent:discover'
     * @param string $description 命令描述（来自 setDescription）
     * @param string $source      来源：'framework' | 'package:{pkg}' | 'plugin:{ident}'
     * @param string $risk        风险等级：read | write | admin（由 Guard 计算回填）
     * @param bool   $supportsJson 命令是否自行声明了 `--json`（内省时探测，见 §11）
     * @param array  $arguments   参数定义 [{name, required, array, default, desc}]
     * @param array  $options     选项定义 [{name, shortcut, value_required, value_optional, array, default, desc}]
     * @param bool   $allowed     是否通过命令白名单（由 Guard 计算回填）
     * @param string $help        命令多行帮助（`setHelp()`），经 {@see self::helpSummary()} 摘要后进入工具描述
     */
    public function __construct(
        public readonly string $name,
        public readonly string $description = '',
        public readonly string $source = self::SOURCE_FRAMEWORK,
        public string $risk = self::RISK_READ,
        public readonly bool $supportsJson = false,
        public readonly array $arguments = [],
        public readonly array $options = [],
        public bool $allowed = false,
        public readonly string $help = '',
    ) {
    }

    /**
     * 从 think 命令实例提取元数据
     *
     * 注意：此处读取的 `getDefinition()` 是命令**自身**的定义，
     * 尚未 merge `Console` 的全局选项（--help/--verbose 等），
     * 因此不会把框架级选项误暴露给 LLM。
     */
    public static function fromCommand(string $name, Command $command): self
    {
        $definition = $command->getDefinition();

        $arguments = [];
        foreach ($definition->getArguments() as $argument) {
            if (!$argument instanceof Argument) {
                continue;
            }
            if ($argument->getName() === 'command') {
                continue;
            }
            $arguments[] = [
                'name'     => $argument->getName(),
                'required' => $argument->isRequired(),
                'array'    => $argument->isArray(),
                'default'  => $argument->isRequired() ? null : $argument->getDefault(),
                'desc'     => $argument->getDescription(),
            ];
        }

        $options = [];
        foreach ($definition->getOptions() as $option) {
            if (!$option instanceof Option) {
                continue;
            }
            if (in_array($option->getName(), self::GLOBAL_OPTIONS, true)) {
                continue;
            }
            $options[] = [
                'name'           => $option->getName(),
                'shortcut'       => $option->getShortcut(),
                'value_required' => $option->isValueRequired(),
                'value_optional' => $option->isValueOptional(),
                'array'          => $option->isArray(),
                'default'        => $option->acceptValue() ? $option->getDefault() : null,
                'desc'           => $option->getDescription(),
            ];
        }

        return new self(
            name: $name,
            description: $command->getDescription(),
            arguments: $arguments,
            options: $options,
            supportsJson: $definition->hasOption('json'),
            help: $command->getHelp(),
        );
    }

    /**
     * 从序列化数组还原（供 HTTP 侧消费 CLI dump 产物）
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: (string) ($data['name'] ?? ''),
            description: (string) ($data['description'] ?? ''),
            source: (string) ($data['source'] ?? self::SOURCE_FRAMEWORK),
            risk: (string) ($data['risk'] ?? self::RISK_READ),
            supportsJson: (bool) ($data['supports_json'] ?? false),
            arguments: is_array($data['arguments'] ?? null) ? $data['arguments'] : [],
            options: is_array($data['options'] ?? null) ? $data['options'] : [],
            allowed: (bool) ($data['allowed'] ?? false),
            help: (string) ($data['help'] ?? ''),
        );
    }

    /**
     * 生成给 LLM 的 JSON Schema（Function Calling parameters）
     *
     * - 位置参数 → `properties`（必填进 `required`）
     * - 选项 → `--name`（VALUE_NONE 为 boolean，其余为 string）
     * - `--json` 不暴露给 LLM：由 wise-agent 按 `supportsJson` 自动追加＼�，
     *   避免 LLM 手工传参造成 JSON 输出被覆盖或重复注入。
     *
     * **类型/枚举推断**（`$inferTypes`，默认开启）：
     * 命令只能给出「字符串 + 文本描述」，若一律下发 `string`，模型对数字/枚举参数
     * 只能靠猜。因此从参数描述里的显式标注推导更精确的类型：
     *   - 「整数 / 整型 / int」        → `integer`
     *   - 「小数 / 浮点 / number」     → `number`
     *   - 「布尔 / bool」              → `boolean`
     *   - 「可选值：a, b, c」「one of: a|b」 → `enum`（配合类型转换）
     * 只认显式标注（不做字符串内容猜测），标注错了仍然只影响提示精度，
     * 因为执行侧 {@see ConsoleCommandTool::sanitize()} 统一把值当字符串处理。
     */
    public function toJsonSchema(bool $inferTypes = true): array
    {
        $properties = [];
        $required   = [];

        foreach ($this->arguments as $argument) {
            $name = (string) ($argument['name'] ?? '');
            if ($name === '') {
                continue;
            }

            $desc = (string) ($argument['desc'] ?? '');
            $type = $this->resolveValueType($desc, $argument['default'] ?? null, !empty($argument['array']), $inferTypes);

            $property = [
                'type'        => $type,
                'description' => $this->decorate($desc, $name, true),
            ];
            if ($type === 'array') {
                $property['items'] = ['type' => 'string'];
            }
            if (array_key_exists('default', $argument) && $argument['default'] !== null && !is_array($argument['default'])) {
                $property['default'] = $this->castValue($argument['default'], $type);
            }
            $this->applyEnum($property, $desc, $type, $inferTypes);

            $properties[$name] = $property;

            if (!empty($argument['required'])) {
                $required[] = $name;
            }
        }

        foreach ($this->options as $option) {
            $name = (string) ($option['name'] ?? '');
            if ($name === '' || $name === 'json') {
                continue;
            }

            $acceptsValue = !empty($option['value_required']) || !empty($option['value_optional']);
            $desc         = (string) ($option['desc'] ?? '');
            $default      = $option['default'] ?? null;
            $type         = $acceptsValue
                ? $this->resolveValueType($desc, $default, !empty($option['array']), $inferTypes)
                : 'boolean';

            $property = [
                'type'        => $type,
                'description' => $this->decorate($desc, $name, false),
            ];
            if ($acceptsValue && $type === 'array') {
                $property['items'] = ['type' => 'string'];
            }
            if ($acceptsValue && is_scalar($default)) {
                $property['default'] = $this->castValue($default, $type);
            }
            if ($acceptsValue) {
                $this->applyEnum($property, $desc, $type, $inferTypes);
            }

            $properties[$name] = $property;
        }

        $schema = [
            'type'       => 'object',
            'properties' => $properties,
        ];
        if ($required !== []) {
            $schema['required'] = $required;
        }

        return $schema;
    }

    /**
     * 示例参数（JSON 片段，给 LLM 一个可直接照抄的调用形态）
     *
     * 取值优先级：描述里的枚举首值 → 声明默认值 → `<参数名>` 占位。
     * 只包含「必填位置参数 + 有默认值的选项」，避免示例里出现无意义占位。
     */
    public function usageExample(): string
    {
        $sample = [];

        foreach ($this->arguments as $argument) {
            $name = (string) ($argument['name'] ?? '');
            if ($name === '') {
                continue;
            }

            $required = !empty($argument['required']);
            $default  = $argument['default'] ?? null;
            if (!$required && ($default === null || $default === '' || $default === [])) {
                continue;
            }

            $value = $this->sampleValue($argument, $name);
            $sample[$name] = !empty($argument['array']) ? [$value] : $value;
        }

        foreach ($this->options as $option) {
            $name = (string) ($option['name'] ?? '');
            if ($name === '' || $name === 'json') {
                continue;
            }

            $acceptsValue = !empty($option['value_required']) || !empty($option['value_optional']);
            $default      = $option['default'] ?? null;

            if (!$acceptsValue) {
                if ($default === true || $default === 1 || $default === '1') {
                    $sample[$name] = true;
                }
                continue;
            }

            if ($default === null || $default === '') {
                continue;
            }

            $value = $this->sampleValue($option, $name);
            $sample[$name] = !empty($option['array']) ? [$value] : $value;
        }

        if ($sample === []) {
            return '';
        }

        return (string) json_encode($sample, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * 命令多行帮助摘要（`setHelp()` → 单行纯文本）
     *
     * think 的 `setHelp()` 允许写多行 usage / 示例，但框架只在内省时暴露
     * `getDescription()`。此处把 help 归一化为一行（去标签 / ANSI / 占位符还原 /
     * 压空白 / 截断），供工具描述使用，避免多行文本稀释 token。
     *
     * @param int $maxLength 字符上限（0 = 不截断）
     */
    public function helpSummary(int $maxLength = 300): string
    {
        $help = trim($this->help);
        if ($help === '') {
            return '';
        }

        $help = str_replace(
            ['%command.full_name%', '%command.name%'],
            ['php think ' . $this->name, $this->name],
            $help
        );
        $help = (string) preg_replace('/\bphp\s+php\s+think\b/i', 'php think', $help);

        $help = (string) preg_replace('/<[^>]+>/', '', $help);
        $help = (string) preg_replace('/\x1B\[[0-9;?]*[ -\/]*[@-~]/', '', $help);

        $help = trim((string) preg_replace('/\s+/u', ' ', $help));

        if ($maxLength > 0 && mb_strlen($help, 'UTF-8') > $maxLength) {
            $help = mb_substr($help, 0, max(1, $maxLength - 1), 'UTF-8') . '…';
        }

        return $help;
    }

    /**
     * 权限标识
     *
     * - 插件命令（source = 'plugin:{ident}'）→ `plugin:{ident}.cmd.{name}`
     * - 其余（框架 / Composer 包）        → `ai.cmd.{name}`
     *
     * 与自动 CRUD 工具 `plugin:{ident}.auto.*` 完全隔离，便于 RBAC 分权。
     */
    public function getPermission(): string
    {
        if (str_starts_with($this->source, 'plugin:')) {
            return $this->source . '.cmd.' . $this->name;
        }

        return 'ai.cmd.' . $this->name;
    }

    /**
     * 工具名（LLM Function Calling 名称）
     *
     * think 命令名含 `:`（如 `wise:agent:discover`），而 OpenAI / DeepSeek 的
     * function name 只允许 `[a-zA-Z0-9_-]`，因此统一归一化为 `cmd_{command}`。
     */
    public function getToolName(): string
    {
        return 'cmd_' . trim(preg_replace('/[^a-zA-Z0-9_]+/', '_', $this->name) ?? '', '_');
    }

    /**
     * 是否为插件命令
     */
    public function isPluginCommand(): bool
    {
        return str_starts_with($this->source, 'plugin:');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name'          => $this->name,
            'description'   => $this->description,
            'source'        => $this->source,
            'risk'          => $this->risk,
            'supports_json' => $this->supportsJson,
            'allowed'       => $this->allowed,
            'permission'    => $this->getPermission(),
            'tool_name'     => $this->getToolName(),
            'arguments'     => $this->arguments,
            'options'       => $this->options,
            'help'          => $this->help,
            'parameters'    => $this->toJsonSchema(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * 拼装展示给 LLM 的参数说明（补充 `[位置参数]` / `[--选项]` 提示）
     */
    private function decorate(string $description, string $name, bool $isArgument): string
    {
        $prefix = $isArgument ? '' : '--' . $name . ': ';
        $suffix = $isArgument
            ? ($description !== '' ? '' : '（位置参数 ' . $name . '）')
            : '';

        $text = trim($prefix . $description . $suffix);

        return $text !== '' ? $text : $name;
    }

    /**
     * 推导参数值类型（仅认显式标注 + 默认值，不做内容猜测）
     */
    private function resolveValueType(string $description, mixed $default, bool $isArray, bool $infer): string
    {
        if ($isArray) {
            return 'array';
        }

        if (!$infer) {
            return 'string';
        }

        $text = mb_strtolower($description, 'UTF-8');

        if ($text !== '') {
            if (preg_match('/(整数|整型|\bintegers?\b|\bint\b)/u', $text) === 1) {
                return 'integer';
            }
            if (preg_match('/(浮点|小数|数值|\bfloats?\b|\bnumbers?\b|\bdecimal\b|\bnumeric\b)/u', $text) === 1) {
                return 'number';
            }
            if (preg_match('/(布尔|\bbooleans?\b|\bbool\b)/u', $text) === 1) {
                return 'boolean';
            }
        }

        if (is_bool($default)) {
            return 'boolean';
        }
        if (is_int($default)) {
            return 'integer';
        }
        if (is_float($default)) {
            return 'number';
        }

        return 'string';
    }

    /**
     * 按目标类型转换默认值 / 枚举值（转不动就保留原样）
     */
    private function castValue(mixed $value, string $type): mixed
    {
        if (!is_scalar($value) || is_bool($value)) {
            return $value;
        }

        return match ($type) {
            'integer' => is_numeric($value) ? (int) $value : (string) $value,
            'number'  => is_numeric($value) ? (float) $value : (string) $value,
            default   => (string) $value,
        };
    }

    /**
     * 把描述里声明的枚举写入 schema（需 >= 2 个值，避免把举例误当枚举）
     *
     * @param array<string, mixed> $property
     */
    private function applyEnum(array &$property, string $description, string $type, bool $infer): void
    {
        if (!$infer || $type === 'boolean') {
            return;
        }

        $enum = $this->extractEnum($description);
        if ($enum === []) {
            return;
        }

        if ($type === 'array') {
            if (isset($property['items'])) {
                $property['items']['enum'] = $enum;
            }
            return;
        }

        $property['enum'] = array_map(fn($value) => $this->castValue($value, $type), $enum);
    }

    /**
     * 从参数描述提取枚举值
     *
     * 只识别显式标注（`可选值：a, b, c` / `取值范围: 1|2|3` / `one of: a|b`），
     * 值 token 限定为 `[A-Za-z0-9_.-]`，总数 2..30，其余一律忽略。
     *
     * @return string[]
     */
    private function extractEnum(string $description): array
    {
        if ($description === '') {
            return [];
        }

        $pattern = '/(?:可选值|取值范围|枚举|允许值|取值|one\s+of|allowed\s+values?|enum)'
            . '\s*(?:[:：=]|\s)\s*([A-Za-z0-9_.\-]+(?:\s*[|,，\/、]\s*[A-Za-z0-9_.\-]+)+)/iu';

        if (preg_match($pattern, $description, $matches) !== 1) {
            return [];
        }

        $parts = preg_split('/\s*[|,，\/、]\s*/u', trim($matches[1])) ?: [];
        $parts = array_values(array_unique(array_filter(
            array_map('trim', $parts),
            static fn(string $value): bool => $value !== ''
        )));

        return count($parts) >= 2 && count($parts) <= 30 ? $parts : [];
    }

    /**
     * 示例取值：枚举首值 → 声明默认值 → `<参数名>` 占位
     */
    private function sampleValue(array $decl, string $name): mixed
    {
        $enum = $this->extractEnum((string) ($decl['desc'] ?? ''));
        if ($enum !== []) {
            return $enum[0];
        }

        $default = $decl['default'] ?? null;
        if (is_scalar($default) && $default !== '') {
            return $default;
        }

        return '<' . $name . '>';
    }
}
