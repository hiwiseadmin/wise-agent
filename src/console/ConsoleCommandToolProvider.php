<?php
declare(strict_types=1);

namespace wise\agent\console;

use think\facade\Event;
use wise\agent\contract\RequestContext;
use wise\agent\contract\ToolInterface;
use wise\agent\contract\ToolProviderInterface;
use wise\agent\event\ToolExecute;
use wise\agent\tool\ToolRegistry;

/**
 * 控制台命令工具提供者＼�
 *
 * 复用插件 AI 接入的 `ToolProviderInterface` 契约：
 *   - `getNamespace()` 返回 `console`，用于逻辑分组与清理；
 *   - `getTools()` 批量产出「命令 → 工具」（内省 → 护栏过滤 → 生成）。
 *
 * ⚠️ **注册命名空间说明**：
 * `ToolRegistry::execute()` 按**精确键**查找，而 `getToolSchemas()` 把
 * `ToolInterface::getName()` 作为 LLM function name 下发——二者必须一致才能回调成功。
 * 由于 `ToolRegistry::register($tool, $name, $namespace)` 会为命名空间工具加上
 * `{namespace}.` 前缀（如 `console.cmd_wise_agent_discover`），与 LLM 拿到的
 * `cmd_wise_agent_discover` 不一致，因此实际注册走 {@see self::registerTo()}：
 * 以 `builtin` 键注册，靠 `cmd_` 前缀完成逻辑隔离（与 `wa_menu_tree` 等框架工具同策略）。
 * 这与 `MenuActionIntrospector` / `SkillManager` 的既有做法保持一致。
 *
 * 门禁：`wise-agent.console.enabled`（默认 false，需显式开启才内省 / 注册）。
 *
 * 两种工具面（`console.two_stage`）：
 *   - false（默认）：一条命令 = 一个 `cmd_xxx` function，schema 全量下发；
 *   - true          ：只下发 `cmd_search` + `cmd_run`，具体 `cmd_xxx` 以 hidden 方式
 *                     留在注册表（供权限校验/执行，但不下发给模型）。
 *                     模型先检索命令再执行，每步省约 2.8k token。
 */
final class ConsoleCommandToolProvider implements ToolProviderInterface
{
    /** 提供者命名空间（逻辑分组 / 权限归属） */
    public const NAMESPACE = 'console';

    /** 工具名前缀（LLM 侧可见的隔离标识） */
    public const TOOL_PREFIX = 'cmd_';

    private ConsoleCommandGuard $guard;
    private ConsoleCommandIntrospector $introspector;

    /** @var array<string, ConsoleCommandMeta>|null 已通过白名单的命令（key = 命令名），懒加载缓存 */
    private ?array $allowedMetas = null;

    public function __construct(
        ?ConsoleCommandGuard $guard = null,
        ?ConsoleCommandIntrospector $introspector = null
    ) {
        $this->guard        = $guard ?? new ConsoleCommandGuard();
        $this->introspector = $introspector ?? new ConsoleCommandIntrospector();
    }

    /**
     * 是否启用两段式工具面（`console.two_stage`）
     *
     * 关闭（默认）＝ 一条命令一个 function，schema 全量下发（本机实测约 3.1k token/步）；
     * 开启 ＝ 只下发 `cmd_search` + `cmd_run`（约 0.3k token/步），具体命令
     * 以 hidden 方式留在注册表里，供 ToolSecurityCheck 校验与执行。
     */
    public function isTwoStage(): bool
    {
        return (bool) $this->config('console.two_stage', false);
    }

    public function getNamespace(): string
    {
        return self::NAMESPACE;
    }

    /**
     * 批量注册命令工具到 ToolRegistry（builtin 键，保证 LLM 可回调）
     *
     * @return int 实际注册的工具数量
     */
    public function registerTo(?ToolRegistry $registry = null): int
    {
        $registry = $registry ?? ToolRegistry::instance();
        $count    = 0;

        if ($this->isEnabled() && $this->isTwoStage()) {
            foreach ($this->allowedMetas() as $meta) {
                $tool = new ConsoleCommandTool($meta, $this->guard);
                $registry->unregister($tool->getName());
                $registry->register($tool, $tool->getName(), 'builtin');
                $registry->hide($tool->getName());
                $count++;
            }
        }

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
     * 生成全部通过白名单的命令工具
     *
     * @return ToolInterface[]
     */
    public function getTools(): array
    {
        if (!$this->isEnabled()) {
            return [];
        }

        if ($this->isTwoStage()) {
            $allowed = $this->allowedMetas();

            if ($allowed === []) {
                return [];
            }

            return [
                new ConsoleCommandRunTool($this->guard, $allowed),
                new ConsoleCommandSearchTool(
                    array_values($allowed),
                    max(1, (int) $this->config('console.two_stage_search_limit', 20))
                ),
            ];
        }

        $allowed = array_values($this->allowedMetas());

        $metas = self::limitMetas($allowed, (int) $this->config('console.max_tools', 0));

        $tools = [];
        $seen  = [];

        foreach ($metas as $meta) {
            $name = $meta->getToolName();
            if ($name === self::TOOL_PREFIX || isset($seen[$name])) {
                continue;
            }
            $seen[$name] = true;

            $tools[] = new ConsoleCommandTool($meta, $this->guard);
        }

        return $tools;
    }

    /**
     * 按数量上限裁剪命令清单（`console.max_tools`，0 = 不限）
     *
     * 超限时按风险从低到高保留（read → write → admin），同风险保持内省顺序，
     * 保证「结果确定、只读能力优先」，不会因为命令名排序而随机丢掉关键命令。
     *
     * @param ConsoleCommandMeta[] $metas
     * @return ConsoleCommandMeta[]
     */
    public static function limitMetas(array $metas, int $max): array
    {
        $metas = array_values($metas);

        if ($max <= 0 || count($metas) <= $max) {
            return $metas;
        }

        $weights = [
            ConsoleCommandMeta::RISK_READ  => 0,
            ConsoleCommandMeta::RISK_WRITE => 1,
            ConsoleCommandMeta::RISK_ADMIN => 2,
        ];

        $indexed = [];
        foreach ($metas as $index => $meta) {
            $indexed[] = [
                'weight' => $weights[$meta->risk] ?? 1,
                'index'  => $index,
                'meta'   => $meta,
            ];
        }

        usort($indexed, static fn(array $a, array $b): int => [$a['weight'], $a['index']] <=> [$b['weight'], $b['index']]);

        return array_map(
            static fn(array $row): ConsoleCommandMeta => $row['meta'],
            array_slice($indexed, 0, $max)
        );
    }

    /**
     * 获取已回填治理结论（risk / allowed）的命令元数据
     *
     * @param bool $force 强制重建内省缓存
     * @return array<string, ConsoleCommandMeta>
     */
    public function getCommandMetas(bool $force = false): array
    {
        $metas = $this->introspector->resolve($force);

        foreach ($metas as $meta) {
            $this->guard->annotate($meta);
        }

        return $metas;
    }

    /**
     * 已通过白名单的命令（key = 命令名，带内省结果缓存）
     *
     * @return array<string, ConsoleCommandMeta>
     */
    private function allowedMetas(): array
    {
        if ($this->allowedMetas !== null) {
            return $this->allowedMetas;
        }

        $map = [];
        foreach ($this->getCommandMetas() as $meta) {
            if ($meta->allowed) {
                $map[$meta->name] = $meta;
            }
        }

        return $this->allowedMetas = $map;
    }

    /**
     * 按名执行命令工具（ToolProviderInterface 契约入口）
     *
     * @param string         $toolName 工具名（`cmd_xxx`）或命令名（`wise:agent:discover`）
     * @param array          $params
     * @param RequestContext $context
     */
    public function execute(string $toolName, array $params, RequestContext $context): mixed
    {
        foreach ($this->getTools() as $tool) {
            if ($tool->getName() === $toolName || $tool->getMeta()->name === $toolName) {
                $before = new ToolExecute(ToolExecute::PHASE_BEFORE, $toolName, $params);
                Event::trigger($before);

                if ($before->skip) {
                    return $before->mockResult ?? json_encode(['skipped' => true], JSON_UNESCAPED_UNICODE);
                }

                $result = $tool->execute($params);

                $after = new ToolExecute(ToolExecute::PHASE_AFTER, $toolName, $params, $result);
                Event::trigger($after);

                return $after->result;
            }
        }

        return json_encode([
            'code' => 1,
            'msg'  => "Console command tool '{$toolName}' not found or not allowed",
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return array{label:string,description:string,version:string}
     */
    public function getMetadata(): array
    {
        return [
            'label'       => 'Framework Console Commands',
            'description' => '将 `php think` 框架 / wise-* 包 / 插件命令内省为 AI 可调用工具（白名单 + RBAC + 审批护栏）',
            'version'     => '1.0',
        ];
    }

    private function isEnabled(): bool
    {
        return (bool) $this->config('console.enabled', false);
    }

    private function config(string $key, mixed $default): mixed
    {
        if (!function_exists('config')) {
            return $default;
        }

        try {
            return config('wise-agent.' . $key, $default) ?? $default;
        } catch (\Throwable) {
            return $default;
        }
    }
}
