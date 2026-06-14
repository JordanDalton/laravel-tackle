<?php

namespace Tackle\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Process;
use Laravel\Ai\Tools\Request;
use Tackle\Support\PathGuard;

class CommitAndPush extends AbstractTool
{
    public function __construct(private PathGuard $pathGuard) {}

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
                ->description('Branch name to push to. Required when the workspace is in detached HEAD (e.g. after git worktree add HEAD). Pass the same branch that was used for CreatePullRequest.'),
        ];
    }

    public function handle(Request $request): string
    {
        $message = (string) $request->string('message', '');
        $branch  = trim((string) $request->string('branch', ''));

        if (trim($message) === '') {
            return 'message is required.';
        }

        $path = $this->pathGuard->workspace();

        $status = Process::path($path)->run('git status --porcelain');
        if (trim($status->output()) === '') {
            return 'No changes to commit.';
        }

        if ($branch !== '') {
            $checkout = Process::path($path)->run('git checkout ' . escapeshellarg($branch));
            if ($checkout->failed()) {
                return 'Failed to switch to branch: ' . trim($checkout->errorOutput());
            }
        }

        Process::path($path)->run('git add -A');

        $commit = Process::path($path)->run('git commit -m ' . escapeshellarg($message));
        if ($commit->failed()) {
            return 'Commit failed: ' . trim($commit->errorOutput());
        }

        $pushCmd = $branch !== ''
            ? 'git push origin ' . escapeshellarg($branch)
            : 'git push';

        $push = Process::path($path)->run($pushCmd);
        if ($push->failed()) {
            return 'Push failed: ' . trim($push->errorOutput());
        }

        return 'Changes committed and pushed to the existing PR branch.';
    }
}
