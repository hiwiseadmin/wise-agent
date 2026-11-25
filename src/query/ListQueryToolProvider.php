<?php
declare(strict_types=1);

namespace wise\agent\query;

use wise\agent\contract\RequestContext;
use wise\agent\contract\ToolInterface;
use wise\agent\contract\ToolProviderInterface;
use wise\agent\query\executor\ListQueryExecutorInterface;
use wise\agent\tool\ToolRegistry;

/**
 * 列表查询工具提供者（A3-3）
 *
 * 注册两个工具：
 * - `lq_query`：自然语言 → 受约束查询参数 → 执行（默认委托 A1）；
 * - `lq_fields`：按需拉取目标的完整维度目录（不占常驻 Prompt）。
 *
 * 由 {@see ListQueryContextProvider} 注入 system 上下文。
 *
 * 与 `ListQueryTool` 同策略：**不加 `final`**，`catalogFor()` 是供离线单测覆写的接缝。
 */
class ListQueryToolProvider implements ToolProviderInterface
{
    /** 提供者命名空间（逻辑分组） */
    public const NAMESPACE = 'list_query';

    /** 极小预算下的短表头（保住目标名，说明文字省掉） */
    private const SHORT_HEADER = "## 可查询列表（lq_query，维度用 lq_fields）\n";

    /** @var array<string, mixed> */
    private array $options;

    private ListQueryTargetResolver $resolver;

    private ?ListQueryExecutorInterface $executor;

    /** @var array<string, string> 目标 → 精简维度块（进程内缓存，避免同一请求内重复建目录） */
    private array $blockCache = [];

    /**
     * @param array<string, mixed> $options `list_query.*` 覆盖项
     */
    public function __construct(
        array $options = [],
        ?ListQueryTargetResolver $resolver = null,
        ?ListQueryExecutorInterface $executor = null
    ) {
        $this->options  = $options;
        $this->resolver = $resolver ?? new ListQueryTargetResolver(null, $options);
        $this->executor = $executor;
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
     * @return ToolInterface[]
     */
    public function getTools(): array
    {
        if (!$this->isEnabled()) {
            return [];
        }

        $query = new ListQueryTool($this->options, $this->resolver, $this->executor);

        return [
            $query,
            new ListQueryFieldsTool($this->options, $query, $this->resolver),
        ];
    }

    /**
     * 注册到 ToolRegistry（builtin 键，保证 LLM 回调名与注册键一致）
     *
     * ⚠️ 与 A1/A4 同一坑：`ToolRegistry::execute()` 按精确键查找，而 `getSchemas()` 用
     * `getName()` 下发给 LLM —— 必须用 builtin 键注册，靠 `lq_` 前缀做逻辑隔离。
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
            'msg'  => "List query tool '{$toolName}' not found",
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return array{label:string,description:string,version:string}
     */
    public function getMetadata(): array
    {
        return [
            'label'       => 'Natural Language List Query',
            'description' => '把自然语言编译为框架既有列表查询参数（只读）：字段/操作符/枚举/时间词全部受约束，执行默认委托 A1 菜单动作（权限与审计同人工操作一致）',
            'version'     => '1.0',
        ];
    }

    public function resolver(): ListQueryTargetResolver
    {
        return $this->resolver;
    }


    /**
     * 生成「目标清单 + 精简维度」文本
     *
     * 预算规则（验收 10）：
     * 1. 逐个目标产出「目标名 + 精简维度」（枚举受 `max_enum_values`、字段数受 `max_filter_fields`）；
     * 2. 目标数量受 `max_targets` 限制；
     * 3. 总长度超过 `max_prompt_chars` → **降级为只保留目标名**（不再注入维度）；
     * 4. 仍超长 → 硬截断。
     *
     * @param array<string, ListQueryTarget>|null $targets
     */
    public function toPrompt(?int $maxChars = null, ?array $targets = null): string
    {
        $targets  = $targets ?? $this->resolver->resolve();
        $maxChars = $maxChars ?? (int) $this->opt('max_prompt_chars', 4000);

        if ($targets === [] || $maxChars <= 0) {
            return '';
        }

        $header = "## 可查询列表（自然语言列表查询）\n"
            . "用 `lq_query` 按自然语言查询；字段名/枚举候选/关联字段用 `lq_fields` 按需拉取，不要编造。"
            . "时间条件用 time_expr（如 上月），不要自己算日期。\n";

        $maxTargets = max(1, (int) $this->opt('max_targets', 20));
        $names      = array_keys($targets);
        $selected   = array_slice($targets, 0, $maxTargets, true);

        $nameLines = array_map(
            static fn(ListQueryTarget $t): string => '- ' . $t->name . ($t->title !== '' ? "（{$t->title}）" : ''),
            $selected
        );

        if (count($names) > $maxTargets) {
            $nameLines[] = sprintf('- …其余 %d 个目标用 lq_fields 查看', count($names) - $maxTargets);
        }

        $lean = $header . implode("\n", $nameLines);

        if (mb_strlen($lean, 'UTF-8') > $maxChars) {
            $lean = self::SHORT_HEADER . implode("\n", $nameLines);
        }

        $richBlocks = [];
        $used       = mb_strlen($header, 'UTF-8');

        foreach (array_keys($selected) as $name) {
            $block = $this->targetBlock($targets[$name], max(200, $maxChars - $used));

            $used += mb_strlen($block, 'UTF-8') + 1;
            if ($used > $maxChars) {
                break;
            }

            $richBlocks[] = $block;
        }

        if (count($names) > $maxTargets) {
            $richBlocks[] = sprintf('- …其余 %d 个目标用 lq_fields 查看', count($names) - $maxTargets);
        }

        $rich = $header . implode("\n", $richBlocks);

        $text = ($richBlocks !== [] && mb_strlen($rich, 'UTF-8') <= $maxChars) ? $rich : $lean;

        if (mb_strlen($text, 'UTF-8') > $maxChars) {
            $text = mb_substr($text, 0, $maxChars, 'UTF-8');
        }

        return $text;
    }

    /**
     * 目标的字段目录（`protected` 接缝：单测可注入以保证 Prompt 预算行为可离线验证）
     */
    protected function catalogFor(ListQueryTarget $target): QueryFieldCatalog
    {
        return QueryFieldCatalog::forController(
            $target->controller,
            $this->options,
            null,
            $target->aliases,
            $target->synonyms === [] ? (array) $this->opt('synonyms', []) : $target->synonyms
        );
    }

    /**
     * 单个目标的精简维度块（去掉目录自带的「目标名 + 表名」首行，避免重复）
     */
    private function targetBlock(ListQueryTarget $target, int $budget): string
    {
        if (isset($this->blockCache[$target->name])) {
            return $this->blockCache[$target->name];
        }

        $head = sprintf('- **%s**%s', $target->name, $target->title !== '' ? "（{$target->title}）" : '');

        $catalogPrompt = '';

        try {
            $catalogPrompt = $this->catalogFor($target)->toPrompt($budget, (int) $this->opt('max_filter_fields', 20));
        } catch (\Throwable) {
            return $this->blockCache[$target->name] = $head . '（维度不可用）';
        }

        $lines = explode("\n", $catalogPrompt);
        array_shift($lines);

        return $this->blockCache[$target->name] = $head . ($lines === [] ? '' : "\n" . implode("\n", $lines));
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
