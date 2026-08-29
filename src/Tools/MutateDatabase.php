<?php

namespace Tackle\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Tools\Request;
use Tackle\Contracts\InteractionPolicy;
use Throwable;

/**
 * Write to the database, under a transaction the user gets to veto.
 *
 * Every other tool in Tackle leans on the same safety net: your files are in
 * git, so a bad edit is `git checkout`. Rows are not. A wrong WHERE on an
 * UPDATE is a restore-from-backup, which is why QueryDatabase is read-only and
 * why this tool is off by default.
 *
 * When it is on, the statement runs inside a transaction that is not committed
 * until a human has seen the exact number of rows it touched. Nothing is
 * estimated and no SQL is parsed to guess an impact: the statement itself
 * reports what it did, and the answer is either COMMIT or ROLLBACK.
 *
 * Enable with tackle.database.mutations. It still refuses outside the
 * environments listed in tackle.database.environments (local only by default),
 * so the flag alone cannot arm production.
 */
class MutateDatabase extends AbstractTool
{
    public function __construct(private ?InteractionPolicy $interaction = null) {}

    public function description(): string
    {
        return 'Run a single write statement (UPDATE, INSERT, or DELETE) against the database. '
            .'The statement runs inside a transaction and is only committed after the user confirms the exact number '
            .'of affected rows — otherwise it is rolled back. UPDATE and DELETE must carry a WHERE clause. '
            .'Use QueryDatabase to read; use this only when the user has asked you to change data.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'statement' => $schema->string()
                ->description('A single UPDATE, INSERT, or DELETE statement. UPDATE and DELETE must include a WHERE clause.')
                ->required(),
            'reason' => $schema->string()
                ->description('One line on why this change is being made. Shown to the user in the confirmation.')
                ->required(),
        ];
    }

    public function handle(Request $request): string
    {
        $sql = trim($request->string('statement', ''));
        $reason = trim($request->string('reason', ''));

        if ($refusal = $this->refuse($sql)) {
            return $refusal;
        }

        $max = (int) config('tackle.database.max_rows', 100);

        try {
            DB::beginTransaction();

            $affected = DB::affectingStatement($sql);
        } catch (Throwable $e) {
            DB::rollBack();

            return 'Statement failed, nothing was changed: '.$e->getMessage();
        }

        // The count is the real one, from the statement that just ran — and it
        // is still undoable at this point, which is the whole design.
        if ($affected > $max) {
            DB::rollBack();

            return "Rolled back: the statement affected {$affected} rows, over the limit of {$max} "
                .'(tackle.database.max_rows). Narrow the WHERE clause, or raise the limit deliberately.';
        }

        if ($affected === 0) {
            DB::rollBack();

            return 'Rolled back: the statement matched no rows, so there was nothing to confirm. '
                .'Check the WHERE clause with QueryDatabase first.';
        }

        $confirmed = $this->interaction()->confirm(
            "⚠ Commit a database change affecting {$affected} row(s)?",
            default: false,
            hint: ($reason !== '' ? $reason."\n\n" : '').$sql,
        );

        if (! $confirmed) {
            DB::rollBack();

            return "Rolled back by the user — {$affected} row(s) were left unchanged.";
        }

        DB::commit();

        return "Committed: {$affected} row(s) changed.";
    }

    /**
     * Everything this refuses to do, and why, before a transaction is opened.
     */
    private function refuse(string $sql): ?string
    {
        if ($sql === '') {
            return 'A non-empty statement is required.';
        }

        if (! (bool) config('tackle.database.mutations', false)) {
            return 'Database writes are disabled. QueryDatabase can read; to allow writes, set '
                .'tackle.database.mutations to true (AI_CODE_DB_MUTATIONS). Consider a purpose-built tool '
                .'for the specific change instead — it is narrower than arbitrary SQL.';
        }

        $environments = (array) config('tackle.database.environments', ['local']);
        $environment = app()->environment();

        if (! in_array($environment, $environments, true)) {
            return "Refused: database writes are not permitted in the '{$environment}' environment "
                .'(tackle.database.environments allows: '.(implode(', ', $environments) ?: 'none').').';
        }

        // Trailing semicolon is fine; a second statement is not. Chaining is
        // how a confirmed one-row UPDATE becomes something else entirely.
        if (str_contains(rtrim($sql, "; \t\n\r"), ';')) {
            return 'Refused: one statement at a time. Chained statements cannot be confirmed as a single change.';
        }

        if (! preg_match('/^\s*(UPDATE|INSERT|DELETE)\b/i', $sql, $match)) {
            return 'Refused: only UPDATE, INSERT, and DELETE are permitted. Use QueryDatabase to read, and '
                .'RunArtisan with a migration to change schema — DDL cannot be rolled back on every driver, '
                .'so it is not safe to confirm this way.';
        }

        $verb = strtoupper($match[1]);

        if (in_array($verb, ['UPDATE', 'DELETE'], true) && ! preg_match('/\bWHERE\b/i', $sql)) {
            return "Refused: a {$verb} with no WHERE clause would affect every row in the table. "
                .'Add a WHERE clause, even if it is the whole table you mean — say so explicitly.';
        }

        return null;
    }

    private function interaction(): InteractionPolicy
    {
        return $this->interaction ??= app(InteractionPolicy::class);
    }
}
