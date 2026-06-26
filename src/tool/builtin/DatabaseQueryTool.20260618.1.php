<?php
declare(strict_types=1);

namespace wise\agent\Tool\builtin;

use think\facade\Db;
use wise\agent\Tool\BaseTool;

/**
 * Database query tool
 *
 * Executes read-only SQL queries. Disabled by default for security.
 */
class DatabaseQueryTool extends BaseTool
{
    /** @var int Maximum query execution time in milliseconds */
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

        // Security: only allow SELECT statements
        $upperQuery = strtoupper($query);
        if (!str_starts_with($upperQuery, 'SELECT') && !str_starts_with($upperQuery, 'SHOW') && !str_starts_with($upperQuery, 'DESCRIBE') && !str_starts_with($upperQuery, 'EXPLAIN')) {
            return json_encode(['error' => 'Only read-only queries are allowed (SELECT, SHOW, DESCRIBE, EXPLAIN)']);
        }

        // Block dangerous keywords (CR-06: added INTO, LOAD, EXEC, BENCHMARK, SLEEP; CR-07: added UNION)
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
            // Set max execution time to prevent runaway queries (HI-07 fix)
            if ($this->maxExecutionTime > 0) {
                $query = "SET STATEMENT max_execution_time={$this->maxExecutionTime} FOR " . $query;
            }

            // Apply limit using regex for precise matching (HI-13 fix: was str_contains which matches field names)
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
