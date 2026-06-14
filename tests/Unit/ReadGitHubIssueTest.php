<?php

use Illuminate\Support\Facades\Http;
use Tackle\Healing\GitHubReader;
use Tackle\Tools\ReadGitHubIssue;
use Laravel\Ai\Tools\Request;

// ---------------------------------------------------------------------------
// GitHubReader — credential resolution
// ---------------------------------------------------------------------------

it('returns empty string when token is not configured', function () {
    config()->set('ai-code.github.token', null);
    config()->set('ai-code.github.repo', 'acme/app');

    \Illuminate\Support\Facades\Process::fake(['gh*' => \Illuminate\Support\Facades\Process::result('', '', 1)]);

    $reader = app(GitHubReader::class);

    expect($reader->forIssue(42))->toBe('');
    expect($reader->recent())->toBe('');
});

it('returns empty string when repo is not configured', function () {
    config()->set('ai-code.github.token', 'ghp_token');
    config()->set('ai-code.github.repo', null);

    \Illuminate\Support\Facades\Process::fake(['gh*' => \Illuminate\Support\Facades\Process::result('', '', 1)]);

    $reader = app(GitHubReader::class);

    expect($reader->forIssue(42))->toBe('');
    expect($reader->recent())->toBe('');
});

// ---------------------------------------------------------------------------
// GitHubReader::forIssue — HTTP behaviour
// ---------------------------------------------------------------------------

it('returns empty string when GitHub API returns a non-2xx response for forIssue', function () {
    config()->set('ai-code.github.token', 'ghp_token');
    config()->set('ai-code.github.repo', 'acme/app');

    Http::fake([
        'api.github.com/*' => Http::response([], 401),
    ]);

    $reader = app(GitHubReader::class);

    expect($reader->forIssue(42))->toBe('');
});

it('formats a GitHub issue with body and comments correctly', function () {
    config()->set('ai-code.github.token', 'ghp_token');
    config()->set('ai-code.github.repo', 'acme/app');

    Http::fake([
        '*issues/42/comments*' => Http::response([
            [
                'user'       => ['login' => 'reviewer'],
                'created_at' => '2026-06-14T09:00:00Z',
                'body'       => 'This is a comment.',
            ],
        ], 200),
        '*issues/42*' => Http::response([
            'number'  => 42,
            'title'   => 'Fix the payment flow',
            'state'   => 'open',
            'user'    => ['login' => 'jordan'],
            'body'    => 'The payment flow is broken when the cart is empty.',
            'labels'  => [['name' => 'bug'], ['name' => 'high-priority']],
        ], 200),
    ]);

    $reader = app(GitHubReader::class);
    $result = $reader->forIssue(42);

    expect($result)
        ->toContain('GitHub Issue #42')
        ->toContain('Fix the payment flow')
        ->toContain('open')
        ->toContain('jordan')
        ->toContain('bug')
        ->toContain('high-priority')
        ->toContain('payment flow is broken')
        ->toContain('reviewer')
        ->toContain('This is a comment.');
});

it('formats an issue without labels or comments', function () {
    config()->set('ai-code.github.token', 'ghp_token');
    config()->set('ai-code.github.repo', 'acme/app');

    Http::fake([
        '*issues/7/comments*' => Http::response([], 200),
        '*issues/7*' => Http::response([
            'number'  => 7,
            'title'   => 'Simple issue',
            'state'   => 'open',
            'user'    => ['login' => 'alice'],
            'body'    => 'Some description.',
            'labels'  => [],
        ], 200),
    ]);

    $reader = app(GitHubReader::class);
    $result = $reader->forIssue(7);

    expect($result)
        ->toContain('GitHub Issue #7')
        ->not->toContain('Labels:')
        ->not->toContain('--- Comments ---');
});

// ---------------------------------------------------------------------------
// GitHubReader::recent — HTTP behaviour
// ---------------------------------------------------------------------------

it('returns empty string when GitHub API returns a non-2xx response for recent()', function () {
    config()->set('ai-code.github.token', 'ghp_token');
    config()->set('ai-code.github.repo', 'acme/app');

    Http::fake([
        'api.github.com/*' => Http::response([], 403),
    ]);

    $reader = app(GitHubReader::class);

    expect($reader->recent())->toBe('');
});

it('formats recent issues correctly and excludes pull requests', function () {
    config()->set('ai-code.github.token', 'ghp_token');
    config()->set('ai-code.github.repo', 'acme/app');

    Http::fake([
        'api.github.com/*' => Http::response([
            ['number' => 10, 'title' => 'Bug in login', 'updated_at' => '2026-06-14T08:00:00Z', 'labels' => [['name' => 'bug']]],
            ['number' => 11, 'title' => 'PR not an issue', 'updated_at' => '2026-06-14T07:00:00Z', 'labels' => [], 'pull_request' => ['url' => 'https://...']],
        ], 200),
    ]);

    $reader = app(GitHubReader::class);
    $result = $reader->recent();

    expect($result)
        ->toContain('#10')
        ->toContain('Bug in login')
        ->toContain('[bug]')
        ->not->toContain('#11');
});

it('returns no issues message when API returns empty array', function () {
    config()->set('ai-code.github.token', 'ghp_token');
    config()->set('ai-code.github.repo', 'acme/app');

    Http::fake([
        'api.github.com/*' => Http::response([], 200),
    ]);

    $reader = app(GitHubReader::class);

    expect($reader->recent())->toBe('No open GitHub issues found.');
});

// ---------------------------------------------------------------------------
// ReadGitHubIssue tool — handle() routing
// ---------------------------------------------------------------------------

it('ReadGitHubIssue returns not-configured message when credentials are missing', function () {
    config()->set('ai-code.github.token', null);
    config()->set('ai-code.github.repo', null);

    \Illuminate\Support\Facades\Process::fake(['gh*' => \Illuminate\Support\Facades\Process::result('', '', 1)]);

    $tool   = app(ReadGitHubIssue::class);
    $result = $tool->handle(new Request(['issue_number' => 42]));

    expect($result)->toContain('GITHUB_TOKEN');
});

it('ReadGitHubIssue routes to recent() when no issue_number is given', function () {
    config()->set('ai-code.github.token', null);
    config()->set('ai-code.github.repo', null);

    \Illuminate\Support\Facades\Process::fake(['gh*' => \Illuminate\Support\Facades\Process::result('', '', 1)]);

    $tool   = app(ReadGitHubIssue::class);
    $result = $tool->handle(new Request([]));

    expect($result)->toContain('GITHUB_TOKEN');
});

it('ReadGitHubIssue clamps limit between 1 and 25', function () {
    config()->set('ai-code.github.token', 'ghp_token');
    config()->set('ai-code.github.repo', 'acme/app');

    Http::fake([
        'api.github.com/*' => Http::response([], 200),
    ]);

    $tool   = app(ReadGitHubIssue::class);
    $result = $tool->handle(new Request(['limit' => 999]));

    expect($result)->toBeString();
});
