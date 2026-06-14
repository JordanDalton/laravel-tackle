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
use Throwable;

class HealJobFailure implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, SerializesModels;

    /**
     * Never retry the healer itself — a healer loop would be unpleasant.
     */
    public int $tries = 1;

    /**
     * Give the agent plenty of time to read, reason, edit, and test.
     */
    public int $timeout = 600;

    public string $queue;

    public function __construct(
        public readonly string $jobUuid,
        public readonly string $jobClass,
        public readonly string $jobPayload,
        public readonly string $exceptionClass,
        public readonly string $exceptionMessage,
        public readonly string $exceptionTrace,
    ) {
        $this->queue = config('ai-code.healing.queue', 'healer');
    }

    public function handle(SandboxRunner $runner, GitHubTokenReader $tokenReader): void
    {
        $branchName   = config('ai-code.healing.branch_prefix', 'tackle/heal-') . substr($this->jobUuid, 0, 8);
        $worktreePath = null;

        try {
            $worktreePath = $runner->prepare($branchName);

            $agent   = new HealingAgent($worktreePath);
            $prompt  = $this->buildPrompt();
            $summary = '';

            $response = $agent->stream($prompt);
            $response->each(function ($event) use (&$summary) {
                if ($event instanceof TextDelta) {
                    $summary .= $event->delta;
                }
            });

            // Commit whatever the agent changed
            $commitMessage = "tackle(healer): auto-fix for {$this->jobClass}\n\n{$this->exceptionClass}: {$this->exceptionMessage}";
            $runner->commit($worktreePath, $commitMessage);

            $testsPassed = $runner->runTests($worktreePath);
            $mode        = config('ai-code.healing.mode', 'pr');

            if ($testsPassed && $mode === 'patch') {
                $runner->applyToMain($branchName);
                $this->redispatch();
                Log::info("Tackle Healer: patch applied and job re-dispatched for {$this->jobClass}.");
            } else {
                $reason = match (true) {
                    !$testsPassed && $mode !== 'patch' => "tests failed, mode={$mode}",
                    !$testsPassed                      => 'tests failed in sandbox',
                    default                            => "mode={$mode} (expected patch)",
                };
                Log::info("Tackle Healer: opening PR ({$reason}) for {$this->jobClass}.");
                $runner->push($branchName, $worktreePath);
                $prUrl = $runner->createPullRequest(
                    branchName: $branchName,
                    title:      $this->buildPrTitle($testsPassed),
                    body:       $this->buildPrBody($summary, $testsPassed),
                    token:      $tokenReader->token(),
                );
                $logMsg = $prUrl
                    ? "Tackle Healer: PR opened at {$prUrl}"
                    : "Tackle Healer: branch {$branchName} pushed (could not open PR — check github_token).";
                Log::info($logMsg);
            }
        } catch (Throwable $e) {
            Log::error("Tackle Healer: failed to process {$this->jobClass} ({$this->jobUuid}): " . $e->getMessage());
        } finally {
            if ($worktreePath !== null) {
                $runner->cleanup($worktreePath, $branchName);
            }
        }
    }

    private function buildPrompt(): string
    {
        $telescopeHint = config('ai-code.healing.telescope', true)
            ? "\n\nYou can call ReadTelescopeEntry with job_uuid=\"{$this->jobUuid}\" if you need richer context."
            : '';

        return <<<PROMPT
        A queue job has failed and needs a code fix.

        **Failing job class:** {$this->jobClass}
        **Job UUID:** {$this->jobUuid}

        **Exception class:** {$this->exceptionClass}
        **Exception message:** {$this->exceptionMessage}

        **Stack trace:**
        {$this->exceptionTrace}
        {$telescopeHint}

        Please diagnose the root cause, apply the minimal fix, run the tests, and provide a brief summary of what you changed.
        PROMPT;
    }

    private function buildPrTitle(bool $testsPassed): string
    {
        $status = $testsPassed ? '' : '[tests failing] ';
        $short  = class_basename($this->jobClass);
        return "tackle(healer): {$status}fix {$short} — {$this->exceptionClass}";
    }

    private function buildPrBody(string $agentSummary, bool $testsPassed): string
    {
        $testLine = $testsPassed
            ? '✅ Tests passed in the sandbox worktree.'
            : '⚠️ Tests did **not** pass after the fix — please review before merging.';

        $short = class_basename($this->jobClass);

        return <<<BODY
        ## Tackle Healer — automated fix

        **Failing job:** `{$this->jobClass}`
        **Exception:** `{$this->exceptionClass}: {$this->exceptionMessage}`

        {$testLine}

        ## Agent summary

        {$agentSummary}

        ## Original stack trace

        ```
        {$this->exceptionTrace}
        ```

        ---
        *This PR was opened automatically by [Laravel Tackle](https://packagist.org/packages/jordandalton/laravel-tackle). Review the diff carefully before merging.*
        BODY;
    }

    private function redispatch(): void
    {
        $payload = json_decode($this->jobPayload, true);

        if (!isset($payload['data']['command'])) {
            return;
        }

        try {
            $job = unserialize($payload['data']['command']);
            dispatch($job);
        } catch (Throwable $e) {
            Log::warning("Tackle Healer: could not re-dispatch job after patch: " . $e->getMessage());
        }
    }
}
