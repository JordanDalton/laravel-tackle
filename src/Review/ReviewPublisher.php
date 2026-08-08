<?php

namespace Tackle\Review;

use RuntimeException;
use Tackle\Support\GitHubClient;

class ReviewPublisher
{
    public function __construct(private GitHubClient $client) {}

    /**
     * Post the review to GitHub as a single PR review: findings that anchor to
     * a line in the diff become inline comments, the rest are folded into the
     * review body.
     *
     * @return string the review's html_url (falls back to the PR url)
     *
     * @throws RuntimeException when GitHub is not configured or the POST fails
     */
    public function publish(PullRequest $pr, ParsedReview $review, DiffLineIndex $index): string
    {
        if (! $this->client->configured()) {
            throw new RuntimeException(
                'GitHub is not configured. Set GITHUB_TOKEN (or run: gh auth login) and GITHUB_REPO in .env.'
            );
        }

        $comments = [];
        $unanchored = [];

        foreach ($review->findings as $finding) {
            if ($index->isCommentable($finding->path, $finding->line)) {
                $comments[] = [
                    'path' => $finding->path,
                    'line' => $finding->line,
                    'side' => 'RIGHT',
                    'body' => $finding->toComment(),
                ];
            } else {
                $unanchored[] = $finding;
            }
        }

        $repo = $this->client->repo();

        $response = $this->client->post("repos/{$repo}/pulls/{$pr->number}/reviews", array_filter([
            'commit_id' => $pr->headSha,
            'event' => 'COMMENT',
            'body' => $this->body($review, $unanchored),
            'comments' => $comments,
        ], fn ($value) => $value !== '' && $value !== []));

        if (! $response->successful()) {
            throw new RuntimeException(
                "Could not post the review to PR #{$pr->number} (HTTP {$response->status()}): ".$response->body()
            );
        }

        return (string) ($response->json('html_url') ?: $pr->url);
    }

    /** @param  Finding[]  $unanchored */
    private function body(ParsedReview $review, array $unanchored): string
    {
        $verdict = match ($review->verdict) {
            'lgtm' => '✅ **LGTM**',
            'needs_changes' => '❌ **Needs changes**',
            default => '✅ **LGTM with minor notes**',
        };

        $body = "## Tackle AI Review\n\n{$verdict}";

        if ($review->findings === []) {
            return $body."\n\nNo issues found.";
        }

        if ($unanchored !== []) {
            $body .= "\n\n### Findings outside the diff's commentable lines\n";

            foreach ($unanchored as $finding) {
                $body .= "\n- {$finding->label()} `{$finding->path}:{$finding->line}` — {$finding->message}";
            }
        }

        return $body;
    }
}
