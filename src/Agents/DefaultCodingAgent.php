<?php

namespace Tackle\Agents;

use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Promptable;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Telescope\Telescope;
use Tackle\Agents\Concerns\CachesInstructions;
use Tackle\Agents\Concerns\SwitchesModel;
use Tackle\Attributes\AiModel;
use Tackle\Attributes\AiProvider;
use Tackle\Attributes\Workspace;
use Tackle\Contracts\CodingAgent;
use Tackle\Support\CommandGuard;
use Tackle\Support\EventedTool;
use Tackle\Support\PathGuard;
use Tackle\Support\ProjectMemory;
use Tackle\Support\ShieldedTool;
use Tackle\Tools\AskUser;
use Tackle\Tools\CommitAndPush;
use Tackle\Tools\ConfirmAction;
use Tackle\Tools\CreateGitHubIssue;
use Tackle\Tools\CreatePullRequest;
use Tackle\Tools\Delegate;
use Tackle\Tools\EditFile;
use Tackle\Tools\GitDiff;
use Tackle\Tools\Glob;
use Tackle\Tools\ListRoutes;
use Tackle\Tools\QueryDatabase;
use Tackle\Tools\ReadFile;
use Tackle\Tools\ReadGitHubIssue;
use Tackle\Tools\ReadLog;
use Tackle\Tools\ReadPullRequest;
use Tackle\Tools\ReadSentryIssue;
use Tackle\Tools\ReadTelescopeEntry;
use Tackle\Tools\RunArtisan;
use Tackle\Tools\RunLarastan;
use Tackle\Tools\RunPint;
use Tackle\Tools\RunShell;
use Tackle\Tools\RunTests;
use Tackle\Tools\SearchCode;
use Tackle\Tools\WriteFile;

#[MaxSteps(40)]
class DefaultCodingAgent implements CodingAgent, HasProviderOptions
{
    use CachesInstructions;
    use Promptable {
        stream as traitStream;
    }
    use SwitchesModel;

    private array $conversationMessages = [];

    public function __construct(
        #[Workspace] private readonly PathGuard $pathGuard,
        private readonly ReadFile $readFile,
        private readonly Glob $glob,
        private readonly SearchCode $searchCode,
        private readonly EditFile $editFile,
        private readonly WriteFile $writeFile,
        private readonly RunArtisan $runArtisan,
        private readonly RunTests $runTests,
        private readonly RunPint $runPint,
        private readonly RunLarastan $runLarastan,
        private readonly RunShell $runShell,
        private readonly QueryDatabase $queryDatabase,
        private readonly ReadLog $readLog,
        private readonly GitDiff $gitDiff,
        private readonly ListRoutes $listRoutes,
        private readonly ReadTelescopeEntry $readTelescopeEntry,
        private readonly ReadSentryIssue $readSentryIssue,
        private readonly ReadGitHubIssue $readGitHubIssue,
        private readonly ReadPullRequest $readPullRequest,
        private readonly CreateGitHubIssue $createGitHubIssue,
        private readonly CreatePullRequest $createPullRequest,
        private readonly CommitAndPush $commitAndPush,
        private readonly AskUser $askUser,
        private readonly ConfirmAction $confirmAction,
        private readonly Delegate $delegate,
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

        $guard = app(CommandGuard::class);
        $allowlist = $guard->resolveList(config('tackle.artisan_allowlist', []));
        $destructive = $guard->resolveList(config('tackle.artisan_destructive', []));

        $allowlistStr = $allowlist ? implode(', ', $allowlist) : '(none)';
        $destructiveStr = $destructive ? implode(', ', $destructive) : '(none)';

        $projectMemory = (new ProjectMemory($workspace))->section();
        $delegation = $this->delegationGuidance();

        return <<<INSTRUCTIONS
        You are an expert Laravel coding assistant running inside the project at: {$workspace}

        You are the model `{$this->model}` served via the `{$this->provider}` provider. If asked which model you are, state this — do not answer from prior training or from project documentation, which may describe other tools or models.

        ## Core principles

        1. **Explore before editing.** Read files and search the codebase to understand context before making any changes.
        2. **Minimal, precise edits.** Make the smallest change that solves the problem. Use str_replace-style edits via EditFile — match exact, unique strings.
        3. **Explain before acting.** Before editing a file or running a command, briefly describe what you are about to do and why.
        4. **Verify with tests.** After modifying code, run RunTests to confirm correctness. Fix any failures before finishing.
        5. **Check types when relevant.** If the project has PHPStan/Larastan installed, run RunLarastan on changed files after editing to catch type errors. Fix any findings before finishing.
        6. **Format before finishing.** Run RunPint on changed files before declaring a task done.
        6. **Be honest about uncertainty.** If you are not sure what a piece of code does, read more of it rather than guessing.

        ## Tool usage guidance

        - Use SearchCode to find symbols, class names, method names, or strings — faster than reading whole directories.
        - Use Glob to list files by pattern when you need to understand project structure.
        - Use ReadFile only after narrowing down to the specific file you need.
        - Use EditFile with unique surrounding context. If old_str is not unique, widen it until it is.
        - Use WriteFile only for genuinely new files; never for files that already exist.
        - Use RunArtisan for framework operations (make:model, migrate, etc.). Allowed for this environment: {$allowlistStr}. Destructive (terminal confirmation required): {$destructiveStr}. Do NOT attempt RunArtisan with commands outside both lists — they will be refused. For blocked operations, tell the user to run the command themselves in their terminal.
        - Use RunTests after any code change.
        - Use RunShell only when no other tool suffices.{$delegation}
        - Use ReadPullRequest (not ReadGitHubIssue) when the user references a PR number. ReadPullRequest returns the branch name (head ref) which you MUST pass to CommitAndPush as the `branch` parameter.
        - Use CommitAndPush to stage, commit, and push additional changes to an existing PR branch. Always pass the `branch` parameter — the branch name returned by ReadPullRequest or the one you passed to CreatePullRequest. CommitAndPush will show the user a diff preview and ask for confirmation before pushing — you do not need to call ConfirmAction separately. CommitAndPush pushes via `HEAD:<branch>` so no checkout is needed and you will never hit a "branch already checked out" error. Do NOT use RunShell for git add/commit/push — it may be blocked in this environment.

        ## User interaction — REQUIRED RULES

        **RULE: When the user asks "what are my options?", "what could this return?", "what are some ideas?", "give me some choices", or any similar exploratory question that results in a list of options — you MUST call AskUser with those options. Do NOT write them as a numbered list or bullet points in your response text.**

        The correct flow is:
        1. Research (read files, search code, etc.) to understand the context.
        2. Identify the options.
        3. Call AskUser with a short label for each option. Always append a final option: "Something else — let me describe what I want". If the user selects it, ask them to clarify in plain text, then proceed based on their answer.
        4. Wait for the user's selection, then proceed to implement or explain only what they chose.

        NEVER do this: research → write a numbered list in text → end with "Would you like me to implement one?"
        ALWAYS do this: research → call AskUser with the options → act on the returned selection.

        **RULE: After completing work that was sourced from a GitHub issue (fetched via ReadGitHubIssue), always offer to open a pull request. Call ConfirmAction first ("Open a pull request for issue #N?"), then call CreatePullRequest with a descriptive branch name (e.g. tackle/issue-3-fix-login), a clear title, and a summary of what was changed and why. Pass the issue_number so the PR auto-closes the issue on merge.**

        **RULE: Always call ConfirmAction before any destructive or irreversible operation** (deleting files, dropping tables, running migrations on production). If the user cancels, stop and explain what you would have done.

        ## Safety

        - You cannot read or write .env files, storage/, vendor/, or .git/. This is enforced in PHP, not advisory.
        - All edits are left unstaged. The user can review them with `git diff`.
        - Do not auto-commit or push changes.{$projectMemory}
        INSTRUCTIONS;
    }

    /**
     * Extra tool guidance included only when subagents are configured, since
     * the Delegate tool itself is only in the toolset then.
     */
    private function delegationGuidance(): string
    {
        if (! config('tackle.subagents', [])) {
            return '';
        }

        return "\n".<<<'GUIDANCE'
        - Use Delegate for self-contained research that would flood your context with intermediate output — e.g. "trace how feature X works" across many files, or writing a test file. The subagent starts fresh and cannot see this conversation, so give it a complete brief; then act on its report instead of re-reading the files yourself. Do not delegate small lookups (a single ReadFile or SearchCode is cheaper), and never delegate the actual code edits you were asked to make.
        GUIDANCE;
    }

    /**
     * Stream a turn, keeping a transcript of it for the next one.
     *
     * $prompt and $provider are deliberately `mixed`. laravel/ai has narrowed
     * this contract across releases — 0.1 declares `string $prompt`, 0.3
     * declares `Decisions|string` and adds `Enums\Lab` to $provider — and a
     * child may widen a parameter type but never narrow it. Accepting `mixed`
     * satisfies every version in the composer constraint without naming classes
     * that only exist in some of them.
     */
    public function stream(mixed $prompt, array $attachments = [], mixed $provider = null, ?string $model = null, ?int $timeout = null): StreamableAgentResponse
    {
        $response = $this->traitStream($prompt, $attachments, $provider, $model, $timeout);

        $response->then(function ($completed) use ($prompt, $attachments) {
            // Approval decisions resume an existing turn rather than starting a
            // new one, so there is no user message to record for them.
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

    /**
     * Total characters held in the conversation — a cheap proxy for context
     * size, used to decide when to compact.
     */
    public function conversationSize(): int
    {
        return array_sum(array_map(
            fn ($message) => strlen((string) ($message->content ?? '')),
            $this->conversationMessages,
        ));
    }

    /** @param  array<mixed>  $messages */
    public function replaceConversation(array $messages): void
    {
        $this->conversationMessages = array_values($messages);
    }

    public function forgetConversation(): void
    {
        $this->conversationMessages = [];
    }

    public function tools(): iterable
    {
        // Always-available core: read/edit/run/inspect the project.
        $tools = [
            $this->readFile,
            $this->glob,
            $this->searchCode,
            $this->editFile,
            $this->writeFile,
            $this->runArtisan,
            $this->runTests,
            $this->runPint,
            $this->runLarastan,
            $this->runShell,
            $this->queryDatabase,
            $this->readLog,
            $this->gitDiff,
            $this->listRoutes,
            $this->askUser,
            $this->confirmAction,
        ];

        // Integration tools cost schema tokens on every step, so only expose
        // them when their integration is actually configured — the agent can't
        // use a GitHub tool without a token anyway. Lazy exposure trims the
        // per-step floor with zero capability loss.
        if (config('tackle.github.token')) {
            $tools[] = $this->readGitHubIssue;
            $tools[] = $this->readPullRequest;
            $tools[] = $this->createGitHubIssue;
            $tools[] = $this->createPullRequest;
            $tools[] = $this->commitAndPush;
        }

        if (config('tackle.sentry.auth_token')) {
            $tools[] = $this->readSentryIssue;
        }

        if (class_exists(Telescope::class)) {
            $tools[] = $this->readTelescopeEntry;
        }

        if (config('tackle.subagents', [])) {
            $tools[] = $this->delegate;
        }

        // Optional explicit allowlist: tackle.tools = ['ReadFile', ...] keeps
        // only those (by class basename). Null/empty = everything above.
        $allow = config('tackle.tools');
        if (is_array($allow) && $allow !== []) {
            $tools = array_values(array_filter(
                $tools,
                fn ($tool) => in_array(class_basename($tool), $allow, true),
            ));
        }

        return EventedTool::wrap(ShieldedTool::wrap($tools));
    }
}
