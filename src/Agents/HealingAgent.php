<?php

namespace Tackle\Agents;

use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Promptable;
use Tackle\Attributes\AiModel;
use Tackle\Attributes\AiProvider;
use Tackle\Contracts\CodingAgent;
use Tackle\Healing\TelescopeReader;
use Tackle\Support\CommandGuard;
use Tackle\Support\EventedTool;
use Tackle\Support\PathGuard;
use Tackle\Support\ProjectMemory;
use Tackle\Tools\EditFile;
use Tackle\Tools\Glob;
use Tackle\Tools\ReadFile;
use Tackle\Tools\ReadTelescopeEntry;
use Tackle\Tools\RunTests;
use Tackle\Tools\SearchCode;

#[MaxSteps(20)]
class HealingAgent implements CodingAgent
{
    use Promptable;

    private PathGuard $guard;

    public function __construct(
        private readonly string $workspace,
        #[AiProvider] private string $provider = 'anthropic',
        #[AiModel] private string $model = 'claude-sonnet-4-6',
    ) {
        $this->guard = new PathGuard($workspace);
    }

    /**
     * The provider heals run on: tackle.healing.provider when set, otherwise
     * the global tackle.provider. Resolved here (not via the #[AiProvider]
     * attribute) because the healer is constructed with `new` off the queue,
     * where container attribute resolution does not run.
     */
    public static function configuredProvider(): string
    {
        return (string) (config('tackle.healing.provider') ?: config('tackle.provider', 'anthropic'));
    }

    /**
     * The model heals run on: tackle.healing.model when set, otherwise the
     * global tackle.model. See configuredProvider() for why this is resolved
     * explicitly rather than through #[AiModel].
     */
    public static function configuredModel(): string
    {
        return (string) (config('tackle.healing.model') ?: config('tackle.model', 'claude-sonnet-4-6'));
    }

    protected function provider(): string
    {
        return $this->provider;
    }

    protected function model(): string
    {
        return $this->model;
    }

    public function instructions(): string
    {
        $projectMemory = (new ProjectMemory($this->workspace))->section();

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
        - If you cannot find a safe fix, say so clearly — do not guess.{$projectMemory}
        INSTRUCTIONS;
    }

    public function messages(): iterable
    {
        return [];
    }

    public function tools(): iterable
    {
        return EventedTool::wrap([
            new ReadFile($this->guard),
            new Glob($this->guard),
            new SearchCode($this->guard),
            new EditFile($this->guard),
            new RunTests($this->guard, app(CommandGuard::class)),
            new ReadTelescopeEntry(new TelescopeReader),
        ]);
    }
}
