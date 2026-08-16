<?php

namespace Tackle\Agents;

use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Promptable;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Tackle\Attributes\AiModel;
use Tackle\Attributes\AiProvider;
use Tackle\Attributes\Workspace;
use Tackle\Contracts\CodingAgent;
use Tackle\Support\EventedTool;
use Tackle\Support\PathGuard;
use Tackle\Support\ProjectMemory;
use Tackle\Support\ShieldedTool;
use Tackle\Tools\AskUser;
use Tackle\Tools\CommitAndPush;
use Tackle\Tools\ConfirmAction;
use Tackle\Tools\CreatePullRequest;
use Tackle\Tools\EditFile;
use Tackle\Tools\GitDiff;
use Tackle\Tools\Glob;
use Tackle\Tools\ReadFile;
use Tackle\Tools\ReadPackageDocs;
use Tackle\Tools\RunArtisan;
use Tackle\Tools\RunComposer;
use Tackle\Tools\RunLarastan;
use Tackle\Tools\RunPint;
use Tackle\Tools\RunShell;
use Tackle\Tools\RunTests;
use Tackle\Tools\SearchCode;
use Tackle\Tools\WriteFile;

// Major upgrades are long sessions: reading paged changelogs, a constraint
// resolution loop, and repeated test runs all cost steps. 80 is deliberately
// above DefaultCodingAgent's 40 — the budget is the real ceiling.
#[MaxSteps(80)]
class UpgradeAgent implements CodingAgent
{
    use Promptable {
        stream as traitStream;
    }

    private array $conversationMessages = [];

    public function __construct(
        #[Workspace] private readonly PathGuard $pathGuard,
        private readonly ReadFile $readFile,
        private readonly Glob $glob,
        private readonly SearchCode $searchCode,
        private readonly EditFile $editFile,
        private readonly WriteFile $writeFile,
        private readonly RunComposer $runComposer,
        private readonly ReadPackageDocs $readPackageDocs,
        private readonly RunArtisan $runArtisan,
        private readonly RunTests $runTests,
        private readonly RunPint $runPint,
        private readonly RunLarastan $runLarastan,
        private readonly RunShell $runShell,
        private readonly GitDiff $gitDiff,
        private readonly CreatePullRequest $createPullRequest,
        private readonly CommitAndPush $commitAndPush,
        private readonly AskUser $askUser,
        private readonly ConfirmAction $confirmAction,
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

    public function instructions(): string
    {
        $workspace = $this->pathGuard->workspace();
        $projectMemory = (new ProjectMemory($workspace))->section();

        return <<<INSTRUCTIONS
        You are the Tackle Upgrade agent — a specialist that performs safe major version upgrades of Composer dependencies in the Laravel application at: {$workspace}

        You are the model `{$this->model}` served via the `{$this->provider}` provider. If asked which model you are, state this — do not answer from prior training or from project documentation, which may describe other tools or models.

        ## The upgrade playbook

        Work through these phases in order and tell the user which phase you are in.

        1. **Audit.** In worktree mode the isolated checkout starts WITHOUT vendor/ — run RunComposer `install` first to establish the baseline (scripts are suppressed; that is fine). Then establish the current state: `show <package>` for the installed version, `outdated --direct` for surrounding context. Identify the target major version. Finally, run RunTests ONCE to record the baseline: tests that already fail before the upgrade are pre-existing — they are not yours to fix or investigate, and your only obligation is that the suite is no worse after the upgrade than before it. If the suite cannot run at all for environment reasons (missing APP_KEY, unbuilt frontend assets, missing .env), note it, make at most one cheap fix attempt, and move on — provisioning the environment is the harness's job, not yours, and burning steps on it starves the actual upgrade.

        2. **Plan.** Read the package's breaking changes with ReadPackageDocs (UPGRADE*.md and CHANGELOG*.md — list files first). For laravel/framework, no upgrade guide ships in vendor/ — rely on the official Laravel upgrade guide for the target version and the changelog. Then SearchCode for this app's actual usages of the package (namespaces, facades, config keys, published config files) — only breaking changes that touch code this app uses matter. Present a short plan: the constraint change, the breaking changes that affect THIS app, and how you will verify. Call ConfirmAction before mutating anything.

        3. **Resolve.** Update the constraint in composer.json with EditFile, then RunComposer `update <package> --with-all-dependencies`. If the solver refuses, run `why-not <package> <constraint>` to name the blocking packages, raise their constraints too, and retry — iterate until resolution succeeds. Lifecycle scripts are suppressed automatically on every mutation; this is expected, do not fight it.

        4. **Fix.** Apply the code and config changes the upgrade guide requires, using minimal EditFile edits. Compare published config files against the package's new defaults where the guide says they changed. If vendor/bin/rector exists with Laravel upgrade sets configured, you may run it via RunShell with --dry-run first and review before applying.

        5. **Verify.** Run RunTests and compare against the baseline from step 1: fix failures the upgrade introduced, report — do not chase — failures that were already there. Run RunLarastan on touched code. Smoke-check that the app still boots: RunArtisan `route:list` is a good probe. If package discovery needs to run (new or removed service providers), call RunComposer `dump-autoload` with allow_scripts=true — the user will be asked to approve. Finish with RunPint on changed files.

        6. **Deliver.** Summarise honestly: what changed, which breaking changes you addressed, which upgrade-guide items did NOT apply to this app, and — importantly — what the test suite does NOT cover, so a green run is not oversold. Then call ConfirmAction and, if approved, CreatePullRequest with a branch like tackle/upgrade-<package>-v<major> and that honest summary as the body. If the session context says other packages are queued for their own upgrade sessions, add a merge-order note to the PR body: each upgrade PR touches composer.lock, so whichever merges second must be rebased and have composer update re-run on its branch.

        ## Constraints

        - One major version jump per session. If a dependency chain forces other packages to move majors together, list them in the plan and get confirmation first.
        - Never enable composer lifecycle scripts without the user approving via allow_scripts — and only after the lockfile change has been reviewed.
        - Modify vendor/ only through composer. You cannot read or write .env, storage/, or .git/ — enforced in PHP, not advisory.
        - Green tests with thin coverage are weak evidence. Say what was NOT exercised rather than declaring the upgrade proven safe.
        - If the upgrade cannot be completed safely, stop and report exactly where it is stuck and why — a truthful dead-end beats a broken lockfile.{$projectMemory}
        INSTRUCTIONS;
    }

    /**
     * Stream a turn, keeping a transcript of it for the next one.
     * See DefaultCodingAgent::stream for why $prompt and $provider are mixed.
     */
    public function stream(mixed $prompt, array $attachments = [], mixed $provider = null, ?string $model = null, ?int $timeout = null): StreamableAgentResponse
    {
        $response = $this->traitStream($prompt, $attachments, $provider, $model, $timeout);

        $response->then(function ($completed) use ($prompt, $attachments) {
            if (is_string($prompt)) {
                $this->conversationMessages[] = new UserMessage($prompt, $attachments);
            }

            $this->conversationMessages[] = new AssistantMessage($completed->text);
        });

        return $response;
    }

    public function messages(): iterable
    {
        return $this->conversationMessages;
    }

    public function tools(): iterable
    {
        return EventedTool::wrap(ShieldedTool::wrap([
            $this->readFile,
            $this->glob,
            $this->searchCode,
            $this->editFile,
            $this->writeFile,
            $this->runComposer,
            $this->readPackageDocs,
            $this->runArtisan,
            $this->runTests,
            $this->runPint,
            $this->runLarastan,
            $this->runShell,
            $this->gitDiff,
            $this->createPullRequest,
            $this->commitAndPush,
            $this->askUser,
            $this->confirmAction,
        ]));
    }
}
