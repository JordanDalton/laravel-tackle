<?php

namespace Tackle\Review;

use RuntimeException;
use Tackle\Support\GitHubClient;
use Tackle\Support\Utf8;

class PullRequestFetcher
{
    public function __construct(private GitHubClient $client) {}

    /**
     * Fetch a pull request's metadata and its unified diff from the GitHub API.
     *
     * The diff comes from the API rather than local git, so the command works
     * regardless of which branches exist in the local checkout.
     *
     * @throws RuntimeException when GitHub is not configured or the PR cannot be fetched
     */
    public function fetch(int $number): PullRequest
    {
        if (! $this->client->configured()) {
            throw new RuntimeException(
                'GitHub is not configured. Set GITHUB_TOKEN (or run: gh auth login) and GITHUB_REPO in .env.'
            );
        }

        $repo = $this->client->repo();

        $meta = $this->client->get("repos/{$repo}/pulls/{$number}");

        if (! $meta->successful()) {
            throw new RuntimeException(
                "Could not fetch PR #{$number} from {$repo} (HTTP {$meta->status()}). Check the PR number and token permissions."
            );
        }

        $diff = $this->client->getRaw("repos/{$repo}/pulls/{$number}", 'application/vnd.github.v3.diff');

        if (! $diff->successful()) {
            throw new RuntimeException(
                "Could not fetch the diff for PR #{$number} (HTTP {$diff->status()})."
            );
        }

        $pr = $meta->json();

        return new PullRequest(
            number: $number,
            title: (string) ($pr['title'] ?? ''),
            body: trim((string) ($pr['body'] ?? '')),
            headRef: (string) ($pr['head']['ref'] ?? ''),
            headSha: (string) ($pr['head']['sha'] ?? ''),
            baseRef: (string) ($pr['base']['ref'] ?? ''),
            url: (string) ($pr['html_url'] ?? ''),
            diff: Utf8::clean(trim($diff->body())),
            headRepo: (string) ($pr['head']['repo']['full_name'] ?? ''),
        );
    }
}
