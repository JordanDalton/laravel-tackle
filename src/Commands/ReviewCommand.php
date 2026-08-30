<?php

namespace Tackle\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\TextDelta;
use RuntimeException;
use Tackle\Agents\ReviewAgent;
use Tackle\Commands\Concerns\EmitsJsonDocument;
use Tackle\Review\DiffLineIndex;
use Tackle\Review\Finding;
use Tackle\Review\FindingsParser;
use Tackle\Review\ParsedReview;
use Tackle\Review\PullRequest;
use Tackle\Review\PullRequestFetcher;
use Tackle\Review\ReviewHistory;
use Tackle\Review\ReviewPublisher;
use Tackle\Support\BudgetTracker;
use Tackle\Support\ProviderCredentials;
use Tackle\Support\Utf8;
use Throwable;

class ReviewCommand extends Command
{
    use EmitsJsonDocument;

    protected $signature = 'ai:review
        {--output=text   : Result format (text|json)}
        {--staged        : Review only staged changes (git diff --staged)}
        {--commit=       : Review a specific commit\'s changes}
        {--against=      : Review everything not yet in this branch, e.g. --against=main}
        {--pr=           : Review a GitHub pull request by number (diff fetched via the GitHub API)}
        {--full          : Review the whole PR even when Tackle has reviewed it before (requires --pr)}
        {--comment       : Post the findings to the pull request as inline review comments (requires --pr)}
        {--fail-on=      : Exit non-zero when findings at or above this severity exist: critical|warning|suggestion}
        {--focus=        : Comma-separated focus areas: bugs,security,performance,tests}';

    protected $description = 'Review code changes with AI — reads the git diff and highlights real issues.';

    private ?PullRequest $pr = null;

    private ?ParsedReview $review = null;

    private ?BudgetTracker $budget = null;

    private string $reviewText = '';

    public function handle(ReviewAgent $agent, PullRequestFetcher $fetcher, ReviewHistory $history, FindingsParser $parser, ReviewPublisher $publisher, BudgetTracker $budget): int
    {
        $this->budget = $budget;

        if (! $this->resolveOutputFormat()) {
            return self::FAILURE;
        }

        if (($error = $this->validateOptions()) !== null) {
            $this->error($error);

            return self::FAILURE;
        }

        $pr = null;
        $incremental = false;
        $previousComments = [];

        if ($this->option('pr')) {
            try {
                $pr = $this->pr = $fetcher->fetch((int) $this->option('pr'));
            } catch (RuntimeException $e) {
                $this->error($e->getMessage());

                return $this->finish(self::FAILURE, 'error', $e->getMessage());
            }

            $diff = $pr->diff;

            // Review only what changed since the last Tackle review, unless
            // --full is passed or the compare cannot be resolved (force-push).
            if (! $this->option('full') && ($lastSha = $history->lastReviewedSha($pr->number)) !== null) {
                if ($lastSha === $pr->headSha) {
                    $this->info('Nothing new to review — the head commit was already reviewed. Use --full to re-review the whole PR.');

                    return $this->finish(self::SUCCESS, 'nothing_to_review');
                }

                $delta = $history->deltaDiff($lastSha, $pr->headSha);

                if ($delta === '') {
                    $this->info('Nothing new to review — no changes since the last Tackle review. Use --full to re-review the whole PR.');

                    return $this->finish(self::SUCCESS, 'nothing_to_review');
                }

                if ($delta !== null) {
                    $incremental = true;
                    $diff = $delta;
                    $previousComments = $history->previousComments($pr->number);
                }
            }
        } else {
            if (! is_dir(base_path('.git'))) {
                $this->error('ai:review requires a git repository.');

                return $this->finish(self::FAILURE, 'error', 'ai:review requires a git repository.');
            }

            $diff = $this->getDiff();

            if ($diff === null) {
                $this->error('Could not read git diff. Check that git is installed and this is a repository.');

                return $this->finish(self::FAILURE, 'error', 'Could not read git diff. Check that git is installed and this is a repository.');
            }
        }

        if ($diff === '') {
            $this->info('Nothing to review — no changes detected for the selected scope.');

            return $this->finish(self::SUCCESS, 'nothing_to_review');
        }

        if ($pr !== null) {
            $this->warnOnCheckoutMismatch($pr);
        }

        $this->renderBanner($pr, $incremental);

        $prompt = $this->buildPrompt($diff, $pr, $incremental, $previousComments);

        $structured = $this->needsStructuredFindings();
        $text = '';

        try {
            if ($credentialError = ProviderCredentials::missing()) {
                throw new RuntimeException($credentialError);
            }

            $response = $agent->stream($prompt);
            $response->each(function ($event) use (&$text, $structured, $budget) {
                if ($event instanceof TextDelta) {
                    $text .= $event->delta;

                    // In structured mode the response ends with a machine-readable
                    // block, so buffer and print a cleaned version at the end.
                    if (! $structured) {
                        $this->output->write($event->delta);
                    }
                } elseif ($event instanceof StreamEnd) {
                    $budget->record($event->usage->promptTokens, $event->usage->completionTokens, $event->usage->cacheReadInputTokens ?? 0, $event->usage->cacheWriteInputTokens ?? 0);
                }
            });
        } catch (Throwable $e) {
            // Text mode keeps the rendered exception; JSON mode owes the
            // caller a document whatever happened.
            if (! $this->jsonOutput) {
                throw $e;
            }

            $this->error($e->getMessage());

            return $this->finish(self::FAILURE, 'error', $e->getMessage());
        }

        if (! $structured) {
            $this->newLine(2);

            return self::SUCCESS;
        }

        $this->reviewText = $parser->strip($text);

        $this->line($this->reviewText);
        $this->newLine();

        $review = $this->review = $parser->parse($text);

        if ($review === null) {
            $error = 'The agent did not return a parseable findings block, so the review cannot be posted or gated. Re-run to retry.';
            $this->error($error);

            return $this->finish(self::FAILURE, 'error', $error);
        }

        if ($this->option('comment')) {
            try {
                // Anchors validate against the FULL PR diff even for an
                // incremental review — that is what GitHub accepts.
                $url = $publisher->publish($pr, $review, new DiffLineIndex($pr->diff), $incremental);
                $this->info("Review posted: {$url}");
            } catch (RuntimeException $e) {
                $this->error($e->getMessage());

                return $this->finish(self::FAILURE, 'error', $e->getMessage());
            }
        }

        return $this->applyFailOn($review);
    }

    /**
     * Resolve the exit code, and in JSON mode emit the single result document
     * on stdout — every post-validation return funnels through here.
     */
    private function finish(int $exit, string $outcome, ?string $error = null): int
    {
        if (! $this->jsonOutput) {
            return $exit;
        }

        $this->emitJsonDocument([
            'ok' => $exit === self::SUCCESS,
            'outcome' => $outcome,
            'error' => $error,
            'verdict' => $this->review?->verdict,
            'findings' => array_map(
                fn (Finding $finding) => [
                    'path' => $finding->path,
                    'line' => $finding->line,
                    'severity' => $finding->severity,
                    'message' => $finding->message,
                ],
                $this->review->findings ?? [],
            ),
            'text' => $this->reviewText,
            'head_sha' => $this->pr?->headSha,
            'pr_number' => $this->pr?->number,
            'usage' => $this->usageSummary($this->budget),
        ]);

        return $exit;
    }

    /**
     * The diff comes from the GitHub API, but the agent reads files from the
     * local working tree. When the two don't match — the checkout is on a
     * different branch than the PR head — the agent sees files that contradict
     * the diff and draws wrong conclusions. Warn instead of guessing.
     */
    private function warnOnCheckoutMismatch(PullRequest $pr): void
    {
        $result = Process::path(base_path())->timeout(10)->run(['git', 'rev-parse', 'HEAD']);

        if (! $result->successful()) {
            return;
        }

        $localHead = trim($result->output());

        if ($localHead === '' || $localHead === $pr->headSha) {
            return;
        }

        $localShort = substr($localHead, 0, 7);
        $prShort = substr($pr->headSha, 0, 7);

        $this->warn(
            "Local checkout ({$localShort}) does not match the PR head ({$prShort}). "
            .'The agent reads files from your working tree, so its context may contradict the diff. '
            ."For accurate results: git checkout {$pr->headRef}"
        );
    }

    private function validateOptions(): ?string
    {
        if ($this->option('pr') && ($this->option('staged') || $this->option('commit') || $this->option('against'))) {
            return 'The --pr option cannot be combined with --staged, --commit, or --against.';
        }

        if ($this->option('comment') && ! $this->option('pr')) {
            return 'The --comment option requires --pr, e.g. ai:review --pr=42 --comment.';
        }

        if ($this->option('full') && ! $this->option('pr')) {
            return 'The --full option requires --pr, e.g. ai:review --pr=42 --full.';
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
        // JSON output always needs the block — the document reports the
        // verdict and findings even when nothing gates or posts them.
        return (bool) ($this->option('comment') || $this->option('fail-on') || $this->jsonOutput);
    }

    private function applyFailOn(ParsedReview $review): int
    {
        $failOn = $this->option('fail-on');

        if (! $failOn) {
            return $this->finish(self::SUCCESS, 'completed');
        }

        $gated = match ($failOn) {
            'critical' => ['critical'],
            'warning' => ['critical', 'warning'],
            default => ['critical', 'warning', 'suggestion'],
        };

        foreach ($gated as $severity) {
            if ($review->has($severity)) {
                $this->error("Failing: the review contains {$severity}-level findings (--fail-on={$failOn}).");

                return $this->finish(self::FAILURE, 'findings_gate_failed');
            }
        }

        return $this->finish(self::SUCCESS, 'completed');
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

    private function buildPrompt(string $diff, ?PullRequest $pr, bool $incremental = false, array $previousComments = []): string
    {
        $scope = $this->scopeDescription($pr, $incremental);
        $focus = $this->focusInstruction();
        $stat = $pr === null ? $this->diffStat() : '';
        $context = $pr === null ? '' : $this->prContext($pr);
        $previous = $this->previousFindingsContext($incremental, $previousComments);
        $structured = $this->needsStructuredFindings() ? $this->structuredInstruction() : '';

        return <<<PROMPT
        Please review the following git diff.

        **Scope:** {$scope}
        {$stat}{$context}{$focus}{$previous}

        Before commenting on any changed function or class, read the full file for context.

        <diff>
        {$diff}
        </diff>{$structured}
        PROMPT;
    }

    private function previousFindingsContext(bool $incremental, array $previousComments): string
    {
        if (! $incremental) {
            return '';
        }

        $context = "\n\n**This is a follow-up review.** The diff above contains only the changes "
            .'pushed since the last review — earlier changes on this PR were already reviewed. '
            .'Do not repeat previously reported findings unless the new changes make them worse.';

        if ($previousComments !== []) {
            $context .= "\n\n**Already reported (do not repeat):**\n- ".implode("\n- ", $previousComments);
        }

        return $context;
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

    private function scopeDescription(?PullRequest $pr = null, bool $incremental = false): string
    {
        if ($pr !== null) {
            $suffix = $incremental ? ' (changes since the last Tackle review)' : '';

            return "pull request #{$pr->number} — {$pr->title}{$suffix}";
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

    private function renderBanner(?PullRequest $pr, bool $incremental = false): void
    {
        $scope = $this->scopeDescription($pr, $incremental);
        $model = config('tackle.model', 'claude-sonnet-4-6');

        $this->line('');
        $this->line('<fg=green;options=bold>Laravel Tackle — AI Code Review</>');
        $this->line("<fg=gray>Scope: {$scope} | Model: {$model}</>");
        $this->line('');
    }
}
