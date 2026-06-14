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
use Tackle\Exceptions\AgentInterruptedException;
use Tackle\Support\BudgetTracker;

use function Laravel\Prompts\error as promptError;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\note;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\stream;
use function Laravel\Prompts\suggest;
use function Laravel\Prompts\title;
use function Laravel\Prompts\warning;

class CodeCommand extends Command
{
    protected $signature = 'ai:code
        {--session= : Resume a named session}
        {--shell= : Override the shell mode for this session (off|allowlist|approve|yolo)}
        {--off : Shorthand for --shell=off}
        {--allowlist : Shorthand for --shell=allowlist}
        {--approve : Shorthand for --shell=approve}
        {--yolo : Shorthand for --shell=yolo}';

    protected $description = 'Start an interactive AI coding session powered by Laravel Tackle.';

    private ?Stream $activeStream = null;
    private array   $history      = [];
    private bool    $interrupted  = false;
    private bool    $agentRunning = false;

    public function handle(CodingAgent $agent, BudgetTracker $budget): int
    {
        if (! App::runningInConsole()) {
            $this->error('ai:code must be run from the terminal.');
            return self::FAILURE;
        }

        if (! $this->isTty()) {
            $this->error('ai:code requires an interactive TTY — cannot run in a non-interactive pipe.');
            return self::FAILURE;
        }

        $shell = match (true) {
            (bool) $this->option('off')       => 'off',
            (bool) $this->option('allowlist') => 'allowlist',
            (bool) $this->option('approve')   => 'approve',
            (bool) $this->option('yolo')      => 'yolo',
            default                           => $this->option('shell'),
        };

        if ($shell !== null) {
            if (! in_array($shell, ['off', 'allowlist', 'approve', 'yolo'], strict: true)) {
                $this->error("Invalid --shell value '{$shell}'. Must be one of: off, allowlist, approve, yolo.");
                return self::FAILURE;
            }
            config(['ai-code.shell' => $shell]);
        }

        $model     = config('ai-code.model', 'claude-sonnet-4-6');
        $budgetUsd = config('ai-code.budget_usd', 1.00);
        $shellMode = config('ai-code.shell', 'approve');

        $this->registerInterruptHandler();

        title('Tackle — Ready');
        intro("Laravel Tackle  ·  {$model}  ·  \${$budgetUsd} budget  ·  shell: {$shellMode}");

        while (true) {
            $task = suggest(
                label: 'What should I work on?',
                options: fn (string $value) => $this->completions($value),
                placeholder: 'Describe a task or type "exit" to quit. Use @ to reference files.',
                required: true,
                hint: count($this->history) > 0 ? 'Use ↑↓ for history · @ to reference a file · Ctrl+C interrupts a run' : '@ to reference a file · Ctrl+C interrupts a run',
                scroll: 10,
            );

            if (in_array(strtolower(trim($task)), ['exit', 'quit', 'q'], strict: true)) {
                title('');
                outro($budget->summary() . ' · Goodbye!');
                return self::SUCCESS;
            }

            $this->history[] = $task;

            if ($budget->overBudget()) {
                title('Tackle — Budget Exceeded');
                promptError(sprintf(
                    'Session aborted: estimated cost ($%.4f) exceeds the budget limit ($%.2f).',
                    $budget->estimatedCost(),
                    $budget->budgetUsd(),
                ));
                return self::FAILURE;
            }

            title('Tackle — Thinking…');
            $this->line('');

            try {
                $this->runAgentTurn($agent, $budget, $this->expandAtMentions($task));
            } catch (\Throwable $e) {
                $this->closeStream();
                promptError('Agent error: ' . $e->getMessage());
                note('The session is still active — continue with a new task.');
            }

            if ($this->interrupted) {
                $this->interrupted = false;
                $this->line('');
                warning('Interrupted — the agent has stopped. Your session is still active.');
                title('Tackle — Ready');
                $this->line('');
                $this->line('<fg=gray>─────────────────────────────────────────────────────────</>');
                continue;
            }

            $this->showGitDiff();

            title('Tackle — Ready');
            $this->line('');
            $this->line('<fg=gray>─────────────────────────────────────────────────────────</>');
        }
    }

    private function registerInterruptHandler(): void
    {
        if (! function_exists('pcntl_async_signals') || ! function_exists('pcntl_signal')) {
            return;
        }

        pcntl_async_signals(true);

        pcntl_signal(SIGINT, function () {
            if ($this->agentRunning) {
                // Mid-run: interrupt the turn, keep the session alive.
                $this->interrupted = true;
            } else {
                // At the idle prompt: exit cleanly.
                title('');
                $this->line('');
                outro('Goodbye!');
                exit(0);
            }
        });
    }

    private function runAgentTurn(CodingAgent $agent, BudgetTracker $budget, string $task): void
    {
        $this->agentRunning = true;
        $this->interrupted  = false;

        try {
            $response = $agent->stream($task);

            $response->each(function ($event) use ($budget) {
                if ($this->interrupted) {
                    $this->closeStream();
                    throw new AgentInterruptedException();
                }

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
        } catch (AgentInterruptedException) {
            // Swallow — the main loop checks $this->interrupted and shows the message.
        } finally {
            $this->agentRunning = false;
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

        // These tools render their own interactive UI — suppress the label.
        if (in_array($tool, ['AskUser', 'ConfirmAction'], strict: true)) {
            return;
        }

        $summary = match ($tool) {
            'ReadFile'           => '📖 reading ' . ($args['path'] ?? '?'),
            'Glob'               => '🔍 listing ' . ($args['pattern'] ?? '?'),
            'SearchCode'         => '🔍 searching for ' . ($args['query'] ?? '?'),
            'EditFile'           => '✏️  editing ' . ($args['path'] ?? '?'),
            'WriteFile'          => '📝 creating ' . ($args['path'] ?? '?'),
            'RunArtisan'         => '⚡ artisan ' . ($args['command'] ?? '?'),
            'RunTests'           => '🧪 running tests' . (! empty($args['filter']) ? ' (filter: ' . $args['filter'] . ')' : ''),
            'RunPint'            => '✨ formatting with pint',
            'RunShell'           => '💻 shell: ' . ($args['command'] ?? '?'),
            'QueryDatabase'      => '🗄️  querying database',
            'ReadLog'            => '📋 reading log' . (! empty($args['filter']) ? ' (filter: ' . $args['filter'] . ')' : ''),
            'GitDiff'            => '🔀 git diff' . (! empty($args['path']) ? ' ' . $args['path'] : ''),
            'ListRoutes'         => '🗺️  listing routes',
            'ReadTelescopeEntry' => '🔭 reading telescope',
            default              => '→ ' . $tool,
        };

        title('Tackle — ' . strip_tags($summary));
        $this->line("<fg=cyan>  {$summary}</>");
    }

    private function renderToolResult(ToolResult $event): void
    {
        $tool   = $event->toolResult->name;
        $result = (string) ($event->toolResult->result ?? '');

        if (in_array($tool, ['RunTests', 'RunArtisan', 'RunShell'], strict: true)) {
            if (str_contains($result, 'FAILED') || str_contains($result, 'Error')) {
                $this->line('<fg=red>  ✗ Command reported failures — agent will handle them.</>');
            } else {
                $this->line('<fg=green>  ✓ Done</>');
            }
        }

        if ($tool === 'EditFile' || $tool === 'WriteFile') {
            $this->line('<fg=green>  ✓ File saved</>');
        }
    }

    private function showGitDiff(): void
    {
        if (! is_dir(base_path('.git'))) {
            return;
        }

        $output = shell_exec('git diff --stat 2>/dev/null');

        if ($output && trim($output) !== '') {
            $this->line('');
            note(trim($output));
        }
    }

    private function completions(string $input): array
    {
        $atPos = strrpos($input, '@');

        // No @ in input — return session history.
        if ($atPos === false) {
            return array_reverse($this->history);
        }

        $afterAt = substr($input, $atPos + 1);

        // Space after the @-mention means it's already complete — fall back to history.
        if (str_contains($afterAt, ' ')) {
            return array_reverse($this->history);
        }

        $before  = substr($input, 0, $atPos + 1); // everything up to and including @
        $base    = base_path();
        $pattern = $base . '/' . $afterAt . '*';
        $matches = glob($pattern) ?: [];

        $excluded = ['vendor', '.git', 'node_modules', 'storage', 'bootstrap/cache'];

        $results = [];
        foreach ($matches as $match) {
            $relative = ltrim(str_replace($base, '', $match), '/');
            $topLevel = explode('/', $relative)[0];

            if (in_array($topLevel, $excluded, strict: true)) {
                continue;
            }

            // Append / for directories so the user can keep drilling down.
            $results[] = $before . $relative . (is_dir($match) ? '/' : '');
        }

        return array_slice($results, 0, 20);
    }

    private function expandAtMentions(string $task): string
    {
        return preg_replace_callback('/@([\w./\-]+)/', function ($matches) {
            $path = base_path($matches[1]);

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
