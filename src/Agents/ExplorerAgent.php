<?php

namespace Tackle\Agents;

use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Promptable;
use Tackle\Attributes\AiModel;
use Tackle\Attributes\AiProvider;
use Tackle\Attributes\Workspace;
use Tackle\Contracts\CodingAgent;
use Tackle\Support\EventedTool;
use Tackle\Support\PathGuard;
use Tackle\Support\ProjectMemory;
use Tackle\Tools\GitDiff;
use Tackle\Tools\Glob;
use Tackle\Tools\ListRoutes;
use Tackle\Tools\ReadFile;
use Tackle\Tools\ReadLog;
use Tackle\Tools\SearchCode;

/**
 * Read-only subagent for codebase exploration. Delegated to via the Delegate
 * tool: it burns its own context reading and searching, then returns a
 * condensed report — the delegating agent keeps its context clean.
 */
#[MaxSteps(15)]
class ExplorerAgent implements CodingAgent
{
    use Promptable;

    public function __construct(
        #[Workspace] private readonly PathGuard $pathGuard,
        private readonly ReadFile $readFile,
        private readonly Glob $glob,
        private readonly SearchCode $searchCode,
        private readonly GitDiff $gitDiff,
        private readonly ListRoutes $listRoutes,
        private readonly ReadLog $readLog,
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
        return EventedTool::wrap([
            $this->readFile,
            $this->glob,
            $this->searchCode,
            $this->gitDiff,
            $this->listRoutes,
            $this->readLog,
        ]);
    }

    public function instructions(): string
    {
        $workspace = $this->pathGuard->workspace();

        $projectMemory = (new ProjectMemory($workspace))->section();

        return <<<INSTRUCTIONS
        You are a read-only exploration subagent working inside the Laravel project at: {$workspace}

        A coding agent has delegated a research task to you. Your job is to explore the
        codebase thoroughly and return a report the delegating agent can act on without
        re-reading the files itself. You cannot edit files and no user is available —
        never ask questions; make reasonable judgment calls and note them.

        ## How to work

        - Use Glob and SearchCode to locate relevant files, then ReadFile to study them.
        - Follow the trail: if a class delegates to another, read that one too.
        - Be exhaustive on the question asked; ignore everything unrelated to it.

        ## Report format — this is what the delegating agent receives

        - Lead with a direct answer to the question you were given.
        - Cite every claim with a file path (and line context where useful) so the
          delegating agent can jump straight to the right place.
        - Include exact names — classes, methods, config keys, routes — not paraphrases.
        - Quote short decisive snippets; never dump whole files.
        - Note anything surprising you found that the delegating agent likely needs
          (conventions, gotchas, related tests).
        - If you could not answer parts of the question, say so explicitly.{$projectMemory}
        INSTRUCTIONS;
    }
}
