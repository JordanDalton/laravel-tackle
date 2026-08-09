<?php

namespace Tackle\Support;

use Illuminate\Container\Attributes\Config;

class BudgetTracker
{
    private int $inputTokens = 0;

    private int $outputTokens = 0;

    private float $budgetUsd;

    private float $inputCostPerM;

    private float $outputCostPerM;

    /**
     * Pricing defaults approximate Claude Sonnet rates. Budget enforcement is
     * only as accurate as these numbers — when using another model or
     * provider, set tackle.pricing (AI_CODE_PRICE_INPUT / AI_CODE_PRICE_OUTPUT)
     * to its per-million-token rates.
     */
    public function __construct(
        #[Config('tackle.budget_usd')] float $budgetUsd = 1.00,
        #[Config('tackle.pricing.input_per_mtok')] ?float $inputCostPerM = null,
        #[Config('tackle.pricing.output_per_mtok')] ?float $outputCostPerM = null,
    ) {
        $this->budgetUsd = $budgetUsd;
        $this->inputCostPerM = $inputCostPerM ?? 3.00;
        $this->outputCostPerM = $outputCostPerM ?? 15.00;
    }

    public function record(int $inputTokens, int $outputTokens): void
    {
        $this->inputTokens += $inputTokens;
        $this->outputTokens += $outputTokens;
    }

    public function estimatedCost(): float
    {
        return ($this->inputTokens / 1_000_000 * $this->inputCostPerM)
             + ($this->outputTokens / 1_000_000 * $this->outputCostPerM);
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
