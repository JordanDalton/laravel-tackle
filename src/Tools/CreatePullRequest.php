<?php

namespace Tackle\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Process;
use Laravel\Ai\Tools\Request;
use Tackle\Support\GitHubClient;
use Throwable;

class CreatePullRequest extends AbstractTool
{
    public function __construct(private GitHubClient $client) {}

    public function description(): string
    {
        return 'Create a git branch, commit all changes, push to origin, and open a GitHub pull request. Call this after finishing work on a GitHub issue. Always call ConfirmAction before calling this tool.';
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

        $title       = (string) $request->string('title', '');
        $body        = (string) $request->string('body', '');
        $branch      = (string) $request->string('branch', '');
        $base        = (string) $request->string('base', 'main');
        $issueNumber = $request->integer('issue_number', 0);

        if (trim($title) === '' || trim($branch) === '') {
            return 'title and branch are required.';
        }

        $base = $base ?: 'main';
        $repo = $this->client->repo();

        $escapedBranch = escapeshellarg($branch);
        $escapedTitle  = escapeshellarg($title);

        // Check there's something to commit
        $status = Process::path(base_path())->run('git status --porcelain');
        if (trim($status->output()) === '') {
            return 'No changes to commit. Make sure the agent has edited files before opening a PR.';
        }

        try {
            // Create and switch to the new branch
            $checkout = Process::path(base_path())->run("git checkout -b {$escapedBranch}");
            if (! $checkout->successful()) {
                return 'Failed to create branch: ' . trim($checkout->errorOutput());
            }

            // Stage all changes (respects .gitignore)
            Process::path(base_path())->run('git add -A');

            // Commit
            $commit = Process::path(base_path())->run("git commit -m {$escapedTitle}");
            if (! $commit->successful()) {
                return 'Commit failed: ' . trim($commit->errorOutput());
            }

            // Push
            $push = Process::path(base_path())->run("git push origin {$escapedBranch}");
            if (! $push->successful()) {
                return 'Push failed: ' . trim($push->errorOutput());
            }

            // Build PR body
            $prBody = $body;
            if ($issueNumber > 0) {
                $prBody .= "\n\nCloses #{$issueNumber}";
            }

            // Open PR via GitHub API
            $response = $this->client->post("repos/{$repo}/pulls", [
                'title' => $title,
                'body'  => $prBody,
                'head'  => $branch,
                'base'  => $base,
            ]);

            if (! $response->successful()) {
                $error = $response->json('message', 'unknown error');
                return "PR creation failed: {$error}";
            }

            $prUrl = $response->json('html_url', '');

            return "Pull request opened: {$prUrl}";
        } catch (Throwable $e) {
            return 'Error opening pull request: ' . $e->getMessage();
        }
    }
}
