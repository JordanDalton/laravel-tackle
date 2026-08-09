<?php

namespace Tackle\Review;

use Tackle\Support\GitHubClient;
use Tackle\Support\Utf8;
use Throwable;

/**
 * Finds what Tackle has already said about a pull request, so a re-run reviews
 * only what changed since — instead of stacking a full duplicate review on
 * every push.
 *
 * Each published review embeds an invisible HTML marker recording the head SHA
 * it reviewed. This class reads that marker back, fetches the compare diff
 * between then and now, and collects the previously posted inline comments so
 * the agent can be told not to repeat them.
 */
class ReviewHistory
{
    public const MARKER_FORMAT = '<!-- tackle-review:sha=%s -->';

    private const MARKER_PATTERN = '/<!-- tackle-review:sha=([0-9a-f]{7,40}) -->/';

    public function __construct(private GitHubClient $client) {}

    public static function marker(string $sha): string
    {
        return sprintf(self::MARKER_FORMAT, $sha);
    }

    /**
     * The head SHA recorded by the most recent Tackle review on this PR, or
     * null when Tackle has not reviewed it before.
     */
    public function lastReviewedSha(int $prNumber): ?string
    {
        $repo = $this->client->repo();

        try {
            $response = $this->client->get("repos/{$repo}/pulls/{$prNumber}/reviews", ['per_page' => 100]);

            if (! $response->successful()) {
                return null;
            }

            $sha = null;

            foreach ($response->json() as $review) {
                if (preg_match(self::MARKER_PATTERN, (string) ($review['body'] ?? ''), $m)) {
                    $sha = $m[1]; // keep scanning — the API returns oldest first
                }
            }

            return $sha;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The unified diff of everything pushed between two commits, or null when
     * the compare cannot be fetched (e.g. the old SHA was force-pushed away) —
     * in which case the caller should fall back to a full review.
     */
    public function deltaDiff(string $fromSha, string $toSha): ?string
    {
        $repo = $this->client->repo();

        try {
            $response = $this->client->getRaw(
                "repos/{$repo}/compare/{$fromSha}...{$toSha}",
                'application/vnd.github.v3.diff'
            );

            if (! $response->successful()) {
                return null;
            }

            return Utf8::clean(trim($response->body()));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Inline comments Tackle posted on earlier reviews of this PR, formatted
     * as "path:line — message" lines for the agent's context.
     *
     * @return string[]
     */
    public function previousComments(int $prNumber): array
    {
        $repo = $this->client->repo();

        try {
            $response = $this->client->get("repos/{$repo}/pulls/{$prNumber}/comments", ['per_page' => 100]);

            if (! $response->successful()) {
                return [];
            }

            $comments = [];

            foreach ($response->json() as $comment) {
                $body = (string) ($comment['body'] ?? '');

                // Tackle's inline comments always lead with a severity emoji.
                if (! preg_match('/^(?:🔴|🟡|🟢)/u', $body)) {
                    continue;
                }

                $path = (string) ($comment['path'] ?? '');
                $line = $comment['line'] ?? $comment['original_line'] ?? '?';
                $text = trim((string) preg_replace('/^(?:🔴|🟡|🟢)\s*\*\*\w+\*\*\s*—\s*/u', '', $body));

                $comments[] = "{$path}:{$line} — {$text}";
            }

            return $comments;
        } catch (Throwable) {
            return [];
        }
    }
}
