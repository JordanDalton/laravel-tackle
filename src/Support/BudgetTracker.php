<?php

namespace Tackle\Support;

use Illuminate\Container\Attributes\Config;

class BudgetTracker
{
    private int $inputTokens = 0;

    private int $outputTokens = 0;

    private int $cacheReadTokens = 0;

    private int $cacheWriteTokens = 0;

    private float $accruedCost = 0.0;

    /**
     * Whether the provider ever reported real usage.
     *
     * Usage arrives once, on StreamEnd, at the end of the whole multi-step
     * loop — so a run that dies mid-loop has real token counts for none of the
     * steps it took. Reporting $0.0000 for such a run is worse than reporting
     * an estimate, because the runs that die mid-loop are the long ones.
     */
    private bool $measured = false;

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

    /** Anthropic cache economics: reads bill at ~10%, the first write at 1.25x. */
    private const CACHE_READ_MULTIPLIER = 0.10;

    private const CACHE_WRITE_MULTIPLIER = 1.25;

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
    public function record(
        int $inputTokens,
        int $outputTokens,
        int $cacheReadTokens = 0,
        int $cacheWriteTokens = 0,
    ): void {
        $this->measured = true;
        $this->inputTokens += $inputTokens;
        $this->outputTokens += $outputTokens;
        $this->cacheReadTokens += $cacheReadTokens;
        $this->cacheWriteTokens += $cacheWriteTokens;

        // Fresh input + output at full rate; cache reads at ~10% and the first
        // cache write at 1.25x of the input rate. Without this, budgets and
        // eval costs undercount whenever prompt caching is on.
        $this->accruedCost += ($inputTokens / 1_000_000 * $this->inputCostPerM)
            + ($outputTokens / 1_000_000 * $this->outputCostPerM)
            + ($cacheReadTokens / 1_000_000 * $this->inputCostPerM * self::CACHE_READ_MULTIPLIER)
            + ($cacheWriteTokens / 1_000_000 * $this->inputCostPerM * self::CACHE_WRITE_MULTIPLIER);

        // A stream has ended; the next turn starts with a clean context and
        // the in-flight estimate is superseded by the real recorded usage.
        $this->contextChars = 0;
        $this->inFlightCost = 0.0;
    }

    /** Whether the figures came from the provider or from estimation. */
    public function measured(): bool
    {
        return $this->measured;
    }

    public function cacheReadTokens(): int
    {
        return $this->cacheReadTokens;
    }

    public function cacheWriteTokens(): int
    {
        return $this->cacheWriteTokens;
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

    /**
     * Every input token the run was billed for, however it was priced.
     * inputTokens() counts only the fresh ones, so it is the wrong number to
     * ask "did this reach the model at all?" — with caching on, most of a
     * step's input can arrive as a cache read.
     */
    public function totalInputTokens(): int
    {
        return $this->inputTokens + $this->cacheReadTokens + $this->cacheWriteTokens;
    }

    /**
     * Share of input tokens served from cache, 0.0-1.0. The one number that
     * answers whether caching is actually working: a long agent run re-sends
     * its whole context on every step, so a low rate here means that context
     * is being bought again at full price on each step.
     */
    public function cacheHitRate(): float
    {
        $total = $this->totalInputTokens();

        return $total === 0 ? 0.0 : $this->cacheReadTokens / $total;
    }

    /**
     * The canonical machine-readable usage block, shared by every command
     * that emits JSON so the shape cannot drift between them.
     *
     * input_tokens is *fresh* input only — cache reads and writes are
     * separate lines, because they are separate prices. Reporting the sum as
     * one figure hides the only lever that matters on a multi-step run.
     *
     * `measured` is false when the provider never reported usage — the run
     * died inside the step loop, before the single StreamEnd that carries it.
     * The cost then falls back to the in-flight estimate, and the token counts
     * stay zero rather than being invented. A consumer that bills on this must
     * treat an unmeasured cost as a floor, not a figure.
     *
     * @return array{input_tokens: int, output_tokens: int, cache_read_tokens: int, cache_write_tokens: int, cache_hit_rate: float, estimated_cost_usd: float, measured: bool}
     */
    public function usageSummary(): array
    {
        return [
            'input_tokens' => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
            'cache_read_tokens' => $this->cacheReadTokens,
            'cache_write_tokens' => $this->cacheWriteTokens,
            'cache_hit_rate' => round($this->cacheHitRate(), 4),
            'estimated_cost_usd' => round($this->measured ? $this->estimatedCost() : $this->projectedCost(), 4),
            'measured' => $this->measured,
        ];
    }

    public function summary(): string
    {
        $cached = ($this->cacheReadTokens + $this->cacheWriteTokens) > 0
            ? sprintf(' (cached: %d read / %d write)', $this->cacheReadTokens, $this->cacheWriteTokens)
            : '';

        return sprintf(
            'Tokens used — input: %d, output: %d%s | Estimated cost: $%.4f / $%.2f budget',
            $this->inputTokens,
            $this->outputTokens,
            $cached,
            $this->estimatedCost(),
            $this->budgetUsd,
        );
    }
}
