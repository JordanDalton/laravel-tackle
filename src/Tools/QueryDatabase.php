<?php

namespace Tackle\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Tools\Request;

class QueryDatabase extends AbstractTool
{
    private const MAX_ROWS = 100;

    public function description(): string
    {
        return 'Run a read-only SQL SELECT query against the default database connection and return results as JSON. Only SELECT statements are permitted. Results capped at ' . self::MAX_ROWS . ' rows.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()
                ->description('A SQL SELECT query to execute.')
                ->required(),
        ];
    }

    public function handle(Request $request): string
    {
        $sql = trim($request->string('query', ''));

        if ($sql === '') {
            return 'A non-empty query is required.';
        }

        if (! preg_match('/^\s*SELECT\b/i', $sql)) {
            return 'Only SELECT queries are permitted.';
        }

        // Enforce row cap via LIMIT injection when no LIMIT is present.
        if (! preg_match('/\bLIMIT\b/i', $sql)) {
            $sql .= ' LIMIT ' . self::MAX_ROWS;
        }

        try {
            $rows = DB::select($sql);
        } catch (\Throwable $e) {
            return 'Query error: ' . $e->getMessage();
        }

        if (empty($rows)) {
            return 'Query returned no rows.';
        }

        $output = json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        if (count($rows) >= self::MAX_ROWS) {
            $output .= "\n\n[Results capped at " . self::MAX_ROWS . " rows.]";
        }

        return $output;
    }
}
