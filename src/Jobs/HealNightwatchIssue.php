<?php

namespace Tackle\Jobs;

use Illuminate\Support\Facades\Log;
use Tackle\Healing\NightwatchIssue;

/**
 * Heals an issue reported by Laravel Nightwatch.
 *
 * Unlike the queue and scheduler healers, this one has no local stack trace to
 * work from — Nightwatch's webhook carries the class, message, and file:line,
 * and nothing more. That is enough, because the agent is running inside the
 * codebase the issue points at and can read the rest for itself.
 *
 * It also covers a case the other healers cannot reach at all: Nightwatch
 * reports slow routes, jobs, commands, and scheduled tasks as issues, so a
 * performance regression arrives here with a measured duration and the
 * threshold it broke.
 */
class HealNightwatchIssue extends AbstractHealJob
{
    public function __construct(
        public readonly NightwatchIssue $issue,
    ) {
        parent::__construct();
    }

    protected function subjectType(): string
    {
        return 'nightwatch';
    }

    protected function subjectClass(): string
    {
        return $this->issue->subject();
    }

    protected function branchSuffix(): string
    {
        return $this->issue->branchSuffix();
    }

    protected function getExceptionClass(): string
    {
        return $this->issue->exceptionClass();
    }

    protected function getExceptionMessage(): string
    {
        return $this->issue->exceptionMessage();
    }

    protected function getExceptionTrace(): string
    {
        // Nightwatch webhooks carry file:line, not a full trace.
        return '';
    }

    protected function commitMessage(): string
    {
        return "tackle(healer): auto-fix for Nightwatch issue {$this->issue->label()}\n\n{$this->issue->title}";
    }

    protected function agentPrompt(): string
    {
        return $this->issue->isPerformance()
            ? $this->performancePrompt()
            : $this->exceptionPrompt();
    }

    private function exceptionPrompt(): string
    {
        $facts = $this->issue->describe();

        return <<<PROMPT
        A production exception has been reported by Laravel Nightwatch and needs a code fix.

        {$facts}

        Nightwatch reports where the exception was thrown but does not send a stack
        trace, so start by reading the file and line above and tracing the call path
        yourself. The exception happened in production against real data — reproduce
        the condition in a test before you change anything, so the fix is provably
        the right one.

        Diagnose the root cause, apply the minimal fix, add or update a test that
        fails without it, run the test suite, and give a brief summary of what you
        changed and why.
        PROMPT;
    }

    private function performancePrompt(): string
    {
        $facts = $this->issue->describe();

        return <<<PROMPT
        Laravel Nightwatch has flagged a performance problem in production that needs
        a code fix.

        {$facts}

        Find the code behind the entry above and work out why it exceeds the
        threshold. The usual causes, in the order worth checking: N+1 queries that
        need eager loading, queries with no supporting index, work in a loop that
        could be done in one query, and uncached calls to slow external services.

        Two rules for this fix:

        1. Do not change observable behaviour. The same inputs must produce the same
           outputs — this is an optimisation, not a rewrite.
        2. If you cannot find a cause you are confident in, say so in your summary
           rather than guessing. A report naming the suspected hot path is more
           useful than a speculative change.

        Run the test suite when you are done and give a brief summary of what you
        changed and why you expect it to help.
        PROMPT;
    }

    protected function onPatched(): void
    {
        // Nothing to re-dispatch — the fix takes effect on the next request,
        // job, or run. Nightwatch closes the loop from its side: once the fix
        // deploys and the issue stops recurring it can be marked resolved.
        Log::info("Tackle Healer: Nightwatch issue {$this->issue->label()} patched — {$this->issue->url}");
    }

    protected function prTitle(bool $testsPassed): string
    {
        $status = $testsPassed ? '' : '[tests failing] ';
        $what = $this->issue->isPerformance()
            ? $this->issue->subject()
            : class_basename($this->issue->exceptionClass());

        return "tackle(healer): {$status}fix Nightwatch {$this->issue->label()} — {$what}";
    }

    protected function prBody(string $agentSummary, bool $testsPassed): string
    {
        $testLine = $testsPassed
            ? '✅ Tests passed in the sandbox worktree.'
            : '⚠️ Tests did **not** pass after the fix — please review before merging.';

        $kind = $this->issue->isPerformance() ? 'performance issue' : 'exception';
        $facts = $this->issue->describe();

        $verify = $this->issue->isPerformance()
            ? 'Watch the duration for this entry in Nightwatch after deploying — that graph, not this diff, is what confirms the fix.'
            : 'Nightwatch will mark this issue resolved on its own once the fix deploys and the exception stops recurring.';

        return <<<BODY
        ## Tackle Healer — automated fix (Nightwatch {$kind})

        {$facts}

        {$testLine}

        ## Agent summary

        {$agentSummary}

        ## Verifying this worked

        {$verify}

        ---
        *This PR was opened automatically by [Laravel Tackle](https://packagist.org/packages/jordandalton/laravel-tackle) in response to a [Nightwatch](https://nightwatch.laravel.com) webhook. Review the diff carefully before merging.*
        BODY;
    }
}
