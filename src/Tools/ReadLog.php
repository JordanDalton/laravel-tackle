<?php

namespace Tackle\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;

class ReadLog extends AbstractTool
{
    private const DEFAULT_LINES = 50;
    private const MAX_LINES     = 500;

    public function description(): string
    {
        return 'Read recent entries from the Laravel log file (storage/logs/laravel.log). Returns the last N lines. Use to diagnose errors and exceptions without running shell commands.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'lines' => $schema->integer()
                ->description('Number of lines to return from the end of the log. Defaults to ' . self::DEFAULT_LINES . ', max ' . self::MAX_LINES . '.'),
            'filter' => $schema->string()
                ->description('Optional string to filter lines by — only lines containing this string are returned.'),
        ];
    }

    public function handle(Request $request): string
    {
        $logPath = storage_path('logs/laravel.log');

        if (! file_exists($logPath)) {
            return 'Log file not found at ' . $logPath . '. The application may not have logged anything yet.';
        }

        $lines  = min((int) $request->integer('lines', self::DEFAULT_LINES), self::MAX_LINES);
        $filter = $request->string('filter', '');

        $all      = file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $tail     = array_slice($all, -$lines);

        if ($filter !== '') {
            $tail = array_values(array_filter($tail, fn ($l) => str_contains($l, $filter)));
        }

        if (empty($tail)) {
            return $filter !== ''
                ? "No log lines matching '{$filter}' found in the last {$lines} lines."
                : 'The log file is empty.';
        }

        return implode("\n", $tail);
    }
}
