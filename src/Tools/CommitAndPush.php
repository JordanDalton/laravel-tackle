<?php

namespace Tackle\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Process;
use Laravel\Ai\Tools\Request;
use Tackle\Contracts\InteractionPolicy;
use Tackle\Support\PathGuard;

class CommitAndPush extends AbstractTool
{
    /** Override in tests: set to true/false to skip the interactive prompt. */
    public static ?bool $confirmOverride = null;

    public function __construct(
        private PathGuard $pathGuard,
        private ?InteractionPolicy $interaction = null,
    ) {}

    public function description(): string
    {
        return 'Stage all changes in the workspace, create a commit, and push to the current remote branch. Use this to add follow-up commits to an existing pull request after CreatePullRequest has already opened it. Does not create a new PR.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'message' => $schema->string()
                ->description('Commit message describing what changed.')
                ->required(),
            'branch' => $schema->string()
                ->description('Remote branch name to push to, e.g. "tackle/issue-6-return-dalton". Required when working in a worktree (detached HEAD). Get this from ReadPullRequest. Do NOT check out the branch — the push uses HEAD:<branch> so no checkout is needed.'),
        ];
    }

    public function handle(Request $request): string
    {
        $message = (string) $this->arg($request, 'message', '');
        $branch = trim((string) $this->arg($request, 'branch', ''));

        if (trim($message) === '') {
            return 'message is required.';
        }

        $path = $this->pathGuard->workspace();

        $status = Process::path($path)->run('git status --porcelain');
        if (trim($status->output()) === '') {
            return 'No changes to commit.';
        }

        if ($branch !== '') {
            // Sync with the remote tip so our commit is a fast-forward.
            // git reset --mixed moves detached HEAD to FETCH_HEAD without
            // touching working-directory files, so our edits survive.
            $fetch = Process::path($path)->run('git fetch origin '.escapeshellarg($branch));
            if ($fetch->successful()) {
                Process::path($path)->run('git reset --mixed FETCH_HEAD');
                $afterReset = Process::path($path)->run('git status --porcelain');
                if (trim($afterReset->output()) === '') {
                    return 'No changes to commit — the remote branch already contains these changes.';
                }
            }
        }

        if (! $this->previewAndConfirm($path, $branch)) {
            return 'Cancelled by user.';
        }

        Process::path($path)->run('git add -A');

        $commit = Process::path($path)->run('git commit -m '.escapeshellarg($message));
        if ($commit->failed()) {
            return 'Commit failed: '.trim($commit->errorOutput());
        }

        // Use HEAD:<branch> so we never need to check out the branch (avoids
        // "already checked out" errors when the same branch exists in the main repo).
        $pushCmd = $branch !== ''
            ? 'git push origin HEAD:'.escapeshellarg($branch)
            : 'git push';

        $push = Process::path($path)->run($pushCmd);
        if ($push->failed()) {
            return 'Push failed: '.trim($push->errorOutput());
        }

        return 'Changes committed and pushed to the existing PR branch.';
    }

    private function previewAndConfirm(string $path, string $branch): bool
    {
        $interaction = $this->interaction();

        // The preview exists so a human can eyeball the diff before it is pushed.
        // With no terminal there is nobody to read it, and echoing it would corrupt
        // stdout for callers parsing structured output.
        if ($interaction->isInteractive()) {
            $this->printPreview($path);
        }

        if (static::$confirmOverride !== null) {
            return static::$confirmOverride;
        }

        $label = $branch !== '' ? "Push these changes to {$branch}?" : 'Push these changes?';

        return $interaction->confirm($label, default: false);
    }

    private function printPreview(string $path): void
    {
        $stat = trim(Process::path($path)->run('git diff HEAD --stat')->output());
        $diff = trim(Process::path($path)->run('git diff HEAD')->output());

        echo PHP_EOL;

        if ($stat !== '') {
            echo $stat.PHP_EOL;
        }

        if ($diff !== '') {
            $lines = explode("\n", $diff);
            if (count($lines) > 50) {
                echo PHP_EOL.implode("\n", array_slice($lines, 0, 50));
                echo PHP_EOL.'... ('.(count($lines) - 50).' more lines not shown)'.PHP_EOL;
            } else {
                echo PHP_EOL.$diff.PHP_EOL;
            }
        }
    }

    private function interaction(): InteractionPolicy
    {
        return $this->interaction ??= app(InteractionPolicy::class);
    }
}
