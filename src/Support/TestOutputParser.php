<?php

namespace Tackle\Support;

/**
 * Turns raw Pest/PHPUnit output into a compact, structured summary.
 *
 * A failing suite on a real app is thousands of lines; returned verbatim it
 * eats the context window and poisons every later turn. The agent almost never
 * needs the whole log — it needs the count, and for each failure the test
 * name, the file:line, and the assertion. This extracts exactly that and
 * points the agent at `--filter` for the full detail of any one test.
 *
 * Defensive by design: if it cannot recognise the output as a test run it
 * falls back to head+tail of the raw text rather than hiding anything.
 */
class TestOutputParser
{
    private const MAX_FAILURES = 20;

    private const MAX_MESSAGE_CHARS = 240;

    private const FALLBACK_HEAD_LINES = 30;

    private const FALLBACK_TAIL_LINES = 30;

    public static function summarize(string $raw): string
    {
        $clean = Utf8::clean($raw);

        if (trim($clean) === '') {
            return '(Tests ran with no output.)';
        }

        $summaryLine = self::match('/^\s*Tests:\s+.+$/m', $clean)
            ?? self::phpunitSummary($clean);
        $durationLine = self::match('/^\s*Duration:\s+.+$/m', $clean);

        $failures = self::failures($clean);
        $passed = self::looksPassing($clean, $failures);

        // Unrecognisable as a test run — don't invent structure, show the ends.
        if ($summaryLine === null && $failures === [] && ! $passed) {
            return self::fallback($clean);
        }

        $out = [];
        $out[] = $summaryLine !== null ? trim($summaryLine) : self::synthSummary($failures);

        if ($durationLine !== null) {
            $out[] = trim($durationLine);
        }

        if ($failures === []) {
            $out[] = 'All tests passed.';

            return implode("\n", $out);
        }

        $shown = array_slice($failures, 0, self::MAX_FAILURES);
        $out[] = '';
        $out[] = 'Failures ('.count($failures).(count($failures) > count($shown) ? ', showing '.count($shown) : '').'):';

        foreach ($shown as $i => $f) {
            $out[] = sprintf('  %d. %s', $i + 1, $f['title']);
            if ($f['location'] !== null) {
                $out[] = '     at '.$f['location'];
            }
            if ($f['message'] !== null) {
                $out[] = '     '.$f['message'];
            }
        }

        if (count($failures) > count($shown)) {
            $out[] = '  … '.(count($failures) - count($shown)).' more.';
        }

        $lineCount = substr_count($clean, "\n") + 1;
        $out[] = '';
        $out[] = "[Structured from {$lineCount} lines of raw output. Re-run with a --filter for one test's full failure detail.]";

        return implode("\n", $out);
    }

    /**
     * @return list<array{title: string, location: ?string, message: ?string}>
     */
    private static function failures(string $clean): array
    {
        $failures = [];
        $seen = [];

        // Pest detail blocks carry the assertion: a header with " > " prefixed
        // by FAILED/•/✗, then the message, then "at path:line". Richest source,
        // so prefer them.
        if (preg_match_all('/^\s*(?:FAILED|•|✗|⨯|×)\s+(\S.*\s>\s.+)$/mu', $clean, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[1] as $idx => $cap) {
                $title = self::normalizeTitle($cap[0]);
                if ($title === '' || isset($seen[$title])) {
                    continue;
                }
                $seen[$title] = true;

                $tail = substr($clean, $m[0][$idx][1]);
                $failures[] = [
                    'title' => $title,
                    'location' => self::match('/^\s*at\s+(.+?:\d+)/m', $tail),
                    'message' => self::firstMessageLine($tail),
                ];
            }
        }

        // No detail blocks — fall back to the per-file list markers, which give
        // the test names but no adjacent assertion text.
        if ($failures === [] && preg_match_all('/^\s*(?:⨯|✗|×)\s+(\S.+)$/mu', $clean, $m)) {
            foreach ($m[1] as $cap) {
                $title = self::normalizeTitle($cap);
                if ($title === '' || isset($seen[$title])) {
                    continue;
                }
                $seen[$title] = true;
                $failures[] = ['title' => $title, 'location' => null, 'message' => null];
            }
        }

        // PHPUnit: "1) Full\Qualified\Test::method" then message then path:line.
        if ($failures === [] && preg_match_all('/^\s*\d+\)\s+(.+::\S+)\s*$/m', $clean, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[1] as $idx => $cap) {
                $tail = substr($clean, $m[0][$idx][1]);
                $failures[] = [
                    'title' => trim($cap[0]),
                    'location' => self::match('/^(.+?:\d+)\s*$/m', $tail),
                    'message' => self::firstMessageLine($tail),
                ];
            }
        }

        return $failures;
    }

    private static function firstMessageLine(string $tail): ?string
    {
        foreach (preg_split('/\r?\n/', $tail) ?: [] as $i => $line) {
            if ($i === 0) {
                continue; // the title/header line itself
            }
            $t = trim($line);
            if ($t === '' || str_starts_with($t, 'at ') || str_starts_with($t, '•') || str_starts_with($t, '⨯')) {
                continue;
            }

            return mb_strlen($t) > self::MAX_MESSAGE_CHARS
                ? mb_substr($t, 0, self::MAX_MESSAGE_CHARS - 1).'…'
                : $t;
        }

        return null;
    }

    private static function looksPassing(string $clean, array $failures): bool
    {
        return $failures === [] && (bool) preg_match('/\bPASS\b|\bOK\b|Tests:\s.*passed|✓/u', $clean);
    }

    private static function phpunitSummary(string $clean): ?string
    {
        return self::match('/^(OK \(.+\))$/m', $clean)
            ?? self::match('/^(Tests:\s*\d+,\s*Assertions:.+)$/m', $clean)
            ?? self::match('/^(FAILURES!.*)$/m', $clean);
    }

    private static function synthSummary(array $failures): string
    {
        return count($failures).' failing test'.(count($failures) === 1 ? '' : 's').' detected.';
    }

    private static function fallback(string $clean): string
    {
        $lines = preg_split('/\r?\n/', $clean) ?: [];

        if (count($lines) <= self::FALLBACK_HEAD_LINES + self::FALLBACK_TAIL_LINES) {
            return $clean;
        }

        $head = implode("\n", array_slice($lines, 0, self::FALLBACK_HEAD_LINES));
        $tail = implode("\n", array_slice($lines, -self::FALLBACK_TAIL_LINES));
        $omitted = count($lines) - self::FALLBACK_HEAD_LINES - self::FALLBACK_TAIL_LINES;

        return $head."\n\n[… {$omitted} lines omitted …]\n\n".$tail;
    }

    private static function normalizeTitle(string $title): string
    {
        return trim(preg_replace('/\s+/', ' ', $title) ?? '');
    }

    private static function match(string $pattern, string $subject): ?string
    {
        return preg_match($pattern, $subject, $m) ? ($m[1] ?? $m[0]) : null;
    }
}
