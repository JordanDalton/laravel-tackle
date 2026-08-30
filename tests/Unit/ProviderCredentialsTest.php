<?php

use Tackle\Support\ProviderCredentials;

// laravel/ai's config carries the intent: 'key' => env('X') is null when the
// required key is unset; 'key' => env('X', '') is optional by design.

it('names the provider and the fix when a required key is absent', function () {
    config()->set('ai.providers.openai.key', null);
    config()->set('tackle.provider', 'openai');

    expect(ProviderCredentials::missing())
        ->toContain("Provider 'openai' has no API key")
        ->toContain('OPENAI_API_KEY')
        ->toContain('workflow');
});

it('passes when the key is set', function () {
    config()->set('ai.providers.openai.key', 'sk-test');

    expect(ProviderCredentials::missing('openai'))->toBeNull();
});

it('treats an empty-string default as optional by design', function () {
    // Ollama declares env('OLLAMA_API_KEY', '') — keyless on purpose.
    config()->set('ai.providers.ollama.key', '');

    expect(ProviderCredentials::missing('ollama'))->toBeNull();
});

it('leaves unknown providers to laravel/ai', function () {
    expect(ProviderCredentials::missing('made-up'))->toBeNull();
});
