<?php

namespace Tackle\Healing;

/**
 * The verifiable record of what a heal actually did — the difference between
 * "the agent says it fixed it" and evidence a reviewer (or the patch gate) can
 * trust. Built from a baseline test run, a post-fix test run, and the diff.
 *
 * The gate is deliberately "no NEW failures", not "the whole suite is green":
 * real apps carry pre-existing failures and slow suites, and a healer that
 * refuses to ship unless everything is green would never ship. What matters is
 * that the fix did not make anything worse and (ideally) added a regression
 * test that now passes.
 */
class HealEvidence
{
    /**
     * @param  list<string>  $baselineFailures  failing tests before the fix
     * @param  list<string>  $afterFailures  failing tests after the fix
     * @param  list<string>  $filesTouched
     * @param  list<string>  $blastRadiusViolations
     */
    public function __construct(
        public readonly array $baselineFailures,
        public readonly array $afterFailures,
        public readonly bool $baselineRan,
        public readonly bool $afterRan,
        public readonly array $filesTouched,
        public readonly int $insertions,
        public readonly int $deletions,
        public readonly bool $regressionTestAdded,
        public readonly array $blastRadiusViolations,
        public readonly bool $analysisRan = false,
        public readonly bool $analysisOk = true,
        public readonly string $analysisSummary = '',
        public readonly bool $redGreenChecked = false,
        public readonly bool $redGreenProven = false,
    ) {}

    /**
     * Failures present after the fix that were not there before — the ones the
     * fix introduced. This is what the gate keys on.
     *
     * @return list<string>
     */
    public function newFailures(): array
    {
        return array_values(array_diff($this->afterFailures, $this->baselineFailures));
    }

    /**
     * Pre-existing failures the fix happened to clear — informational.
     *
     * @return list<string>
     */
    public function resolvedFailures(): array
    {
        return array_values(array_diff($this->baselineFailures, $this->afterFailures));
    }

    /**
     * True if the heal changed at least one non-test file. A heal that touches
     * only tests (or nothing) is not a fix — for a performance issue, where no
     * test was failing to begin with, an agent can satisfy "no new failures" by
     * adding a passing test and changing no code. That must never auto-apply.
     */
    public function codeChanged(): bool
    {
        foreach ($this->filesTouched as $path) {
            if (! self::isTestPath($path)) {
                return true;
            }
        }

        return false;
    }

    public static function isTestPath(string $path): bool
    {
        return str_contains($path, 'tests/')
            || str_contains($path, 'Tests/')
            || str_ends_with($path, 'Test.php')
            || str_ends_with($path, 'Test.phpt');
    }

    public function changedLines(): int
    {
        return $this->insertions + $this->deletions;
    }

    /**
     * Tests are "clean" if the post-fix run happened and introduced no new
     * failures. Drives the PR wording.
     */
    public function testsClean(): bool
    {
        return $this->afterRan && $this->newFailures() === [];
    }

    /**
     * Static analysis is "clean" if it did not run (not installed / not
     * applicable) or ran without errors on the changed files.
     */
    public function analysisClean(): bool
    {
        return ! $this->analysisRan || $this->analysisOk;
    }

    /**
     * The patch gate: clean tests AND within blast-radius limits. Only when
     * this holds may a heal be auto-applied to main; otherwise it goes to a PR
     * for human review.
     */
    public function gatePassed(): bool
    {
        return $this->testsClean()
            && $this->codeChanged()
            && $this->analysisClean()
            && $this->blastRadiusViolations === [];
    }

    /**
     * A short tag for the PR title reflecting what a reviewer must weigh.
     */
    public function titleTag(): string
    {
        if (! $this->codeChanged()) {
            return '[incomplete] ';
        }

        if (! $this->testsClean()) {
            return '[tests failing] ';
        }

        if (! $this->analysisClean() || $this->blastRadiusViolations !== []) {
            return '[needs review] ';
        }

        return '';
    }

    /**
     * A Markdown evidence block appended to every heal PR so review is quick
     * and the claim is backed rather than asserted.
     */
    public function render(): string
    {
        $lines = ['## Heal evidence', ''];

        $lines[] = $this->afterRan
            ? ($this->newFailures() === []
                ? '- ✅ **No new test failures** introduced by this fix.'
                : '- ❌ **New failures introduced:** '.$this->list($this->newFailures()))
            : '- ⚠️ **Tests could not be run** in the sandbox — treat this fix as unverified.';

        if (! $this->codeChanged()) {
            $lines[] = '- ⛔ **No application code changed** — this heal added tests only and does not contain a fix. Not auto-applied.';
        }

        if ($this->baselineRan && $this->baselineFailures !== []) {
            $lines[] = sprintf(
                '- ℹ️ %d test(s) were already failing before the fix (pre-existing, not caused by this change).',
                count($this->baselineFailures),
            );
        }

        if ($this->resolvedFailures() !== []) {
            $lines[] = '- ✅ Also cleared pre-existing failure(s): '.$this->list($this->resolvedFailures());
        }

        $lines[] = $this->regressionTestAdded
            ? '- ✅ **Regression test added** — a test reproducing the issue is included.'
            : '- ⚠️ **No regression test added** — the fix is not guarded against recurrence.';

        if ($this->redGreenChecked) {
            $lines[] = $this->redGreenProven
                ? '- ✅ **Regression test proven** — it fails without the fix and passes with it (red→green).'
                : '- ⚠️ **Regression test did not fail without the fix** — it may not actually reproduce the issue.';
        }

        if ($this->analysisRan) {
            $lines[] = $this->analysisOk
                ? '- ✅ **Static analysis clean** on the changed files.'
                : '- ❌ **Static analysis errors** on the changed files'.($this->analysisSummary !== '' ? ': '.$this->analysisSummary : '').'.';
        }

        $lines[] = sprintf(
            '- 📊 Diff: %d file(s), +%d/-%d lines.',
            count($this->filesTouched),
            $this->insertions,
            $this->deletions,
        );

        if ($this->filesTouched !== []) {
            $lines[] = '- 📁 Files: '.$this->list($this->filesTouched);
        }

        if ($this->blastRadiusViolations !== []) {
            $lines[] = '';
            $lines[] = '> ⚠️ **Blast-radius limits exceeded** — this heal was not auto-applied and needs a careful look:';
            foreach ($this->blastRadiusViolations as $v) {
                $lines[] = '> - '.$v;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param  list<string>  $items
     */
    private function list(array $items, int $max = 10): string
    {
        $shown = array_slice($items, 0, $max);
        $suffix = count($items) > $max ? ', … +'.(count($items) - $max).' more' : '';

        return '`'.implode('`, `', $shown).'`'.$suffix;
    }
}
