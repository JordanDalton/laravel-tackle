<?php

namespace Tackle\Evals;

/**
 * One case's outcome: the grade plus what it cost to get there.
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
    ) {}

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
