<?php

namespace Tackle\Support;

use function Laravel\Ai\agent;

class ProviderProbe
{
    public function verify(?string $provider = null, ?string $model = null): void
    {
        $provider ??= (string) config('tackle.provider', 'anthropic');
        $model ??= (string) config('tackle.model');

        agent(
            instructions: 'You are a provider connectivity check. Reply with OK and nothing else.',
        )->prompt('OK', provider: $provider, model: $model, timeout: 15);
    }
}
