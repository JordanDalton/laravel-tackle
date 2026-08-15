<?php

use Tackle\Support\ModelCatalog;

it('knows built-in anthropic pricing', function () {
    expect(ModelCatalog::pricing('claude-opus-5'))->toBe(['input' => 5.00, 'output' => 25.00])
        ->and(ModelCatalog::pricing('claude-haiku-4-5'))->toBe(['input' => 1.00, 'output' => 5.00]);
});

it('prefix-matches date-suffixed model ids', function () {
    expect(ModelCatalog::pricing('claude-haiku-4-5-20251001'))->toBe(['input' => 1.00, 'output' => 5.00]);
});

it('prefers the longest prefix match', function () {
    // gpt-4o-mini-2024... must hit gpt-4o-mini, not gpt-4o.
    expect(ModelCatalog::pricing('gpt-4o-mini-2024-07-18'))->toBe(ModelCatalog::pricing('gpt-4o-mini'))
        ->and(ModelCatalog::pricing('gpt-4o-mini'))->not->toBe(ModelCatalog::pricing('gpt-4o'));
});

it('knows grok pricing and keeps siblings distinct', function () {
    expect(ModelCatalog::pricing('grok-4'))->toBe(['input' => 3.00, 'output' => 15.00])
        // A dated variant prefix-matches its family…
        ->and(ModelCatalog::pricing('grok-4-0709'))->toBe(ModelCatalog::pricing('grok-4'))
        // …while the longer sibling id wins over the shorter prefix.
        ->and(ModelCatalog::pricing('grok-4-fast-reasoning'))->toBe(['input' => 0.20, 'output' => 0.50]);
});

it('strips the bedrock provider prefix', function () {
    expect(ModelCatalog::pricing('anthropic.claude-opus-5'))->toBe(['input' => 5.00, 'output' => 25.00]);
});

it('returns null for unknown models', function () {
    expect(ModelCatalog::pricing('mystery-model-9000'))->toBeNull();
});

it('merges custom entries from config, overriding built-ins', function () {
    config()->set('tackle.pricing.models', [
        'my-local-model' => ['input' => 0, 'output' => 0],
        'claude-opus-5' => ['input' => 4.00, 'output' => 20.00],
    ]);

    expect(ModelCatalog::pricing('my-local-model'))->toBe(['input' => 0.0, 'output' => 0.0])
        ->and(ModelCatalog::pricing('claude-opus-5'))->toBe(['input' => 4.00, 'output' => 20.00]);
});
