<?php
declare(strict_types=1);

namespace wise\agent\console;

use think\App;
use think\Console;
use think\console\Command;
use wise\support\tools\CommandTool;

/**
 * think 控制台命令内省器＼�
 *
 * **为什么以 `Console::all()` 为唯一事实来源**：
 * 插件命令由 `PluginServiceProvider` 在 `Console::starting()` 阶段动态
 * `addCommand` 进来（前缀 `{ident}:`），文件扫描会漏掉它们；
 * 且 `Service::commands()` 本身也是 `Console::starting()` 回调——
 * 在 HTTP 进程里 `Console` 实例根本不会被创建，回调永不执行。
 *
 * 因此：
 *   - **CLI 进程**（正在执行 think 命令）→ 直接 `Console::all()`，此时命令注册表完整；
 *   - **HTTP 进程** → 借 CLI 子进程 `php think wise:agent:console-dump --json`
 *     导出纯 JSON，再回填本地缓存（**绝不解析 `php think list` 的格式化文本**）。
 *
 * 缓存：`runtime/wise-agent/console_commands.json`（TTL 见 `console.cache_ttl`）。
 * 复用 ProviderCache 的失效标记 `cache_invalidated.flag`，因此
 * 插件安装 / 卸载（ProviderCache::invalidate()）会自动让本缓存失效。
 */
final class ConsoleCommandIntrospector
{
    /** 缓存文件名（相对 runtime/wise-agent/） */
    private const CACHE_FILE = 'console_commands.json';

    /** 进程内缓存（同一请求内避免重复子进程） */
    private static ?array $memoryCache = null;

    private ?Console $console;

    public function __construct(?Console $console = null)
    {
        $this->console = $console;
    }

    /**
     * 内省当前进程的命令注册表（仅在 CLI 进程调用才有完整命令集）
     *
     * @return array<string, ConsoleCommandMeta> name => meta
     */
    public function introspect(): array
    {
        $console = $this->resolveConsole();
        if ($console === null) {
            return [];
        }

        $metas  = [];
        $seen   = [];

        foreach ($console->all() as $key => $command) {
            if (is_string($command)) {
                $command = $this->makeCommand($command);
            }

            if (!$command instanceof Command) {
                continue;
            }

            $name = $command->getName();
            if ($name === '' && is_string($key) && $key !== '') {
                $name = $key;
            }
            if ($name === '' || isset($seen[$name])) {
                continue;
            }
            $seen[$name] = true;

            try {
                $meta = ConsoleCommandMeta::fromCommand($name, $command);
            } catch (\Throwable $e) {
                continue;
            }

            $metas[$name] = new ConsoleCommandMeta(
                name: $meta->name,
                description: $meta->description,
                source: $this->detectSource($name, $command),
                risk: $meta->risk,
                supportsJson: $meta->supportsJson,
                arguments: $meta->arguments,
                options: $meta->options,
                help: $meta->help,
            );
        }

        ksort($metas);

        return $metas;
    }

    /**
     * 获取命令元数据（自动选择进程内内省 / CLI 子进程 + 缓存）
     *
     * @param bool $force 强制跳过缓存
     * @return array<string, ConsoleCommandMeta>
     */
    public function resolve(bool $force = false): array
    {
        if (!$force && self::$memoryCache !== null) {
            return self::$memoryCache;
        }

        if ($this->runningInConsole()) {
            $metas = $this->introspect();
            if ($metas !== []) {
                $this->writeCache($metas);

                return self::$memoryCache = $metas;
            }
        }

        if (!$force) {
            $cached = $this->readCache();
            if ($cached !== null) {
                return self::$memoryCache = $cached;
            }
        }

        $metas = $this->introspectViaCli();
        if ($metas !== []) {
            $this->writeCache($metas);

            return self::$memoryCache = $metas;
        }

        return [];
    }

    /**
     * 通过 CLI 子进程内省（HTTP 进程路径）
     *
     * @return array<string, ConsoleCommandMeta>
     */
    public function introspectViaCli(int $timeout = 60): array
    {
        $cmdline = $this->buildDumpCmdline();

        try {
            $result = (new CommandTool())->execute($cmdline, [
                'timeout'           => $timeout,
                'max_output_length' => 4 * 1024 * 1024,
                'log_execution'     => false,
            ]);
        } catch (\Throwable $e) {
            return [];
        }

        if (!($result['success'] ?? false)) {
            return [];
        }

        $payload = ConsoleResult::decodeJson((string) ($result['output'] ?? ''));
        if ($payload === null) {
            return [];
        }

        $commands = $payload['data']['commands'] ?? $payload['commands'] ?? null;
        if (!is_array($commands)) {
            return [];
        }

        return self::fromArrayList($commands);
    }

    /**
     * 强制重建缓存并返回最新元数据
     *
     * @return array<string, ConsoleCommandMeta>
     */
    public function refresh(): array
    {
        self::$memoryCache = null;
        $this->clearCache();

        return $this->resolve(true);
    }

    /**
     * 清空缓存（含内存缓存）
     */
    public function clearCache(): void
    {
        self::$memoryCache = null;

        $file = $this->cacheFile();
        if ($file !== null && is_file($file)) {
            @unlink($file);
        }
    }

    /**
     * 重置进程内缓存（长驻进程 / 测试用）
     */
    public static function resetMemoryCache(): void
    {
        self::$memoryCache = null;
    }

    /**
     * 从序列化列表构建元数据映射
     *
     * @param array $list CLI dump 的 commands 数组
     * @return array<string, ConsoleCommandMeta>
     */
    public static function fromArrayList(array $list): array
    {
        $metas = [];

        foreach ($list as $key => $item) {
            if (!is_array($item)) {
                continue;
            }

            $meta = ConsoleCommandMeta::fromArray($item);
            if ($meta->name === '') {
                continue;
            }

            $metas[$meta->name] = $meta;
        }

        ksort($metas);

        return $metas;
    }

    /**
     * 序列化元数据为可缓存数组
     *
     * @param array<string, ConsoleCommandMeta> $metas
     * @return array<int, array>
     */
    public static function toArrayList(array $metas): array
    {
        $list = [];
        foreach ($metas as $meta) {
            $list[] = $meta->toArray();
        }

        return $list;
    }


    /**
     * 实例化按类名注册的命令（与 think\Console::getCommand() 同一语义）
     */
    private function makeCommand(string $class): ?Command
    {
        if (!class_exists($class) || !is_subclass_of($class, Command::class)) {
            return null;
        }

        try {
            $instance = function_exists('app') ? app()->invokeClass($class) : new $class();
        } catch (\Throwable) {
            try {
                $instance = new $class();
            } catch (\Throwable) {
                return null;
            }
        }

        return $instance instanceof Command ? $instance : null;
    }

    private function resolveConsole(): ?Console
    {
        if ($this->console !== null) {
            return $this->console;
        }

        if (!function_exists('app')) {
            return null;
        }

        try {
            $console = app()->get(Console::class);
        } catch (\Throwable) {
            return null;
        }

        return $console instanceof Console ? $console : null;
    }

    private function runningInConsole(): bool
    {
        if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') {
            return true;
        }

        try {
            return function_exists('app') && app()->runningInConsole();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * 判定命令来源：plugin:{ident} > package:{pkg} > framework
     */
    private function detectSource(string $name, Command $command): string
    {
        $first = explode(':', $name)[0];

        $pluginDir = $this->pluginPath($first);
        if ($pluginDir !== null && is_file($pluginDir . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'console.php')) {
            return 'plugin:' . $first;
        }

        try {
            $file = (new \ReflectionClass($command))->getFileName();
        } catch (\Throwable) {
            $file = false;
        }

        if (is_string($file) && $file !== '') {
            if (preg_match('#[/\\\\]packages[/\\\\]wiseadmin[/\\\\]([^/\\\\]+)[/\\\\]#', $file, $m) === 1) {
                return 'package:' . $m[1];
            }
        }

        return ConsoleCommandMeta::SOURCE_FRAMEWORK;
    }

    private function rootPath(string $relative = ''): ?string
    {
        if (!function_exists('app')) {
            return null;
        }

        try {
            $app = app();
            $root = $app instanceof App ? $app->getRootPath() : null;
        } catch (\Throwable) {
            return null;
        }

        if ($root === null) {
            return null;
        }

        return $relative === '' ? $root : $root . ltrim($relative, '/\\');
    }

    /**
     * 插件目录路径（优先复用框架 plugin_path()，宿主不同布局时保持一致）
     */
    private function pluginPath(string $ident): ?string
    {
        if (function_exists('plugin_path')) {
            try {
                return rtrim(plugin_path(), '/\\') . DIRECTORY_SEPARATOR . $ident;
            } catch (\Throwable) {
            }
        }

        $root = $this->rootPath();

        return $root === null ? null : $root . 'plugins' . DIRECTORY_SEPARATOR . $ident;
    }

    /**
     * 构造内省子进程命令（绝对路径调用 think，与当前工作目录无关）
     */
    private function buildDumpCmdline(): string
    {
        $root = $this->rootPath();
        $php  = defined('PHP_BINARY') && PHP_BINARY !== '' ? PHP_BINARY : 'php';

        if ($root !== null) {
            return escapeshellarg($php) . ' ' . escapeshellarg($root . 'think') . ' wise:agent:console-dump --json';
        }

        return escapeshellarg($php) . ' think wise:agent:console-dump --json';
    }


    private function cacheFile(): ?string
    {
        $runtime = $this->runtimePath();
        if ($runtime === null) {
            return null;
        }

        $dir = $runtime . 'wise-agent' . DIRECTORY_SEPARATOR;

        return $dir . self::CACHE_FILE;
    }

    private function runtimePath(): ?string
    {
        if (!function_exists('app')) {
            return null;
        }

        try {
            $app = app();
            return $app instanceof App ? $app->getRuntimePath() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * 读取缓存（TTL + ProviderCache 失效标记双重校验）
     *
     * @return array<string, ConsoleCommandMeta>|null
     */
    private function readCache(): ?array
    {
        $file = $this->cacheFile();
        if ($file === null || !is_file($file)) {
            return null;
        }

        if (is_file(dirname($file) . DIRECTORY_SEPARATOR . 'cache_invalidated.flag')) {
            return null;
        }

        $raw = @file_get_contents($file);
        if ($raw === false || $raw === '') {
            return null;
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return null;
        }

        $ttl         = (int) $this->config('console.cache_ttl', 3600);
        $discoveredAt = (int) ($data['discovered_at'] ?? 0);

        if ($ttl > 0 && time() - $discoveredAt > $ttl) {
            return null;
        }

        $commands = $data['commands'] ?? null;
        if (!is_array($commands)) {
            return null;
        }

        return self::fromArrayList($commands);
    }

    /**
     * @param array<string, ConsoleCommandMeta> $metas
     */
    private function writeCache(array $metas): void
    {
        $file = $this->cacheFile();
        if ($file === null) {
            return;
        }

        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return;
        }

        $payload = json_encode([
            'discovered_at' => time(),
            'commands'      => self::toArrayList($metas),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($payload === false) {
            return;
        }

        @file_put_contents($file, $payload, LOCK_EX);
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
