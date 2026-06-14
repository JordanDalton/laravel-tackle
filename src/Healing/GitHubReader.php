<?php

namespace Tackle\Healing;

use Tackle\Support\GitHubClient;
use Throwable;

class GitHubReader
{
    public function __construct(private GitHubClient $client) {}

    public function forIssue(int $issueNumber): string
    {
        if (! $this->client->configured()) {
            return '';
        }

        $repo = $this->client->repo();

        try {
            $issueResponse = $this->client->get("repos/{$repo}/issues/{$issueNumber}");

            if (! $issueResponse->successful()) {
                return '';
            }

            $commentsResponse = $this->client->get("repos/{$repo}/issues/{$issueNumber}/comments", ['per_page' => 25]);
            $comments = $commentsResponse->successful() ? $commentsResponse->json() : [];

            return $this->formatIssue($issueResponse->json(), $comments);
        } catch (Throwable) {
            return '';
        }
    }

    public function recent(int $limit = 10): string
    {
        if (! $this->client->configured()) {
            return '';
        }

        $repo = $this->client->repo();

        try {
            $response = $this->client->get("repos/{$repo}/issues", [
                'state'     => 'open',
                'per_page'  => min($limit, 25),
                'sort'      => 'updated',
                'direction' => 'desc',
            ]);

            if (! $response->successful()) {
                return '';
            }

            $issues = collect($response->json())->filter(fn ($i) => ! isset($i['pull_request']));

            if ($issues->isEmpty()) {
                return 'No open GitHub issues found.';
            }

            return $issues->map(function ($issue) {
                $number  = $issue['number']     ?? '?';
                $title   = $issue['title']      ?? '?';
                $updated = $issue['updated_at'] ?? '';
                $labels  = collect($issue['labels'] ?? [])->pluck('name')->implode(', ');
                $label   = $labels ? " [{$labels}]" : '';
                return "[{$updated}] #{$number}{$label} {$title}";
            })->implode("\n");
        } catch (Throwable) {
            return '';
        }
    }

    private function formatIssue(array $issue, array $comments): string
    {
        $number  = $issue['number']        ?? '?';
        $title   = $issue['title']         ?? '?';
        $state   = $issue['state']         ?? '?';
        $author  = $issue['user']['login'] ?? '?';
        $body    = trim($issue['body']     ?? '');
        $labels  = collect($issue['labels'] ?? [])->pluck('name')->implode(', ');

        $output  = "GitHub Issue #{$number} — {$title}";
        $output .= "\nState: {$state} | Author: {$author}";

        if ($labels) {
            $output .= " | Labels: {$labels}";
        }

        if ($body) {
            $output .= "\n\n{$body}";
        }

        if (! empty($comments)) {
            $output .= "\n\n--- Comments ---";
            foreach ($comments as $comment) {
                $login   = $comment['user']['login'] ?? '?';
                $created = $comment['created_at']    ?? '';
                $text    = trim($comment['body']     ?? '');
                $output .= "\n\n[{$created}] {$login}:\n{$text}";
            }
        }

        return trim($output);
    }
}
