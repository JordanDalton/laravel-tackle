<?php

namespace Tackle\Events;

use Illuminate\Foundation\Events\Dispatchable;

class SessionEnded
{
    use Dispatchable;

    public function __construct(
        public readonly string $command,
        public readonly int $inputTokens,
        public readonly int $outputTokens,
        public readonly float $estimatedCostUsd,
    ) {}
}
