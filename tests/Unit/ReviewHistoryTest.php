<?php

use Illuminate\Support\Facades\Http;
use Tackle\Review\ReviewHistory;
use Tackle\Support\GitHubClient;

function makeHistory(): ReviewHistory
{
    return new ReviewHistory(app(GitHubClient::class));
}

beforeEach(function () {
    config()->set('tackle.github.token', 'ghp_token');
    config()->set('tackle.github.repo', 'acme/app');
});

it('finds the last reviewed sha from the marker', function () {
    Http::fake([
        'api.github.com/repos/acme/app/pulls/42/reviews*' => Http::response([
            ['body' => "## Tackle AI Review\n\n<!-- tackle-review:sha=aaa1111 -->"],
            ['body' => 'A human review with no marker'],
            ['body' => "## Tackle AI Review\n\n<!-- tackle-review:sha=bbb2222 -->"],
        ], 200),
    ]);

    expect(makeHistory()->lastReviewedSha(42))->toBe('bbb2222');
});

it('returns null when no tackle review exists', function () {
    Http::fake([
        'api.github.com/*' => Http::response([
            ['body' => 'Just a human review'],
        ], 200),
    ]);

    expect(makeHistory()->lastReviewedSha(42))->toBeNull();
});

it('returns null when the reviews request fails', function () {
    Http::fake(['api.github.com/*' => Http::response([], 500)]);

    expect(makeHistory()->lastReviewedSha(42))->toBeNull();
});

it('fetches the delta diff between two shas', function () {
    Http::fake([
        'api.github.com/repos/acme/app/compare/aaa1111...bbb2222' => Http::response("diff --git a/x.php b/x.php\n", 200),
    ]);

    expect(makeHistory()->deltaDiff('aaa1111', 'bbb2222'))->toBe('diff --git a/x.php b/x.php');
});

it('returns null for an unresolvable compare (force push)', function () {
    Http::fake(['api.github.com/*' => Http::response('Not Found', 404)]);

    expect(makeHistory()->deltaDiff('gone000', 'bbb2222'))->toBeNull();
});

it('collects previous tackle inline comments and skips human ones', function () {
    Http::fake([
        'api.github.com/repos/acme/app/pulls/42/comments*' => Http::response([
            ['body' => '🔴 **Critical** — Slug is never validated.', 'path' => 'app/Models/Post.php', 'line' => 12],
            ['body' => 'nit: rename this', 'path' => 'app/Models/Post.php', 'line' => 20],
            ['body' => '🟡 **Warning** — Missing index.', 'path' => 'database/migrations/x.php', 'line' => 8],
        ], 200),
    ]);

    $comments = makeHistory()->previousComments(42);

    expect($comments)->toHaveCount(2)
        ->and($comments[0])->toBe('app/Models/Post.php:12 — Slug is never validated.')
        ->and($comments[1])->toBe('database/migrations/x.php:8 — Missing index.');
});

it('builds a marker containing the sha', function () {
    expect(ReviewHistory::marker('abc123'))->toBe('<!-- tackle-review:sha=abc123 -->');
});
