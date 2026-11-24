<?php
declare(strict_types=1);

namespace wise\agent\console;

use wise\agent\security\CapabilityAccess;
use wise\agent\tool\BaseTool;

/**
 * 命令检索工具（两段式工具面的第一段）
 *
 * 背景：把每条 `php think` 命令都做成一个独立 function 时，14 条命令的 schema
 * 就吃掉约 3.1k token，而且**每一步**都要重发。两段式把工具面压成两个小工具：
 *
 * ```
 * cmd_search(keyword)  →  [{name, risk, params[]}]   ← 本类，只回目录，不回完整 schema
 * cmd_run(command, arguments) → 执行（参数由服务端按 ConsoleCommandMeta 校验）
 * ```
 *
 * 为什么不用 RAG / wiki：命令的「参数名 + 必填 + 枚举」必须精确，检索回来的自然语言
 * 文本无法保证确定性；而且检索结果每次不同，会破坏 Provider 的前缀缓存
 * （缓存命中价约为输入价 1/10，稳定前缀反而更省钱）。见 docs 讨论记录。
 *
 * 安全：只暴露**已通过白名单**（allow / deny）的命令；权限与审批仍在执行侧
 * （`ai.cmd.{name}`），本工具本身不产生新的权限面（`getPermission()` 返回空串）。
 */
final class ConsoleCommandSearchTool extends BaseTool
{
    /** LLM 侧可见的工具名 */
    public const NAME = 'cmd_search';

    /** 描述文本截断长度（保持返回紧凑，这正是本工具存在的意义） */
    private const DESC_LIMIT = 120;

    /**
     * @param ConsoleCommandMeta[] $metas       已通过白名单的命令元数据
     * @param int                  $defaultLimit 默认返回条数
     * @param int                  $maxLimit     最大返回条数
     */
    public function __construct(
        private readonly array $metas,
        private readonly int $defaultLimit = 20,
        private readonly int $maxLimit = 50
    ) {
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public function getDescription(): string
    {
        return '检索可执行的 php think 命令（返回命令名、风险等级与参数名清单）。'
            . '**先用本工具找到命令，再用 cmd_run 执行**；直接用猜想出来的命令名会被拒绝。'
            . 'keyword 支持多个关键词（空格分隔，全部匹配）。';
    }

    public function getParameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'keyword' => [
                    'type'        => 'string',
                    'description' => '关键词，如 "migrate status"、"菜单同步"、"缓存"; 留空则按风险从低到高列出前 N 条',
                ],
                'limit'   => [
                    'type'        => 'integer',
                    'description' => '返回条数（默认 ' . $this->defaultLimit . '）',
                ],
            ],
            'required'   => ['keyword'],
        ];
    }

    public function getAccess(): string
    {
        return CapabilityAccess::READ;
    }

    public function getRiskLevel(): string
    {
        return CapabilityAccess::RISK_LOW;
    }

    public function execute(array $arguments): string
    {
        $keyword = trim((string) ($arguments['keyword'] ?? ''));
        $limit   = (int) ($arguments['limit'] ?? $this->defaultLimit);
        $limit   = $limit > 0 ? min($limit, max(1, $this->maxLimit)) : $this->defaultLimit;

        $matched = $this->match($keyword);

        $weights = [
            ConsoleCommandMeta::RISK_READ  => 0,
            ConsoleCommandMeta::RISK_WRITE => 1,
            ConsoleCommandMeta::RISK_ADMIN => 2,
        ];
        usort($matched, static function (array $a, array $b) use ($weights): int {
            return [$weights[$a['meta']->risk] ?? 1, $a['index']] <=> [$weights[$b['meta']->risk] ?? 1, $b['index']];
        });

        $rows = [];
        foreach (array_slice($matched, 0, $limit) as $hit) {
            $meta   = $hit['meta'];
            $params = [];
            foreach ($meta->arguments as $declaration) {
                $params[] = [
                    'name'     => (string) ($declaration['name'] ?? ''),
                    'required' => !empty($declaration['required']),
                ];
            }
            foreach ($meta->options as $declaration) {
                if (($declaration['name'] ?? '') === 'json') {
                    continue;
                }
                $params[] = [
                    'name'     => (string) ($declaration['name'] ?? ''),
                    'required' => false,
                ];
            }

            $rows[] = [
                'name'   => $meta->name,
                'risk'   => $meta->risk,
                'desc'   => $this->truncate($meta->description),
                'params' => $params,
            ];
        }

        return (string) json_encode([
            'count'    => count($matched),
            'returned' => count($rows),
            'commands' => $rows,
            'hint'     => $rows === []
                ? '没有匹配的命令；换个关键词，或留空查看全部可执行命令。'
                : '用 cmd_run 执行：{"command":"<name>","arguments":{...}}',
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * 关键词匹配（全部 token 命中 name / description / help 才算匹配）
     *
     * @return array<int, array{meta: ConsoleCommandMeta, index: int}>
     */
    private function match(string $keyword): array
    {
        $tokens = $keyword === '' ? [] : (preg_split('/\s+/u', mb_strtolower($keyword, 'UTF-8')) ?: []);
        $tokens = array_values(array_filter($tokens, static fn(string $t): bool => $t !== ''));

        $result = [];
        foreach (array_values($this->metas) as $index => $meta) {
            if ($tokens === []) {
                $result[] = ['meta' => $meta, 'index' => $index];
                continue;
            }

            $haystack = mb_strtolower($meta->name . ' ' . $meta->description . ' ' . $meta->help, 'UTF-8');

            foreach ($tokens as $token) {
                if (!str_contains($haystack, $token)) {
                    continue 2;
                }
            }

            $result[] = ['meta' => $meta, 'index' => $index];
        }

        return $result;
    }

    private function truncate(string $text): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return mb_strlen($text, 'UTF-8') <= self::DESC_LIMIT
            ? $text
            : mb_substr($text, 0, self::DESC_LIMIT, 'UTF-8') . '…';
    }
}
