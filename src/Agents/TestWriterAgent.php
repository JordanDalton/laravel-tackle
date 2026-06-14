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
use Tackle\Tools\RunTests;
use Tackle\Tools\SearchCode;
use Tackle\Tools\WriteFile;

#[MaxSteps(20)]
class TestWriterAgent implements CodingAgent
{
    use Promptable;

    public function __construct(
        #[AiProvider] private string $provider = 'anthropic',
        #[AiModel]    private string $model    = 'claude-sonnet-4-6',
        #[Workspace] private readonly PathGuard $pathGuard,
        private readonly ReadFile $readFile,
        private readonly Glob $glob,
        private readonly SearchCode $searchCode,
        private readonly WriteFile $writeFile,
        private readonly RunTests $runTests,
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
            $this->writeFile,
            $this->runTests,
        ];
    }

    public function instructions(): string
    {
        $workspace = $this->pathGuard->workspace();

        return <<<INSTRUCTIONS
        You are an expert Laravel test writer operating inside the project at: {$workspace}

        Your job is to write thorough, idiomatic tests for the code you are given.

        ## Process

        1. **Read the target class fully** — understand its constructor, dependencies, public methods, and side effects.
        2. **Find existing tests** — check the `tests/` directory to understand naming conventions, helpers, and what is already covered. Do not duplicate existing tests.
        3. **Read closely-related classes** — factories, models, services, or traits the subject uses.
        4. **Write the tests** — use Pest by default (PHPUnit if the project has no Pest). Place feature tests in `tests/Feature/`, unit tests in `tests/Unit/`.
        5. **Run the tests** — verify they pass before finishing. If a test fails, fix it.

        ## Test quality rules

        - Test behaviour, not implementation — assert outcomes, not that specific methods were called.
        - One logical assertion per test wherever possible.
        - Use descriptive test names: `it('returns null when user has no subscription')`.
        - Use factories for model creation. Never insert raw DB records without a factory unless one doesn't exist.
        - Cover: the happy path, edge cases, and at least one error/failure case per method.
        - Do not test private methods directly — test them through public API.
        - Do not mock the database — use SQLite in-memory (already configured in most Laravel test setups).

        ## Output

        Create the test file using WriteFile. Finish by running the tests to confirm they pass.
        INSTRUCTIONS;
    }
}
