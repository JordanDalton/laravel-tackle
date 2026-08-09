<?php

namespace Tackle\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolCall;
use RuntimeException;
use Tackle\Commands\Concerns\ResolvesSessionOptions;
use Tackle\Contracts\CodingAgent;
use Tackle\Contracts\InteractionPolicy;
use Tackle\Exceptions\AgentInterruptedException;
use Tackle\Review\CommentFetcher;
use Tackle\Review\CommentResponder;
use Tackle\Review\CommentThread;
use Tackle\Review\PullRequest;
use Tackle\Review\PullRequestFetcher;
use Tackle\Support\AutoApproveInteraction;
use Tackle\Support\BudgetTracker;
use Tackle\Support\DenyInteraction;
use Tackle\Support\GitHubClient;
use Throwable;

/**
 * Acts on a "/tackle ..." comment left on a pull request: runs the coding
 * agent against the comment's instruction, pushes any resulting changes to the
 * PR branch, and always replies in the thread — success, no-op, or failure.
 */
class RespondCommand extends Command
{
    use ResolvesSessionOptions;

    protected $signature = 'ai:respond
        {--pr=           : Pull request number the comment was left on}
        {--comment-id=   : ID of the triggering comment}
        {--comment-type=review : Where the comment lives: review (inline) | issue (conversation)}
        {--budget=       : Override the spend limit in USD for this run}
        {--max-steps=    : Stop after this many tool calls}
        {--yes           : Approve confirmations automatically instead of denying them}
        {--shell=        : Override the shell mode for this run (off|allowlist|approve|yolo)}
        {--off           : Shorthand for --shell=off}
        {--allowlist     : Shorthand for --shell=allowlist}
        {--approve       : Shorthand for --shell=approve}
        {--yolo          : Shorthand for --shell=yolo}';

    protected $description = 'Act on a /tackle pull request comment — apply the requested change and reply in the thread.';

    private int $steps = 0;

    private int $maxSteps = 40;

    public function handle(
        PullRequestFetcher $prs,
        CommentFetcher $comments,
        CommentResponder $responder,
        GitHubClient $github,
    ): int {
        if (($error = $this->validateOptions()) !== null) {
            $this->error($error);

            return self::FAILURE;
        }

        if (! $this->applyShellOverride()) {
            return self::FAILURE;
        }

        if (($budget = $this->option('budget')) !== null) {
            config(['tackle.budget_usd' => (float) $budget]);
        }

        $this->maxSteps = (int) ($this->option('max-steps') ?: config('tackle.max_steps', 40));

        try {
            $pr = $prs->fetch((int) $this->option('pr'));
            $comment = $comments->fetch($pr->number, (int) $this->option('comment-id'), (string) $this->option('comment-type'));
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $responder->acknowledge($comment);

        // Never push into someone else's repository. Fork PRs get a polite
        // reply instead of a failed push.
        if ($pr->isFromFork((string) $github->repo())) {
            $responder->reply($pr->number, $comment, '🤖 This PR comes from a fork, and Tackle only pushes to branches in this repository. Apply the change manually or move the branch here.');
            $this->error("PR #{$pr->number} is from a fork ({$pr->headRepo}) — refusing to push.");

            return self::FAILURE;
        }

        // The agent edits the local working tree and the result is pushed to
        // the PR branch, so the checkout MUST be the PR head. This is an
        // operator error, not something to announce on the PR.
        if (! $this->checkoutMatches($pr)) {
            $this->error("Local checkout does not match the PR head ({$pr->headSha}). Check out {$pr->headRef} (in CI: ref: refs/pull/{$pr->number}/head) and re-run.");

            return self::FAILURE;
        }

        $interaction = $this->option('yes') ? new AutoApproveInteraction : new DenyInteraction;
        $this->laravel->instance(InteractionPolicy::class, $interaction);

        $budget = $this->laravel->make(BudgetTracker::class);
        $agent = $this->laravel->make(CodingAgent::class);

        $this->info("Responding to {$comment->author}'s comment on PR #{$pr->number}…");

        try {
            $summary = $this->runAgent($agent, $budget, $this->buildPrompt($pr, $comment));
        } catch (Throwable $e) {
            $reason = $e instanceof AgentInterruptedException
                ? ($e->getMessage() === 'budget_exceeded' ? 'the spend limit was reached' : 'the step limit was reached')
                : $e->getMessage();

            $responder->reply($pr->number, $comment, "❌ Tackle couldn't complete this: {$reason}");
            $this->error("Agent failed: {$reason}");

            return self::FAILURE;
        }

        $summary = $summary !== '' ? $summary : 'Done.';

        if (! $this->hasChanges()) {
            $responder->reply($pr->number, $comment, "🤖 {$this->truncate($summary)}");
            $this->info('No file changes were needed — replied with the agent\'s answer.');

            return self::SUCCESS;
        }

        $diffStat = trim(Process::path(base_path())->run(['git', 'diff', '--stat'])->output());

        try {
            $sha = $this->commitAndPush($pr, $comment);
        } catch (RuntimeException $e) {
            $responder->reply($pr->number, $comment, "❌ Tackle made the change locally but couldn't push it: {$e->getMessage()}");
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $responder->reply($pr->number, $comment, "🔧 {$this->truncate($summary)}\n\nPushed `{$sha}`:\n```\n{$diffStat}\n```");
        $this->info("Pushed {$sha} to {$pr->headRef} and replied.");

        return self::SUCCESS;
    }

    private function validateOptions(): ?string
    {
        if (! ctype_digit((string) $this->option('pr'))) {
            return 'The --pr option is required and must be a pull request number.';
        }

        if (! ctype_digit((string) $this->option('comment-id'))) {
            return 'The --comment-id option is required and must be a comment ID.';
        }

        if (! in_array($this->option('comment-type'), ['review', 'issue'], true)) {
            return 'The --comment-type option must be review or issue.';
        }

        return null;
    }

    private function checkoutMatches(PullRequest $pr): bool
    {
        $result = Process::path(base_path())->timeout(10)->run(['git', 'rev-parse', 'HEAD']);

        return $result->successful() && trim($result->output()) === $pr->headSha;
    }

    private function runAgent(CodingAgent $agent, BudgetTracker $budget, string $prompt): string
    {
        $text = '';

        $agent->stream($prompt)->each(function ($event) use (&$text, $budget) {
            if ($event instanceof TextDelta) {
                $text .= $event->delta;
                $this->output->write($event->delta);
            } elseif ($event instanceof ToolCall) {
                if (++$this->steps > $this->maxSteps) {
                    throw new AgentInterruptedException('max_steps_reached');
                }
            } elseif ($event instanceof StreamEnd) {
                $budget->record($event->usage->promptTokens, $event->usage->completionTokens);

                if ($budget->overBudget()) {
                    throw new AgentInterruptedException('budget_exceeded');
                }
            }
        });

        $this->newLine();

        return trim($text);
    }

    private function buildPrompt(PullRequest $pr, CommentThread $comment): string
    {
        $location = $comment->path !== ''
            ? "\n**Location:** `{$comment->path}`".($comment->line ? " line {$comment->line}" : '')
            : '';

        $hunk = $comment->diffHunk !== ''
            ? "\n\n**Diff context:**\n```\n{$comment->diffHunk}\n```"
            : '';

        $thread = $comment->thread !== []
            ? "\n\n**Earlier comments in this thread:**\n- ".implode("\n- ", $comment->thread)
            : '';

        return <<<PROMPT
        A reviewer left a comment on pull request #{$pr->number} ("{$pr->title}", branch `{$pr->headRef}`) and asked you to act on it.
        {$location}{$hunk}{$thread}

        **{$comment->author} wrote:**
        {$comment->instruction}

        Do what the comment asks, and nothing more:
        - If it asks for a code change, make the smallest change that addresses it, then run the relevant tests if the project has them.
        - If it asks a question, answer it — do not change any files.
        - Do NOT commit or push. The changes are committed and pushed for you after you finish.

        Your final message is posted verbatim as the reply in the comment thread, so write it to the reviewer: brief, direct, and about what you did or found — not a running narration.
        PROMPT;
    }

    private function hasChanges(): bool
    {
        $result = Process::path(base_path())->run(['git', 'status', '--porcelain']);

        return trim($result->output()) !== '';
    }

    private function commitAndPush(PullRequest $pr, CommentThread $comment): string
    {
        $base = base_path();
        $message = "Apply review feedback from @{$comment->author}\n\n".$this->truncate($comment->instruction, 300);

        Process::path($base)->run(['git', 'add', '-A']);

        $commit = Process::path($base)->run([
            'git', '-c', 'user.name=Tackle', '-c', 'user.email=tackle-bot@users.noreply.github.com',
            'commit', '-m', $message,
        ]);

        if (! $commit->successful()) {
            throw new RuntimeException('git commit failed: '.trim($commit->errorOutput().$commit->output()));
        }

        $push = Process::path($base)->timeout(60)->run(['git', 'push', 'origin', "HEAD:{$pr->headRef}"]);

        if (! $push->successful()) {
            throw new RuntimeException('git push failed: '.trim($push->errorOutput()));
        }

        return trim(Process::path($base)->run(['git', 'rev-parse', '--short', 'HEAD'])->output());
    }

    private function truncate(string $text, int $limit = 2000): string
    {
        return mb_strlen($text) <= $limit ? $text : mb_substr($text, 0, $limit).'…';
    }
}
