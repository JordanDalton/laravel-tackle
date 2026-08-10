<?php

namespace Tackle\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\App;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolCall;
use Laravel\Ai\Streaming\Events\ToolResult;
use Laravel\Prompts\Stream;
use Tackle\Agents\PlanningAgent;
use Tackle\Commands\Concerns\ResolvesSessionOptions;
use Tackle\Contracts\CodingAgent;
use Tackle\Events\SessionEnded;
use Tackle\Events\SessionStarted;
use Tackle\Prompts\TackleSuggestPrompt;
use Tackle\Support\BudgetTracker;
use Tackle\Support\ConversationCompactor;
use Tackle\Support\CustomCommands;
use Tackle\Support\ImageAttachments;
use Tackle\Support\SessionStore;
use Tackle\Support\ToolSummary;
use Tackle\Support\WorktreeManager;

use function Laravel\Prompts\error as promptError;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\note;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\select;
use function Laravel\Prompts\stream;
use function Laravel\Prompts\text;
use function Laravel\Prompts\title;
use function Laravel\Prompts\warning;

class CodeCommand extends Command
{
    use ResolvesSessionOptions;

    protected $signature = 'ai:code
        {--session= : Resume a named session}
        {--plan : Plan first — every task produces a read-only plan you approve before edits happen}
        {--shell= : Override the shell mode for this session (off|allowlist|approve|yolo)}
        {--off : Shorthand for --shell=off}
        {--allowlist : Shorthand for --shell=allowlist}
        {--approve : Shorthand for --shell=approve}
        {--yolo : Shorthand for --shell=yolo}
        {--worktree : Force worktree isolation for this session}
        {--no-worktree : Disable worktree isolation for this session}';

    protected $description = 'Start an interactive AI coding session powered by Laravel Tackle.';

    private ?Stream $activeStream = null;

    private array $history = [];

    private ?array $fileIndex = null;

    private CustomCommands $customCommands;

    private PlanningAgent $planner;

    private ConversationCompactor $compactor;

    private SessionStore $sessions;

    private string $sessionName = 'default';

    public function handle(CodingAgent $agent, BudgetTracker $budget, WorktreeManager $worktrees, CustomCommands $commands, PlanningAgent $planner, ConversationCompactor $compactor, SessionStore $sessions): int
    {
        $this->customCommands = $commands;
        $this->planner = $planner;
        $this->compactor = $compactor;
        $this->sessions = $sessions;
        $this->sessionName = (string) ($this->option('session') ?: 'default');

        if (! App::runningInConsole()) {
            $this->error('ai:code must be run from the terminal.');

            return self::FAILURE;
        }

        if (! $this->isTty()) {
            $this->error('ai:code requires an interactive TTY — cannot run in a non-interactive pipe.');

            return self::FAILURE;
        }

        if (! $this->applyShellOverride()) {
            return self::FAILURE;
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

        title('Tackle — Ready');
        intro("Laravel Tackle  ·  {$model}  ·  \${$budgetUsd} budget  ·  shell: {$shellMode}{$wtLabel}");

        if ($worktree) {
            note('Worktree mode — all edits go to an isolated copy of the repo. Live files are untouched until you open a PR.');
        }

        SessionStarted::dispatch('ai:code', (string) config('tackle.provider', 'anthropic'), (string) $model);

        if ($this->sessions->enabled() && method_exists($agent, 'replaceConversation')) {
            $resumed = $this->sessions->load($this->sessionName);

            if ($resumed !== []) {
                $agent->replaceConversation($resumed);
                note("Resumed session '{$this->sessionName}' — ".count($resumed).' messages of history. Type /clear to start fresh.');
            }
        }

        while (true) {
            $task = (new TackleSuggestPrompt(
                label: 'What should I work on?',
                options: fn (string $value) => $this->completions($value),
                placeholder: 'Describe a task, /command, or "exit". Use @ to reference files.',
                required: true,
                hint: count($this->history) > 0 ? 'Use ↑↓ for history · @ for files · / for commands' : '@ for files · / for commands',
                scroll: 10,
            ))->prompt();

            if (in_array(strtolower(trim($task)), ['exit', 'quit', 'q'], strict: true)) {
                title('');
                outro($budget->summary().' · Goodbye!');
                $this->dispatchSessionEnded($budget);

                return self::SUCCESS;
            }

            $this->history[] = $task;

            $planFirst = (bool) $this->option('plan');

            if (($slash = CustomCommands::parse($task)) !== null) {
                [$name, $args] = $slash;

                if ($name === 'plan') {
                    $task = $args;
                    $planFirst = true;
                } else {
                    $resolved = $this->handleSlashCommand($name, $args, $agent);

                    if ($resolved === null) {
                        continue;
                    }

                    $task = $resolved;
                }
            }

            if (trim($task) === '') {
                continue;
            }

            if ($budget->overBudget()) {
                title('Tackle — Budget Exceeded');
                promptError(sprintf(
                    'Session aborted: estimated cost ($%.4f) exceeds the budget limit ($%.2f).',
                    $budget->estimatedCost(),
                    $budget->budgetUsd(),
                ));
                $this->dispatchSessionEnded($budget);

                return self::FAILURE;
            }

            if ($this->compactor->shouldCompact($agent)) {
                title('Tackle — Compacting context…');

                if ($this->compactor->compact($agent)) {
                    note('Session history compacted — older context summarized, recent messages kept.');
                }
            }

            title('Tackle — Thinking…');
            $this->line('');

            try {
                // Images first — otherwise @screenshot.png would be inlined
                // as (binary) text by the @-mention expansion below.
                [$task, $attachments, $unreadable] = ImageAttachments::extract($task, $this->workspaceRoot());

                if ($attachments !== []) {
                    note(count($attachments).' image'.(count($attachments) === 1 ? '' : 's').' attached.');

                    // An image with no instruction is a question waiting to be
                    // asked — not a license to improvise a task from context.
                    if (trim((string) preg_replace('/\[attached image: [^\]]*\]/', '', $task)) === '') {
                        $task = text(
                            label: 'What should I do with the image'.(count($attachments) === 1 ? '' : 's').'?',
                            required: true,
                        ).' '.$task;
                    }
                }

                foreach ($unreadable as $blocked) {
                    warning("Could not read image {$blocked} — it may have been deleted, or macOS is protecting it. Drag the file from Desktop or Finder instead of the floating screenshot thumbnail.");
                }

                $task = $this->expandAtMentions($task);

                if ($planFirst) {
                    $this->planThenExecute($agent, $budget, $task, $attachments);
                } else {
                    $this->runAgentTurn($agent, $budget, $task, $attachments);
                }
            } catch (\Throwable $e) {
                $this->closeStream();
                promptError('Agent error: '.$e->getMessage());
                note('The session is still active — continue with a new task.');
            }

            $this->persistSession($agent);

            $this->showGitDiff();

            title('Tackle — Ready');
            $this->line('');
            $this->line('<fg=gray>─────────────────────────────────────────────────────────</>');
        }
    }

    private function persistSession(CodingAgent $agent): void
    {
        if ($this->sessions->enabled() && method_exists($agent, 'messages')) {
            $this->sessions->save($this->sessionName, $agent->messages());
        }
    }

    /**
     * Handle a built-in or custom slash command. Returns a task string to run
     * (custom commands render to a prompt), or null when the command was
     * handled in place (or unknown) and the loop should continue.
     */
    private function handleSlashCommand(string $name, string $args, CodingAgent $agent): ?string
    {
        switch ($name) {
            case 'help':
                $builtins = "/plan <task> — plan first, edit after your approval\n"
                    ."/compact — summarize older session history now\n"
                    ."/clear — forget the session history\n"
                    .'/help — this list';
                $customs = collect($this->customCommands->all())
                    ->keys()
                    ->map(fn (string $custom) => "/{$custom}")
                    ->implode("\n");

                note("Built-in commands:\n{$builtins}".($customs !== '' ? "\n\nProject commands (.tackle/commands):\n{$customs}" : ''));

                return null;

            case 'clear':
                if (method_exists($agent, 'forgetConversation')) {
                    $agent->forgetConversation();
                    $this->sessions->forget($this->sessionName);
                    note('Session history cleared.');
                } else {
                    warning('The bound agent does not support /clear.');
                }

                return null;

            case 'compact':
                if ($this->compactor->compact($agent)) {
                    $this->persistSession($agent);
                    note('Session history compacted.');
                } else {
                    note('Nothing to compact yet.');
                }

                return null;
        }

        $rendered = $this->customCommands->render($name, $args);

        if ($rendered === null) {
            warning("Unknown command /{$name} — type /help for the list, or add .tackle/commands/{$name}.md to define it.");

            return null;
        }

        return $rendered;
    }

    /**
     * Plan mode: a read-only agent investigates and proposes a plan; nothing
     * is edited until the user approves it.
     */
    private function planThenExecute(CodingAgent $agent, BudgetTracker $budget, string $task, array $attachments = []): void
    {
        $planPrompt = $task;

        while (true) {
            title('Tackle — Planning…');
            note('Plan mode — read-only. No files will change until you approve the plan.');

            $plan = $this->runAgentTurn($this->planner, $budget, $planPrompt, $attachments);

            if (trim($plan) === '') {
                promptError('The planner returned no plan — running the task directly instead.');
                $this->runAgentTurn($agent, $budget, $task);

                return;
            }

            $choice = select(
                label: 'Execute this plan?',
                options: ['execute' => 'Execute the plan', 'revise' => 'Revise the plan', 'cancel' => 'Cancel'],
                default: 'execute',
            );

            if ($choice === 'cancel') {
                note('Plan discarded — nothing was changed.');

                return;
            }

            if ($choice === 'revise') {
                $feedback = text(label: 'What should change about the plan?', required: true);
                $planPrompt = "{$task}\n\nYou previously proposed this plan:\n{$plan}\n\nThe user asked for revisions: {$feedback}\n\nProduce an updated plan.";

                continue;
            }

            title('Tackle — Executing plan…');
            $this->runAgentTurn($agent, $budget, "{$task}\n\nThe following implementation plan has been reviewed and approved by the user — follow it, deviating only if the code contradicts it (say so when you do):\n\n{$plan}", $attachments);

            return;
        }
    }

    private function dispatchSessionEnded(BudgetTracker $budget): void
    {
        SessionEnded::dispatch(
            'ai:code',
            $budget->inputTokens(),
            $budget->outputTokens(),
            round($budget->estimatedCost(), 4),
        );
    }

    private function runAgentTurn(CodingAgent $agent, BudgetTracker $budget, string $task, array $attachments = []): string
    {
        $text = '';

        try {
            $response = $agent->stream($task, $attachments);

            $response->each(function ($event) use ($budget, &$text) {
                if ($event instanceof TextDelta) {
                    if ($this->activeStream === null) {
                        $this->line('');
                        $this->activeStream = stream();
                    }
                    $this->activeStream->append($event->delta);
                    $text .= $event->delta;

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

        return $text;
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

        // These tools render their own interactive UI — suppress the label.
        if (in_array($tool, ['AskUser', 'ConfirmAction'], strict: true)) {
            return;
        }

        $summary = ToolSummary::for($tool, $args);

        title('Tackle — '.strip_tags($summary));
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

    private function completions(string $input): array
    {
        // Slash-command completion while typing the command name itself.
        if (str_starts_with($input, '/') && ! str_contains($input, ' ')) {
            $query = substr($input, 1);
            $names = ['plan', 'compact', 'clear', 'help', ...array_keys($this->customCommands->all())];

            return collect($names)
                ->filter(fn (string $name) => $query === '' || stripos($name, $query) !== false)
                ->map(fn (string $name) => "/{$name}")
                ->values()
                ->all();
        }

        $atPos = strrpos($input, '@');

        if ($atPos === false) {
            return array_reverse($this->history);
        }

        $afterAt = substr($input, $atPos + 1);

        // Space after a completed @-mention — back to history.
        if (str_contains($afterAt, ' ')) {
            return array_reverse($this->history);
        }

        $before = substr($input, 0, $atPos + 1);

        // Query contains a slash — path-prefix glob so the user can drill down.
        if (str_contains($afterAt, '/') || $afterAt === '') {
            return $this->pathCompletions($before, $afterAt);
        }

        // No slash — fuzzy filename search across the whole project.
        return $this->filenameCompletions($before, $afterAt);
    }

    private function pathCompletions(string $before, string $query): array
    {
        $base = base_path();
        $excluded = ['vendor', '.git', 'node_modules', 'storage', 'bootstrap/cache'];
        $matches = glob($base.'/'.$query.'*') ?: [];
        $results = [];

        foreach ($matches as $match) {
            $relative = ltrim(str_replace($base, '', $match), '/');

            if (in_array(explode('/', $relative)[0], $excluded, strict: true)) {
                continue;
            }

            $results[] = $before.$relative.(is_dir($match) ? '/' : '');
        }

        return array_slice($results, 0, 20);
    }

    private function filenameCompletions(string $before, string $query): array
    {
        $index = $this->fileIndex();
        $results = [];

        foreach ($index as $relative) {
            if (stripos(basename($relative), $query) !== false) {
                $results[] = $before.$relative;
            }
        }

        // Exact basename-prefix matches first, then contains matches.
        usort($results, function (string $a, string $b) use ($before, $query): int {
            $aStart = stripos(basename(substr($a, strlen($before))), $query) === 0;
            $bStart = stripos(basename(substr($b, strlen($before))), $query) === 0;

            return match (true) {
                $aStart && ! $bStart => -1,
                ! $aStart && $bStart => 1,
                default => strcmp($a, $b),
            };
        });

        return array_slice($results, 0, 20);
    }

    private function fileIndex(): array
    {
        if ($this->fileIndex !== null) {
            return $this->fileIndex;
        }

        $excluded = ['vendor', '.git', 'node_modules', 'storage', 'bootstrap'];
        $base = base_path();
        $index = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base, \RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->isDir()) {
                continue;
            }

            $relative = ltrim(str_replace($base, '', $file->getPathname()), '/');

            if (in_array(explode('/', $relative)[0], $excluded, strict: true)) {
                continue;
            }

            $index[] = $relative;
        }

        return $this->fileIndex = $index;
    }

    private function workspaceRoot(): string
    {
        $wt = app(WorktreeManager::class);

        return $wt->active() ? $wt->path() : base_path();
    }

    private function expandAtMentions(string $task): string
    {
        $root = $this->workspaceRoot();

        return preg_replace_callback('#@([\w./_-]+)#', function ($matches) use ($root) {
            $path = $root.DIRECTORY_SEPARATOR.$matches[1];

            if (! file_exists($path) || is_dir($path)) {
                return $matches[0];
            }

            $content = @file_get_contents($path);

            if ($content === false) {
                return $matches[0];
            }

            return sprintf(
                "%s\n```\n// %s\n%s\n```",
                $matches[0],
                $matches[1],
                rtrim($content),
            );
        }, $task);
    }

    private function isTty(): bool
    {
        return function_exists('posix_isatty') && posix_isatty(STDIN);
    }
}
