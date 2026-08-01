<?php

namespace Tackle\Commands;

use Illuminate\Console\Command;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolCall;
use Laravel\Ai\Streaming\Events\ToolResult;
use Tackle\Commands\Concerns\ResolvesSessionOptions;
use Tackle\Contracts\CodingAgent;
use Tackle\Contracts\InteractionPolicy;
use Tackle\Exceptions\AgentInterruptedException;
use Tackle\Support\AutoApproveInteraction;
use Tackle\Support\BudgetTracker;
use Tackle\Support\DenyInteraction;
use Tackle\Support\Reporting\JsonReporter;
use Tackle\Support\Reporting\RunReporter;
use Tackle\Support\Reporting\TextReporter;
use Tackle\Support\WorktreeManager;
use Throwable;

class RunCommand extends Command
{
    use ResolvesSessionOptions;

    /** Completed normally. */
    public const EXIT_OK = 0;

    /** The agent or provider errored. */
    public const EXIT_ERROR = 1;

    /** Stopped because the spend limit was reached. */
    public const EXIT_BUDGET = 2;

    /** A confirmation was auto-denied and --fail-on-denied was set. */
    public const EXIT_DENIED = 3;

    /** Hit the step ceiling without finishing. */
    public const EXIT_MAX_STEPS = 4;

    protected $signature = 'ai:run
        {prompt : The task to run}
        {--output=text : Result format (text|json)}
        {--session= : Resume a named session}
        {--budget= : Override the spend limit in USD for this run}
        {--max-steps= : Stop after this many tool calls}
        {--yes : Approve confirmations automatically instead of denying them}
        {--fail-on-denied : Exit non-zero if any confirmation was auto-denied}
        {--shell= : Override the shell mode for this run (off|allowlist|approve|yolo)}
        {--off : Shorthand for --shell=off}
        {--allowlist : Shorthand for --shell=allowlist}
        {--approve : Shorthand for --shell=approve}
        {--yolo : Shorthand for --shell=yolo}
        {--worktree : Force worktree isolation for this run}
        {--no-worktree : Disable worktree isolation for this run}';

    protected $description = 'Run a single Tackle task to completion with no terminal — for CI, cron, and scripts.';

    private int $steps = 0;

    private ?int $maxSteps = null;

    private ?string $pullRequestUrl = null;

    public function handle(WorktreeManager $worktrees): int
    {
        $format = (string) $this->option('output');

        if (! in_array($format, ['text', 'json'], strict: true)) {
            $this->error("Invalid --output value '{$format}'. Must be one of: text, json.");

            return self::EXIT_ERROR;
        }

        if (! $this->applyShellOverride()) {
            return self::EXIT_ERROR;
        }

        if (($budgetOverride = $this->option('budget')) !== null) {
            if (! is_numeric($budgetOverride) || (float) $budgetOverride <= 0) {
                $this->error("Invalid --budget value '{$budgetOverride}'. Must be a positive number.");

                return self::EXIT_ERROR;
            }

            config(['tackle.budget_usd' => (float) $budgetOverride]);
        }

        if (($stepsOverride = $this->option('max-steps')) !== null) {
            if (! ctype_digit((string) $stepsOverride) || (int) $stepsOverride < 1) {
                $this->error("Invalid --max-steps value '{$stepsOverride}'. Must be a positive integer.");

                return self::EXIT_ERROR;
            }

            config(['tackle.max_steps' => (int) $stepsOverride]);
        }

        $this->maxSteps = (int) config('tackle.max_steps', 40);

        // Nobody is watching, so confirmations are refused unless the caller
        // explicitly signed up for the opposite with --yes.
        $interaction = $this->option('yes') ? new AutoApproveInteraction : new DenyInteraction;
        $this->laravel->instance(InteractionPolicy::class, $interaction);

        $reporter = $format === 'json'
            ? new JsonReporter($this->output)
            : new TextReporter($this->output);

        // Resolved after the config overrides above, since both read config at
        // construction time.
        $budget = $this->laravel->make(BudgetTracker::class);
        $agent = $this->laravel->make(CodingAgent::class);

        $useWorktree = $this->resolveWorktreeMode();

        if ($useWorktree) {
            try {
                $worktrees->create();
            } catch (\RuntimeException $e) {
                $reporter->note('Could not create worktree: '.$e->getMessage().' — falling back to live workspace.');
                $useWorktree = false;
            }
        }

        $reporter->starting([
            'model' => config('tackle.model'),
            'shell' => $this->resolveShellMode(),
            'worktree' => $useWorktree ? $worktrees->path() : 'off',
            'budget' => sprintf('$%.2f', $budget->budgetUsd()),
            'max_steps' => $this->maxSteps,
            'confirmations' => $this->option('yes') ? 'auto-approved (--yes)' : 'auto-denied',
        ]);

        try {
            return $this->runTask($agent, $budget, $reporter, $interaction, $useWorktree, $worktrees);
        } finally {
            if ($worktrees->active()) {
                $worktrees->cleanup();
            }
        }
    }

    private function runTask(
        CodingAgent $agent,
        BudgetTracker $budget,
        RunReporter $reporter,
        InteractionPolicy $interaction,
        bool $useWorktree,
        WorktreeManager $worktrees,
    ): int {
        $outcome = 'completed';
        $exit = self::EXIT_OK;
        $error = null;
        $worktreePath = $useWorktree ? $worktrees->path() : null;

        try {
            $agent->stream((string) $this->argument('prompt'))->each(
                fn ($event) => $this->handleEvent($event, $budget, $reporter),
            );
        } catch (AgentInterruptedException $e) {
            $outcome = $e->getMessage();
            $exit = $outcome === 'budget_exceeded' ? self::EXIT_BUDGET : self::EXIT_MAX_STEPS;
        } catch (Throwable $e) {
            $outcome = 'error';
            $exit = self::EXIT_ERROR;
            $error = $e->getMessage();
        }

        $denied = $interaction->deniedCount();

        if ($exit === self::EXIT_OK && $denied > 0 && $this->option('fail-on-denied')) {
            $outcome = 'interaction_denied';
            $exit = self::EXIT_DENIED;
        }

        $diffStat = $this->diffStat($worktreePath ?? base_path());

        $reporter->finish([
            'ok' => $exit === self::EXIT_OK,
            'outcome' => $outcome,
            'error' => $error,
            'steps' => $this->steps,
            'files_changed' => $this->changedFiles($worktreePath ?? base_path()),
            'diff_stat' => $diffStat,
            'interactions_denied' => $denied,
            'usage' => [
                'input_tokens' => $budget->inputTokens(),
                'output_tokens' => $budget->outputTokens(),
                'estimated_cost_usd' => round($budget->estimatedCost(), 4),
            ],
            'budget_usd' => $budget->budgetUsd(),
            'worktree' => $worktreePath,
            'pr_url' => $this->pullRequestUrl,
        ]);

        return $exit;
    }

    private function handleEvent(mixed $event, BudgetTracker $budget, RunReporter $reporter): void
    {
        if ($event instanceof TextDelta) {
            $reporter->text($event->delta);

            return;
        }

        if ($event instanceof ToolCall) {
            $this->steps++;

            $reporter->toolCall($event->toolCall->name, (array) $event->toolCall->arguments);

            // Enforced here because laravel/ai resolves its own MaxSteps from a
            // class attribute, which cannot be overridden at runtime.
            if ($this->steps > $this->maxSteps) {
                throw new AgentInterruptedException('max_steps_reached');
            }

            return;
        }

        if ($event instanceof ToolResult) {
            $result = (string) ($event->toolResult->result ?? '');

            $reporter->toolResult($event->toolResult->name, $result);

            if ($event->toolResult->name === 'CreatePullRequest'
                && preg_match('#https://github\.com/\S+/pull/\d+#', $result, $matches)) {
                $this->pullRequestUrl = $matches[0];
            }

            return;
        }

        if ($event instanceof StreamEnd) {
            $budget->record($event->usage->promptTokens, $event->usage->completionTokens);

            if ($budget->overBudget()) {
                throw new AgentInterruptedException('budget_exceeded');
            }
        }
    }

    private function diffStat(string $root): string
    {
        return trim((string) shell_exec(
            'git -C '.escapeshellarg($root).' diff --stat 2>/dev/null'
        ));
    }

    /**
     * @return list<string>
     */
    private function changedFiles(string $root): array
    {
        $output = trim((string) shell_exec(
            'git -C '.escapeshellarg($root).' diff --name-only 2>/dev/null'
        ));

        return $output === '' ? [] : explode("\n", $output);
    }
}
