<?php

use Illuminate\Support\Facades\Http;
use Tackle\Review\PullRequestFetcher;
use Tackle\Support\GitHubClient;

function makeFetcher(): PullRequestFetcher
{
    return new PullRequestFetcher(app(GitHubClient::class));
}

it('throws when GitHub is not configured', function () {
    config()->set('tackle.github.token', 'ghp_token');
    config()->set('tackle.github.repo', null);

    makeFetcher()->fetch(42);
})->throws(RuntimeException::class, 'GitHub is not configured');

it('fetches PR metadata and the API diff', function () {
    config()->set('tackle.github.token', 'ghp_token');
    config()->set('tackle.github.repo', 'acme/app');

    Http::fake(function ($request) {
        if ($request->hasHeader('Accept', 'application/vnd.github.v3.diff')) {
            return Http::response("diff --git a/a.php b/a.php\n", 200);
        }

        return Http::response([
            'title' => 'Add slugs',
            'body' => 'Adds slug support.',
            'head' => ['ref' => 'feat/slugs', 'sha' => 'abc123'],
            'base' => ['ref' => 'main'],
            'html_url' => 'https://github.com/acme/app/pull/42',
        ], 200);
    });

    $pr = makeFetcher()->fetch(42);

    expect($pr->number)->toBe(42)
        ->and($pr->title)->toBe('Add slugs')
        ->and($pr->headRef)->toBe('feat/slugs')
        ->and($pr->headSha)->toBe('abc123')
        ->and($pr->baseRef)->toBe('main')
        ->and($pr->url)->toBe('https://github.com/acme/app/pull/42')
        ->and($pr->diff)->toBe('diff --git a/a.php b/a.php');
});

it('throws when the PR cannot be fetched', function () {
    config()->set('tackle.github.token', 'ghp_token');
    config()->set('tackle.github.repo', 'acme/app');

    Http::fake(['*' => Http::response(['message' => 'Not Found'], 404)]);

    makeFetcher()->fetch(9999);
})->throws(RuntimeException::class, 'Could not fetch PR #9999');
