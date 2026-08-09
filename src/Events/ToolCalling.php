<?php

namespace Tackle\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dispatched before a tool executes. A listener may veto the call by returning
 * false (the agent receives a generic refusal) or a string (used as the
 * refusal message). Return nothing to observe only.
 */
class ToolCalling
{
    use Dispatchable;

    public function __construct(
        public readonly string $tool,
        public readonly array $arguments,
    ) {}
}
