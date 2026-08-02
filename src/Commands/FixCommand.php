<?php

namespace Tackle\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\App;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolCall;
use Laravel\Ai\Streaming\Events\ToolResult;
use Laravel\Prompts\Stream;
use Tackle\Contracts\CodingAgent;
use Tackle\Healing\GitHubReader;
use Tackle\Healing\SentryReader;
use Tackle\Prompts\TackleSuggestPrompt;
use Tackle\Support\BudgetTracker;
use Tackle\Support\WorktreeManager;

use function Laravel\Prompts\error as promptError;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\note;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\stream;
use function Laravel\Prompts\text;
use function Laravel\Prompts\title;
use function Laravel\Prompts\warning;

class FixCommand extends Command
{
    protected $signature = 'ai:fix
        {--sentry=      : Sentry issue ID to fetch and fix}
        {--issue=       : GitHub issue number to fetch and fix}
        {--shell=       : Override the shell mode for this session (off|allowlist|approve|yolo)}
        {--off          : Shorthand for --shell=off}
        {--allowlist    : Shorthand for --shell=allowlist}
        {--approve      : Shorthand for --shell=approve}
        {--yolo         : Shorthand for --shell=yolo}
        {--worktree     : Force worktree isolation for this session}
        {--no-worktree  : Disable worktree isolation for this session}';

    protected $description = 'Fix an exception or issue — loads context from Sentry, a GitHub issue, or a pasted exception, then opens an interactive fix session.';

    private ?Stream $activeStream = null;

    private array $history = [];

    public function handle(CodingAgent $agent, BudgetTracker $budget, WorktreeManager $worktrees): int
    {
        if (! App::runningInConsole()) {
            $this->error('ai:fix must be run from the terminal.');

            return self::FAILURE;
        }

        if (! $this->isTty()) {
            $this->error('ai:fix requires an interactive TTY.');

            return self::FAILURE;
        }

        $shell = match (true) {
            (bool) $this->option('off') => 'off',
            (bool) $this->option('allowlist') => 'allowlist',
            (bool) $this->option('approve') => 'approve',
            (bool) $this->option('yolo') => 'yolo',
            default => $this->option('shell'),
        };

        if ($shell !== null) {
            if (! in_array($shell, ['off', 'allowlist', 'approve', 'yolo'], strict: true)) {
                $this->error("Invalid --shell value '{$shell}'. Must be one of: off, allowlist, approve, yolo.");

                return self::FAILURE;
            }
            config(['tackle.shell' => $shell]);
        }

        $useWorktree = $this->resolveWorktreeMode();

        if ($useWorktree) {
            try {
                $worktrees->create();
            } catch (\RuntimeException $e) {
                $this->warn('Could not create worktree: '.$e->getMessage().' — falling back to live workspace.');
                $useWorktree = false;
            }
        }

        try {
            return $this->runSession($agent, $budget, $useWorktree);
        } finally {
            if ($worktrees->active()) {
                $worktrees->cleanup();
            }
        }
    }

    private function runSession(CodingAgent $agent, BudgetTracker $budget, bool $worktree): int
    {
        $model = config('tackle.model', 'claude-sonnet-4-6');
        $budgetUsd = config('tackle.budget_usd', 1.00);
        $shellMode = $this->resolveShellMode();
        $wtLabel = $worktree ? ' · worktree: on' : '';

        title('Tackle Fix — Ready');
        intro("Laravel Tackle Fix  ·  {$model}  ·  \${$budgetUsd} budget  ·  shell: {$shellMode}{$wtLabel}");

        if ($worktree) {
            note('Worktree mode — all edits go to an isolated copy of the repo. Live files are untouched until you open a PR.');
        }

        // Load context from the provided source, or prompt for it.
        [$firstPrompt, $sourceLabel] = $this->buildFirstPrompt();

        if ($firstPrompt === null) {
            return self::FAILURE;
        }

        if ($sourceLabel) {
            $this->line("<fg=cyan>  Context loaded from {$sourceLabel}</>");
            $this->line('');
        }

        title('Tackle Fix — Thinking…');
        $this->line('');

        try {
            $this->runAgentTurn($agent, $budget, $firstPrompt);
        } catch (\Throwable $e) {
            $this->closeStream();
            promptError('Agent error: '.$e->getMessage());
            note('The session is still active — continue with a new task.');
        }

        $this->showGitDiff();
        $this->history[] = $firstPrompt;

        // Drop into the interactive follow-up loop.
        while (true) {
            title('Tackle Fix — Ready');
            $this->line('');
            $this->line('<fg=gray>─────────────────────────────────────────────────────────</>');

            $task = (new TackleSuggestPrompt(
                label: 'Follow up or type "exit" to quit',
                options: fn (string $value) => array_reverse($this->history),
                placeholder: 'e.g. "add a test", "open a PR", or type "exit"',
                required: true,
                hint: count($this->history) > 0 ? 'Use ↑↓ for history' : '',
                scroll: 10,
            ))->prompt();

            if (in_array(strtolower(trim($task)), ['exit', 'quit', 'q'], strict: true)) {
                title('');
                outro($budget->summary().' · Goodbye!');

                return self::SUCCESS;
            }

            $this->history[] = $task;

            if ($budget->overBudget()) {
                title('Tackle Fix — Budget Exceeded');
                promptError(sprintf(
                    'Session aborted: estimated cost ($%.4f) exceeds the budget limit ($%.2f).',
                    $budget->estimatedCost(),
                    $budget->budgetUsd(),
                ));

                return self::FAILURE;
            }

            title('Tackle Fix — Thinking…');
            $this->line('');

            try {
                $this->runAgentTurn($agent, $budget, $task);
            } catch (\Throwable $e) {
                $this->closeStream();
                promptError('Agent error: '.$e->getMessage());
                note('The session is still active — continue with a new task.');
            }

            $this->showGitDiff();
        }
    }

    /**
     * Build the initial agent prompt and a short label describing the source.
     * Returns [prompt, sourceLabel] or [null, null] on failure.
     *
     * @return array{string|null, string|null}
     */
    private function buildFirstPrompt(): array
    {
        if ($sentryId = $this->option('sentry')) {
            $context = app(SentryReader::class)->forIssue((string) $sentryId);

            if ($context === '') {
                $this->error("Could not fetch Sentry issue #{$sentryId}. Check SENTRY_AUTH_TOKEN and SENTRY_ORG.");

                return [null, null];
            }

            return [
                $this->wrapContext("Sentry issue #{$sentryId}", $context),
                "Sentry issue #{$sentryId}",
            ];
        }

        if ($issueNumber = $this->option('issue')) {
            $context = app(GitHubReader::class)->forIssue((int) $issueNumber);

            if ($context === '') {
                $this->error("Could not fetch GitHub issue #{$issueNumber}. Check GITHUB_TOKEN and GITHUB_REPO.");

                return [null, null];
            }

            return [
                $this->wrapContext("GitHub issue #{$issueNumber}", $context),
                "GitHub issue #{$issueNumber}",
            ];
        }

        // No source flag — prompt the user to describe or paste the exception.
        $description = text(
            label: 'Paste the exception or describe what to fix',
            placeholder: 'e.g. "TypeError in BillingService line 42: ..." or "fix the login 500 error"',
            required: true,
            hint: 'You can paste a stack trace, a Sentry/Telescope excerpt, or a plain description.',
        );

        return [
            "Fix the following issue in this Laravel application:\n\n{$description}\n\n"
            .'Diagnose the root cause by reading the relevant code, apply the minimal fix, run tests to verify, '
            .'then offer to open a pull request.',
            null,
        ];
    }

    private function wrapContext(string $label, string $context): string
    {
        return "Fix the following issue in this Laravel application:\n\n"
            ."--- {$label} ---\n{$context}\n---\n\n"
            .'Diagnose the root cause by reading the relevant code, apply the minimal fix, run tests to verify, '
            .'then offer to open a pull request.';
    }

    private function runAgentTurn(CodingAgent $agent, BudgetTracker $budget, string $task): void
    {
        try {
            $response = $agent->stream($task);

            $response->each(function ($event) use ($budget) {
                if ($event instanceof TextDelta) {
                    if ($this->activeStream === null) {
                        $this->line('');
                        $this->activeStream = stream();
                    }
                    $this->activeStream->append($event->delta);

                    return;
                }

                if ($event instanceof ToolCall) {
                    $this->closeStream();
                    $this->renderToolCall($event);

                    return;
                }

                if ($event instanceof ToolResult) {
                    $this->renderToolResult($event);

                    return;
                }

                if ($event instanceof StreamEnd) {
                    $this->closeStream();
                    $budget->record($event->usage->promptTokens, $event->usage->completionTokens);

                    if ($budget->overBudget()) {
                        promptError(sprintf(
                            'Budget limit reached ($%.4f / $%.2f). Stopping.',
                            $budget->estimatedCost(),
                            $budget->budgetUsd(),
                        ));
                    } elseif ($budget->estimatedCost() / $budget->budgetUsd() >= 0.8) {
                        warning(sprintf(
                            'Budget at %.0f%% ($%.4f / $%.2f) — consider wrapping up soon.',
                            ($budget->estimatedCost() / $budget->budgetUsd()) * 100,
                            $budget->estimatedCost(),
                            $budget->budgetUsd(),
                        ));
                    }
                }
            });
        } finally {
            $this->closeStream();
        }
    }

    private function closeStream(): void
    {
        if ($this->activeStream !== null) {
            $this->activeStream->close();
            $this->activeStream = null;
        }
    }

    private function renderToolCall(ToolCall $event): void
    {
        $tool = $event->toolCall->name;
        $args = $event->toolCall->arguments;

        if (in_array($tool, ['AskUser', 'ConfirmAction'], strict: true)) {
            return;
        }

        $summary = match ($tool) {
            'ReadFile' => '📖 reading '.($args['path'] ?? '?'),
            'Glob' => '🔍 listing '.($args['pattern'] ?? '?'),
            'SearchCode' => '🔍 searching for '.($args['query'] ?? '?'),
            'EditFile' => '✏️  editing '.($args['path'] ?? '?'),
            'WriteFile' => '📝 creating '.($args['path'] ?? '?'),
            'RunArtisan' => '⚡ artisan '.($args['command'] ?? '?'),
            'RunTests' => '🧪 running tests'.(! empty($args['filter']) ? ' (filter: '.$args['filter'].')' : ''),
            'RunPint' => '✨ formatting with pint',
            'RunLarastan' => '🔎 running larastan'.(! empty($args['path']) ? ' on '.$args['path'] : ''),
            'RunShell' => '💻 shell: '.($args['command'] ?? '?'),
            'QueryDatabase' => '🗄️  querying database',
            'ReadLog' => '📋 reading log'.(! empty($args['filter']) ? ' (filter: '.$args['filter'].')' : ''),
            'GitDiff' => '🔀 git diff'.(! empty($args['path']) ? ' '.$args['path'] : ''),
            'ListRoutes' => '🗺️  listing routes',
            'ReadTelescopeEntry' => '🔭 reading telescope',
            'ReadSentryIssue' => '🪲 reading sentry',
            'ReadGitHubIssue' => '🐙 reading github issue',
            'ReadPullRequest' => '🐙 reading pull request',
            'CreateGitHubIssue' => '🐙 creating github issue',
            'CreatePullRequest' => '🚀 opening pull request',
            'CommitAndPush' => '📤 committing and pushing',
            default => '→ '.$tool,
        };

        title('Tackle Fix — '.strip_tags($summary));
        $this->line("<fg=cyan>  {$summary}</>");
    }

    private function renderToolResult(ToolResult $event): void
    {
        $tool = $event->toolResult->name;
        $result = (string) ($event->toolResult->result ?? '');

        if (in_array($tool, ['RunTests', 'RunArtisan', 'RunShell'], strict: true)) {
            if (str_starts_with($result, 'Shell execution is disabled')) {
                $this->line('<fg=yellow>  ⚠ Refused — shell is disabled in this environment.</>');
            } elseif (str_starts_with($result, "Command '") && str_contains($result, 'not in the allowlist')) {
                $this->line('<fg=yellow>  ⚠ Refused — command not in allowlist.</>');
            } elseif (str_starts_with($result, 'RunTests is disabled')) {
                $this->line('<fg=yellow>  ⚠ Refused — tests are disabled in this environment.</>');
            } elseif (str_contains($result, 'FAILED') || str_contains($result, 'Error')) {
                $this->line('<fg=red>  ✗ Command reported failures — agent will handle them.</>');
            } else {
                $this->line('<fg=green>  ✓ Done</>');
            }
        }

        if ($tool === 'RunLarastan') {
            if (str_contains($result, 'not installed')) {
                $this->line('<fg=yellow>  ⚠ PHPStan not installed — skipping static analysis.</>');
            } elseif (str_contains($result, '[ERROR]') || str_contains($result, 'error')) {
                $this->line('<fg=red>  ✗ Larastan found issues — agent will handle them.</>');
            } else {
                $this->line('<fg=green>  ✓ No issues found</>');
            }
        }

        if ($tool === 'CommitAndPush') {
            if ($result === 'Changes committed and pushed to the existing PR branch.') {
                $this->line('<fg=green>  ✓ Committed and pushed.</>');
            } elseif ($result === 'No changes to commit.' || str_starts_with($result, 'No changes to commit —')) {
                $this->line('<fg=yellow>  ⚠ No changes to commit.</>');
            } elseif ($result === 'Cancelled by user.') {
                $this->line('<fg=yellow>  ⚠ Push cancelled.</>');
            } else {
                $this->line('<fg=red>  ✗ '.$result.'</>');
            }
        }

        if ($tool === 'EditFile' || $tool === 'WriteFile') {
            if (str_contains($result, 'outside the workspace')
                || str_contains($result, 'could not be resolved')
                || str_contains($result, 'protected pattern')
                || str_contains($result, 'not found')
                || str_contains($result, 'not unique')) {
                $this->line('<fg=yellow>  ⚠ '.$result.'</>');
            } else {
                $this->line('<fg=green>  ✓ File saved</>');
            }
        }
    }

    private function showGitDiff(): void
    {
        $wt = app(WorktreeManager::class);
        $root = $wt->active() ? $wt->path() : base_path();

        if (! is_dir($root.'/.git') && ! $wt->active()) {
            return;
        }

        $output = shell_exec('git -C '.escapeshellarg($root).' diff --stat 2>/dev/null');

        if ($output && trim($output) !== '') {
            $this->line('');
            $label = $wt->active() ? 'Worktree changes (live files untouched)' : 'Uncommitted changes';
            note($label."\n".trim($output));
        }
    }

    private function resolveWorktreeMode(): bool
    {
        if ($this->option('worktree')) {
            return true;
        }

        if ($this->option('no-worktree')) {
            return false;
        }

        // ai:fix defaults worktree on — fixes should not touch live files without review.
        return true;
    }

    private function resolveShellMode(): string
    {
        $config = config('tackle.shell', 'approve');

        if (is_array($config)) {
            $env = app()->environment();

            return $config[$env] ?? $config['*'] ?? 'approve';
        }

        return $config;
    }

    private function isTty(): bool
    {
        return function_exists('posix_isatty') && posix_isatty(STDIN);
    }
}
