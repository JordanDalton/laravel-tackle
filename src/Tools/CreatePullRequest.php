<?php

namespace Tackle\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Process;
use InvalidArgumentException;
use Laravel\Ai\Tools\Request;
use Tackle\Support\GitHubClient;
use Tackle\Support\PathGuard;
use Tackle\Support\RedGreenProof;
use Tackle\Support\ScopedGitCommit;
use Throwable;

class CreatePullRequest extends AbstractTool
{
    public function __construct(
        private GitHubClient $client,
        private PathGuard $pathGuard,
    ) {}

    public function description(): string
    {
        return 'Create a git branch, commit only the explicitly listed files, push to origin, and open a GitHub pull request. Call this after finishing work on a GitHub issue. Always call ConfirmAction before calling this tool.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()
                ->description('Pull request title.')
                ->required(),
            'body' => $schema->string()
                ->description('Pull request description — summarise what was changed and why.')
                ->required(),
            'branch' => $schema->string()
                ->description('Branch name to create, e.g. "tackle/issue-3-fix-login". Must not already exist.')
                ->required(),
            'files' => $schema->array()
                ->description('Every repository-relative file to commit. List individual files edited, created, renamed, or deleted by this task; never pass directories.')
                ->required(),
            'base' => $schema->string()
                ->description('Base branch to open the PR against. Defaults to "main".'),
            'issue_number' => $schema->integer()
                ->description('GitHub issue number to close when the PR is merged. Appends "Closes #N" to the PR body.'),
        ];
    }

    public function handle(Request $request): string
    {
        if (! $this->client->configured()) {
            return 'GitHub is not configured. Set GITHUB_TOKEN (or run: gh auth login) and GITHUB_REPO in .env.';
        }

        $title = (string) $this->arg($request, 'title', '');
        $body = (string) $this->arg($request, 'body', '');
        $branch = (string) $this->arg($request, 'branch', '');
        $base = (string) $this->arg($request, 'base', 'main');
        $issueNumber = $request->integer('issue_number', 0);

        if (trim($title) === '' || trim($branch) === '') {
            return 'title and branch are required.';
        }

        $base = $base ?: 'main';
        $repo = $this->client->repo();
        $git = new ScopedGitCommit($this->pathGuard);

        try {
            $files = $git->files($request->array('files', []));
        } catch (InvalidArgumentException $e) {
            return $e->getMessage();
        }

        $escapedBranch = escapeshellarg($branch);

        // Check there's something to commit
        $status = $git->status($files);
        if (trim($status->output()) === '') {
            return 'None of the selected files has changes to commit. Make sure files lists everything the agent edited.';
        }

        // Prove the new tests test the change while everything is still
        // uncommitted — the answer to "what would you need to see before
        // trusting this PR" was verification, every time we asked.
        $proof = (new RedGreenProof($this->pathGuard))->run($files);

        try {
            // Create and switch to the new branch
            $checkout = Process::path($this->pathGuard->workspace())->run("git checkout -b {$escapedBranch}");
            if (! $checkout->successful()) {
                return 'Failed to create branch: '.trim($checkout->errorOutput());
            }

            $stage = $git->stage($files);
            if ($stage->failed()) {
                return 'Staging failed: '.trim($stage->errorOutput());
            }

            // Commit only the task's files, even if the deployment already
            // has unrelated staged changes.
            $commit = $git->commit($title, $files);
            if (! $commit->successful()) {
                return 'Commit failed: '.trim($commit->errorOutput());
            }

            // Push
            $push = Process::path($this->pathGuard->workspace())->run("git push origin {$escapedBranch}");
            if (! $push->successful()) {
                return 'Push failed: '.trim($push->errorOutput());
            }

            // Build PR body. The agent is often told to reference the issue
            // itself — Tackle Cloud's issue-to-task prompt says exactly that —
            // so appending unconditionally produced PRs closing the same issue
            // twice. GitHub accepts any of its closing keywords, so check for
            // all of them rather than only the one this tool writes.
            $prBody = $body.RedGreenProof::markdown($proof);

            if ($issueNumber > 0 && ! $this->alreadyCloses($body, $issueNumber)) {
                $prBody .= "\n\nCloses #{$issueNumber}";
            }

            // Open PR via GitHub API
            $response = $this->client->post("repos/{$repo}/pulls", [
                'title' => $title,
                'body' => $prBody,
                'head' => $branch,
                'base' => $base,
            ]);

            if (! $response->successful()) {
                $error = $response->json('message', 'unknown error');
                $details = $response->json('errors');

                if (is_array($details) && $details !== []) {
                    $encoded = json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    $error .= $encoded === false ? '' : ' — '.$encoded;
                }

                return "PR creation failed (HTTP {$response->status()}): {$error}";
            }

            $prUrl = $response->json('html_url', '');

            return "Pull request opened: {$prUrl}";
        } catch (Throwable $e) {
            return 'Error opening pull request: '.$e->getMessage();
        }
    }

    /**
     * Whether the body already links the issue with a closing keyword.
     *
     * @see https://docs.github.com/articles/closing-issues-using-keywords
     */
    private function alreadyCloses(string $body, int $issueNumber): bool
    {
        return (bool) preg_match(
            '/\b(close[sd]?|fix(e[sd])?|resolve[sd]?)\b\s*:?\s*#'.$issueNumber.'\b/i',
            $body,
        );
    }
}
