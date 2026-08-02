<?php

namespace Tackle\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Tackle\Support\GitHubClient;
use Throwable;

class ReadPullRequest extends AbstractTool
{
    public function __construct(private GitHubClient $client) {}

    public function description(): string
    {
        return 'Fetch a GitHub pull request by number — returns the title, body, branch name (head ref), base branch, state, author, and comments. Omit pr_number to list recent open pull requests. Use this (not ReadGitHubIssue) when the user references a PR number, especially when the branch name is needed for CommitAndPush.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'pr_number' => $schema->integer()
                ->description('GitHub pull request number. Omit to list recent open pull requests.'),
            'limit' => $schema->integer()
                ->description('Number of open PRs to return when no pr_number is given. Defaults to 10.'),
        ];
    }

    public function handle(Request $request): string
    {
        if (! $this->client->configured()) {
            return 'GitHub is not configured. Set GITHUB_TOKEN (or run: gh auth login) and GITHUB_REPO in .env.';
        }

        $prNumber = $request->integer('pr_number', 0);

        if ($prNumber > 0) {
            return $this->fetchOne($prNumber);
        }

        return $this->listOpen(max(1, min(25, (int) $request->integer('limit', 10))));
    }

    private function fetchOne(int $prNumber): string
    {
        $repo = $this->client->repo();

        try {
            $prResponse = $this->client->get("repos/{$repo}/pulls/{$prNumber}");

            if (! $prResponse->successful()) {
                return "Could not fetch PR #{$prNumber}. Check that GITHUB_TOKEN and GITHUB_REPO are set and the PR number is correct.";
            }

            $commentsResponse = $this->client->get("repos/{$repo}/issues/{$prNumber}/comments", ['per_page' => 25]);
            $comments = $commentsResponse->successful() ? $commentsResponse->json() : [];

            return $this->format($prResponse->json(), $comments);
        } catch (Throwable $e) {
            return 'Error fetching pull request: '.$e->getMessage();
        }
    }

    private function listOpen(int $limit): string
    {
        $repo = $this->client->repo();

        try {
            $response = $this->client->get("repos/{$repo}/pulls", [
                'state' => 'open',
                'per_page' => $limit,
                'sort' => 'updated',
                'direction' => 'desc',
            ]);

            if (! $response->successful()) {
                return 'Could not list pull requests. Check that GITHUB_TOKEN and GITHUB_REPO are set.';
            }

            $prs = $response->json();

            if (empty($prs)) {
                return 'No open pull requests found.';
            }

            return collect($prs)->map(function (array $pr): string {
                $number = $pr['number'] ?? '?';
                $title = $pr['title'] ?? '?';
                $branch = $pr['head']['ref'] ?? '?';
                $base = $pr['base']['ref'] ?? '?';
                $author = $pr['user']['login'] ?? '?';
                $updated = $pr['updated_at'] ?? '';

                return "[{$updated}] #{$number} [{$branch} → {$base}] {$title} (by {$author})";
            })->implode("\n");
        } catch (Throwable $e) {
            return 'Error listing pull requests: '.$e->getMessage();
        }
    }

    private function format(array $pr, array $comments): string
    {
        $number = $pr['number'] ?? '?';
        $title = $pr['title'] ?? '?';
        $state = $pr['state'] ?? '?';
        $author = $pr['user']['login'] ?? '?';
        $branch = $pr['head']['ref'] ?? '?';
        $base = $pr['base']['ref'] ?? '?';
        $url = $pr['html_url'] ?? '';
        $body = trim($pr['body'] ?? '');

        $output = "GitHub PR #{$number} — {$title}";
        $output .= "\nState: {$state} | Author: {$author}";
        $output .= "\nBranch: {$branch} → {$base}";

        if ($url) {
            $output .= "\nURL: {$url}";
        }

        if ($body) {
            $output .= "\n\n{$body}";
        }

        if (! empty($comments)) {
            $output .= "\n\n--- Comments ---";
            foreach ($comments as $comment) {
                $login = $comment['user']['login'] ?? '?';
                $created = $comment['created_at'] ?? '';
                $text = trim($comment['body'] ?? '');
                $output .= "\n\n[{$created}] {$login}:\n{$text}";
            }
        }

        return trim($output);
    }
}
