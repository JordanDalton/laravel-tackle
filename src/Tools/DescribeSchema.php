<?php

namespace Tackle\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Schema;
use Laravel\Ai\Tools\Request;
use Throwable;

/**
 * The real database schema from the live connection — columns, types,
 * nullability, indexes, and foreign keys — not a guess from migration files
 * that may have drifted. Something a terminal agent structurally can't get.
 */
class DescribeSchema extends AbstractTool
{
    public function description(): string
    {
        return 'Describe the actual database schema from the live connection. With no argument, lists tables and their column counts. With a table name, returns its columns (name, type, nullable, default), indexes, and foreign keys. Authoritative — reflects the real DB, not migration files.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'table' => $schema->string()
                ->description('Optional table name to describe in full. Omit to list all tables.'),
        ];
    }

    public function handle(Request $request): string
    {
        $table = trim((string) $request->string('table', ''));

        try {
            if ($table === '') {
                $tables = collect(Schema::getTables())
                    ->map(fn ($t) => is_array($t) ? ($t['name'] ?? '') : (string) $t)
                    ->filter()
                    ->sort()
                    ->values();

                if ($tables->isEmpty()) {
                    return 'No tables found (has the database been migrated?).';
                }

                $lines = $tables->map(function (string $name) {
                    $cols = count(Schema::getColumnListing($name));

                    return "- {$name} ({$cols} columns)";
                });

                return "Tables:\n".$lines->implode("\n")."\n\nCall again with a table name for its columns, indexes, and foreign keys.";
            }

            if (! Schema::hasTable($table)) {
                return "Table '{$table}' does not exist.";
            }

            $out = ["Table: {$table}", '', 'Columns:'];
            foreach (Schema::getColumns($table) as $col) {
                $out[] = sprintf(
                    '  %-24s %-14s %s%s',
                    $col['name'] ?? '?',
                    $col['type'] ?? ($col['type_name'] ?? '?'),
                    ($col['nullable'] ?? false) ? 'nullable' : 'not null',
                    isset($col['default']) && $col['default'] !== null ? ' default '.$col['default'] : '',
                );
            }

            $indexes = Schema::getIndexes($table);
            if ($indexes !== []) {
                $out[] = '';
                $out[] = 'Indexes:';
                foreach ($indexes as $idx) {
                    $cols = implode(', ', (array) ($idx['columns'] ?? []));
                    $flags = collect(['unique' => $idx['unique'] ?? false, 'primary' => $idx['primary'] ?? false])
                        ->filter()->keys()->implode(', ');
                    $out[] = '  '.($idx['name'] ?? '?')." ({$cols})".($flags !== '' ? " [{$flags}]" : '');
                }
            }

            $fks = Schema::getForeignKeys($table);
            if ($fks !== []) {
                $out[] = '';
                $out[] = 'Foreign keys:';
                foreach ($fks as $fk) {
                    $local = implode(', ', (array) ($fk['columns'] ?? []));
                    $foreign = implode(', ', (array) ($fk['foreign_columns'] ?? []));
                    $out[] = "  {$local} → ".($fk['foreign_table'] ?? '?')." ({$foreign})";
                }
            }

            return implode("\n", $out);
        } catch (Throwable $e) {
            return 'Could not read the schema: '.$e->getMessage();
        }
    }
}
