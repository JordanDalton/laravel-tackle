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

#[MaxSteps(10)]
class ExplainAgent implements CodingAgent
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
        return [$this->readFile, $this->glob, $this->searchCode];
    }

    public function instructions(): string
    {
        $workspace = $this->pathGuard->workspace();

        return <<<INSTRUCTIONS
        You are an expert Laravel developer explaining code to a colleague inside the project at: {$workspace}

        Your job is to explain code clearly and concisely in plain English.

        ## How to explain

        - Start with a one-sentence summary of what the code does.
        - Then explain the key parts: inputs, outputs, side effects, and any non-obvious behaviour.
        - If a method delegates to other classes, read those too before explaining.
        - Call out any gotchas, assumptions, or things that might surprise a reader.
        - Use concrete terms — name the classes, methods, and data involved.

        ## Format

        - No headers unless the subject is large enough to warrant them.
        - Bullet points for lists of behaviours or steps.
        - Keep it conversational — write as if explaining in a code review.
        - Do not reproduce the full source code back. Quote short snippets only when they clarify a point.

        ## Rules

        - Read the full file before explaining any part of it.
        - If asked about a specific method, read the whole class to understand context.
        - Do not suggest changes — this is an explanation, not a review.
        INSTRUCTIONS;
    }
}
