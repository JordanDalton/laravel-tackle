<?php

namespace Tackle\Review;

use Tackle\Support\GitHubClient;
use Throwable;

class CommentResponder
{
    public function __construct(private GitHubClient $client) {}

    /**
     * Reply where the trigger came from: threaded under an inline review
     * comment, or as a new conversation comment for issue comments (which
     * GitHub cannot thread).
     *
     * @return bool whether the reply was posted
     */
    public function reply(int $prNumber, CommentThread $comment, string $body): bool
    {
        $repo = $this->client->repo();

        try {
            $response = $comment->type === 'review'
                ? $this->client->post("repos/{$repo}/pulls/{$prNumber}/comments/{$comment->commentId}/replies", ['body' => $body])
                : $this->client->post("repos/{$repo}/issues/{$prNumber}/comments", ['body' => $body]);

            return $response->successful();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Best-effort 👀 on the trigger comment so the requester knows Tackle is
     * on it. Failures are ignored — the reaction is a nicety, not the result.
     */
    public function acknowledge(CommentThread $comment): void
    {
        $repo = $this->client->repo();

        $path = $comment->type === 'review'
            ? "repos/{$repo}/pulls/comments/{$comment->commentId}/reactions"
            : "repos/{$repo}/issues/comments/{$comment->commentId}/reactions";

        try {
            $this->client->post($path, ['content' => 'eyes']);
        } catch (Throwable) {
            // ignore
        }
    }
}
