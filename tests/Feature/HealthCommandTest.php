<?php

use Tackle\Support\ProviderProbe;

it('can verify provider credentials with an explicit live probe', function () {
    config()->set('tackle.provider', 'anthropic');
    config()->set('tackle.model', 'claude-test');
    config()->set('ai.providers.anthropic', ['key' => 'sk-test']);

    $this->mock(ProviderProbe::class, function ($mock) {
        $mock->shouldReceive('verify')
            ->once()
            ->with('anthropic', 'claude-test');
    });

    $this->artisan('tackle:health', ['--probe-provider' => true])
        ->expectsOutputToContain('Provider [anthropic] accepted a live request');
});

it('reports rejected provider credentials with an actionable hint', function () {
    config()->set('tackle.provider', 'anthropic');
    config()->set('tackle.model', 'claude-test');
    config()->set('ai.providers.anthropic', ['key' => 'sk-invalid']);

    $this->mock(ProviderProbe::class, function ($mock) {
        $mock->shouldReceive('verify')
            ->once()
            ->andThrow(new RuntimeException('HTTP request returned status code 401'));
    });

    $this->artisan('tackle:health', ['--probe-provider' => true])
        ->expectsOutputToContain('Provider [anthropic] rejected the live request')
        ->expectsOutputToContain('Verify the provider API key and URL');
});
