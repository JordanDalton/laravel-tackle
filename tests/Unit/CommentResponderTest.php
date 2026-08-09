<?php

use Illuminate\Support\Facades\Http;
use Tackle\Review\CommentResponder;
use Tackle\Review\CommentThread;
use Tackle\Support\GitHubClient;

function makeResponder(): CommentResponder
{
    return new CommentResponder(app(GitHubClient::class));
}

function reviewTrigger(): CommentThread
{
    return new CommentThread(type: 'review', commentId: 555, author: 'jordan', instruction: '@tackle fix this');
}

function issueTrigger(): CommentThread
{
    return new CommentThread(type: 'issue', commentId: 777, author: 'jordan', instruction: '@tackle do it');
}

beforeEach(function () {
    config()->set('tackle.github.token', 'ghp_token');
    config()->set('tackle.github.repo', 'acme/app');
});

it('replies in-thread to review comments', function () {
    Http::fake(['api.github.com/*' => Http::response(['id' => 1], 201)]);

    expect(makeResponder()->reply(42, reviewTrigger(), 'Done.'))->toBeTrue();

    Http::assertSent(fn ($request) => str_contains($request->url(), 'pulls/42/comments/555/replies')
        && $request->data()['body'] === 'Done.');
});

it('replies as a conversation comment for issue comments', function () {
    Http::fake(['api.github.com/*' => Http::response(['id' => 1], 201)]);

    expect(makeResponder()->reply(42, issueTrigger(), 'Done.'))->toBeTrue();

    Http::assertSent(fn ($request) => str_contains($request->url(), 'issues/42/comments')
        && $request->data()['body'] === 'Done.');
});

it('returns false when the reply fails instead of throwing', function () {
    Http::fake(['api.github.com/*' => Http::response([], 500)]);

    expect(makeResponder()->reply(42, reviewTrigger(), 'Done.'))->toBeFalse();
});

it('acknowledges with an eyes reaction and swallows failures', function () {
    Http::fake(['api.github.com/*' => Http::response([], 500)]);

    makeResponder()->acknowledge(reviewTrigger());

    Http::assertSent(fn ($request) => str_contains($request->url(), 'pulls/comments/555/reactions')
        && $request->data()['content'] === 'eyes');
});
