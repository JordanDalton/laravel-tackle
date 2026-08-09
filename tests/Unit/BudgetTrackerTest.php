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

it('produces a readable summary string', function () {
    $tracker = new BudgetTracker(1.00);
    $tracker->record(1000, 500);

    expect($tracker->summary())->toContain('Tokens used')
        ->toContain('1000')
        ->toContain('500');
});
