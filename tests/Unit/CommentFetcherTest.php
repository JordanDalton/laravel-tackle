<?php

use Illuminate\Support\Facades\Http;
use Tackle\Review\CommentFetcher;
use Tackle\Support\GitHubClient;

function makeCommentFetcher(): CommentFetcher
{
    return new CommentFetcher(app(GitHubClient::class));
}

beforeEach(function () {
    config()->set('tackle.github.token', 'ghp_token');
    config()->set('tackle.github.repo', 'acme/app');
});

it('fetches a review comment with its thread', function () {
    Http::fake([
        'api.github.com/repos/acme/app/pulls/comments/555' => Http::response([
            'id' => 555,
            'in_reply_to_id' => 100,
            'user' => ['login' => 'jordan'],
            'body' => '@tackle fix this',
            'path' => 'app/Models/Post.php',
            'line' => 12,
            'diff_hunk' => "@@ -10,3 +10,4 @@\n+    public function slug()",
        ], 200),
        'api.github.com/repos/acme/app/pulls/42/comments*' => Http::response([
            ['id' => 100, 'in_reply_to_id' => null, 'user' => ['login' => 'tackle-bot'], 'body' => '🔴 **Critical** — Slug never validated.'],
            ['id' => 555, 'in_reply_to_id' => 100, 'user' => ['login' => 'jordan'], 'body' => '@tackle fix this'],
            ['id' => 999, 'in_reply_to_id' => null, 'user' => ['login' => 'other'], 'body' => 'unrelated thread'],
        ], 200),
    ]);

    $comment = makeCommentFetcher()->fetch(42, 555, 'review');

    expect($comment->type)->toBe('review')
        ->and($comment->author)->toBe('jordan')
        ->and($comment->instruction)->toBe('@tackle fix this')
        ->and($comment->path)->toBe('app/Models/Post.php')
        ->and($comment->line)->toBe(12)
        ->and($comment->diffHunk)->toContain('public function slug')
        ->and($comment->thread)->toHaveCount(1)
        ->and($comment->thread[0])->toContain('Slug never validated');
});

it('fetches an issue comment without thread or location', function () {
    Http::fake([
        'api.github.com/repos/acme/app/issues/comments/777' => Http::response([
            'id' => 777,
            'user' => ['login' => 'jordan'],
            'body' => '@tackle add a test for the new endpoint',
        ], 200),
    ]);

    $comment = makeCommentFetcher()->fetch(42, 777, 'issue');

    expect($comment->type)->toBe('issue')
        ->and($comment->instruction)->toBe('@tackle add a test for the new endpoint')
        ->and($comment->path)->toBe('')
        ->and($comment->thread)->toBe([]);
});

it('throws when the comment cannot be fetched', function () {
    Http::fake(['api.github.com/*' => Http::response([], 404)]);

    makeCommentFetcher()->fetch(42, 555, 'review');
})->throws(RuntimeException::class, 'Could not fetch review comment 555');
