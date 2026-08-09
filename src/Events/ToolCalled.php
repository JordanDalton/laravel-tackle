<?php

namespace Tackle\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dispatched after a tool has executed, with its result and duration.
 */
class ToolCalled
{
    use Dispatchable;

    public function __construct(
        public readonly string $tool,
        public readonly array $arguments,
        public readonly string $result,
        public readonly float $durationMs,
    ) {}
}
