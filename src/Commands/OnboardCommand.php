<?php

namespace Tackle\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\App;
use Laravel\Ai\Streaming\Events\Error;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolCall;
use Laravel\Ai\Streaming\Events\ToolResult;
use Laravel\Prompts\Stream;
use Tackle\Agents\OnboardingAgent;
use Tackle\Commands\Concerns\ResolvesSessionOptions;
use Tackle\Contracts\CodingAgent;
use Tackle\Prompts\TackleSuggestPrompt;
use Tackle\Support\BudgetTracker;

use function Laravel\Prompts\error as promptError;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\note;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\stream;
use function Laravel\Prompts\title;
use function Laravel\Prompts\warning;

class OnboardCommand extends Command
{
    use ResolvesSessionOptions;

    public const DEFAULT_OUTPUT = 'docs/ONBOARDING.md';

    protected $signature = 'ai:onboard
        {--focus=    : Tour one area in depth — a directory, module, or domain (e.g. app/Billing or "checkout")}
        {--write=    : Save the tour as markdown — to docs/ONBOARDING.md, or to the path given. Works without a terminal, for CI and cron.}
        {--ask       : Skip the tour and go straight to questions about the codebase}
        {--model=    : Model to use for this session}
        {--provider= : Provider to use for this session}';

    protected $description = 'Onboard a new developer — a guided, read-only tour of this codebase (what it is, how it is put together, how to run it, where to be careful), then answer their questions.';

    private ?Stream $activeStream = null;

    private array $history = [];

    /**
     * Why the last turn ended early, if it did — a provider stream error or a
     * length/error finish reason. laravel/ai ends the turn normally after a
     * mid-stream provider error, so without tracking this a truncated tour
     * looks complete.
     */
    private ?string $interrupted = null;

    public function handle(): int
    {
        if (! App::runningInConsole()) {
            $this->error('ai:onboard must be run from the terminal.');

            return self::FAILURE;
        }

        $write = $this->resolveWritePath();
        $ask = (bool) $this->option('ask');
        $tty = $this->isTty();

        if ($write === false) {
            return self::FAILURE;
        }

        if ($ask && $write !== null) {
            $this->error('--ask skips the tour, so there is nothing to --write. Use one or the other.');

            return self::FAILURE;
        }

        if (! $tty && $write === null) {
            $this->error('ai:onboard requires an interactive TTY. Pass --write to generate '.self::DEFAULT_OUTPUT.' without one.');

            return self::FAILURE;
        }

        $this->applyModelOverride();

        $agent = app(OnboardingAgent::class);
        $budget = app(BudgetTracker::class);

        $model = config('tackle.model', 'claude-sonnet-4-6');
        $budgetUsd = config('tackle.budget_usd', 1.00);
        $focus = $this->focus();

        title('Tackle Onboard — Ready');
        intro("Laravel Tackle Onboard  ·  {$model}  ·  \${$budgetUsd} budget  ·  read-only");

        if ($focus !== null) {
            $this->line("<fg=cyan>  Focus: {$focus}</>");
            $this->line('');
        }

        if (! $ask) {
            title('Tackle Onboard — Exploring…');
            $this->line('');

            $tour = '';

            try {
                $tour = $this->runAgentTurn($agent, $budget, $this->tourPrompt($focus));
            } catch (\Throwable $e) {
                $this->closeStream();
                promptError('Agent error: '.$e->getMessage());

                if (! $tty) {
                    return self::FAILURE;
                }

                note('The session is still active — ask a question to continue.');
            }

            if ($this->interrupted !== null) {
                promptError("The tour was cut short — {$this->interrupted}.");

                if ($write !== null) {
                    $this->error('Nothing written: a partial tour would replace a complete one. Re-run ai:onboard --write.');

                    return self::FAILURE;
                }

                note('Ask it to continue from where it stopped, or re-run.');
            }

            if ($write !== null) {
                if (trim($tour) === '') {
                    $this->error('The agent produced no tour — nothing written.');

                    return self::FAILURE;
                }

                $this->writeTour($write, $tour, $focus);
            }

            if (! $tty) {
                outro($budget->summary());

                return self::SUCCESS;
            }
        }

        return $this->askLoop($agent, $budget);
    }

    private function askLoop(CodingAgent $agent, BudgetTracker $budget): int
    {
        while (true) {
            title('Tackle Onboard — Ready');
            $this->line('');
            $this->line('<fg=gray>─────────────────────────────────────────────────────────</>');

            $question = (new TackleSuggestPrompt(
                label: 'Ask about the codebase, or type "exit" to quit',
                options: fn (string $value) => array_reverse($this->history),
                placeholder: 'e.g. "where is the refund logic?", "how does API auth work?", or "exit"',
                required: true,
                hint: count($this->history) > 0 ? 'Use ↑↓ for history' : '',
                scroll: 10,
            ))->prompt();

            if (in_array(strtolower(trim($question)), ['exit', 'quit', 'q'], strict: true)) {
                title('');
                outro($budget->summary().' · Goodbye!');

                return self::SUCCESS;
            }

            $this->history[] = $question;

            if ($budget->overBudget()) {
                title('Tackle Onboard — Budget Exceeded');
                promptError(sprintf(
                    'Session aborted: estimated cost ($%.4f) exceeds the budget limit ($%.2f).',
                    $budget->estimatedCost(),
                    $budget->budgetUsd(),
                ));

                return self::FAILURE;
            }

            title('Tackle Onboard — Thinking…');
            $this->line('');

            try {
                $this->runAgentTurn($agent, $budget, $question);
            } catch (\Throwable $e) {
                $this->closeStream();
                promptError('Agent error: '.$e->getMessage());
                note('The session is still active — ask another question.');
            }
        }
    }

    private function tourPrompt(?string $focus): string
    {
        if ($focus !== null) {
            return "Give me the onboarding tour for ONE area of this application: {$focus}. "
                .'Locate it first (it may be a directory, a namespace, a domain concept, or a feature name), then work through the tour sections '
                .'as they apply to that area only — what it is for, how it is put together, its entrypoints, its data model, the conventions it follows, '
                .'where to be careful inside it, and good first tasks within it. Mention the rest of the application only where this area touches it. '
                .'Read the code before writing; cite file paths throughout.';
        }

        return 'Give me the full onboarding tour of this application, working through every section in order. '
            .'Survey the repository before writing — composer.json, README, routes, app/, config/, migrations, tests, .env.example, CI workflows — '
            .'and build each section from what you find. Cite file paths throughout.';
    }

    private function focus(): ?string
    {
        $focus = $this->option('focus');

        return is_string($focus) && trim($focus) !== '' ? trim($focus) : null;
    }

    /**
     * Resolve --write to an absolute path inside the workspace.
     * Returns null when --write was not passed, false on an invalid path.
     */
    private function resolveWritePath(): string|false|null
    {
        if (! $this->input->hasParameterOption('--write')) {
            return null;
        }

        $given = $this->option('write');
        $relative = is_string($given) && trim($given) !== '' ? trim($given) : self::DEFAULT_OUTPUT;

        $workspace = rtrim(config('tackle.workspace') ?? base_path(), DIRECTORY_SEPARATOR);
        $target = str_starts_with($relative, DIRECTORY_SEPARATOR) ? $relative : $workspace.DIRECTORY_SEPARATOR.$relative;

        // Normalise without requiring the file to exist: the directory must
        // resolve to somewhere inside the workspace.
        $directory = dirname($target);
        $existing = $directory;

        while (! is_dir($existing) && dirname($existing) !== $existing) {
            $existing = dirname($existing);
        }

        $resolvedRoot = realpath($workspace);
        $resolvedExisting = realpath($existing);

        if ($resolvedRoot === false || $resolvedExisting === false
            || ($resolvedExisting !== $resolvedRoot && ! str_starts_with($resolvedExisting, $resolvedRoot.DIRECTORY_SEPARATOR))) {
            $this->error("--write must point inside the project: {$relative}");

            return false;
        }

        if (! str_ends_with(strtolower($target), '.md')) {
            $this->error("--write expects a markdown file (.md): {$relative}");

            return false;
        }

        return $target;
    }

    private function writeTour(string $target, string $tour, ?string $focus): void
    {
        $directory = dirname($target);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $heading = $focus !== null ? "Onboarding — {$focus}" : 'Onboarding';
        $generated = 'Generated by `php artisan ai:onboard'.($focus !== null ? ' --focus='.escapeshellarg($focus) : '').' --write` — re-run it to refresh.';

        $content = "# {$heading}\n\n<!-- {$generated} -->\n\n".trim($tour)."\n";

        file_put_contents($target, $content);

        $workspace = rtrim(config('tackle.workspace') ?? base_path(), DIRECTORY_SEPARATOR);
        $shown = str_starts_with($target, $workspace.DIRECTORY_SEPARATOR)
            ? substr($target, strlen($workspace) + 1)
            : $target;

        $this->line('');
        $this->line("<fg=green>  ✓ Tour written to {$shown}</>");
    }

    /**
     * Stream one agent turn to the terminal and return the text it produced.
     */
    private function runAgentTurn(CodingAgent $agent, BudgetTracker $budget, string $prompt): string
    {
        $text = '';
        $this->interrupted = null;

        try {
            $response = $agent->stream($prompt);

            $response->each(function ($event) use ($budget, &$text) {
                if ($event instanceof TextDelta) {
                    $text .= $event->delta;

                    // Prompts' stream() renders through /dev/tty, which CI
                    // and cron do not have — plain output there.
                    if (! $this->isTty()) {
                        $this->output->write($event->delta);

                        return;
                    }

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

                if ($event instanceof Error) {
                    $this->closeStream();
                    $this->interrupted = "provider error ({$event->type}): {$event->message}";
                    warning("Provider error ({$event->type}): {$event->message}");

                    return;
                }

                if ($event instanceof StreamEnd) {
                    $this->closeStream();
                    $budget->record($event->usage->promptTokens, $event->usage->completionTokens, $event->usage->cacheReadInputTokens ?? 0, $event->usage->cacheWriteInputTokens ?? 0);

                    if ($this->interrupted === null && in_array($event->reason, ['length', 'error', 'content_filter'], strict: true)) {
                        $this->interrupted = "the response ended with finish reason '{$event->reason}'";
                    }

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

        $summary = match ($tool) {
            'ReadFile' => '📖 reading '.($args['path'] ?? '?'),
            'Glob' => '🔍 listing '.($args['pattern'] ?? '?'),
            'SearchCode' => '🔍 searching for '.($args['query'] ?? '?'),
            'ListRoutes' => '🗺️  listing routes',
            'Delegate' => '🧭 exploring with '.($args['agent'] ?? 'subagent').': '.$this->brief($args['task'] ?? ''),
            default => '→ '.$tool,
        };

        title('Tackle Onboard — '.strip_tags($summary));
        $this->line("<fg=cyan>  {$summary}</>");
    }

    private function renderToolResult(ToolResult $event): void
    {
        if ($event->toolResult->name !== 'Delegate') {
            return;
        }

        $result = (string) ($event->toolResult->result ?? '');

        if (str_starts_with($result, 'Report from subagent')) {
            $this->line('<fg=green>  ✓ Report received</>');
        } else {
            $this->line('<fg=yellow>  ⚠ '.$this->brief($result, 120).'</>');
        }
    }

    private function brief(string $text, int $max = 80): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');

        return mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1).'…' : $text;
    }

    private function isTty(): bool
    {
        return function_exists('posix_isatty') && posix_isatty(STDIN);
    }
}
