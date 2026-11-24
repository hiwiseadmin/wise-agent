<?php
declare(strict_types=1);

namespace wise\agent\console;

use wise\agent\security\CapabilityAccess;

/**
 * 控制台命令安全护栏（A4 第 1/2/3 层）
 *
 * 四层护栏中的前三层在此落地（第 4 层「进程隔离」由 CommandTool 承担）：
 *
 * ```
 * 第1层  白名单       allow / deny（配置驱动，前缀通配）
 * 第2层  权限         ai.cmd.{name} / plugin:{ident}.cmd.{name} → CapabilityAuthorizer + RBAC
 *                     （权限标识由 ConsoleCommandMeta::getPermission() 生成，
 *                      注册进 ToolRegistry 后自动进入权限树）
 * 第3层  确认/审批     写类 / 管理类命令 requireConfirmation()=true（由审批策略决定 confirm / workflow）
 * ```
 *
 * 判定顺序：**deny 优先于 allow，allow 优先于内置安全前缀兜底**。
 * 全部未命中 → 拒绝（默认拒绝，符合「不做泛化 shell」的边界）。
 */
final class ConsoleCommandGuard
{
    /**
     * 内置安全前缀（配置未覆盖时的兜底放行集）
     *
     * 仅收录「只读 / 无副作用」或「AI 自身能力刷新」类命令，
     * 其余命令必须由使用方在 `wise-agent.console.allow` 显式声明。
     *
     * @var string[]
     */
    private const SAFE_PREFIXES = [
        'wise:agent:discover',
        'wise:agent:console-dump',
        'migrate:status',
        'list',
        'help',
    ];

    /**
     * 管理类（系统级运维）命令模式 → risk = admin
     *
     * 涵盖：清缓存 / 迁移 / 队列重启 / 插件启停 / 权限同步 / 密钥轮换 / 配置缓存 / 内置服务器。
     *
     * @var string[]
     */
    private const ADMIN_PATTERNS = [
        'cache:*',
        'migrate:*',
        'queue:restart',
        'queue:clean',
        'queue:failed',
        'plugin:*',
        'install',
        'install:*',
        'optimize',
        'optimize:*',
        'service:discover',
        'vendor:publish',
        'run',
        'clear',
        'wise:auth:*',
        'wise:api:*',
        'wise:jwt:key:*',
        'wise:login:*',
        '*:key:generate',
        '*:key:rotate',
        'wise:agent:permissions',
        'wise:agent:mcp',
    ];

    /**
     * 写类（产生副作用但可恢复）命令模式 → risk = write
     *
     * @var string[]
     */
    private const WRITE_PATTERNS = [
        'wise:agent:discover',
        '*:discover',
        '*:sync',
        '*:rebuild',
        '*:regenerate',
        '*:rescore',
        '*:refresh',
        '*:generate',
        '*:materialize',
        '*:cleanup',
        '*:clean',
        '*:prune',
        '*:reap',
        '*:push',
        '*:publish*',
        '*:import',
        '*:restore',
        '*:backup:*',
        '*:apply',
        '*:reset',
        '*:fix',
        '*:rescan',
        '*:bootstrap',
        '*:migrate*',
    ];

    /**
     * 只读命令模式 → risk = read
     *
     * @var string[]
     */
    private const READ_PATTERNS = [
        'list',
        'help',
        'version',
        'route:list',
        '*:status',
        '*:stats',
        '*:list',
        '*:info',
        '*:dump',
        '*:check',
        '*:inspect',
        '*:audit*',
        '*:decode',
    ];

    private array $allow;
    private array $deny;
    private bool $writeRequiresConfirmation;

    /**
     * @param string[]|null $allow                     白名单（前缀通配），null 取配置
     * @param string[]|null $deny                      拒绝清单（优先级高于 allow），null 取配置
     * @param bool|null     $writeRequiresConfirmation 写类命令是否强制确认，null 取配置
     */
    public function __construct(?array $allow = null, ?array $deny = null, ?bool $writeRequiresConfirmation = null)
    {
        $this->allow = $allow ?? $this->config('console.allow', self::SAFE_PREFIXES);
        $this->deny  = $deny ?? $this->config('console.deny', []);

        $this->writeRequiresConfirmation = $writeRequiresConfirmation
            ?? (bool) $this->config('console.write_requires_confirmation', true);
    }

    /**
     * 第 1 层：命令是否在白名单内
     */
    public function isAllowed(ConsoleCommandMeta $meta): bool
    {
        if ($meta->isPluginCommand() && !$this->isPluginCommandDeclared($meta->name)) {
            return false;
        }

        if ($this->matches($meta->name, $this->deny)) {
            return false;
        }

        if ($this->matches($meta->name, $this->allow)) {
            return true;
        }

        foreach (self::SAFE_PREFIXES as $prefix) {
            if ($prefix === $meta->name || str_starts_with($meta->name, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 断言命令可执行，否则抛异常（工具执行入口使用）
     *
     * @throws \RuntimeException
     */
    public function assertAllowed(ConsoleCommandMeta $meta): void
    {
        if (!$this->isAllowed($meta)) {
            throw new \RuntimeException(
                "Command '{$meta->name}' is not allowed by the console allowlist (wise-agent.console.allow / plugin_commands)."
            );
        }
    }

    /**
     * 第 3 层：是否需要人工确认
     *
     * 只读命令放行；写类命令在 `write_requires_confirmation=true` 时确认；
     * 管理类命令始终确认（不可被配置放宽，只能由审批策略加严为 workflow）。
     */
    public function requireConfirmation(ConsoleCommandMeta $meta): bool
    {
        $risk = $this->riskOf($meta);

        if ($risk === ConsoleCommandMeta::RISK_READ) {
            return false;
        }

        if ($risk === ConsoleCommandMeta::RISK_ADMIN) {
            return true;
        }

        return $this->writeRequiresConfirmation;
    }

    /**
     * 风险分级（admin → write → read；未知命令保守归为 write）
     */
    public function riskOf(ConsoleCommandMeta $meta): string
    {
        $name = $meta->name;

        if ($this->matches($name, self::ADMIN_PATTERNS)) {
            return ConsoleCommandMeta::RISK_ADMIN;
        }

        if ($this->matches($name, self::WRITE_PATTERNS)) {
            return ConsoleCommandMeta::RISK_WRITE;
        }

        if ($this->matches($name, self::READ_PATTERNS)) {
            return ConsoleCommandMeta::RISK_READ;
        }

        return ConsoleCommandMeta::RISK_WRITE;
    }

    /**
     * 风险等级 → 能力访问类型（权限树归类）
     */
    public function accessOf(ConsoleCommandMeta $meta): string
    {
        return match ($this->riskOf($meta)) {
            ConsoleCommandMeta::RISK_READ  => CapabilityAccess::READ,
            ConsoleCommandMeta::RISK_ADMIN => CapabilityAccess::ADMIN,
            default                        => CapabilityAccess::WRITE,
        };
    }

    /**
     * 风险等级 → 能力风险等级（审批策略输入）
     */
    public function riskLevelOf(ConsoleCommandMeta $meta): string
    {
        return match ($this->riskOf($meta)) {
            ConsoleCommandMeta::RISK_READ  => CapabilityAccess::RISK_LOW,
            ConsoleCommandMeta::RISK_ADMIN => CapabilityAccess::RISK_HIGH,
            default                        => CapabilityAccess::RISK_MEDIUM,
        };
    }

    /**
     * 回填治理结论到元数据（供 dump 命令与工具注册侧共用）
     */
    public function annotate(ConsoleCommandMeta $meta): ConsoleCommandMeta
    {
        $meta->allowed = $this->isAllowed($meta);
        $meta->risk    = $this->riskOf($meta);

        return $meta;
    }

    /**
     * 前缀通配匹配：支持 `*`（全通配）、`wise:*`、`wise:agent:*`、`*:status`、精确匹配
     */
    public function matches(string $name, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (!is_string($pattern) || $pattern === '') {
                continue;
            }

            if ($pattern === '*' || $pattern === $name) {
                return true;
            }

            if (str_contains($pattern, '*')) {
                $regex = '#^' . str_replace('\*', '.*', preg_quote($pattern, '#')) . '$#';
                if (preg_match($regex, $name) === 1) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * 插件命令是否已在 plugin_commands 中显式声明
     *
     * 配置形态：`['plugin:match3game:daily' => true]` 或 `['plugin:match3game:daily']`
     * （键即命令名，或值即命令名）。
     */
    public function isPluginCommandDeclared(string $name): bool
    {
        $declared = (array) $this->config('console.plugin_commands', []);

        foreach ($declared as $key => $value) {
            $declaredName = is_int($key) ? $value : $key;
            if (!is_string($declaredName) || $declaredName === '') {
                continue;
            }

            if ($declaredName === $name) {
                return true;
            }

            if (str_contains($declaredName, '*') && $this->matches($name, [$declaredName])) {
                return true;
            }
        }

        return false;
    }

    /**
     * 读取配置，容器不可用时回退默认值（便于脱离框架的单元测试）
     */
    private function config(string $key, mixed $default): mixed
    {
        if (!function_exists('config')) {
            return $default;
        }

        try {
            $value = config('wise-agent.' . $key, $default);

            return $value ?? $default;
        } catch (\Throwable) {
            return $default;
        }
    }
}
