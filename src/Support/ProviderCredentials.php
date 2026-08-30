<?php

namespace Tackle\Support;

/**
 * Fail a run in milliseconds, not after a CI boot, when the chosen provider
 * has no API key.
 *
 * Picking a model in a settings UI and learning about the missing key from
 * "HTTP request returned status code 401" on the run page — after a runner
 * booted, on every PR, reviews only — is the worst version of this error.
 * laravel/ai's own config already says which providers need a key: entries
 * declare `'key' => env('X')` (null when unset — required and missing) or
 * `'key' => env('X', '')` (empty-string default — optional by design, e.g.
 * Ollama). Reading that intent means no hardcoded provider table here.
 */
class ProviderCredentials
{
    /**
     * An actionable error when the provider's key is required and absent,
     * or null when the run can proceed.
     */
    public static function missing(?string $provider = null): ?string
    {
        $provider = $provider ?: (string) config('tackle.provider', 'anthropic');

        $entry = config("ai.providers.{$provider}");

        // Unknown provider: laravel/ai will refuse it with its own message.
        if (! is_array($entry) || ! array_key_exists('key', $entry)) {
            return null;
        }

        // '' is a declared default — the provider works without a key.
        if ($entry['key'] !== null) {
            return null;
        }

        $hint = strtoupper($provider).'_API_KEY';

        return "Provider '{$provider}' has no API key configured. Set its key (usually {$hint}) in the "
            .'environment the run executes in — for CI runs, pass the secret in your workflow. '
            .'Reviews using a pinned reviewer model need that model\'s provider key too.';
    }
}
