<?php

namespace Tackle\Evals;

/**
 * One case's outcome: the grade plus what it cost to get there.
 *
 * inputTokens is *fresh* input. With prompt caching on it is a tiny fraction
 * of what the case actually put through the model, so a report that showed it
 * alone made every run look free and could not compare two agents' context
 * volume at all — which is most of what an eval is for.
 */
class EvalResult
{
    public function __construct(
        public readonly string $caseId,
        public readonly string $category,
        public readonly EvalGrade $grade,
        public readonly int $inputTokens,
        public readonly int $outputTokens,
        public readonly float $costUsd,
        public readonly int $durationMs,
        public readonly ?string $error = null,
        // Appended rather than grouped with the other token fields: the
        // constructor is called positionally, including by anyone with a
        // custom eval harness.
        public readonly int $cacheReadTokens = 0,
        public readonly int $cacheWriteTokens = 0,
        /** @var list<string> tool names in call order */
        public readonly array $toolCalls = [],
    ) {}

    /**
     * How many times each tool was called, most-used first — the shape of the
     * run rather than its length.
     *
     * @return array<string, int>
     */
    public function toolCounts(): array
    {
        $counts = array_count_values($this->toolCalls);
        arsort($counts);

        return $counts;
    }

    public function status(): string
    {
        if ($this->error !== null) {
            return 'error';
        }
        if ($this->grade->isFalseFix()) {
            return 'false-fix';
        }

        return $this->grade->fixed ? 'fixed' : 'not-fixed';
    }
}
