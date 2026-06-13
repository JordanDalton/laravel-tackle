<?php

namespace Tackle\Agents;

use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Promptable;
use Tackle\Healing\TelescopeReader;
use Tackle\Support\PathGuard;
use Tackle\Tools\EditFile;
use Tackle\Tools\Glob;
use Tackle\Tools\ReadFile;
use Tackle\Tools\ReadTelescopeEntry;
use Tackle\Tools\RunTests;
use Tackle\Tools\SearchCode;

#[MaxSteps(20)]
class HealingAgent
{
    use Promptable;

    private PathGuard $guard;

    public function __construct(private readonly string $workspace)
    {
        $this->guard = new PathGuard($workspace);
    }

    protected function provider(): string
    {
        return config('ai-code.provider', 'anthropic');
    }

    protected function model(): string
    {
        return config('ai-code.model', 'claude-sonnet-4-6');
    }

    public function instructions(): string
    {
        return <<<INSTRUCTIONS
        You are the Tackle Healer — a specialist AI that diagnoses and repairs failing Laravel queue jobs.

        You are operating inside an isolated git worktree at: {$this->workspace}

        ## Your task

        You will be given:
        - The fully-qualified class name of the failing job
        - The exception class, message, and stack trace
        - Optional Telescope context

        Your goal is to apply the smallest correct fix that makes the job stop failing.

        ## Process

        1. **Read the failing job class** to understand what it does.
        2. **Read the classes involved in the stack trace** to find the root cause.
        3. **Run tests** to establish the baseline (some may already be failing).
        4. **Apply a minimal edit** using EditFile — do not rewrite whole files.
        5. **Run tests again** to confirm the fix works.
        6. **Summarise** what you changed and why, in 2–4 sentences. This text becomes the PR description.

        ## Constraints

        - Make only the fix required — do not refactor surrounding code.
        - Do not create new files.
        - Do not modify .env, vendor/, storage/, or .git/.
        - If the root cause is ambiguous, make your best attempt and explain your uncertainty in the summary.
        - If you cannot find a safe fix, say so clearly — do not guess.
        INSTRUCTIONS;
    }

    public function messages(): iterable
    {
        return [];
    }

    public function tools(): iterable
    {
        return [
            new ReadFile($this->guard),
            new Glob($this->guard),
            new SearchCode($this->guard),
            new EditFile($this->guard),
            new RunTests($this->guard),
            new ReadTelescopeEntry(new TelescopeReader()),
        ];
    }
}
