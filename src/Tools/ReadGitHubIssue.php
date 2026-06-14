<?php

namespace Tackle\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Tackle\Healing\GitHubReader;
use Tackle\Support\GitHubClient;

class ReadGitHubIssue extends AbstractTool
{
    public function __construct(private GitHubReader $reader, private GitHubClient $client) {}

    public function description(): string
    {
        return 'Fetch a GitHub issue by number — returns the title, body, labels, and all comments so you have full context before working on it. Omit issue_number to list recent open issues. Returns an empty result if GITHUB_TOKEN or GITHUB_REPO is not configured.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'issue_number' => $schema->integer()
                ->description('GitHub issue number (e.g. 42). Omit to list recent open issues.'),
            'limit' => $schema->integer()
                ->description('Number of recent issues to return when no issue_number is given. Defaults to 10.'),
        ];
    }

    public function handle(Request $request): string
    {
        if (! $this->client->configured()) {
            return 'GitHub is not configured. Set GITHUB_TOKEN (or run: gh auth login) and GITHUB_REPO in .env.';
        }

        $issueNumber = $request->integer('issue_number', 0);

        if ($issueNumber > 0) {
            $result = $this->reader->forIssue($issueNumber);

            return $result !== ''
                ? $result
                : 'Could not fetch GitHub issue. Check that GITHUB_TOKEN and GITHUB_REPO are set and the issue number is correct.';
        }

        $limit  = max(1, min(25, (int) $request->integer('limit', 10)));
        $result = $this->reader->recent($limit);

        return $result !== ''
            ? $result
            : 'No GitHub issues found. Check that GITHUB_TOKEN and GITHUB_REPO are set.';
    }
}
