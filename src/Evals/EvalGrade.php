<?php

namespace Tackle\Evals;

/**
 * The grader's verdict on a single eval case.
 *
 * - fixed: the target behaviour is now correct.
 * - brokeHappyPath: a previously-correct behaviour is now wrong. Combined with
 *   `fixed`, this is a FALSE FIX — the agent "solved" the bug but regressed
 *   something else, the most dangerous outcome to measure.
 */
class EvalGrade
{
    public function __construct(
        public readonly bool $fixed,
        public readonly bool $brokeHappyPath = false,
        public readonly string $note = '',
    ) {}

    /** A genuine, non-regressing fix. */
    public function isClean(): bool
    {
        return $this->fixed && ! $this->brokeHappyPath;
    }

    /** Claimed the fix but regressed something — worse than not fixing. */
    public function isFalseFix(): bool
    {
        return $this->brokeHappyPath;
    }
}
