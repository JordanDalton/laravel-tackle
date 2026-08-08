<?php

namespace Tackle\Review;

/**
 * Extracts the machine-readable findings block the agent is asked to append
 * when a review needs to be posted to GitHub or gate an exit code.
 *
 * The block is a fenced code block with the `tackle-findings` language tag:
 *
 * ```tackle-findings
 * {"verdict": "needs_changes", "findings": [{"path": "app/Foo.php", "line": 12, "severity": "critical", "message": "..."}]}
 * ```
 */
class FindingsParser
{
    private const FENCE = '/```tackle-findings\s*\n(.*?)\n```/s';

    public function parse(string $text): ?ParsedReview
    {
        if (! preg_match(self::FENCE, $text, $m)) {
            return null;
        }

        $data = json_decode(trim($m[1]), true);

        if (! is_array($data)) {
            return null;
        }

        $findings = [];

        foreach ($data['findings'] ?? [] as $raw) {
            if (! is_array($raw)) {
                continue;
            }

            $path = trim((string) ($raw['path'] ?? ''));
            $line = (int) ($raw['line'] ?? 0);
            $message = trim((string) ($raw['message'] ?? ''));

            if ($path === '' || $line < 1 || $message === '') {
                continue;
            }

            $findings[] = new Finding(
                path: ltrim($path, '/'),
                line: $line,
                severity: $this->normalizeSeverity((string) ($raw['severity'] ?? '')),
                message: $message,
            );
        }

        return new ParsedReview(
            verdict: $this->normalizeVerdict((string) ($data['verdict'] ?? '')),
            findings: $findings,
        );
    }

    /**
     * Remove the findings block from the agent's response so terminal output
     * stays human-readable.
     */
    public function strip(string $text): string
    {
        return trim((string) preg_replace(self::FENCE, '', $text));
    }

    private function normalizeSeverity(string $severity): string
    {
        $severity = strtolower(trim($severity));

        return in_array($severity, ['critical', 'warning', 'suggestion'], true)
            ? $severity
            : 'suggestion';
    }

    private function normalizeVerdict(string $verdict): string
    {
        $verdict = strtolower(trim($verdict));

        return in_array($verdict, ['lgtm', 'lgtm_with_notes', 'needs_changes'], true)
            ? $verdict
            : 'lgtm_with_notes';
    }
}
