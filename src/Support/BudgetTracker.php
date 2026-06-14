<?php

namespace Tackle\Support;

use Illuminate\Container\Attributes\Config;

class BudgetTracker
{
    // Pricing per million tokens (approximate Sonnet 4 rates; overrideable).
    private const INPUT_COST_PER_M  = 3.00;
    private const OUTPUT_COST_PER_M = 15.00;

    private int   $inputTokens  = 0;
    private int   $outputTokens = 0;
    private float $budgetUsd;

    public function __construct(
        #[Config('ai-code.budget_usd')] float $budgetUsd = 1.00,
    ) {
        $this->budgetUsd = $budgetUsd;
    }

    public function record(int $inputTokens, int $outputTokens): void
    {
        $this->inputTokens  += $inputTokens;
        $this->outputTokens += $outputTokens;
    }

    public function estimatedCost(): float
    {
        return ($this->inputTokens  / 1_000_000 * self::INPUT_COST_PER_M)
             + ($this->outputTokens / 1_000_000 * self::OUTPUT_COST_PER_M);
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
