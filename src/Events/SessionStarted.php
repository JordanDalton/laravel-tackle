<?php

namespace Tackle\Events;

use Illuminate\Foundation\Events\Dispatchable;

class SessionStarted
{
    use Dispatchable;

    public function __construct(
        public readonly string $command, // ai:code | ai:run | ...
        public readonly string $provider,
        public readonly string $model,
    ) {}
}
