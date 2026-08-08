<?php

namespace Tackle\Review;

/**
 * Parses a unified diff and records which (file, line) pairs may carry an
 * inline pull request comment.
 *
 * GitHub rejects an entire review if any single comment targets a line that is
 * not part of the diff, so findings are validated against this index before
 * posting — anything that cannot be anchored inline is folded into the review
 * body instead.
 */
class DiffLineIndex
{
    /** @var array<string, array<int, true>> new-file line numbers present in the diff, per path */
    private array $lines = [];

    public function __construct(string $diff)
    {
        $this->parse($diff);
    }

    public function isCommentable(string $path, int $line): bool
    {
        return isset($this->lines[$path][$line]);
    }

    /** @return string[] */
    public function paths(): array
    {
        return array_keys($this->lines);
    }

    private function parse(string $diff): void
    {
        $path = null;
        $newLine = 0;

        foreach (preg_split('/\r?\n/', $diff) as $raw) {
            if (str_starts_with($raw, 'diff --git ')) {
                $path = null;

                continue;
            }

            if (str_starts_with($raw, '+++ ')) {
                $target = substr($raw, 4);
                $path = $target === '/dev/null' ? null : preg_replace('#^b/#', '', $target);

                continue;
            }

            if (preg_match('/^@@ -\d+(?:,\d+)? \+(\d+)(?:,\d+)? @@/', $raw, $m)) {
                $newLine = (int) $m[1];

                continue;
            }

            if ($path === null || $newLine === 0) {
                continue;
            }

            // Inside a hunk: added and context lines exist on the new side and
            // are commentable; removed lines only advance the old side.
            if (str_starts_with($raw, '+')) {
                $this->lines[$path][$newLine] = true;
                $newLine++;
            } elseif (str_starts_with($raw, '-')) {
                // old side only
            } elseif (str_starts_with($raw, ' ') || $raw === '') {
                $this->lines[$path][$newLine] = true;
                $newLine++;
            } elseif (str_starts_with($raw, '\\')) {
                // "\ No newline at end of file" — not a diff line
            } else {
                // Any other marker ends the current hunk until the next @@ header.
                $newLine = 0;
            }
        }
    }
}
