<?php

namespace Tackle\Support;

use Illuminate\Container\Attributes\Config;

class BudgetTracker
{
    private int $inputTokens = 0;

    private int $outputTokens = 0;

    private float $accruedCost = 0.0;

    private float $budgetUsd;

    private float $inputCostPerM;

    private float $outputCostPerM;

    private bool $explicitPricing;

    /**
     * Pricing resolution: explicit tackle.pricing rates win when set;
     * otherwise rates are looked up in the ModelCatalog for the configured
     * model, falling back to Claude Sonnet-class rates ($3/$15) when the
     * model is unknown. Budget enforcement is only as accurate as these
     * numbers — for unknown models, set AI_CODE_PRICE_INPUT /
     * AI_CODE_PRICE_OUTPUT or add the model to tackle.pricing.models.
     */
    public function __construct(
        #[Config('tackle.budget_usd')] float $budgetUsd = 1.00,
        #[Config('tackle.pricing.input_per_mtok')] ?float $inputCostPerM = null,
        #[Config('tackle.pricing.output_per_mtok')] ?float $outputCostPerM = null,
    ) {
        $this->budgetUsd = $budgetUsd;
        $this->explicitPricing = $inputCostPerM !== null || $outputCostPerM !== null;

        if ($this->explicitPricing) {
            $this->inputCostPerM = $inputCostPerM ?? 3.00;
            $this->outputCostPerM = $outputCostPerM ?? 15.00;
        } else {
            $rates = ModelCatalog::pricing((string) config('tackle.model', ''));
            $this->inputCostPerM = $rates['input'] ?? 3.00;
            $this->outputCostPerM = $rates['output'] ?? 15.00;
        }
    }

    /**
     * Cost accrues at the rates in effect when the tokens were spent, so a
     * mid-session model switch reprices future usage without rewriting the
     * cost of what already ran.
     */
    public function record(int $inputTokens, int $outputTokens): void
    {
        $this->inputTokens += $inputTokens;
        $this->outputTokens += $outputTokens;
        $this->accruedCost += ($inputTokens / 1_000_000 * $this->inputCostPerM)
            + ($outputTokens / 1_000_000 * $this->outputCostPerM);
    }

    /**
     * Switch to the given model's catalog rates for usage recorded from now
     * on. Returns false (keeping the current rates) when the model has no
     * catalog entry — the caller should warn that enforcement is estimated.
     */
    public function repriceFor(string $model): bool
    {
        $rates = ModelCatalog::pricing($model);

        if ($rates === null) {
            return false;
        }

        $this->inputCostPerM = $rates['input'];
        $this->outputCostPerM = $rates['output'];

        return true;
    }

    /**
     * @return array{input: float, output: float}
     */
    public function rates(): array
    {
        return ['input' => $this->inputCostPerM, 'output' => $this->outputCostPerM];
    }

    public function hasExplicitPricing(): bool
    {
        return $this->explicitPricing;
    }

    public function estimatedCost(): float
    {
        return $this->accruedCost;
    }

    public function overBudget(): bool
    {
        return $this->estimatedCost() >= $this->budgetUsd;
    }

    public function inputTokens(): int
    {
        return $this->inputTokens;
    }

    public function outputTokens(): int
    {
        return $this->outputTokens;
    }

    public function budgetUsd(): float
    {
        return $this->budgetUsd;
    }

    public function summary(): string
    {
        return sprintf(
            'Tokens used — input: %d, output: %d | Estimated cost: $%.4f / $%.2f budget',
            $this->inputTokens,
            $this->outputTokens,
            $this->estimatedCost(),
            $this->budgetUsd,
        );
    }
}
