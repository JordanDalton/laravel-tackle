<?php

namespace Tackle\Support;

/**
 * Deterministic blast-radius limits for an unattended heal. A self-healer that
 * can touch anything, at any size, is one bad diagnosis away from a large wrong
 * change landing on a branch that looks trustworthy. These caps keep a heal to
 * the shape of a heal — a few files, a bounded diff, nothing structural — and
 * anything past them forces human review (never an auto-applied patch).
 *
 * Not a security boundary (PathGuard is). This bounds scope, not permission.
 */
class BlastRadius
{
    /**
     * Return human-readable violation messages for a heal's diff. Empty means
     * within limits.
     *
     * @param  array<string, string>  $files  path => git status letter (A/M/D/R…)
     * @return list<string>
     */
    public static function violations(array $files, int $changedLines): array
    {
        $violations = [];

        $maxFiles = (int) config('tackle.healing.max_files', 20);
        $maxLines = (int) config('tackle.healing.max_diff_lines', 400);
        $protected = (array) config('tackle.healing.protected_from_healing', self::defaultProtected());

        if ($maxFiles > 0 && count($files) > $maxFiles) {
            $violations[] = sprintf('touches %d files (limit %d)', count($files), $maxFiles);
        }

        if ($maxLines > 0 && $changedLines > $maxLines) {
            $violations[] = sprintf('changes %d lines (limit %d)', $changedLines, $maxLines);
        }

        foreach ($files as $path => $status) {
            // Adding a file is fine (a new migration is the safe way to change
            // schema). Modifying or deleting a protected path is not — that is
            // the already-run-migration / config / composer.json footgun.
            if (strtoupper((string) $status) === 'A') {
                continue;
            }

            foreach ($protected as $glob) {
                if (fnmatch($glob, $path)) {
                    $violations[] = "modifies a protected path: {$path} (matches \"{$glob}\")";
                    break;
                }
            }
        }

        return $violations;
    }

    /**
     * @return list<string>
     */
    public static function defaultProtected(): array
    {
        return [
            'database/migrations/*',
            'config/*',
            'composer.json',
            'composer.lock',
            '.env*',
        ];
    }
}
