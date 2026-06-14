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
use Tackle\Support\PathGuard;
use Tackle\Tools\AskUser;
use Tackle\Tools\ConfirmAction;
use Tackle\Tools\EditFile;
use Tackle\Tools\GitDiff;
use Tackle\Tools\Glob;
use Tackle\Tools\ListRoutes;
use Tackle\Tools\QueryDatabase;
use Tackle\Tools\ReadFile;
use Tackle\Tools\ReadLog;
use Tackle\Tools\CreateGitHubIssue;
use Tackle\Tools\CreatePullRequest;
use Tackle\Tools\ReadGitHubIssue;
use Tackle\Tools\ReadSentryIssue;
use Tackle\Tools\ReadTelescopeEntry;
use Tackle\Tools\RunArtisan;
use Tackle\Tools\RunPint;
use Tackle\Tools\RunShell;
use Tackle\Tools\RunTests;
use Tackle\Tools\SearchCode;
use Tackle\Tools\WriteFile;

#[MaxSteps(40)]
class DefaultCodingAgent implements CodingAgent
{
    use Promptable {
        stream as traitStream;
    }

    private array $conversationMessages = [];

    public function __construct(
        #[AiProvider] private string $provider = 'anthropic',
        #[AiModel]    private string $model    = 'claude-sonnet-4-6',
        #[Workspace] private readonly PathGuard $pathGuard,
        private readonly ReadFile $readFile,
        private readonly Glob $glob,
        private readonly SearchCode $searchCode,
        private readonly EditFile $editFile,
        private readonly WriteFile $writeFile,
        private readonly RunArtisan $runArtisan,
        private readonly RunTests $runTests,
        private readonly RunPint $runPint,
        private readonly RunShell $runShell,
        private readonly QueryDatabase $queryDatabase,
        private readonly ReadLog $readLog,
        private readonly GitDiff $gitDiff,
        private readonly ListRoutes $listRoutes,
        private readonly ReadTelescopeEntry $readTelescopeEntry,
        private readonly ReadSentryIssue $readSentryIssue,
        private readonly ReadGitHubIssue $readGitHubIssue,
        private readonly CreateGitHubIssue $createGitHubIssue,
        private readonly CreatePullRequest $createPullRequest,
        private readonly AskUser $askUser,
        private readonly ConfirmAction $confirmAction,
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

        return <<<INSTRUCTIONS
        You are an expert Laravel coding assistant running inside the project at: {$workspace}

        ## Core principles

        1. **Explore before editing.** Read files and search the codebase to understand context before making any changes.
        2. **Minimal, precise edits.** Make the smallest change that solves the problem. Use str_replace-style edits via EditFile — match exact, unique strings.
        3. **Explain before acting.** Before editing a file or running a command, briefly describe what you are about to do and why.
        4. **Verify with tests.** After modifying code, run RunTests to confirm correctness. Fix any failures before finishing.
        5. **Format before finishing.** Run RunPint on changed files before declaring a task done.
        6. **Be honest about uncertainty.** If you are not sure what a piece of code does, read more of it rather than guessing.

        ## Tool usage guidance

        - Use SearchCode to find symbols, class names, method names, or strings — faster than reading whole directories.
        - Use Glob to list files by pattern when you need to understand project structure.
        - Use ReadFile only after narrowing down to the specific file you need.
        - Use EditFile with unique surrounding context. If old_str is not unique, widen it until it is.
        - Use WriteFile only for genuinely new files; never for files that already exist.
        - Use RunArtisan for framework operations (make:model, migrate, etc.). The allowlist permits: make:*, migrate, migrate:*, db:seed, route:list, test. Do NOT attempt RunArtisan with commands outside this list (e.g. db:wipe, db:drop) — they will be refused. For destructive operations not in the allowlist, tell the user to run the command themselves in their terminal.
        - Use RunTests after any code change.
        - Use RunShell only when no other tool suffices.

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
        - Do not auto-commit or push changes.
        INSTRUCTIONS;
    }

    public function stream(string $prompt, array $attachments = [], mixed $provider = null, ?string $model = null, ?int $timeout = null): StreamableAgentResponse
    {
        $response = $this->traitStream($prompt, $attachments, $provider, $model, $timeout);

        $response->then(function ($completed) use ($prompt) {
            $this->conversationMessages[] = new UserMessage($prompt);
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
        return [
            $this->readFile,
            $this->glob,
            $this->searchCode,
            $this->editFile,
            $this->writeFile,
            $this->runArtisan,
            $this->runTests,
            $this->runPint,
            $this->runShell,
            $this->queryDatabase,
            $this->readLog,
            $this->gitDiff,
            $this->listRoutes,
            $this->readTelescopeEntry,
            $this->readSentryIssue,
            $this->readGitHubIssue,
            $this->createGitHubIssue,
            $this->createPullRequest,
            $this->askUser,
            $this->confirmAction,
        ];
    }
}
