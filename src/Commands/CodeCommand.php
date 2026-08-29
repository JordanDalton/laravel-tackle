<?php

namespace Tackle\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Process;
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
use Tackle\Support\ModelCatalog;
use Tackle\Support\SessionStore;
use Tackle\Support\StreamRenderer;
use Tackle\Support\ToolSummary;
use Tackle\Support\WorktreeManager;
use Throwable;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error as promptError;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\note;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\select;
use function Laravel\Prompts\stream;
use function Laravel\Prompts\table;
use function Laravel\Prompts\text;
use function Laravel\Prompts\title;
use function Laravel\Prompts\warning;

class CodeCommand extends Command
{
    use ResolvesSessionOptions;

    protected $signature = 'ai:code
        {--session= : Resume a named session}
        {--model= : Override the model for this session (also switchable mid-session with /model)}
        {--provider= : Override the laravel/ai provider for this session}
        {--plan : Plan first — every task produces a read-only plan you approve before edits happen}
        {--shell= : Override the shell mode for this session (off|allowlist|approve|yolo)}
        {--off : Shorthand for --shell=off}
        {--allowlist : Shorthand for --shell=allowlist}
        {--approve : Shorthand for --shell=approve}
        {--yolo : Shorthand for --shell=yolo}
        {--worktree : Force worktree isolation for this session}
        {--no-worktree : Disable worktree isolation for this session}';

    protected $description = 'Start an interactive AI coding session powered by Laravel Tackle.';

    /**
     * Built-in slash commands: name => its line in /help.
     *
     * One source for both the help listing and tab completion. They were two
     * hand-maintained lists and they drifted the first time a command was
     * added — /raw shipped completable only from memory.
     */
    private const BUILT_INS = [
        'plan' => '/plan <task> — plan first, edit after your approval',
        'model' => '/model [name] — switch the model (no name lists models with rates)',
        'compact' => '/compact — summarize older session history now',
        'clear' => '/clear — forget the session history',
        'sessions' => '/sessions — list saved sessions',
        'save' => '/save <name> — save your last prompt as a project command',
        'edit' => '/edit [name] — open a project command in $EDITOR',
        'forget' => '/forget [name] — delete a project command',
        'raw' => '/raw — toggle markdown tables between drawn and literal',
        'help' => '/help — this list',
    ];

    private ?Stream $activeStream = null;

    /**
     * Whether a markdown table in the response is drawn as a table. Toggled
     * for the session with /raw, for when you want the markdown itself.
     */
    private bool $renderTables = true;

    private array $history = [];

    private ?array $fileIndex = null;

    private CustomCommands $customCommands;

    private PlanningAgent $planner;

    private ConversationCompactor $compactor;

    private SessionStore $sessions;

    private BudgetTracker $budget;

    private string $sessionName = 'default';

    public function handle(WorktreeManager $worktrees, CustomCommands $commands, ConversationCompactor $compactor, SessionStore $sessions): int
    {
        $this->customCommands = $commands;
        $this->compactor = $compactor;
        $this->sessions = $sessions;
        $this->sessionName = (string) ($this->option('session') ?: 'default');
        $this->renderTables = (bool) config('tackle.render_tables', true);

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

        $this->applyModelOverride();

        // Resolved after the overrides above — the agent, planner, and
        // budget tracker all read tackle.model / tackle.pricing at
        // construction time.
        $agent = $this->laravel->make(CodingAgent::class);
        $budget = $this->laravel->make(BudgetTracker::class);
        $this->planner = $this->laravel->make(PlanningAgent::class);
        $this->budget = $budget;

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
        $sessionLabel = $this->sessions->enabled() ? "  ·  session: {$this->sessionName}" : '';

        title('Tackle — Ready');
        intro("Laravel Tackle  ·  {$model}  ·  \${$budgetUsd} budget  ·  shell: {$shellMode}{$wtLabel}{$sessionLabel}");

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

                if ($this->sessions->enabled() && method_exists($agent, 'conversationSize') && $agent->conversationSize() > 0) {
                    $resume = $this->sessionName === 'default'
                        ? 'php artisan ai:code'
                        : "php artisan ai:code --session={$this->sessionName}";
                    note("Session '{$this->sessionName}' saved — {$resume} picks up where you left off.");
                }

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
            } catch (Throwable $e) {
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
            case 'save':
                $this->saveCommand($args);

                return null;

            case 'edit':
                $this->editCommand($args);

                return null;

            case 'forget':
                $this->forgetCommand($args);

                return null;

            case 'raw':
                $this->renderTables = ! $this->renderTables;

                note($this->renderTables
                    ? 'Markdown tables will be drawn as tables.'
                    : 'Markdown tables will be printed as written.');

                return null;

            case 'help':
                $builtins = implode("\n", self::BUILT_INS);
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

            case 'model':
                $this->handleModelCommand(trim($args), $agent);

                return null;

            case 'sessions':
                $saved = $this->sessions->all();

                if ($saved === []) {
                    note('No saved sessions yet — history is written after your first task.');

                    return null;
                }

                $lines = collect($saved)
                    ->map(fn (int $count, string $name) => ($name === $this->sessionName ? "{$name} (current)" : $name)." — {$count} messages")
                    ->implode("\n");

                note("Saved sessions:\n{$lines}\n\nResume one with: php artisan ai:code --session=<name>");

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
     * /model — switch the session's model. With no argument, shows a picker
     * listing known models with their per-MTok rates. With one argument,
     * switches to that model on the current provider; with two ("provider
     * model"), switches both.
     */
    private function handleModelCommand(string $args, CodingAgent $agent): void
    {
        if (! method_exists($agent, 'useModel')) {
            warning('The bound agent does not support /model — restart with --model or AI_CODE_MODEL set instead.');

            return;
        }

        $provider = null;
        $model = $args;

        if (str_contains($model, ' ')) {
            [$provider, $model] = preg_split('/\s+/', $model, 2);
        }

        if ($model === '') {
            $current = (string) config('tackle.model');
            $options = [];

            foreach (ModelCatalog::all() as $id => $rates) {
                $options[$id] = sprintf(
                    '%s — $%s in / $%s out per MTok%s',
                    $id,
                    $this->formatRate($rates['input']),
                    $this->formatRate($rates['output']),
                    $id === $current ? '  (current)' : '',
                );
            }

            $options['__custom'] = 'Other — type a model id';

            $model = select(
                label: 'Switch to which model?',
                options: $options,
                default: array_key_exists($current, $options) ? $current : '__custom',
                scroll: 12,
            );

            if ($model === '__custom') {
                $model = trim(text(label: 'Model id', required: true));
                $providerInput = trim(text(
                    label: 'Provider',
                    default: (string) config('tackle.provider', 'anthropic'),
                    hint: 'Must match a key in config/ai.php',
                ));
                $provider = $providerInput !== '' ? $providerInput : null;
            }
        }

        if ($model === (string) config('tackle.model') && ($provider === null || $provider === (string) config('tackle.provider'))) {
            note("Already using {$model}.");

            return;
        }

        $provider ??= (string) config('tackle.provider', 'anthropic');
        config(['tackle.provider' => $provider, 'tackle.model' => $model]);

        // The session agent and planner were built before the switch; freshly
        // resolved agents (subagents, compaction) read the new config.
        $agent->useModel($provider, $model);

        if (method_exists($this->planner, 'useModel')) {
            $this->planner->useModel($provider, $model);
        }

        $repriced = $this->budget->repriceFor($model);
        $rates = $this->budget->rates();
        $label = sprintf('$%s in / $%s out per MTok', $this->formatRate($rates['input']), $this->formatRate($rates['output']));

        if ($repriced) {
            note("Switched to {$model} ({$provider}) — {$label}. Spend so far is kept; future usage bills at the new rates.");
        } else {
            warning("Switched to {$model} ({$provider}) — no known rates for this model, so budget tracking continues at {$label}. Add it to tackle.pricing.models for accurate enforcement.");
        }
    }

    private function formatRate(float $rate): string
    {
        return rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.');
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
        $renderer = new StreamRenderer($this->renderTables);

        try {
            $response = $agent->stream($task, $attachments);

            $response->each(function ($event) use ($budget, $renderer, &$text) {
                if ($event instanceof TextDelta) {
                    // The transcript keeps the markdown; only the terminal
                    // sees the rendering.
                    $text .= $event->delta;
                    $this->render($renderer->push($event->delta));

                    return;
                }

                if ($event instanceof ToolCall) {
                    $this->render($renderer->flush());
                    $this->closeStream();
                    $this->renderToolCall($event);

                    return;
                }

                if ($event instanceof ToolResult) {
                    $this->renderToolResult($event);

                    return;
                }

                if ($event instanceof StreamEnd) {
                    $this->render($renderer->flush());
                    $this->closeStream();
                    $budget->record($event->usage->promptTokens, $event->usage->completionTokens, $event->usage->cacheReadInputTokens ?? 0, $event->usage->cacheWriteInputTokens ?? 0);

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
            $this->render($renderer->flush());
            $this->closeStream();
        }

        return $text;
    }

    /**
     * Save the prompt you just typed as a project command.
     *
     * Verbatim, and without the model: asking the agent to "save that as a
     * command" makes it reconstruct your words, which costs tokens for a copy
     * and paraphrases the thing you liked enough to keep.
     */
    private function saveCommand(string $args): void
    {
        $name = trim(preg_split('/\s+/', trim($args))[0] ?? '');

        if ($name === '') {
            promptError('Usage: /save <name> — saves your last prompt as .tackle/commands/<name>.md');

            return;
        }

        if (! CustomCommands::validName($name)) {
            promptError("'{$name}' cannot be a command name — use letters, numbers, hyphens, and underscores.");

            return;
        }

        // A project command named after a built-in would never run: the switch
        // above catches the name first. Better to refuse than to write a file
        // that silently does nothing.
        if (array_key_exists($name, self::BUILT_INS)) {
            promptError("/{$name} is a built-in command — pick another name.");

            return;
        }

        $prompt = CustomCommands::lastPrompt($this->history);

        if ($prompt === null) {
            promptError('Nothing to save yet — /save keeps the last prompt you sent.');

            return;
        }

        if ($this->customCommands->has($name)
            && ! confirm("/{$name} already exists. Overwrite it?", default: false)) {
            return;
        }

        note("Saving as /{$name}:\n\n".$this->preview($prompt));

        if (! confirm("Save to .tackle/commands/{$name}.md?")) {
            return;
        }

        $path = $this->customCommands->save($name, $prompt);

        note("Saved {$this->relative($path)} — /{$name} works right now, no restart.\n"
            ."It is uncommitted: your team gets it when you commit it.\n"
            .'Put $ARGUMENTS in the file where you want the rest of the line substituted.');
    }

    /**
     * Open a project command in $EDITOR. It is your prose in a markdown file —
     * the editor you already have beats anything reimplemented at a prompt,
     * and the change is live the moment you save.
     */
    private function editCommand(string $args): void
    {
        $name = $this->pickCommand($args, 'Edit which command?');

        if ($name === null) {
            return;
        }

        $path = $this->customCommands->path($name);
        $editor = getenv('VISUAL') ?: getenv('EDITOR');

        // No guessing. Dropping someone into an editor they did not choose and
        // cannot exit is a worse outcome than telling them where the file is.
        if (! is_string($editor) || trim($editor) === '') {
            note("No \$EDITOR set. The file is at:\n{$path}");

            return;
        }

        $this->closeStream();

        try {
            Process::forever()->tty()->run($editor.' '.escapeshellarg($path));
        } catch (Throwable $e) {
            // No TTY to hand over (Windows, an odd terminal) — say where the
            // file is rather than leaving the user with an exception.
            note("Could not open \$EDITOR ({$e->getMessage()}). The file is at:\n{$path}");

            return;
        }

        note("/{$name} is live — the next call uses what you just saved.");
    }

    /**
     * Delete a project command, after saying what will be lost and whether git
     * still has a copy.
     */
    private function forgetCommand(string $args): void
    {
        $name = $this->pickCommand($args, 'Delete which command?');

        if ($name === null) {
            return;
        }

        $path = $this->customCommands->path($name);
        $body = (string) @file_get_contents($path);

        note("/{$name} — ".$this->relative($path)."\n\n".$this->preview($body)."\n\n"
            .($this->tracked($path)
                ? 'Committed to git, so `git checkout` brings it back.'
                : 'Not committed to git — deleting it loses it.'));

        if (! confirm("Delete /{$name}?", default: false)) {
            return;
        }

        $this->customCommands->delete($name)
            ? note("Deleted /{$name}.")
            : promptError("Could not delete /{$name}.");
    }

    /**
     * Resolve a command name from the argument, or offer a picker. Returns
     * null when there is nothing to choose or the name is unknown.
     */
    private function pickCommand(string $args, string $question): ?string
    {
        $available = array_keys($this->customCommands->all());

        if ($available === []) {
            note('No project commands yet. /save <name> keeps your last prompt as one.');

            return null;
        }

        $name = trim(preg_split('/\s+/', trim($args))[0] ?? '');

        if ($name === '') {
            return (string) select($question, $available);
        }

        if (! in_array($name, $available, true)) {
            promptError("/{$name} is not a project command. Available: /".implode('  /', $available));

            return null;
        }

        return $name;
    }

    private function preview(string $text, int $lines = 6): string
    {
        $split = explode("\n", trim($text));
        $shown = implode("\n", array_slice($split, 0, $lines));

        return count($split) > $lines
            ? $shown."\n… (".(count($split) - $lines).' more lines)'
            : $shown;
    }

    private function tracked(string $path): bool
    {
        $root = $this->workspaceRoot();

        exec(
            'git -C '.escapeshellarg($root).' ls-files --error-unmatch '.escapeshellarg($path).' 2>/dev/null',
            $out,
            $status,
        );

        return $status === 0;
    }

    private function relative(string $path): string
    {
        $root = rtrim($this->workspaceRoot(), '/').'/';

        return str_starts_with($path, $root) ? substr($path, strlen($root)) : $path;
    }

    /**
     * Draw what the renderer asked for: text into the live stream, and a
     * table as a table — which means closing the stream first, since the two
     * cannot share the cursor.
     *
     * @param  list<array<string, mixed>>  $ops
     */
    private function render(array $ops): void
    {
        foreach ($ops as $op) {
            if ($op['type'] === 'table') {
                $this->closeStream();
                $this->line('');
                table($op['headers'], $op['rows']);

                continue;
            }

            if ($op['text'] === '') {
                continue;
            }

            if ($this->activeStream === null) {
                $this->line('');
                $this->activeStream = stream();
            }

            $this->activeStream->append($op['text']);
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
            // Read live, so a command written during this session — by you in
            // another window, or by the agent itself — completes immediately.
            $names = [...array_keys(self::BUILT_INS), ...array_keys($this->customCommands->all())];

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
