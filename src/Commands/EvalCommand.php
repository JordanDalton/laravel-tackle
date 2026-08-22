<?php

namespace Tackle\Commands;

use Illuminate\Console\Command;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\ToolCall;
use Tackle\Contracts\CodingAgent;
use Tackle\Contracts\InteractionPolicy;
use Tackle\Evals\CaseRepository;
use Tackle\Evals\EvalCase;
use Tackle\Evals\EvalRunner;
use Tackle\Support\BudgetTracker;
use Tackle\Support\DenyInteraction;

class EvalCommand extends Command
{
    protected $signature = 'ai:eval
        {--case=*    : Run only these case ids (repeatable). Default: all.}
        {--budget=   : Per-case USD budget (default 0.50).}
        {--model=    : Model to run the cases on.}
        {--provider= : Provider to run the cases on.}
        {--json      : Emit the report as JSON.}';

    protected $description = 'Benchmark the coding agent against seeded bugs — reports fix rate, false-fix rate, tokens, and cost.';

    public function handle(CaseRepository $cases): int
    {
        $ids = (array) $this->option('case');
        $suite = $ids !== [] ? $cases->only($ids) : $cases->all();

        if ($suite === []) {
            $this->error('No matching eval cases.');

            return self::FAILURE;
        }

        if ($provider = $this->option('provider')) {
            config(['tackle.provider' => $provider]);
        }
        if ($model = $this->option('model')) {
            config(['tackle.model' => $model]);
        }

        $budgetUsd = (float) ($this->option('budget') ?: 0.50);
        // Evals grade a self-contained fix — no shell, no worktree, no prompts.
        config(['tackle.shell' => 'off']);
        app()->instance(InteractionPolicy::class, new DenyInteraction);

        $json = (bool) $this->option('json');
        $maxSteps = (int) config('tackle.max_steps', 40);

        if (! $json) {
            $this->line('');
            $this->line('<fg=green;options=bold>Tackle Eval</> — '.count($suite).' case(s) · $'.number_format($budgetUsd, 2).'/case · '.config('tackle.model'));
            $this->line('');
        }

        $report = (new EvalRunner)->runAll(
            $suite,
            function (string $dir, EvalCase $case) use ($budgetUsd, $maxSteps, $json): array {
                if (! $json) {
                    $this->output->write(sprintf('  running %-28s ', $case->id));
                }

                config(['tackle.workspace' => $dir, 'tackle.budget_usd' => $budgetUsd]);
                app()->forgetInstance(BudgetTracker::class);
                $budget = app(BudgetTracker::class);
                $agent = app(CodingAgent::class);

                $steps = 0;

                try {
                    $agent->stream($case->prompt)->each(function ($event) use ($budget, &$steps, $maxSteps) {
                        if ($event instanceof ToolCall && ++$steps > $maxSteps) {
                            throw new \RuntimeException('max steps reached');
                        }
                        if ($event instanceof StreamEnd) {
                            $budget->record($event->usage->promptTokens, $event->usage->completionTokens);
                            if ($budget->overBudget()) {
                                throw new \RuntimeException('budget exceeded');
                            }
                        }
                    });
                } catch (\Throwable $e) {
                    // Record spend so far; the grade reflects the partial state.
                }

                if (! $json) {
                    $this->line('done');
                }

                return [
                    'inputTokens' => $budget->inputTokens(),
                    'outputTokens' => $budget->outputTokens(),
                    'costUsd' => $budget->estimatedCost(),
                ];
            },
        );

        if ($json) {
            $this->line(json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->line('');
            $this->line($report->render());
            $this->line('');
        }

        // Non-zero exit if anything regressed or errored — useful in CI.
        return $report->falseFixes() > 0 || $report->errors() > 0 ? self::FAILURE : self::SUCCESS;
    }
}
