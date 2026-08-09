<?php

use Illuminate\Support\Facades\Http;
use Tackle\Review\DiffLineIndex;
use Tackle\Review\Finding;
use Tackle\Review\ParsedReview;
use Tackle\Review\PullRequest;
use Tackle\Review\ReviewPublisher;
use Tackle\Support\GitHubClient;

function makePublisher(): ReviewPublisher
{
    return new ReviewPublisher(app(GitHubClient::class));
}

function makePr(string $diff = ''): PullRequest
{
    return new PullRequest(
        number: 42,
        title: 'Add slugs',
        body: 'Adds slug support.',
        headRef: 'feat/slugs',
        headSha: 'abc123',
        baseRef: 'main',
        url: 'https://github.com/acme/app/pull/42',
        diff: $diff,
    );
}

function slugDiff(): string
{
    return implode("\n", [
        'diff --git a/app/Models/Post.php b/app/Models/Post.php',
        '--- a/app/Models/Post.php',
        '+++ b/app/Models/Post.php',
        '@@ -1,2 +1,3 @@',
        ' class Post',
        '+    // new line 2',
        ' {',
    ]);
}

beforeEach(function () {
    config()->set('tackle.github.token', 'ghp_token');
    config()->set('tackle.github.repo', 'acme/app');
});

it('throws when GitHub is not configured', function () {
    config()->set('tackle.github.repo', null);

    $review = new ParsedReview('lgtm', []);

    makePublisher()->publish(makePr(), $review, new DiffLineIndex(''));
})->throws(RuntimeException::class, 'GitHub is not configured');

it('posts anchorable findings as inline comments', function () {
    Http::fake([
        'api.github.com/repos/acme/app/pulls/42/reviews' => Http::response(['html_url' => 'https://github.com/acme/app/pull/42#pullrequestreview-1'], 200),
    ]);

    $review = new ParsedReview('needs_changes', [
        new Finding('app/Models/Post.php', 2, 'critical', 'Slug is never validated.'),
    ]);

    $url = makePublisher()->publish(makePr(slugDiff()), $review, new DiffLineIndex(slugDiff()));

    expect($url)->toBe('https://github.com/acme/app/pull/42#pullrequestreview-1');

    Http::assertSent(function ($request) {
        $data = $request->data();

        return str_contains($request->url(), 'pulls/42/reviews')
            && $data['commit_id'] === 'abc123'
            && $data['event'] === 'COMMENT'
            && count($data['comments']) === 1
            && $data['comments'][0]['path'] === 'app/Models/Post.php'
            && $data['comments'][0]['line'] === 2
            && $data['comments'][0]['side'] === 'RIGHT'
            && str_contains($data['comments'][0]['body'], 'Critical')
            && str_contains($data['body'], 'Needs changes');
    });
});

it('folds unanchorable findings into the review body', function () {
    Http::fake([
        'api.github.com/*' => Http::response(['html_url' => 'https://example.com/review'], 200),
    ]);

    $review = new ParsedReview('lgtm_with_notes', [
        new Finding('app/Models/Post.php', 999, 'warning', 'Line outside the diff.'),
    ]);

    makePublisher()->publish(makePr(slugDiff()), $review, new DiffLineIndex(slugDiff()));

    Http::assertSent(function ($request) {
        $data = $request->data();

        return ! isset($data['comments'])
            && str_contains($data['body'], 'app/Models/Post.php:999')
            && str_contains($data['body'], 'Line outside the diff.');
    });
});

it('posts a clean-review body when there are no findings', function () {
    Http::fake([
        'api.github.com/*' => Http::response(['html_url' => 'https://example.com/review'], 200),
    ]);

    makePublisher()->publish(makePr(slugDiff()), new ParsedReview('lgtm', []), new DiffLineIndex(slugDiff()));

    Http::assertSent(function ($request) {
        $data = $request->data();

        return str_contains($data['body'], 'LGTM')
            && str_contains($data['body'], 'No issues found');
    });
});

it('throws when the GitHub API rejects the review', function () {
    Http::fake([
        'api.github.com/*' => Http::response(['message' => 'Validation Failed'], 422),
    ]);

    makePublisher()->publish(makePr(slugDiff()), new ParsedReview('lgtm', []), new DiffLineIndex(slugDiff()));
})->throws(RuntimeException::class, 'HTTP 422');

it('embeds the reviewed-sha marker in the review body', function () {
    Http::fake([
        'api.github.com/*' => Http::response(['html_url' => 'https://example.com/review'], 200),
    ]);

    makePublisher()->publish(makePr(slugDiff()), new ParsedReview('lgtm', []), new DiffLineIndex(slugDiff()));

    Http::assertSent(fn ($request) => str_contains($request->data()['body'], '<!-- tackle-review:sha=abc123 -->'));
});

it('labels incremental reviews as follow-ups', function () {
    Http::fake([
        'api.github.com/*' => Http::response(['html_url' => 'https://example.com/review'], 200),
    ]);

    makePublisher()->publish(makePr(slugDiff()), new ParsedReview('lgtm', []), new DiffLineIndex(slugDiff()), incremental: true);

    Http::assertSent(fn ($request) => str_contains($request->data()['body'], 'Follow-up review'));
});

it('falls back to the PR url when the response has no html_url', function () {
    Http::fake([
        'api.github.com/*' => Http::response([], 200),
    ]);

    $url = makePublisher()->publish(makePr(slugDiff()), new ParsedReview('lgtm', []), new DiffLineIndex(slugDiff()));

    expect($url)->toBe('https://github.com/acme/app/pull/42');
});
