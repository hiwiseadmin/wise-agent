<?php
declare(strict_types=1);

namespace wise\agent\form;

use wise\agent\contract\RequestContext;
use wise\agent\contract\ToolInterface;
use wise\agent\contract\ToolProviderInterface;
use wise\agent\tool\ToolRegistry;

/**
 * 表单 Schema 工具提供者（A2）
 *
 * 命名空间：`form_schema`（与配置节 `wise-agent.form_schema` 同名）。
 *
 * ## 工具清单
 *
 * | 工具 | 阶段 | 读写 |
 * |---|---|---|
 * | `form_fields` | A2-3 | 只读（元数据） |
 * | `form_fill` | A2-3 | 只读 / 纯计算 |
 * | `form_validate` | A2-2 | 只读 / 纯计算 |
 * | `form_generate` | **A2-4** | 默认只读（`write_mode=never`）；`assisted` 时为 WRITE |
 *
 * `form_generate` 的开关 `tools.generate` **默认关**＼�改 `defineForm()` =
 * 改未来的权限与校验语义，影响面比改一条业务数据更大）。它开启后仍默认只出草案，
 * 写盘需要 `generate.write_mode=assisted` + 白名单 + 确认（见 {@see FormDraftWriter}）。
 *
 * ## 三层裁剪的第 ① 层在这里
 *
 * {@see self::toPrompt()} 产出「目标清单 + 精简字段」的常驻文本（受 `max_prompt_chars` 硬约束，
 * 超预算即降级为「只保留目标名」）；完整目录由 `form_fields` 按需拉取（第 ② 层），
 *
 * ## 注册约定（与 A1/A3/A4 同坑）
 *
 * `ToolRegistry::execute()` 按**精确键**查找，而 `getSchemas()` 用 `getName()` 下发给 LLM；
 * 因此必须用 builtin 键注册（`form_fields` / `form_fill` / `form_validate`），
 * 否则 LLM 回调名与注册键不一致、执行时找不到工具。
 *
 * ## 可测试性
 * `introspector()` / `schemaFor()` 是 `protected` 接缝，Prompt 预算行为可完全离线断言。
 * **不加 `final`**。
 */
class FormSchemaToolProvider implements ToolProviderInterface
{
    /** 提供者命名空间（逻辑分组） */
    public const NAMESPACE = 'form_schema';

    /** 极小预算下的短表头（保住目标名，说明文字省掉） */
    private const SHORT_HEADER = "## 可填写表单（form_fields / form_fill / form_validate）\n";

    /** @var array<string, mixed> */
    private array $options;

    private ?FormSchemaIntrospector $introspector = null;

    /** @var array<string, FormSchema> 目标 → 目录（进程内缓存，避免同一请求内重复内省） */
    private array $schemaCache = [];

    /**
     * @param array<string, mixed> $options `form_schema.*` 覆盖项
     */
    public function __construct(array $options = [])
    {
        $this->options = $options;
    }

    public function getNamespace(): string
    {
        return self::NAMESPACE;
    }

    public function isEnabled(): bool
    {
        return (bool) $this->opt('enabled', false);
    }

    /**
     * 单个工具的开关（`form_schema.tools.{name}`，缺省 true）
     */
    public function isToolEnabled(string $tool): bool
    {
        $tools = $this->opt('tools', []);

        if (!is_array($tools) || !array_key_exists($tool, $tools)) {
            return true;
        }

        return (bool) $tools[$tool];
    }

    /**
     * 该提供者当前能提供的工具
     *
     * 四个工具均已实现，各自受 `form_schema.tools.{name}` 开关控制
     * （`generate` 默认关，见类注释）。
     *
     * 注：这里原有一条"`form_generate` 尚未实现，即使开关为 true 也不注册"的注释，
     * 与代码不符 —— `FormGenerateTool` 早已实现（含草案生成、锚点权限判定、
     * `write_mode=assisted` 下的白名单写盘），注释已同步修正。
     *
     * @return ToolInterface[]
     */
    public function getTools(): array
    {
        if (!$this->isEnabled()) {
            return [];
        }

        $tools = [];

        if ($this->isToolEnabled('fields')) {
            $tools[] = new FormFieldsTool($this->options);
        }

        if ($this->isToolEnabled('fill')) {
            $tools[] = new FormFillTool($this->options);
        }

        if ($this->isToolEnabled('validate')) {
            $tools[] = new FormValidateTool($this->options);
        }

        if ($this->isToolEnabled('generate')) {
            $tools[] = new FormGenerateTool($this->options);
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
            if ($tool->getName() === $toolName) {
                return $tool->execute($params);
            }
        }

        return json_encode([
            'code' => 1,
            'msg'  => "Form schema tool '{$toolName}' not found",
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return array{label:string,description:string,version:string}
     */
    public function getMetadata(): array
    {
        return [
            'label'       => 'Form Schema Introspection',
            'description' => '把控制器声明的表单（CrudSchemaTrait::defineForm）变成 AI 可消费的能力（只读、不落库）：'
                . '解释字段 / 智能填充 / 提交前预检 / 生成 defineForm() 草案',
            'version'     => '1.0',
        ];
    }


    /**
     * 生成「表单目标清单 + 精简字段」文本
     *
     * 降级级联（预算从宽到紧，**目标名永远保住**）：
     * 1. 完整表头 + 目标名 + 精简字段（受 `max_fields` / `max_enum_values`）；
     * 2. 完整表头 + 目标名；
     * 3. 短表头 + 目标名；
     * 4. 只有目标名；
     * 5. 硬截断。
     *
     * @param array<string, string>|null $targets 目标名 → 控制器 FQCN（默认由内省结果推导）
     */
    public function toPrompt(?int $maxChars = null, ?array $targets = null): string
    {
        $maxChars = $maxChars ?? (int) $this->opt('max_prompt_chars', 3000);
        $targets  = $targets ?? $this->introspector()->targets();

        if ($targets === [] || $maxChars <= 0) {
            return '';
        }

        $header = "## 可填写表单（form_fields / form_fill / form_validate）\n"
            . "字段名与可选值先用 `form_fields` 取回，不要编造；填充用 `form_fill`（只读、不落库），"
            . "提交永远用 A1 的 save/update（权限与审计同人工）。\n";

        $maxTargets = max(1, (int) $this->opt('max_targets', 20));
        $names      = array_keys($targets);
        $selected   = array_slice($targets, 0, $maxTargets, true);

        $nameLines = array_map(static fn(string $name): string => '- ' . $name, array_keys($selected));

        if (count($names) > $maxTargets) {
            $nameLines[] = sprintf('- …其余 %d 个目标用 form_fields 查看', count($names) - $maxTargets);
        }

        $namesOnly = implode('、', array_keys($selected));

        $short = self::SHORT_HEADER . implode("\n", $nameLines);

        $lean = $header . implode("\n", $nameLines);

        $maxFields = max(1, (int) $this->opt('max_fields', 30));
        $maxEnum   = max(1, (int) $this->opt('max_enum_values', 16));

        $richBlocks = [];
        $used       = mb_strlen($header, 'UTF-8');

        foreach (array_keys($selected) as $name) {
            try {
                $schema = $this->schemaFor($targets[$name]);
            } catch (\Throwable) {
                continue;
            }

            if ($schema->isEmpty()) {
                continue;
            }

            $block = $schema->toPrompt(max(200, $maxChars - $used), $maxFields, $maxEnum);

            if ($block === '') {
                continue;
            }

            $used += mb_strlen($block, 'UTF-8') + 1;
            if ($used > $maxChars) {
                break;
            }

            $richBlocks[] = $block;
        }

        if (count($names) > $maxTargets) {
            $richBlocks[] = sprintf('- …其余 %d 个目标用 form_fields 查看', count($names) - $maxTargets);
        }

        $rich = $header . implode("\n", $richBlocks);

        foreach ([[$rich, $richBlocks !== []], [$lean, true], [$short, true], [$namesOnly, true]] as [$candidate, $usable]) {
            if ($usable && $candidate !== '' && mb_strlen($candidate, 'UTF-8') <= $maxChars) {
                return $candidate;
            }
        }

        return mb_substr($namesOnly, 0, $maxChars, 'UTF-8');
    }

    /**
     * 目标名 → 目录（`protected` 接缝：单测可注入夹具，避免依赖菜单数据）
     */
    protected function schemaFor(string $controller): FormSchema
    {
        if (isset($this->schemaCache[$controller])) {
            return $this->schemaCache[$controller];
        }

        return $this->schemaCache[$controller] = $this->introspector()->forController($controller);
    }

    protected function introspector(): FormSchemaIntrospector
    {
        return $this->introspector ??= new FormSchemaIntrospector($this->options);
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
