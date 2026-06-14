<?php

namespace Tackle\Jobs;

class HealScheduledTask extends AbstractHealJob
{
    public function __construct(
        public readonly string $taskCommand,
        public readonly string $taskDescription,
        public readonly string $exceptionClass,
        public readonly string $exceptionMessage,
        public readonly string $exceptionTrace,
    ) {
        parent::__construct();
    }

    protected function subjectType(): string { return 'scheduled_task'; }
    protected function subjectClass(): string { return $this->taskDescription ?: $this->taskCommand; }
    protected function branchSuffix(): string { return 'sched-' . substr(md5($this->taskCommand), 0, 6); }
    protected function getExceptionClass(): string { return $this->exceptionClass; }
    protected function getExceptionMessage(): string { return $this->exceptionMessage; }
    protected function getExceptionTrace(): string { return $this->exceptionTrace; }

    protected function commitMessage(): string
    {
        return "tackle(healer): auto-fix for scheduled task\n\n{$this->taskDescription}\n{$this->exceptionClass}: {$this->exceptionMessage}";
    }

    protected function agentPrompt(): string
    {
        return <<<PROMPT
        A scheduled Laravel command has failed and needs a code fix.

        **Command:** {$this->taskCommand}
        **Description:** {$this->taskDescription}

        **Exception class:** {$this->exceptionClass}
        **Exception message:** {$this->exceptionMessage}

        **Stack trace:**
        {$this->exceptionTrace}

        Please diagnose the root cause, locate the command class in the codebase, apply the minimal fix, run the tests, and provide a brief summary of what you changed.
        PROMPT;
    }

    protected function onPatched(): void
    {
        // Scheduled tasks run on their schedule — no re-dispatch needed.
        // The fix will take effect the next time the task runs.
    }

    protected function prTitle(bool $testsPassed): string
    {
        $status = $testsPassed ? '' : '[tests failing] ';

        return "tackle(healer): {$status}fix scheduled task — {$this->exceptionClass}";
    }

    protected function prBody(string $agentSummary, bool $testsPassed): string
    {
        $testLine = $testsPassed
            ? '✅ Tests passed in the sandbox worktree.'
            : '⚠️ Tests did **not** pass after the fix — please review before merging.';

        return <<<BODY
        ## Tackle Healer — automated fix (scheduled task)

        **Failed command:** `{$this->taskCommand}`
        **Description:** {$this->taskDescription}
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
}
