<?php
declare(strict_types=1);

namespace wise\agent\skill;

use wise\agent\skill\contract\SkillInterface;
use wise\agent\skill\exception\SkillException;
use wise\agent\skill\parser\MarkdownSkill;
use wise\agent\skill\parser\SkillMdParser;

/**
 * Skill 注册表
 *
 * 管理所有已注册的 Skill。支持内置 Skill 和插件自定义 Skill。
 * 双重注册模式：内存注册 + 数据库持久化（可选）。
 */
class SkillRegistry
{
    protected static ?SkillRegistry $instance = null;

    /** @var array<string, SkillInterface> */
    protected array $skills = [];

    /** @var array<string, array> 数据库配置缓存 */
    protected array $dbConfigs = [];

    /** @var bool 是否已从数据库加载 */
    protected bool $loadedFromDb = false;

    /**
     * 获取单例实例
     */
    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * 重置单例（用于测试或长运行进程）
     */
    public static function reset(): void
    {
        self::$instance = null;
    }

    /**
     * 注册一个 Skill
     *
     * @param SkillInterface $skill
     * @throws SkillException 如果名称冲突
     */
    public function register(SkillInterface $skill): void
    {
        $name = $skill->getName();

        if (isset($this->skills[$name])) {
            throw new SkillException("Skill '{$name}' 已注册，来源: {$this->skills[$name]->getSource()}", $name);
        }

        $this->skills[$name] = $skill;
    }

    /**
     * 批量注册 Skill
     *
     * @param SkillInterface[] $skills
     */
    public function registerMany(array $skills): void
    {
        foreach ($skills as $skill) {
            $this->register($skill);
        }
    }

    /**
     * 按类名注册 Skill
     *
     * @param string $skillClass 完整类名
     * @throws SkillException
     */
    public function registerClass(string $skillClass): void
    {
        if (!class_exists($skillClass)) {
            throw new SkillException("Skill 类 '{$skillClass}' 不存在");
        }

        $instance = new $skillClass();
        if (!$instance instanceof SkillInterface) {
            throw new SkillException("类 '{$skillClass}' 未实现 SkillInterface");
        }

        $this->register($instance);
    }

    /**
     * 按名称获取 Skill
     *
     * @param string $name
     * @return SkillInterface|null
     */
    public function get(string $name): ?SkillInterface
    {
        return $this->skills[$name] ?? null;
    }

    /**
     * 检查 Skill 是否存在
     */
    public function has(string $name): bool
    {
        return isset($this->skills[$name]);
    }

    /**
     * 列出所有 Skill 名称
     *
     * @return string[]
     */
    public function list(): array
    {
        return array_keys($this->skills);
    }

    /**
     * 获取所有已注册 Skill（含名称和实例）
     *
     * @return array<string, SkillInterface>
     */
    public function all(): array
    {
        return $this->skills;
    }

    /**
     * 按分类列出 Skill
     *
     * @param string $category 'content' | 'data' | 'code' | 'business'
     * @return array<string, SkillInterface>
     */
    public function listByCategory(string $category): array
    {
        $result = [];
        foreach ($this->skills as $name => $skill) {
            if ($skill->getCategory() === $category) {
                $result[$name] = $skill;
            }
        }
        return $result;
    }

    /**
     * 按来源列出 Skill
     *
     * @param string $source 'builtin' | 'plugin:cms' | ...
     * @return array<string, SkillInterface>
     */
    public function listBySource(string $source): array
    {
        $result = [];
        foreach ($this->skills as $name => $skill) {
            if ($skill->getSource() === $source) {
                $result[$name] = $skill;
            }
        }
        return $result;
    }

    /**
     * 获取所有 Skill 的概览信息（用于 API 返回）
     *
     * @return array
     */
    public function getOverview(): array
    {
        $categories = [];
        foreach ($this->skills as $name => $skill) {
            $cat = $skill->getCategory();
            if (!isset($categories[$cat])) {
                $categories[$cat] = [];
            }
            $categories[$cat][] = [
                'name'        => $skill->getName(),
                'description' => $skill->getDescription(),
                'source'      => $skill->getSource(),
                'output_format' => $skill->getOutputFormat(),
            ];
        }
        return $categories;
    }

    /**
     * 获取 Skill 数量
     */
    public function count(): int
    {
        return count($this->skills);
    }

    /**
     * 移除 Skill
     */
    public function unregister(string $name): void
    {
        unset($this->skills[$name]);
    }

    /**
     * 从数据库加载 Skill 配置并注册
     *
     * @param string $table 表名
     * @param array $classMap 内置 Skill 类名映射 ['name' => 'class']
     */
    public function loadFromDb(?string $table = null, array $classMap = []): void
    {
        if ($this->loadedFromDb) {
            return;
        }

        $table = $table ?? 'ai_skills';

        try {
            $rows = \think\facade\Db::name($table)
                ->where('is_active', 1)
                ->select()
                ->toArray();

            foreach ($rows as $row) {
                $name = $row['name'];
                if (isset($this->skills[$name])) {
                    continue;
                }

                if (isset($classMap[$name]) && class_exists($classMap[$name])) {
                    $this->registerClass($classMap[$name]);
                    continue;
                }

                $config = is_string($row['config']) ? json_decode($row['config'], true) : $row['config'];
                if (is_array($config)) {
                    $this->dbConfigs[$name] = $config;
                }
            }
        } catch (\Throwable $e) {
        }

        $this->loadedFromDb = true;
    }

    /**
     * 获取数据库配置（用于内联 Skill 执行）
     */
    public function getDbConfig(string $name): ?array
    {
        return $this->dbConfigs[$name] ?? null;
    }

    /**
     * @param string $filePath 文件路径（如 plugin/cms/skills/article.skill.md）
     * @return MarkdownSkill|null 成功返回 Skill，失败返回 null
     */
    public function registerFromMd(string $filePath): ?MarkdownSkill
    {
        if (!file_exists($filePath)) {
            return null;
        }

        $parser = new SkillMdParser();
        $result = $parser->parseFile($filePath);

        if (!$parser->isValid()) {
            return null;
        }

        $name = $result['raw']['name'] ?? '';
        if (empty($name)) {
            return null;
        }

        if (isset($this->skills[$name]) && !($this->skills[$name] instanceof MarkdownSkill)) {
            return null;
        }

        try {
            $skill = new MarkdownSkill($result, $filePath);
            $this->skills[$name] = $skill;
            return $skill;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * 扫描目录中所有匹配模式的文件并注册为 Skill。
     * 支持嵌套子目录。
     *
     * @param string $directory 目录路径
     * @param string $pattern   文件匹配模式（默认 *.skill.md）
     * @return array{loaded: int, skipped: int, errors: string[]} 加载统计
     */
    public function loadFromDirectory(string $directory, string $pattern = '*.skill.md'): array
    {
        $stats = ['loaded' => 0, 'skipped' => 0, 'errors' => []];

        if (!is_dir($directory)) {
            $stats['errors'][] = "目录不存在: {$directory}";
            return $stats;
        }

        $files = $this->findFiles($directory, $pattern);

        foreach ($files as $file) {
            $parser = new SkillMdParser();
            $result = $parser->parseFile($file);

            if (!$parser->isValid()) {
                $stats['skipped']++;
                $stats['errors'][] = "跳过 {$file}: " . implode('; ', $parser->getErrors());
                continue;
            }

            $name = $result['raw']['name'] ?? '';
            if (empty($name)) {
                $stats['skipped']++;
                continue;
            }

            if (isset($this->skills[$name]) && !($this->skills[$name] instanceof MarkdownSkill)) {
                $stats['skipped']++;
                continue;
            }

            try {
                $skill = new MarkdownSkill($result, $file);
                $this->skills[$name] = $skill;
                $stats['loaded']++;
            } catch (\Throwable $e) {
                $stats['skipped']++;
                $stats['errors'][] = "注册失败 {$file}: {$e->getMessage()}";
            }
        }

        return $stats;
    }

    /**
     * 递归查找匹配模式的文件
     *
     * @param string $directory
     * @param string $pattern
     * @return string[]
     */
    protected function findFiles(string $directory, string $pattern): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        $globPattern = $pattern;

        foreach ($iterator as $file) {
            if ($file->isFile() && fnmatch($globPattern, $file->getFilename())) {
                $files[] = $file->getPathname();
            }
        }

        sort($files);
        return $files;
    }
}
