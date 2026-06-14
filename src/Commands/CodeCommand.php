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
use Tackle\Support\BudgetTracker;

use function Laravel\Prompts\error as promptError;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\note;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\stream;
use Tackle\Prompts\TackleSuggestPrompt;
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
    private ?array  $fileIndex    = null;

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

        title('Tackle — Ready');
        intro("Laravel Tackle  ·  {$model}  ·  \${$budgetUsd} budget  ·  shell: {$shellMode}");

        while (true) {
            $task = (new TackleSuggestPrompt(
                label: 'What should I work on?',
                options: fn (string $value) => $this->completions($value),
                placeholder: 'Describe a task or type "exit" to quit. Use @ to reference files.',
                required: true,
                hint: count($this->history) > 0 ? 'Use ↑↓ for history · @ for files · Tab to complete' : '@ for files · Tab to complete',
                scroll: 10,
            ))->prompt();

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

            $this->showGitDiff();

            title('Tackle — Ready');
            $this->line('');
            $this->line('<fg=gray>─────────────────────────────────────────────────────────</>');
        }
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
            'ReadSentryIssue'    => '🪲 reading sentry',
            'ReadGitHubIssue'    => '🐙 reading github issue',
            'CreatePullRequest'  => '🚀 opening pull request',
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
        $base     = base_path();
        $excluded = ['vendor', '.git', 'node_modules', 'storage', 'bootstrap/cache'];
        $matches  = glob($base . '/' . $query . '*') ?: [];
        $results  = [];

        foreach ($matches as $match) {
            $relative = ltrim(str_replace($base, '', $match), '/');

            if (in_array(explode('/', $relative)[0], $excluded, strict: true)) {
                continue;
            }

            $results[] = $before . $relative . (is_dir($match) ? '/' : '');
        }

        return array_slice($results, 0, 20);
    }

    private function filenameCompletions(string $before, string $query): array
    {
        $index   = $this->fileIndex();
        $results = [];

        foreach ($index as $relative) {
            if (stripos(basename($relative), $query) !== false) {
                $results[] = $before . $relative;
            }
        }

        // Exact basename-prefix matches first, then contains matches.
        usort($results, function (string $a, string $b) use ($before, $query): int {
            $aStart = stripos(basename(substr($a, strlen($before))), $query) === 0;
            $bStart = stripos(basename(substr($b, strlen($before))), $query) === 0;

            return match (true) {
                $aStart && ! $bStart => -1,
                ! $aStart && $bStart => 1,
                default              => strcmp($a, $b),
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
        $base     = base_path();
        $index    = [];

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

    private function expandAtMentions(string $task): string
    {
        return preg_replace_callback('#@([\w./_-]+)#', function ($matches) {
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
