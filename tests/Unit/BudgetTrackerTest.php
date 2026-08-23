<?php

use Tackle\Support\BudgetTracker;

it('uses configured per-model pricing when provided', function () {
    // GPT-class pricing: $2.50 in / $10 out per million tokens.
    $tracker = new BudgetTracker(1.00, 2.50, 10.00);
    $tracker->record(1_000_000, 1_000_000);

    expect($tracker->estimatedCost())->toBe(12.50);
});

it('treats zero pricing as free (local models)', function () {
    $tracker = new BudgetTracker(1.00, 0.0, 0.0);
    $tracker->record(5_000_000, 5_000_000);

    expect($tracker->estimatedCost())->toBe(0.0)
        ->and($tracker->overBudget())->toBeFalse();
});

it('resolves pricing from config through the container', function () {
    config()->set('tackle.pricing.input_per_mtok', 1.00);
    config()->set('tackle.pricing.output_per_mtok', 2.00);

    $tracker = app(BudgetTracker::class);
    $tracker->record(1_000_000, 1_000_000);

    expect($tracker->estimatedCost())->toBe(3.00);
});

it('starts with zero spend', function () {
    $tracker = new BudgetTracker(1.00);

    expect($tracker->estimatedCost())->toBe(0.0);
    expect($tracker->overBudget())->toBeFalse();
});

it('tracks token usage across multiple calls', function () {
    $tracker = new BudgetTracker(1.00);
    $tracker->record(1000, 500);
    $tracker->record(2000, 1000);

    expect($tracker->inputTokens())->toBe(3000);
    expect($tracker->outputTokens())->toBe(1500);
});

it('reports over budget when estimated cost exceeds limit', function () {
    // 1M output tokens at $15/M = $15 — way over a $1 budget.
    $tracker = new BudgetTracker(1.00);
    $tracker->record(0, 1_000_000);

    expect($tracker->overBudget())->toBeTrue();
});

it('does not report over budget when under limit', function () {
    // 10k tokens in and out is tiny.
    $tracker = new BudgetTracker(1.00);
    $tracker->record(10_000, 10_000);

    expect($tracker->overBudget())->toBeFalse();
});

it('resolves rates from the model catalog when no explicit pricing is set', function () {
    config()->set('tackle.model', 'claude-opus-5');
    config()->set('tackle.pricing.input_per_mtok', null);
    config()->set('tackle.pricing.output_per_mtok', null);

    $tracker = app(BudgetTracker::class);
    $tracker->record(1_000_000, 1_000_000);

    // Opus-class: $5 in / $25 out per MTok.
    expect($tracker->estimatedCost())->toBe(30.00)
        ->and($tracker->hasExplicitPricing())->toBeFalse();
});

it('reprices only future usage when switching models', function () {
    $tracker = new BudgetTracker(100.00, 3.00, 15.00);
    $tracker->record(1_000_000, 0); // $3 at the old rate

    expect($tracker->repriceFor('claude-opus-5'))->toBeTrue();

    $tracker->record(1_000_000, 0); // $5 at the new rate

    expect($tracker->estimatedCost())->toBe(8.00)
        ->and($tracker->rates())->toBe(['input' => 5.00, 'output' => 25.00]);
});

it('keeps current rates when repricing to an unknown model', function () {
    $tracker = new BudgetTracker(100.00, 3.00, 15.00);

    expect($tracker->repriceFor('mystery-model-9000'))->toBeFalse()
        ->and($tracker->rates())->toBe(['input' => 3.00, 'output' => 15.00]);
});

it('produces a readable summary string', function () {
    $tracker = new BudgetTracker(1.00);
    $tracker->record(1000, 500);

    expect($tracker->summary())->toContain('Tokens used')
        ->toContain('1000')
        ->toContain('500');
});

it('prices cache reads at ~10% and the first write at 1.25x of the input rate', function () {
    // $3/MTok input, $15/MTok output.
    $tracker = new BudgetTracker(10.00, 3.00, 15.00);

    // 1M fresh input + 1M cache read + 1M cache write + 100k output.
    $tracker->record(1_000_000, 100_000, 1_000_000, 1_000_000);

    // fresh 3.00 + read 0.30 + write 3.75 + output 1.50 = 8.55
    expect(round($tracker->estimatedCost(), 4))->toBe(8.55)
        ->and($tracker->cacheReadTokens())->toBe(1_000_000)
        ->and($tracker->cacheWriteTokens())->toBe(1_000_000);
});

it('is backward compatible — record() without cache args prices only fresh tokens', function () {
    $tracker = new BudgetTracker(10.00, 3.00, 15.00);
    $tracker->record(1_000_000, 1_000_000);

    expect($tracker->estimatedCost())->toBe(18.00)
        ->and($tracker->cacheReadTokens())->toBe(0);
});

it('surfaces cached tokens in the summary when present', function () {
    $tracker = new BudgetTracker(10.00, 3.00, 15.00);
    $tracker->record(1000, 100, 5000, 500);

    expect($tracker->summary())->toContain('cached: 5000 read / 500 write');
});
