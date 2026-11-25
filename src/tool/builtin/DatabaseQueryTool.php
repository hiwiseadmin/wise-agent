<?php
declare(strict_types=1);

namespace wise\agent\tool\builtin;


use wise\support\context\ContextualLogger;
use think\facade\Db;
use wise\agent\security\CapabilityAccess;
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

    public function getAccess(): string
    {
        return CapabilityAccess::READ;
    }

    public function getRiskLevel(): string
    {
        return CapabilityAccess::RISK_MEDIUM;
    }

    public function execute(array $arguments): string
    {
        $query = trim($arguments['query'] ?? '');
        $limit = (int) ($arguments['limit'] ?? 100);

        if (empty($query)) {
            return json_encode(['error' => 'Query is required']);
        }

        $upperQuery = strtoupper($query);
        if (!str_starts_with($upperQuery, 'SELECT') && !str_starts_with($upperQuery, 'SHOW') && !str_starts_with($upperQuery, 'DESCRIBE') && !str_starts_with($upperQuery, 'EXPLAIN')) {
            return json_encode(['error' => 'Only read-only queries are allowed (SELECT, SHOW, DESCRIBE, EXPLAIN)']);
        }

        $normalized = preg_replace('/\/\*.*?\*\//s', ' ', $query);
        $normalized = preg_replace('/--[^\n]*/', ' ', $normalized);
        $normalized = preg_replace('/#[^\n]*/', ' ', $normalized);
        $normalized = strtoupper($normalized);

        $dangerousKeywords = [
            'DROP', 'DELETE', 'UPDATE', 'INSERT', 'ALTER', 'CREATE',
            'TRUNCATE', 'RENAME', 'GRANT', 'REVOKE',
            'LOAD', 'EXEC', 'BENCHMARK', 'SLEEP',
            'LOAD_FILE', 'GET_LOCK', 'RELEASE_LOCK',
        ];
        foreach ($dangerousKeywords as $keyword) {
            if (preg_match('/(?<![A-Z0-9_])' . preg_quote($keyword, '/') . '(?![A-Z0-9_])/i', $normalized)) {
                return json_encode(['error' => "Dangerous SQL keyword detected: {$keyword}"]);
            }
        }

        if (preg_match('/\bINTO\s+(OUTFILE|DUMPFILE|VAR|@)/i', $normalized)) {
            return json_encode(['error' => 'SELECT INTO OUTFILE/DUMPFILE/VAR is not allowed']);
        }

        try {
            if ($this->maxExecutionTime > 0) {
                $query = preg_replace('/^\s*SELECT/i', "SELECT /*+ MAX_EXECUTION_TIME({$this->maxExecutionTime}) */", $query, 1);
            }

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
            ContextualLogger::error("[WiseAgent] DB query error: " . $e->getMessage(), [
                'query'     => substr($query, 0, 500),
                'exception' => get_class($e),
            ]);
            return json_encode(['error' => 'Query execution failed. Check the SQL syntax and try again.']);
        }
    }
}
