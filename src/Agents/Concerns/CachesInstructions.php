<?php

namespace Tackle\Agents\Concerns;

use Laravel\Ai\Enums\Lab;
use Tackle\Support\ConversationCache;

/**
 * Marks the system prompt with an Anthropic `cache_control` breakpoint via
 * provider options. Anthropic caches the cumulative prefix (tools, then
 * system), so a breakpoint on the system block caches BOTH the tool schemas
 * and the instructions — the fixed per-step floor that is otherwise re-sent at
 * full price on every step. Cached reads bill at ~10%.
 *
 * That covers the part of the request that never changes. The part that grows
 * is the conversation, and laravel/ai owns those messages — so this also arms
 * ConversationCache, which puts a second breakpoint at the end of the message
 * list on its way out over the wire. laravel/ai calls providerOptions() while
 * building the body, so arming here scopes that rewrite to exactly the request
 * about to be sent.
 *
 * The class using this trait must `implements HasProviderOptions` and expose
 * `instructions()` (every CodingAgent does). No-op for non-Anthropic providers.
 */
trait CachesInstructions
{
    public function providerOptions($provider): array
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

        ConversationCache::arm();

        return [
            'system' => [[
                'type' => 'text',
                'text' => $system,
                'cache_control' => ['type' => 'ephemeral'],
            ]],
        ];
    }
}
