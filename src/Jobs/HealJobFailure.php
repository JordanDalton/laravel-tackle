<?php

namespace Tackle\Jobs;

use Illuminate\Support\Facades\Log;
use Throwable;

class HealJobFailure extends AbstractHealJob
{
    public function __construct(
        public readonly string $jobUuid,
        public readonly string $jobClass,
        public readonly string $jobPayload,
        public readonly string $exceptionClass,
        public readonly string $exceptionMessage,
        public readonly string $exceptionTrace,
    ) {
        parent::__construct();
    }

    protected function subjectType(): string { return 'job'; }
    protected function subjectClass(): string { return $this->jobClass; }
    protected function branchSuffix(): string { return substr($this->jobUuid, 0, 8); }
    protected function getExceptionClass(): string { return $this->exceptionClass; }
    protected function getExceptionMessage(): string { return $this->exceptionMessage; }
    protected function getExceptionTrace(): string { return $this->exceptionTrace; }

    protected function commitMessage(): string
    {
        return "tackle(healer): auto-fix for {$this->jobClass}\n\n{$this->exceptionClass}: {$this->exceptionMessage}";
    }

    protected function agentPrompt(): string
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

    protected function onPatched(): void
    {
        $this->redispatch();
        Log::info("Tackle Healer: job re-dispatched for {$this->jobClass}.");
    }

    protected function prTitle(bool $testsPassed): string
    {
        $status = $testsPassed ? '' : '[tests failing] ';
        $short  = class_basename($this->jobClass);

        return "tackle(healer): {$status}fix {$short} — {$this->exceptionClass}";
    }

    protected function prBody(string $agentSummary, bool $testsPassed): string
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

        if (! isset($payload['data']['command'])) {
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
