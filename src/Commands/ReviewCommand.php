<?php

namespace Tackle\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Laravel\Ai\Streaming\Events\TextDelta;
use RuntimeException;
use Tackle\Agents\ReviewAgent;
use Tackle\Review\DiffLineIndex;
use Tackle\Review\FindingsParser;
use Tackle\Review\ParsedReview;
use Tackle\Review\PullRequest;
use Tackle\Review\PullRequestFetcher;
use Tackle\Review\ReviewPublisher;
use Tackle\Support\Utf8;

class ReviewCommand extends Command
{
    protected $signature = 'ai:review
        {--staged        : Review only staged changes (git diff --staged)}
        {--commit=       : Review a specific commit\'s changes}
        {--against=      : Review everything not yet in this branch, e.g. --against=main}
        {--pr=           : Review a GitHub pull request by number (diff fetched via the GitHub API)}
        {--comment       : Post the findings to the pull request as inline review comments (requires --pr)}
        {--fail-on=      : Exit non-zero when findings at or above this severity exist: critical|warning|suggestion}
        {--focus=        : Comma-separated focus areas: bugs,security,performance,tests}';

    protected $description = 'Review code changes with AI — reads the git diff and highlights real issues.';

    public function handle(ReviewAgent $agent, PullRequestFetcher $fetcher, FindingsParser $parser, ReviewPublisher $publisher): int
    {
        if (($error = $this->validateOptions()) !== null) {
            $this->error($error);

            return self::FAILURE;
        }

        $pr = null;

        if ($this->option('pr')) {
            try {
                $pr = $fetcher->fetch((int) $this->option('pr'));
            } catch (RuntimeException $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }

            $diff = $pr->diff;
        } else {
            if (! is_dir(base_path('.git'))) {
                $this->error('ai:review requires a git repository.');

                return self::FAILURE;
            }

            $diff = $this->getDiff();

            if ($diff === null) {
                $this->error('Could not read git diff. Check that git is installed and this is a repository.');

                return self::FAILURE;
            }
        }

        if ($diff === '') {
            $this->info('Nothing to review — no changes detected for the selected scope.');

            return self::SUCCESS;
        }

        $this->renderBanner($pr);

        $prompt = $this->buildPrompt($diff, $pr);

        $structured = $this->needsStructuredFindings();
        $text = '';

        $response = $agent->stream($prompt);
        $response->each(function ($event) use (&$text, $structured) {
            if ($event instanceof TextDelta) {
                $text .= $event->delta;

                // In structured mode the response ends with a machine-readable
                // block, so buffer and print a cleaned version at the end.
                if (! $structured) {
                    $this->output->write($event->delta);
                }
            }
        });

        if (! $structured) {
            $this->newLine(2);

            return self::SUCCESS;
        }

        $this->line($parser->strip($text));
        $this->newLine();

        $review = $parser->parse($text);

        if ($review === null) {
            $this->error('The agent did not return a parseable findings block, so the review cannot be posted or gated. Re-run to retry.');

            return self::FAILURE;
        }

        if ($this->option('comment')) {
            try {
                $url = $publisher->publish($pr, $review, new DiffLineIndex($pr->diff));
                $this->info("Review posted: {$url}");
            } catch (RuntimeException $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }
        }

        return $this->applyFailOn($review);
    }

    private function validateOptions(): ?string
    {
        if ($this->option('pr') && ($this->option('staged') || $this->option('commit') || $this->option('against'))) {
            return 'The --pr option cannot be combined with --staged, --commit, or --against.';
        }

        if ($this->option('comment') && ! $this->option('pr')) {
            return 'The --comment option requires --pr, e.g. ai:review --pr=42 --comment.';
        }

        if ($this->option('pr') && ! ctype_digit((string) $this->option('pr'))) {
            return 'The --pr option must be a pull request number, e.g. --pr=42.';
        }

        $failOn = $this->option('fail-on');

        if ($failOn && ! in_array($failOn, ['critical', 'warning', 'suggestion'], true)) {
            return 'The --fail-on option must be one of: critical, warning, suggestion.';
        }

        return null;
    }

    private function needsStructuredFindings(): bool
    {
        return (bool) ($this->option('comment') || $this->option('fail-on'));
    }

    private function applyFailOn(ParsedReview $review): int
    {
        $failOn = $this->option('fail-on');

        if (! $failOn) {
            return self::SUCCESS;
        }

        $gated = match ($failOn) {
            'critical' => ['critical'],
            'warning' => ['critical', 'warning'],
            default => ['critical', 'warning', 'suggestion'],
        };

        foreach ($gated as $severity) {
            if ($review->has($severity)) {
                $this->error("Failing: the review contains {$severity}-level findings (--fail-on={$failOn}).");

                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }

    private function getDiff(): ?string
    {
        $cmd = $this->diffCommand();

        $result = Process::path(base_path())->timeout(30)->run($cmd);

        if (! $result->successful() && $result->exitCode() !== 1) {
            return null;
        }

        return Utf8::clean(trim($result->output()));
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

    private function buildPrompt(string $diff, ?PullRequest $pr): string
    {
        $scope = $this->scopeDescription($pr);
        $focus = $this->focusInstruction();
        $stat = $pr === null ? $this->diffStat() : '';
        $context = $pr === null ? '' : $this->prContext($pr);
        $structured = $this->needsStructuredFindings() ? $this->structuredInstruction() : '';

        return <<<PROMPT
        Please review the following git diff.

        **Scope:** {$scope}
        {$stat}{$context}{$focus}

        Before commenting on any changed function or class, read the full file for context.

        <diff>
        {$diff}
        </diff>{$structured}
        PROMPT;
    }

    private function prContext(PullRequest $pr): string
    {
        $context = "\n**Branch:** {$pr->headRef} → {$pr->baseRef}";

        if ($pr->body !== '') {
            $context .= "\n\n**PR description:**\n{$pr->body}\n";
        }

        return $context;
    }

    private function structuredInstruction(): string
    {
        return <<<'INSTRUCTION'


        ---

        After the review, append your findings as a fenced code block with the exact
        language tag `tackle-findings`, containing a single JSON object:

        ```tackle-findings
        {"verdict": "lgtm|lgtm_with_notes|needs_changes", "findings": [{"path": "app/Example.php", "line": 42, "severity": "critical|warning|suggestion", "message": "One clear sentence."}]}
        ```

        Rules for the block:
        - `path` is the file path relative to the repository root, exactly as it appears in the diff.
        - `line` is the line number in the NEW version of the file, and must be a line visible in the diff.
        - Every finding from your review must appear in the block, and vice versa.
        - If there are no findings, use an empty `findings` array.
        - Output the block exactly once, at the very end of your response.
        INSTRUCTION;
    }

    private function scopeDescription(?PullRequest $pr = null): string
    {
        if ($pr !== null) {
            return "pull request #{$pr->number} — {$pr->title}";
        }

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
        $statCmd = $this->diffCommand();
        $statCmd[] = '--stat';

        $result = Process::path(base_path())->timeout(15)->run($statCmd);

        if (! $result->successful()) {
            return '';
        }

        $stat = Utf8::clean(trim($result->output()));

        return $stat !== '' ? "\n**Stat:**\n```\n{$stat}\n```\n" : '';
    }

    private function renderBanner(?PullRequest $pr): void
    {
        $scope = $this->scopeDescription($pr);
        $model = config('tackle.model', 'claude-sonnet-4-6');

        $this->line('');
        $this->line('<fg=green;options=bold>Laravel Tackle — AI Code Review</>');
        $this->line("<fg=gray>Scope: {$scope} | Model: {$model}</>");
        $this->line('');
    }
}
