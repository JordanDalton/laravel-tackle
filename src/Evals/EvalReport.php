<?php

namespace Tackle\Evals;

/**
 * Aggregates case results into the numbers that make harness changes
 * measurable: fix rate, false-fix rate, regression rate, tokens, and cost.
 * Without these, every change to prompts/tools/guards is a guess.
 */
class EvalReport
{
    /** @param list<EvalResult> $results */
    public function __construct(public readonly array $results) {}

    public function total(): int
    {
        return count($this->results);
    }

    public function fixed(): int
    {
        return $this->count(fn (EvalResult $r) => $r->grade->isClean());
    }

    public function falseFixes(): int
    {
        return $this->count(fn (EvalResult $r) => $r->grade->isFalseFix());
    }

    public function notFixed(): int
    {
        return $this->count(fn (EvalResult $r) => ! $r->grade->fixed && ! $r->grade->isFalseFix() && $r->error === null);
    }

    public function errors(): int
    {
        return $this->count(fn (EvalResult $r) => $r->error !== null);
    }

    public function fixRate(): float
    {
        return $this->rate($this->fixed());
    }

    public function falseFixRate(): float
    {
        return $this->rate($this->falseFixes());
    }

    public function totalInputTokens(): int
    {
        return (int) array_sum(array_map(fn (EvalResult $r) => $r->inputTokens, $this->results));
    }

    public function totalOutputTokens(): int
    {
        return (int) array_sum(array_map(fn (EvalResult $r) => $r->outputTokens, $this->results));
    }

    public function totalCacheReadTokens(): int
    {
        return (int) array_sum(array_map(fn (EvalResult $r) => $r->cacheReadTokens, $this->results));
    }

    public function totalCacheWriteTokens(): int
    {
        return (int) array_sum(array_map(fn (EvalResult $r) => $r->cacheWriteTokens, $this->results));
    }

    /**
     * Every input token the suite put through the model, cached or not — the
     * figure to compare when asking whether a change to the agent made it
     * carry less context.
     */
    public function totalContextTokens(): int
    {
        return $this->totalInputTokens() + $this->totalCacheReadTokens() + $this->totalCacheWriteTokens();
    }

    public function totalCost(): float
    {
        return (float) array_sum(array_map(fn (EvalResult $r) => $r->costUsd, $this->results));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'total' => $this->total(),
            'fixed' => $this->fixed(),
            'false_fixes' => $this->falseFixes(),
            'not_fixed' => $this->notFixed(),
            'errors' => $this->errors(),
            'fix_rate' => round($this->fixRate(), 4),
            'false_fix_rate' => round($this->falseFixRate(), 4),
            'input_tokens' => $this->totalInputTokens(),
            'output_tokens' => $this->totalOutputTokens(),
            'cache_read_tokens' => $this->totalCacheReadTokens(),
            'cache_write_tokens' => $this->totalCacheWriteTokens(),
            'context_tokens' => $this->totalContextTokens(),
            'cost_usd' => round($this->totalCost(), 4),
            'by_case' => array_map(fn (array $runs) => [
                'runs' => count($runs),
                'fixed' => count(array_filter($runs, fn (EvalResult $r) => $r->status() === 'fixed')),
                'false_fixes' => count(array_filter($runs, fn (EvalResult $r) => $r->status() === 'false-fix')),
                'errors' => count(array_filter($runs, fn (EvalResult $r) => $r->status() === 'error')),
                'cost_min' => round(min(array_map(fn (EvalResult $r) => $r->costUsd, $runs)), 4),
                'cost_max' => round(max(array_map(fn (EvalResult $r) => $r->costUsd, $runs)), 4),
                'cost_mean' => round(array_sum(array_map(fn (EvalResult $r) => $r->costUsd, $runs)) / count($runs), 4),
            ], $this->byCase()),
            'cases' => array_map(fn (EvalResult $r) => [
                'id' => $r->caseId,
                'category' => $r->category,
                'status' => $r->status(),
                'input_tokens' => $r->inputTokens,
                'output_tokens' => $r->outputTokens,
                'cache_read_tokens' => $r->cacheReadTokens,
                'cache_write_tokens' => $r->cacheWriteTokens,
                'context_tokens' => $r->inputTokens + $r->cacheReadTokens + $r->cacheWriteTokens,
                'cost_usd' => round($r->costUsd, 4),
                'tool_calls' => $r->toolCalls,
                'tool_counts' => $r->toolCounts(),
                'kept_dir' => $r->keptDir,
                'duration_ms' => $r->durationMs,
                'note' => $r->grade->note,
                'error' => $r->error,
            ], $this->results),
        ];
    }

    /**
     * A run's shape as a compact line: ReadFile×3 SearchCode×2 EditFile RunTests.
     *
     * Two arms of the same corpus are then diffable by eye — which is the
     * question a total step count cannot answer.
     *
     * @param  array<string, int>  $counts
     */
    private static function toolShape(array $counts): string
    {
        return implode(' ', array_map(
            fn (string $tool, int $n): string => $n > 1 ? "{$tool}×{$n}" : $tool,
            array_keys($counts),
            $counts,
        ));
    }

    /**
     * Results grouped by case id, in first-seen order. With --repeat every
     * case appears several times; the per-case story is then a rate and a
     * cost range, not a verdict.
     *
     * @return array<string, list<EvalResult>>
     */
    public function byCase(): array
    {
        $groups = [];
        foreach ($this->results as $r) {
            $groups[$r->caseId][] = $r;
        }

        return $groups;
    }

    private function isRepeated(): bool
    {
        return count($this->byCase()) < count($this->results);
    }

    /**
     * @param  list<EvalResult>  $runs
     */
    private function renderGroup(string $id, array $runs): string
    {
        $n = count($runs);
        $fixed = count(array_filter($runs, fn (EvalResult $r) => $r->status() === 'fixed'));
        $false = count(array_filter($runs, fn (EvalResult $r) => $r->status() === 'false-fix'));
        $errors = count(array_filter($runs, fn (EvalResult $r) => $r->status() === 'error'));
        $costs = array_map(fn (EvalResult $r) => $r->costUsd, $runs);
        $icon = $fixed === $n ? '✅' : ($false > 0 ? '🟥' : ($errors > 0 ? '⚠️' : '❌'));

        return sprintf(
            '  %s  %-28s fixed %d/%d · false-fix %d/%d%s · $%.4f–$%.4f (mean $%.4f)',
            $icon, mb_strimwidth($id, 0, 28), $fixed, $n, $false, $n,
            $errors > 0 ? " · errors {$errors}/{$n}" : '',
            min($costs), max($costs), array_sum($costs) / $n,
        );
    }

    public function render(): string
    {
        if ($this->isRepeated()) {
            $rows = [];
            foreach ($this->byCase() as $id => $runs) {
                $rows[] = $this->renderGroup($id, $runs);
            }

            return implode("\n", $rows)."\n\n".$this->summaryLine();
        }

        $rows = [];
        foreach ($this->results as $r) {
            $icon = match ($r->status()) {
                'fixed' => '✅',
                'false-fix' => '🟥',
                'not-fixed' => '❌',
                default => '⚠️',
            };
            $rows[] = sprintf(
                '  %s  %-28s %-9s %8s tok  $%.4f  %ds%s',
                $icon,
                mb_strimwidth($r->caseId, 0, 28),
                $r->status(),
                number_format($r->inputTokens + $r->cacheReadTokens + $r->cacheWriteTokens + $r->outputTokens),
                $r->costUsd,
                (int) round($r->durationMs / 1000),
                $r->error !== null ? '  — '.$r->error : ($r->grade->note !== '' ? '  — '.$r->grade->note : ''),
            );

            if ($r->toolCalls !== []) {
                $rows[] = '      '.self::toolShape($r->toolCounts());
            }

            if ($r->keptDir !== null) {
                $rows[] = '      '.$r->keptDir;
            }
        }

        return implode("\n", $rows)."\n\n".$this->summaryLine();
    }

    private function summaryLine(): string
    {
        $summary = sprintf(
            "Runs: %d  ·  fixed: %d (%.0f%%)  ·  false-fixes: %d (%.0f%%)  ·  not-fixed: %d  ·  errors: %d\n".
            'Context: %s in (%s fresh) / %s out  ·  Cost: $%.4f',
            $this->total(),
            $this->fixed(), $this->fixRate() * 100,
            $this->falseFixes(), $this->falseFixRate() * 100,
            $this->notFixed(),
            $this->errors(),
            number_format($this->totalContextTokens()),
            number_format($this->totalInputTokens()),
            number_format($this->totalOutputTokens()),
            $this->totalCost(),
        );

        return $summary;
    }

    private function count(callable $pred): int
    {
        return count(array_filter($this->results, $pred));
    }

    private function rate(int $n): float
    {
        return $this->total() === 0 ? 0.0 : $n / $this->total();
    }
}
