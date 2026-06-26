<?php
declare(strict_types=1);

namespace wise\agent\tool\builtin;

use think\facade\Db;
use wise\agent\tool\BaseTool;

/**
 * 数据库查询工具
 *
 * 执行只读 SQL 查询。出于安全考虑，默认禁用。
 */
class DatabaseQueryTool extends BaseTool
{
    /** @var int 最大查询执行时间（毫秒） */
    protected int $maxExecutionTime = 30000;

    public function getName(): string
    {
        return 'db_query';
    }

    public function getDescription(): string
    {
        return 'Execute a read-only SQL query (SELECT only). Returns results as JSON. Use for data analysis and reporting.';
    }

    public function getParameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'query' => [
                    'type'        => 'string',
                    'description' => 'The SELECT SQL query to execute (read-only)',
                ],
                'limit' => [
                    'type'        => 'integer',
                    'description' => 'Maximum number of rows to return (default: 100)',
                ],
            ],
            'required'   => ['query'],
        ];
    }

    public function getPermission(): string
    {
        return 'ai.tool.db_query';
    }

    public function requireConfirmation(): bool
    {
        return true;
    }

    public function execute(array $arguments): string
    {
        $query = trim($arguments['query'] ?? '');
        $limit = (int) ($arguments['limit'] ?? 100);

        if (empty($query)) {
            return json_encode(['error' => 'Query is required']);
        }

        // 安全：仅允许 SELECT 语句
        $upperQuery = strtoupper($query);
        if (!str_starts_with($upperQuery, 'SELECT') && !str_starts_with($upperQuery, 'SHOW') && !str_starts_with($upperQuery, 'DESCRIBE') && !str_starts_with($upperQuery, 'EXPLAIN')) {
            return json_encode(['error' => 'Only read-only queries are allowed (SELECT, SHOW, DESCRIBE, EXPLAIN)']);
        }

        // 拦截危险关键词（CR-06: 新增 INTO、LOAD、EXEC、BENCHMARK、SLEEP；CR-07: 新增 UNION）
        $dangerousKeywords = [
            'DROP', 'DELETE', 'UPDATE', 'INSERT', 'ALTER', 'CREATE',
            'TRUNCATE', 'RENAME', 'GRANT', 'REVOKE',
            'INTO', 'LOAD', 'EXEC', 'BENCHMARK', 'SLEEP', 'UNION',
        ];
        foreach ($dangerousKeywords as $keyword) {
            if (preg_match('/\b' . $keyword . '\b/i', $query)) {
                return json_encode(['error' => "Dangerous SQL keyword detected: {$keyword}"]);
            }
        }

        try {
            // 设置最大执行时间以防止失控查询（HI-07 修复）
            if ($this->maxExecutionTime > 0) {
                $query = "SET STATEMENT max_execution_time={$this->maxExecutionTime} FOR " . $query;
            }

            // 使用正则精确匹配以应用 limit（HI-13 修复：原先使用 str_contains，会误匹配字段名）
            if ($limit > 0 && !preg_match('/\bLIMIT\s+\d+(\s*,\s*\d+)?(\s+OFFSET\s+\d+)?\s*$/i', $query)) {
                $query = rtrim($query, ';') . " LIMIT {$limit}";
            }

            $results = Db::query($query);
            $count = count($results);

            return json_encode([
                'success' => true,
                'count'   => $count,
                'data'    => $results,
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        } catch (\Throwable $e) {
            return json_encode(['error' => "Query error: " . $e->getMessage()]);
        }
    }
}
