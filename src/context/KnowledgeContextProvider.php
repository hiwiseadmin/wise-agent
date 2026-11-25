<?php
declare(strict_types=1);

namespace wise\agent\context;

use wise\agent\agent\AgentContext;
use wise\agent\context\contract\ContextProviderInterface;
use wise\agent\execution\ExecutionTracker;
use wise\agent\task\TaskQueryService;

/**
 * 知识上下文注入器＼�
 *
 * 让 AI「懂这个项目」：按当前任务描述**按需检索**两个来源，注入 system 上下文——
 *   1. **知识库**（向量检索，复用 `wise-rag` 的 `RetrievalService`，不重造向量库抽象）；
 *   2. **历史轨迹**（结构化查询，复用 C1 的 `TaskQueryService`，只取成功执行）。
 *
 * 与 `SchemaContextProvider` 的分工：Schema 是「结构（表/字段/能力）」，本类是「语义
 * （文档/设计意图/历史经验）」，不同 priority / source，不互相替代。
 *
 * ## 健壮性
 * - `wise-rag` 未安装 / 检索异常 ⇒ 该来源降级为空（返回 []），不阻断上下文构建；
 * - 注入受 `top_k` + `min_score` + `max_chars` 三重约束，不撑爆 prompt；
 * - 来源标注用 content 前缀（`[知识库检索]` / `[历史轨迹]`）——`ContextManager::buildContext()`
 *   会在 provider 级统一加 `_source`，provider 内部来源靠 content 前缀区分。
 *
 * @package wise\agent
 */
class KnowledgeContextProvider implements ContextProviderInterface
{
    public const SOURCE = 'knowledge';

    public const PREFIX_KNOWLEDGE = '[知识库检索]';
    public const PREFIX_HISTORY   = '[历史轨迹]';

    public function __construct(
        private readonly array $options = [],
        /** 可注入的检索回调（单测替身）：fn(string $query): array<array{content:string, score:float}> */
        private readonly ?\Closure $retriever = null,
        /** 可注入的历史轨迹回调（单测替身）：fn(string $query): array<array{task:string, create_time:string}> */
        private readonly ?\Closure $historyFetcher = null,
    ) {
    }

    public function getPriority(): int
    {
        return (int) $this->opt('priority', 20);
    }

    public function getSource(): string
    {
        return self::SOURCE;
    }

    public function getContext(AgentContext $agentContext): array
    {
        if (!(bool) $this->opt('enabled', false)) {
            return [];
        }

        $query = trim($agentContext->getTask());
        if ($query === '') {
            return [];
        }

        $messages = [];

        try {
            $text = $this->buildKnowledgeText($this->retrieve($query));
            if ($text !== '') {
                $messages[] = ['role' => 'system', 'content' => self::PREFIX_KNOWLEDGE . "\n" . $text];
            }
        } catch (\Throwable) {
        }

        if ($this->historyEnabled()) {
            try {
                $text = $this->buildHistoryText($this->fetchHistory($query));
                if ($text !== '') {
                    $messages[] = ['role' => 'system', 'content' => self::PREFIX_HISTORY . "\n" . $text];
                }
            } catch (\Throwable) {
            }
        }

        return $messages;
    }

    /**
     * 知识库检索（软依赖 RetrievalService）
     *
     * @return array<int, array{content: string, score: float}>
     */
    private function retrieve(string $query): array
    {
        if ($this->retriever !== null) {
            return (array) ($this->retriever)($query);
        }

        if (!class_exists(\wise\rag\service\RetrievalService::class)
            || !class_exists(\wise\rag\dto\RetrievalQuery::class)) {
            return [];
        }

        $service = app(\wise\rag\service\RetrievalService::class);
        $chunks  = $service->retrieve(new \wise\rag\dto\RetrievalQuery(
            text: $query,
            topK: (int) $this->opt('top_k', 4),
        ));

        $minScore = (float) $this->opt('min_score', 0.5);
        $result   = [];
        foreach ($chunks as $chunk) {
            if ($chunk instanceof \wise\rag\dto\ScoredChunk && $chunk->score >= $minScore) {
                $result[] = ['content' => $chunk->content, 'score' => $chunk->score];
            }
        }

        return $result;
    }

    /**
     * 历史成功轨迹（软依赖 TaskQueryService，只取 completed）
     *
     * @return array<int, array{task: string, create_time: string}>
     */
    private function fetchHistory(string $query): array
    {
        if ($this->historyFetcher !== null) {
            return (array) ($this->historyFetcher)($query);
        }

        $limit = max(1, (int) $this->opt('history.limit', 3));

        $paginated = (new TaskQueryService())->paginate([
            'keyword' => $query,
            'status'  => ExecutionTracker::STATUS_COMPLETED,
        ], 1, $limit);

        $result = [];
        foreach (($paginated['rows'] ?? []) as $row) {
            $result[] = [
                'task'        => (string) ($row['task'] ?? ''),
                'create_time' => (string) ($row['create_time'] ?? ''),
            ];
        }

        return $result;
    }

    /**
     * 组装知识库 chunk 文本（截断到 max_chars）
     *
     * @param array<int, array{content: string, score?: float}> $chunks
     */
    private function buildKnowledgeText(array $chunks): string
    {
        $maxChars = $this->maxChars();
        $text     = '';

        foreach ($chunks as $chunk) {
            $content = trim((string) ($chunk['content'] ?? ''));
            if ($content === '') {
                continue;
            }
            $text .= ($text === '' ? '' : "\n---\n") . $content;
            if (mb_strlen($text) >= $maxChars) {
                break;
            }
        }

        return mb_substr($text, 0, $maxChars);
    }

    /**
     * 组装历史轨迹文本（截断到 max_chars）
     *
     * @param array<int, array{task: string, create_time?: string}> $rows
     */
    private function buildHistoryText(array $rows): string
    {
        $maxChars = $this->maxChars();
        $lines    = [];

        foreach ($rows as $row) {
            $task = trim((string) ($row['task'] ?? ''));
            if ($task === '') {
                continue;
            }
            $time  = (string) ($row['create_time'] ?? '');
            $lines[] = $time !== '' ? "{$time}：{$task}" : $task;
        }

        $text = implode("\n", $lines);

        return mb_substr($text, 0, $maxChars);
    }

    private function historyEnabled(): bool
    {
        return (bool) $this->opt('history.enabled', true);
    }

    private function maxChars(): int
    {
        return max(1, (int) $this->opt('max_chars', 4000));
    }

    private function opt(string $key, mixed $default): mixed
    {
        if (array_key_exists($key, $this->options)) {
            return $this->options[$key];
        }

        $value = $this->options;
        foreach (explode('.', $key) as $part) {
            if (is_array($value) && array_key_exists($part, $value)) {
                $value = $value[$part];
            } else {
                $value = null;
                break;
            }
        }
        if ($value !== null) {
            return $value;
        }

        try {
            return config('wise-agent.knowledge.' . $key, $default) ?? $default;
        } catch (\Throwable) {
            return $default;
        }
    }
}
