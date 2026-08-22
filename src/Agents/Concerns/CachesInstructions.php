<?php

namespace Tackle\Agents\Concerns;

use Laravel\Ai\Enums\Lab;

/**
 * Marks the system prompt with an Anthropic `cache_control` breakpoint via
 * provider options. Anthropic caches the cumulative prefix (tools, then
 * system), so a breakpoint on the system block caches BOTH the tool schemas
 * and the instructions — the fixed per-step floor that is otherwise re-sent at
 * full price on every step. Cached reads bill at ~10%.
 *
 * The class using this trait must `implements HasProviderOptions` and expose
 * `instructions()` (every CodingAgent does). No-op for non-Anthropic providers.
 */
trait CachesInstructions
{
    public function providerOptions(Lab|string $provider): array
    {
        if (! (bool) config('tackle.prompt_cache', true)) {
            return [];
        }

        $name = $provider instanceof Lab ? $provider->value : (string) $provider;

        if ($name !== 'anthropic') {
            return [];
        }

        $system = trim((string) $this->instructions());

        if ($system === '') {
            return [];
        }

        return [
            'system' => [[
                'type' => 'text',
                'text' => $system,
                'cache_control' => ['type' => 'ephemeral'],
            ]],
        ];
    }
}
