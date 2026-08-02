<?php

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Tools\Request;
use Tackle\Support\GitHubClient;
use Tackle\Tools\ReadPullRequest;

function makePrReadTool(): ReadPullRequest
{
    return new ReadPullRequest(app(GitHubClient::class));
}

it('returns not-configured message when credentials are missing', function () {
    config()->set('tackle.github.token', null);
    config()->set('tackle.github.repo', null);

    Http::fake(['*' => Http::response([], 401)]);

    $result = makePrReadTool()->handle(new Request(['pr_number' => 6]));

    expect($result)->toContain('GitHub is not configured');
});

it('lists open PRs when pr_number is omitted', function () {
    config()->set('tackle.github.token', 'ghp_token');
    config()->set('tackle.github.repo', 'acme/app');

    Http::fake([
        '*api.github.com*/pulls*' => Http::response([], 200),
    ]);

    $result = makePrReadTool()->handle(new Request([]));

    // No error — falls through to the list path
    expect($result)->not->toContain('pr_number is required');
});

it('returns pr details including branch name', function () {
    config()->set('tackle.github.token', 'ghp_token');
    config()->set('tackle.github.repo', 'acme/app');

    Http::fake([
        '*api.github.com*/pulls/6' => Http::response([
            'number' => 6,
            'title' => 'Return jordandalton from controller',
            'state' => 'open',
            'user' => ['login' => 'JordanDalton'],
            'head' => ['ref' => 'tackle/issue-6-return-jordandalton'],
            'base' => ['ref' => 'main'],
            'html_url' => 'https://github.com/acme/app/pull/6',
            'body' => 'Updates the return value.',
        ], 200),
        '*api.github.com*/issues/6/comments*' => Http::response([], 200),
    ]);

    $result = makePrReadTool()->handle(new Request(['pr_number' => 6]));

    expect($result)
        ->toContain('PR #6')
        ->toContain('Return jordandalton from controller')
        ->toContain('Branch: tackle/issue-6-return-jordandalton → main')
        ->toContain('open')
        ->toContain('JordanDalton');
});

it('includes comments in the output', function () {
    config()->set('tackle.github.token', 'ghp_token');
    config()->set('tackle.github.repo', 'acme/app');

    Http::fake([
        '*api.github.com*/pulls/6' => Http::response([
            'number' => 6,
            'title' => 'My PR',
            'state' => 'open',
            'user' => ['login' => 'JordanDalton'],
            'head' => ['ref' => 'tackle/my-branch'],
            'base' => ['ref' => 'main'],
            'html_url' => 'https://github.com/acme/app/pull/6',
            'body' => '',
        ], 200),
        '*api.github.com*/issues/6/comments*' => Http::response([
            ['user' => ['login' => 'reviewer'], 'created_at' => '2026-01-01T00:00:00Z', 'body' => 'LGTM'],
        ], 200),
    ]);

    $result = makePrReadTool()->handle(new Request(['pr_number' => 6]));

    expect($result)->toContain('reviewer')->toContain('LGTM');
});

it('lists open pull requests when no pr_number is given', function () {
    config()->set('tackle.github.token', 'ghp_token');
    config()->set('tackle.github.repo', 'acme/app');

    Http::fake([
        '*api.github.com*/pulls*' => Http::response([
            [
                'number' => 6,
                'title' => 'Return jordandalton from controller',
                'user' => ['login' => 'JordanDalton'],
                'head' => ['ref' => 'tackle/issue-6-return-jordandalton'],
                'base' => ['ref' => 'main'],
                'updated_at' => '2026-06-14T10:00:00Z',
            ],
            [
                'number' => 5,
                'title' => 'Add slug field',
                'user' => ['login' => 'JordanDalton'],
                'head' => ['ref' => 'tackle/issue-5-slug'],
                'base' => ['ref' => 'main'],
                'updated_at' => '2026-06-13T08:00:00Z',
            ],
        ], 200),
    ]);

    $result = makePrReadTool()->handle(new Request([]));

    expect($result)
        ->toContain('#6')
        ->toContain('tackle/issue-6-return-jordandalton → main')
        ->toContain('#5')
        ->toContain('tackle/issue-5-slug → main');
});

it('returns no-PRs message when list is empty', function () {
    config()->set('tackle.github.token', 'ghp_token');
    config()->set('tackle.github.repo', 'acme/app');

    Http::fake([
        '*api.github.com*/pulls*' => Http::response([], 200),
    ]);

    $result = makePrReadTool()->handle(new Request([]));

    expect($result)->toBe('No open pull requests found.');
});

it('returns error when GitHub API returns non-200', function () {
    config()->set('tackle.github.token', 'ghp_token');
    config()->set('tackle.github.repo', 'acme/app');

    Http::fake([
        '*api.github.com*/pulls/99' => Http::response(['message' => 'Not Found'], 404),
        '*api.github.com*/issues/99/comments' => Http::response([], 200),
    ]);

    $result = makePrReadTool()->handle(new Request(['pr_number' => 99]));

    expect($result)->toContain('Could not fetch PR #99');
});
