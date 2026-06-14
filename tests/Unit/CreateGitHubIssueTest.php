<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Laravel\Ai\Tools\Request;
use Tackle\Support\GitHubClient;
use Tackle\Tools\CreateGitHubIssue;

function makeIssueTool(): CreateGitHubIssue
{
    return new CreateGitHubIssue(app(GitHubClient::class));
}

it('returns not-configured message when credentials are missing', function () {
    config()->set('ai-code.github.token', null);
    config()->set('ai-code.github.repo', null);

    Process::fake(['gh*' => Process::result('', '', 1)]);

    $result = makeIssueTool()->handle(new Request([
        'title' => 'Bug: payment fails',
    ]));

    expect($result)->toContain('GITHUB_TOKEN');
});

it('returns error when title is missing', function () {
    config()->set('ai-code.github.token', 'ghp_token');
    config()->set('ai-code.github.repo', 'acme/app');

    $result = makeIssueTool()->handle(new Request([]));

    expect($result)->toContain('required');
});

it('creates an issue and returns the URL', function () {
    config()->set('ai-code.github.token', 'ghp_token');
    config()->set('ai-code.github.repo', 'acme/app');

    Http::fake([
        '*api.github.com*' => Http::response([
            'number'   => 7,
            'html_url' => 'https://github.com/acme/app/issues/7',
        ], 201),
    ]);

    $result = makeIssueTool()->handle(new Request([
        'title' => 'Bug: payment fails on empty cart',
        'body'  => 'Steps to reproduce: add nothing to cart, proceed to checkout.',
    ]));

    expect($result)
        ->toContain('#7')
        ->toContain('https://github.com/acme/app/issues/7');
});

it('sends labels when provided', function () {
    config()->set('ai-code.github.token', 'ghp_token');
    config()->set('ai-code.github.repo', 'acme/app');

    Http::fake([
        '*api.github.com*' => Http::response(['number' => 8, 'html_url' => 'https://github.com/acme/app/issues/8'], 201),
    ]);

    makeIssueTool()->handle(new Request([
        'title'  => 'Improve logging',
        'labels' => ['enhancement', 'logging'],
    ]));

    Http::assertSent(fn ($request) =>
        str_contains($request->body(), 'enhancement') &&
        str_contains($request->body(), 'logging')
    );
});

it('returns error when GitHub API rejects the request', function () {
    config()->set('ai-code.github.token', 'ghp_token');
    config()->set('ai-code.github.repo', 'acme/app');

    Http::fake([
        '*api.github.com*' => Http::response(['message' => 'Validation Failed'], 422),
    ]);

    $result = makeIssueTool()->handle(new Request([
        'title' => 'Some issue',
    ]));

    expect($result)->toContain('Validation Failed');
});
