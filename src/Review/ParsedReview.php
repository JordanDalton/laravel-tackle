<?php

namespace Tackle\Review;

class ParsedReview
{
    /** @param  Finding[]  $findings */
    public function __construct(
        public readonly string $verdict,
        public readonly array $findings,
    ) {}

    public function has(string $severity): bool
    {
        foreach ($this->findings as $finding) {
            if ($finding->severity === $severity) {
                return true;
            }
        }

        return false;
    }
}
