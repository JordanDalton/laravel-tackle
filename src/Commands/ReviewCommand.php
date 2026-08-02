<?php

namespace Tackle\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Laravel\Ai\Streaming\Events\TextDelta;
use Tackle\Agents\ReviewAgent;

class ReviewCommand extends Command
{
    protected $signature = 'ai:review
        {--staged        : Review only staged changes (git diff --staged)}
        {--commit=       : Review a specific commit\'s changes}
        {--against=      : Review everything not yet in this branch, e.g. --against=main}
        {--focus=        : Comma-separated focus areas: bugs,security,performance,tests}';

    protected $description = 'Review code changes with AI — reads the git diff and highlights real issues.';

    public function handle(ReviewAgent $agent): int
    {
        if (! is_dir(base_path('.git'))) {
            $this->error('ai:review requires a git repository.');

            return self::FAILURE;
        }

        $diff = $this->getDiff();

        if ($diff === null) {
            $this->error('Could not read git diff. Check that git is installed and this is a repository.');

            return self::FAILURE;
        }

        if ($diff === '') {
            $this->info('Nothing to review — no changes detected for the selected scope.');

            return self::SUCCESS;
        }

        $this->renderBanner();

        $prompt = $this->buildPrompt($diff);

        $response = $agent->stream($prompt);
        $response->each(function ($event) {
            if ($event instanceof TextDelta) {
                $this->output->write($event->delta);
            }
        });

        $this->newLine(2);

        return self::SUCCESS;
    }

    private function getDiff(): ?string
    {
        $cmd = $this->diffCommand();

        $result = Process::path(base_path())->timeout(30)->run($cmd);

        if (! $result->successful() && $result->exitCode() !== 1) {
            return null;
        }

        return trim($result->output());
    }

    private function diffCommand(): array
    {
        if ($this->option('staged')) {
            return ['git', 'diff', '--staged'];
        }

        if ($commit = $this->option('commit')) {
            return ['git', 'diff', "{$commit}^", $commit];
        }

        if ($against = $this->option('against')) {
            return ['git', 'diff', "{$against}...HEAD"];
        }

        return ['git', 'diff', 'HEAD'];
    }

    private function buildPrompt(string $diff): string
    {
        $scope = $this->scopeDescription();
        $focus = $this->focusInstruction();
        $stat = $this->diffStat();

        return <<<PROMPT
        Please review the following git diff.

        **Scope:** {$scope}
        {$stat}{$focus}

        Before commenting on any changed function or class, read the full file for context.

        <diff>
        {$diff}
        </diff>
        PROMPT;
    }

    private function scopeDescription(): string
    {
        if ($this->option('staged')) {
            return 'staged changes only';
        }

        if ($commit = $this->option('commit')) {
            return "commit {$commit}";
        }

        if ($against = $this->option('against')) {
            return "all changes on this branch not yet in {$against}";
        }

        return 'all changes since the last commit (staged + unstaged)';
    }

    private function focusInstruction(): string
    {
        $focus = $this->option('focus');

        if (! $focus) {
            return '';
        }

        $areas = implode(', ', array_map('trim', explode(',', $focus)));

        return "\n**Focus especially on:** {$areas}";
    }

    private function diffStat(): string
    {
        $cmd = array_merge(
            array_slice($this->diffCommand(), 0, -0),
            ['--stat']
        );

        // Replace the base diff command args with --stat variant
        $statCmd = $this->diffCommand();
        $statCmd[] = '--stat';

        $result = Process::path(base_path())->timeout(15)->run($statCmd);

        if (! $result->successful()) {
            return '';
        }

        $stat = trim($result->output());

        return $stat !== '' ? "\n**Stat:**\n```\n{$stat}\n```\n" : '';
    }

    private function renderBanner(): void
    {
        $scope = $this->scopeDescription();
        $model = config('tackle.model', 'claude-sonnet-4-6');

        $this->line('');
        $this->line('<fg=green;options=bold>Laravel Tackle — AI Code Review</>');
        $this->line("<fg=gray>Scope: {$scope} | Model: {$model}</>");
        $this->line('');
    }
}
