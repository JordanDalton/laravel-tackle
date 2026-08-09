<?php

namespace Tackle\Review;

use RuntimeException;
use Tackle\Support\GitHubClient;
use Tackle\Support\Utf8;

class CommentFetcher
{
    public function __construct(private GitHubClient $client) {}

    /**
     * Fetch the triggering comment and, for inline review comments, the rest
     * of its thread so the agent sees the whole conversation.
     *
     * @throws RuntimeException when the comment cannot be fetched
     */
    public function fetch(int $prNumber, int $commentId, string $type): CommentThread
    {
        return $type === 'issue'
            ? $this->issueComment($commentId)
            : $this->reviewComment($prNumber, $commentId);
    }

    private function reviewComment(int $prNumber, int $commentId): CommentThread
    {
        $repo = $this->client->repo();

        $response = $this->client->get("repos/{$repo}/pulls/comments/{$commentId}");

        if (! $response->successful()) {
            throw new RuntimeException("Could not fetch review comment {$commentId} (HTTP {$response->status()}).");
        }

        $comment = $response->json();
        $rootId = (int) ($comment['in_reply_to_id'] ?? 0) ?: $commentId;

        return new CommentThread(
            type: 'review',
            commentId: $commentId,
            author: (string) ($comment['user']['login'] ?? '?'),
            instruction: Utf8::clean(trim((string) ($comment['body'] ?? ''))),
            thread: $this->threadFor($prNumber, $rootId, $commentId),
            path: (string) ($comment['path'] ?? ''),
            line: $comment['line'] ?? $comment['original_line'] ?? null,
            diffHunk: Utf8::clean((string) ($comment['diff_hunk'] ?? '')),
        );
    }

    private function issueComment(int $commentId): CommentThread
    {
        $repo = $this->client->repo();

        $response = $this->client->get("repos/{$repo}/issues/comments/{$commentId}");

        if (! $response->successful()) {
            throw new RuntimeException("Could not fetch comment {$commentId} (HTTP {$response->status()}).");
        }

        $comment = $response->json();

        return new CommentThread(
            type: 'issue',
            commentId: $commentId,
            author: (string) ($comment['user']['login'] ?? '?'),
            instruction: Utf8::clean(trim((string) ($comment['body'] ?? ''))),
        );
    }

    /**
     * Every earlier comment in the same review thread, oldest first, excluding
     * the triggering comment itself.
     *
     * @return string[]
     */
    private function threadFor(int $prNumber, int $rootId, int $triggerId): array
    {
        $repo = $this->client->repo();

        $response = $this->client->get("repos/{$repo}/pulls/{$prNumber}/comments", ['per_page' => 100]);

        if (! $response->successful()) {
            return [];
        }

        $thread = [];

        foreach ($response->json() as $comment) {
            $id = (int) ($comment['id'] ?? 0);
            $inThread = $id === $rootId || (int) ($comment['in_reply_to_id'] ?? 0) === $rootId;

            if (! $inThread || $id === $triggerId) {
                continue;
            }

            $author = (string) ($comment['user']['login'] ?? '?');
            $body = Utf8::clean(trim((string) ($comment['body'] ?? '')));

            $thread[] = "{$author}: {$body}";
        }

        return $thread;
    }
}
