<?php

namespace Tackle\Agents;

use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Promptable;
use Tackle\Attributes\AiModel;
use Tackle\Attributes\AiProvider;
use Tackle\Attributes\Workspace;
use Tackle\Contracts\CodingAgent;
use Tackle\Support\PathGuard;
use Tackle\Support\ProjectMemory;
use Tackle\Tools\GitDiff;
use Tackle\Tools\Glob;
use Tackle\Tools\ListRoutes;
use Tackle\Tools\ReadFile;
use Tackle\Tools\SearchCode;

/**
 * Read-only agent that investigates the codebase and produces an
 * implementation plan for the user to approve before any edits happen.
 */
#[MaxSteps(15)]
class PlanningAgent implements CodingAgent
{
    use Promptable;

    public function __construct(
        #[Workspace] private readonly PathGuard $pathGuard,
        private readonly ReadFile $readFile,
        private readonly Glob $glob,
        private readonly SearchCode $searchCode,
        private readonly GitDiff $gitDiff,
        private readonly ListRoutes $listRoutes,
        #[AiProvider] private string $provider = 'anthropic',
        #[AiModel] private string $model = 'claude-sonnet-4-6',
    ) {}

    protected function provider(): string
    {
        return $this->provider;
    }

    protected function model(): string
    {
        return $this->model;
    }

    public function messages(): iterable
    {
        return [];
    }

    public function tools(): iterable
    {
        return [
            $this->readFile,
            $this->glob,
            $this->searchCode,
            $this->gitDiff,
            $this->listRoutes,
        ];
    }

    public function instructions(): string
    {
        $workspace = $this->pathGuard->workspace();

        $projectMemory = (new ProjectMemory($workspace))->section();

        return <<<INSTRUCTIONS
        You are an expert Laravel architect operating inside the project at: {$workspace}

        You will be given a task. Your job is to produce an implementation PLAN — not to
        implement it. You have read-only access: use ReadFile, Glob, and SearchCode to
        understand the code the plan touches before writing it.

        ## Plan format

        1. One short paragraph: your understanding of the task and the approach.
        2. Numbered steps. Each step names the file(s) it touches and states the change
           in one or two sentences. Include new files, migrations, and test updates.
        3. A **Risks / open questions** section when anything is genuinely uncertain —
           omit it when nothing is.

        ## Rules

        - Read the relevant files before planning around them. Never guess signatures.
        - Prefer the smallest plan that fully solves the task.
        - Do not include code snippets longer than a few lines; the plan is for a human
          to approve, not a diff.
        - Do not attempt any edits — you have no editing tools.{$projectMemory}
        INSTRUCTIONS;
    }
}
