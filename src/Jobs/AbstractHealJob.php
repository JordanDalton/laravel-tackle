<?php

namespace Tackle\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Streaming\Events\TextDelta;
use Tackle\Agents\HealingAgent;
use Tackle\Contracts\CodingAgent;
use Tackle\Contracts\InteractionPolicy;
use Tackle\Healing\GitHubTokenReader;
use Tackle\Healing\HealEvidence;
use Tackle\Healing\SandboxRunner;
use Tackle\Models\HealingLog;
use Tackle\Support\BlastRadius;
use Tackle\Support\DenyInteraction;
use Throwable;

abstract class AbstractHealJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public string $queue;

    public function __construct()
    {
        $this->queue = config('tackle.healing.queue', 'healer');
    }

    // -----------------------------------------------------------------------
    // Subclasses define these
    // -----------------------------------------------------------------------

    abstract protected function subjectType(): string;    // 'job' | 'scheduled_task'

    abstract protected function subjectClass(): string;

    abstract protected function branchSuffix(): string;

    abstract protected function agentPrompt(): string;

    abstract protected function commitMessage(): string;

    abstract protected function onPatched(): void;

    abstract protected function prTitle(bool $testsPassed): string;

    abstract protected function prBody(string $agentSummary, bool $testsPassed): string;

    abstract protected function getExceptionClass(): string;

    abstract protected function getExceptionMessage(): string;

    abstract protected function getExceptionTrace(): string;

    // -----------------------------------------------------------------------
    // Shared healing engine
    // -----------------------------------------------------------------------

    /**
     * Build the healing agent for a worktree. Overridable so tests can inject
     * a scripted agent instead of one that calls a provider.
     */
    protected function makeAgent(string $worktreePath): CodingAgent
    {
        return new HealingAgent(
            $worktreePath,
            HealingAgent::configuredProvider(),
            HealingAgent::configuredModel(),
        );
    }

    public function handle(SandboxRunner $runner, GitHubTokenReader $tokenReader): void
    {
        // A queue worker has no terminal. Without this, any prompting tool added
        // to a custom healing agent would block the worker indefinitely.
        app()->instance(InteractionPolicy::class, new DenyInteraction);

        $branchName = config('tackle.healing.branch_prefix', 'tackle/heal-').$this->branchSuffix();
        $worktreePath = null;
        $outcome = 'failed';
        $prUrl = null;
        $testsPassed = false;
        $mode = config('tackle.healing.mode', 'pr');

        try {
            $worktreePath = $runner->prepare($branchName);

            // Baseline the suite BEFORE the fix so we can tell a failure the fix
            // introduced from one that was already there. Skippable for very
            // slow suites, where the gate falls back to "suite green".
            $baselineEnabled = (bool) config('tackle.healing.baseline', true);
            $baseline = $baselineEnabled
                ? $runner->testFailures($worktreePath)
                : ['ran' => false, 'ok' => true, 'failures' => []];

            $agent = $this->makeAgent($worktreePath);
            $summary = '';

            $agent->stream($this->agentPrompt())->each(function ($event) use (&$summary) {
                if ($event instanceof TextDelta) {
                    $summary .= $event->delta;
                }
            });

            $runner->commit($worktreePath, $this->commitMessage());

            $diff = $runner->diff($worktreePath);
            $after = $runner->testFailures($worktreePath);

            $evidence = new HealEvidence(
                baselineFailures: $baseline['failures'],
                afterFailures: $after['failures'],
                baselineRan: $baseline['ran'],
                afterRan: $after['ran'],
                filesTouched: array_keys($diff['files']),
                insertions: $diff['insertions'],
                deletions: $diff['deletions'],
                regressionTestAdded: $this->regressionTestAdded($diff['files']),
                blastRadiusViolations: BlastRadius::violations($diff['files'], $diff['insertions'] + $diff['deletions']),
            );

            $testsPassed = $evidence->testsClean();

            if ($evidence->gatePassed() && $mode === 'patch') {
                $runner->applyToMain($branchName);
                $this->onPatched();
                $outcome = 'patched';
                Log::info("Tackle Healer: patch applied for {$this->subjectClass()} (no new failures, within blast-radius limits).");
            } else {
                $reason = match (true) {
                    ! $evidence->codeChanged() => 'no application code changed (tests only)',
                    ! $evidence->testsClean() => 'new test failures',
                    $evidence->blastRadiusViolations !== [] => 'blast-radius limits exceeded',
                    default => "mode={$mode}",
                };
                Log::info("Tackle Healer: opening PR ({$reason}) for {$this->subjectClass()}.");

                $runner->push($branchName, $worktreePath);

                $prUrl = $runner->createPullRequest(
                    branchName: $branchName,
                    title: $evidence->titleTag().$this->prTitle($testsPassed),
                    body: $this->prBody($summary, $testsPassed)."\n\n".$evidence->render(),
                    token: $tokenReader->token(),
                );
                $outcome = 'pr_opened';

                Log::info($prUrl
                    ? "Tackle Healer: PR opened at {$prUrl}"
                    : "Tackle Healer: branch {$branchName} pushed (could not open PR — check github_token)."
                );
            }
        } catch (Throwable $e) {
            Log::error("Tackle Healer: failed to process {$this->subjectClass()}: ".$e->getMessage());
        } finally {
            $this->writeAuditLog($branchName, $prUrl, $testsPassed, $outcome, $mode);

            if ($worktreePath !== null) {
                $runner->cleanup($worktreePath, $branchName);
            }
        }
    }

    /**
     * True if the heal added a new test file — the regression-test-first
     * signal. Keyed on ADDED status so an edit to an existing test does not
     * count as a fresh guard against recurrence.
     *
     * @param  array<string, string>  $files  path => git status letter
     */
    protected function regressionTestAdded(array $files): bool
    {
        foreach ($files as $path => $status) {
            if (strtoupper((string) $status) !== 'A') {
                continue;
            }
            if (str_contains($path, 'tests/') || str_ends_with($path, 'Test.php') || preg_match('/(^|\/)[A-Za-z].*Test\.php$/', $path)) {
                return true;
            }
        }

        return false;
    }

    private function writeAuditLog(
        string $branchName,
        ?string $prUrl,
        bool $testsPassed,
        string $outcome,
        string $mode,
    ): void {
        try {
            HealingLog::create([
                'subject_type' => $this->subjectType(),
                'subject_class' => $this->subjectClass(),
                'exception_class' => $this->getExceptionClass(),
                'exception_message' => $this->getExceptionMessage(),
                'branch' => $branchName,
                'pr_url' => $prUrl,
                'mode' => $mode,
                'tests_passed' => $testsPassed,
                'outcome' => $outcome,
            ]);
        } catch (Throwable) {
            // Degrade gracefully if the migration has not been run.
        }
    }
}
