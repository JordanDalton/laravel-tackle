<?php

namespace Tackle\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Streaming\Events\TextDelta;
use Tackle\Agents\HealingAgent;
use Tackle\Healing\GitHubTokenReader;
use Tackle\Healing\SandboxRunner;
use Tackle\Models\HealingLog;
use Throwable;

abstract class AbstractHealJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, SerializesModels;

    public int $tries   = 1;
    public int $timeout = 600;
    public string $queue;

    public function __construct()
    {
        $this->queue = config('ai-code.healing.queue', 'healer');
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

    public function handle(SandboxRunner $runner, GitHubTokenReader $tokenReader): void
    {
        $branchName   = config('ai-code.healing.branch_prefix', 'tackle/heal-') . $this->branchSuffix();
        $worktreePath = null;
        $outcome      = 'failed';
        $prUrl        = null;
        $testsPassed  = false;
        $mode         = config('ai-code.healing.mode', 'pr');

        try {
            $worktreePath = $runner->prepare($branchName);

            $agent   = new HealingAgent($worktreePath);
            $summary = '';

            $agent->stream($this->agentPrompt())->each(function ($event) use (&$summary) {
                if ($event instanceof TextDelta) {
                    $summary .= $event->delta;
                }
            });

            $runner->commit($worktreePath, $this->commitMessage());
            $testsPassed = $runner->runTests($worktreePath);

            if ($testsPassed && $mode === 'patch') {
                $runner->applyToMain($branchName);
                $this->onPatched();
                $outcome = 'patched';
                Log::info("Tackle Healer: patch applied for {$this->subjectClass()}.");
            } else {
                $reason = ! $testsPassed ? 'tests failed in sandbox' : "mode={$mode}";
                Log::info("Tackle Healer: opening PR ({$reason}) for {$this->subjectClass()}.");

                $runner->push($branchName, $worktreePath);

                $prUrl  = $runner->createPullRequest(
                    branchName: $branchName,
                    title:      $this->prTitle($testsPassed),
                    body:       $this->prBody($summary, $testsPassed),
                    token:      $tokenReader->token(),
                );
                $outcome = 'pr_opened';

                Log::info($prUrl
                    ? "Tackle Healer: PR opened at {$prUrl}"
                    : "Tackle Healer: branch {$branchName} pushed (could not open PR — check github_token)."
                );
            }
        } catch (Throwable $e) {
            Log::error("Tackle Healer: failed to process {$this->subjectClass()}: " . $e->getMessage());
        } finally {
            $this->writeAuditLog($branchName, $prUrl, $testsPassed, $outcome, $mode);

            if ($worktreePath !== null) {
                $runner->cleanup($worktreePath, $branchName);
            }
        }
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
                'subject_type'      => $this->subjectType(),
                'subject_class'     => $this->subjectClass(),
                'exception_class'   => $this->getExceptionClass(),
                'exception_message' => $this->getExceptionMessage(),
                'branch'            => $branchName,
                'pr_url'            => $prUrl,
                'mode'              => $mode,
                'tests_passed'      => $testsPassed,
                'outcome'           => $outcome,
            ]);
        } catch (Throwable) {
            // Degrade gracefully if the migration has not been run.
        }
    }
}
