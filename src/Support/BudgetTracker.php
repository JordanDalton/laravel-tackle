<?php

namespace Tackle\Support;

use Illuminate\Container\Attributes\Config;

class BudgetTracker
{
    private int $inputTokens = 0;

    private int $outputTokens = 0;

    private float $accruedCost = 0.0;

    /**
     * Characters of tool output pulled into context during the current turn.
     * The budget can only be checked when a stream ends, but context grows
     * with every tool result and is re-sent on every step — this is the
     * mid-turn signal. Reset when usage for the turn is recorded.
     */
    private int $contextChars = 0;

    /**
     * Estimated USD spent on the current turn but not yet recorded. Usage is
     * only reported at stream end, yet every tool call triggers another step
     * that re-sends the whole grown context — so a single turn can spend many
     * multiples of the budget before the end-of-stream check ever runs. This
     * accrues that in-flight cost from the growing context so the ceiling can
     * be enforced mid-turn.
     */
    private float $inFlightCost = 0.0;

    /** Rough chars-per-token for input-size estimation (code/English ~3.5-4). */
    private const CHARS_PER_TOKEN = 4;

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

        // A stream has ended; the next turn starts with a clean context and
        // the in-flight estimate is superseded by the real recorded usage.
        $this->contextChars = 0;
        $this->inFlightCost = 0.0;
    }

    /**
     * Note tool output that just entered the context of the current turn, and
     * charge the estimated cost of re-sending the now-larger context on the
     * next step. Called once per tool call, so the estimate tracks the actual
     * re-send pattern: cost grows with both context size and step count.
     */
    public function recordToolOutput(int $chars): void
    {
        $this->contextChars += max(0, $chars);

        $projectedInputTokens = $this->contextChars / self::CHARS_PER_TOKEN;
        $this->inFlightCost += $projectedInputTokens / 1_000_000 * $this->inputCostPerM;
    }

    /**
     * Total spend so far this session including the current turn's estimated,
     * not-yet-recorded cost — the number to check mid-turn.
     */
    public function projectedCost(): float
    {
        return $this->estimatedCost() + $this->inFlightCost;
    }

    /**
     * True once the current turn's projected spend has reached the budget —
     * the mid-turn hard stop that end-of-stream checking cannot provide.
     * Always false when pricing is free (local models), where the character
     * ceiling is the only guard.
     */
    public function projectedOverBudget(): bool
    {
        if ($this->inputCostPerM <= 0.0 && $this->outputCostPerM <= 0.0) {
            return false;
        }

        return $this->projectedCost() >= $this->budgetUsd;
    }

    public function contextChars(): int
    {
        return $this->contextChars;
    }

    /**
     * Replace the per-turn counter — used around a subagent run so the child
     * starts clean and the parent's count survives the child's stream end.
     */
    public function resetContextChars(int $to = 0): void
    {
        $this->contextChars = max(0, $to);
    }

    public function inFlightCost(): float
    {
        return $this->inFlightCost;
    }

    /**
     * Save/restore the in-flight estimate around a subagent run, mirroring
     * resetContextChars — the child accrues its own, the parent keeps its own.
     */
    public function resetInFlightCost(float $to = 0.0): void
    {
        $this->inFlightCost = max(0.0, $to);
    }

    public function contextCeilingChars(): int
    {
        $configured = config('tackle.max_context_chars', 600_000);

        return is_numeric($configured) && (int) $configured > 0 ? (int) $configured : 600_000;
    }

    public function contextCeilingReached(): bool
    {
        return $this->contextChars >= $this->contextCeilingChars();
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
