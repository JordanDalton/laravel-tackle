<?php

namespace Tackle\Commands;

use Illuminate\Console\Command;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\ToolCall;
use Tackle\Agents\CachingCodingAgent;
use Tackle\Agents\LeanCodingAgent;
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
        {--agent=    : Agent to benchmark: "lean", "default", or a CodingAgent class. Default: the configured agent.}
        {--no-cache  : Disable prompt caching for the run (to measure its effect).}
        {--json      : Emit the report as JSON.}
        {--keep      : Leave each case directory in place so the produced code can be read.}';

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

        $agentClass = $this->resolveAgentClass();
        if ($agentClass === null) {
            return self::FAILURE;
        }

        $budgetUsd = (float) ($this->option('budget') ?: 0.50);
        // Evals grade a self-contained fix — no shell, no worktree, no prompts.
        config(['tackle.shell' => 'off']);
        if ($this->option('no-cache')) {
            config(['tackle.prompt_cache' => false]);
        }
        app()->instance(InteractionPolicy::class, new DenyInteraction);

        $json = (bool) $this->option('json');
        $maxSteps = (int) config('tackle.max_steps', 40);

        if (! $json) {
            $this->line('');
            $this->line('<fg=green;options=bold>Tackle Eval</> — '.count($suite).' case(s) · $'.number_format($budgetUsd, 2).'/case · '.config('tackle.model').' · '.class_basename($agentClass));
            $this->line('');
        }

        $report = (new EvalRunner)->keepDirectories((bool) $this->option('keep'))->runAll(
            $suite,
            function (string $dir, EvalCase $case) use ($budgetUsd, $maxSteps, $json, $agentClass): array {
                // In JSON mode progress goes to stderr, not nowhere: the suite
                // runs for minutes and stdout must stay a single parseable
                // document. Same discipline as ai:review and ai:respond.
                $this->progress($json, sprintf('  running %-28s ', $case->id));

                config(['tackle.workspace' => $dir, 'tackle.budget_usd' => $budgetUsd]);
                app()->forgetInstance(BudgetTracker::class);
                $budget = app(BudgetTracker::class);
                $agent = app($agentClass);

                $steps = 0;

                // The sequence of tools, in order. Step counts tell you a run
                // was expensive; this tells you what it spent the steps on —
                // eight ReadFile calls is a re-reading problem, six RunTests a
                // verification loop, and neither is visible in a total.
                $toolCalls = [];

                try {
                    $agent->stream($case->prompt)->each(function ($event) use ($budget, &$steps, &$toolCalls, $maxSteps) {
                        if ($event instanceof ToolCall) {
                            $toolCalls[] = $event->toolCall->name;

                            if (++$steps > $maxSteps) {
                                throw new \RuntimeException('max steps reached');
                            }
                        }
                        if ($event instanceof StreamEnd) {
                            $budget->record($event->usage->promptTokens, $event->usage->completionTokens, $event->usage->cacheReadInputTokens ?? 0, $event->usage->cacheWriteInputTokens ?? 0);
                            if ($budget->overBudget()) {
                                throw new \RuntimeException('budget exceeded');
                            }
                        }
                    });
                } catch (\Throwable $e) {
                    // If the turn produced no tokens at all, it never reached the
                    // model (bad model id, auth, network) — that's an error to
                    // surface, not a silent "not-fixed". If it ran and then threw
                    // (budget/step ceiling), keep the partial state to grade.
                    //
                    // Total, not fresh: with prompt caching most of a step's
                    // input can arrive as a cache read, so inputTokens() alone
                    // would call a real run a failure to reach the model.
                    if ($budget->totalInputTokens() === 0) {
                        $this->progress($json, "error\n");

                        throw new \RuntimeException($e->getMessage(), 0, $e);
                    }
                }

                $this->progress($json, sprintf("done  %s tok  $%.4f\n",
                    number_format($budget->totalInputTokens() + $budget->outputTokens()),
                    $budget->estimatedCost(),
                ));

                return [
                    'toolCalls' => $toolCalls,
                    'inputTokens' => $budget->inputTokens(),
                    'outputTokens' => $budget->outputTokens(),
                    'cacheReadTokens' => $budget->cacheReadTokens(),
                    'cacheWriteTokens' => $budget->cacheWriteTokens(),
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

    /**
     * The CodingAgent class to benchmark. Defaults to the bound agent (what
     * ai:code / ai:run use) so the numbers reflect production; an explicit
     * --agent lets you measure a leaner toolset against the same cases.
     */
    /**
     * Per-case progress. A suite takes minutes and --json used to print
     * nothing at all until the final document, which is indistinguishable
     * from a hang. Routed to stderr so stdout stays one parseable document.
     */
    private function progress(bool $json, string $line): void
    {
        if ($json) {
            $this->output->getErrorStyle()->write($line);

            return;
        }

        $this->output->write($line);
    }

    private function resolveAgentClass(): ?string
    {
        $given = $this->option('agent');

        if ($given === null || $given === '') {
            return CodingAgent::class;
        }

        // Shorthands for the built-in agents.
        $given = match ($given) {
            'default', 'full' => CodingAgent::class,
            'lean' => LeanCodingAgent::class,
            'cached' => CachingCodingAgent::class,
            default => $given,
        };

        if (! class_exists($given)) {
            $this->error("Unknown --agent class: {$given}");

            return null;
        }

        if (! is_subclass_of($given, CodingAgent::class) && ! in_array(CodingAgent::class, class_implements($given) ?: [], true)) {
            $this->error('--agent must implement '.CodingAgent::class.": {$given}");

            return null;
        }

        return $given;
    }
}
