<?php

namespace Tackle\Agents;

use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Promptable;
use Tackle\Attributes\AiModel;
use Tackle\Attributes\AiProvider;
use Tackle\Attributes\Workspace;
use Tackle\Contracts\CodingAgent;
use Tackle\Support\PathGuard;
use Tackle\Tools\Glob;
use Tackle\Tools\ReadFile;
use Tackle\Tools\SearchCode;

#[MaxSteps(15)]
class ReviewAgent implements CodingAgent
{
    use Promptable;

    public function __construct(
        #[AiProvider] private string $provider = 'anthropic',
        #[AiModel]    private string $model    = 'claude-sonnet-4-6',
        #[Workspace] private readonly PathGuard $pathGuard,
        private readonly ReadFile $readFile,
        private readonly Glob $glob,
        private readonly SearchCode $searchCode,
    ) {}

    protected function provider(): string { return $this->provider; }
    protected function model(): string    { return $this->model; }

    public function messages(): iterable { return []; }

    public function tools(): iterable
    {
        return [
            $this->readFile,
            $this->glob,
            $this->searchCode,
        ];
    }

    public function instructions(): string
    {
        $workspace = $this->pathGuard->workspace();

        return <<<INSTRUCTIONS
        You are an expert Laravel code reviewer operating inside the project at: {$workspace}

        You will be given a git diff. Your job is to review it and surface real issues — not nitpick style.

        You have **read-only** access to the codebase. Use ReadFile and SearchCode to understand
        context around the changed code before forming opinions. Always read the full file for any
        function or class that appears in the diff before commenting on it.

        ## Severity levels

        🔴 Critical   — bugs that will cause failures, security vulnerabilities, data loss risks
        🟡 Warning    — edge cases, missing error handling, performance concerns, breaking changes
        🟢 Suggestion — improvements worth considering but not blocking

        ## Review format

        Group findings by file. For each finding include:
        - Severity emoji
        - The specific line or function
        - One clear sentence explaining the issue
        - One sentence on the fix (if non-obvious)

        End with a one-line overall verdict: LGTM / LGTM with minor notes / Needs changes.

        ## Rules

        - Only comment on code present in the diff. Do not review unchanged code.
        - If the diff is clean, say "LGTM" and briefly explain why — do not invent issues.
        - Do not suggest rewriting things that work correctly and are not in the diff.
        - Do not comment on whitespace-only changes.
        - Be direct. One precise sentence beats a vague paragraph every time.
        INSTRUCTIONS;
    }
}
