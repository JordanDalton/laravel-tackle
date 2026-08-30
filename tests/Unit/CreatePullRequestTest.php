<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Laravel\Ai\Tools\Request;
use Tackle\Support\GitHubClient;
use Tackle\Support\PathGuard;
use Tackle\Tools\CreatePullRequest;

function makePrTool(): CreatePullRequest
{
    return new CreatePullRequest(app(GitHubClient::class), app(PathGuard::class));
}

function fakeGitSuccess(string $statusOutput = ' M app/Foo.php'): void
{
    Process::fake([
        'git status --porcelain' => Process::result($statusOutput),
        'git checkout*' => Process::result(''),
        'git add -A' => Process::result(''),
        'git commit*' => Process::result(''),
        'git push*' => Process::result(''),
    ]);
}

// ---------------------------------------------------------------------------
// Not configured
// ---------------------------------------------------------------------------

it('returns not-configured message when GitHub credentials are missing', function () {
    config()->set('tackle.github.token', null);
    config()->set('tackle.github.repo', null);

    Process::fake([
        'gh*' => Process::result('', '', 1),
    ]);

    $result = makePrTool()->handle(new Request([
        'title' => 'Fix login',
        'body' => 'Fixed the login flow.',
        'branch' => 'tackle/issue-3-fix-login',
    ]));

    expect($result)->toContain('GITHUB_TOKEN');
});

// ---------------------------------------------------------------------------
// Validation
// ---------------------------------------------------------------------------

it('returns error when title is missing', function () {
    config()->set('tackle.github.token', 'ghp_token');
    config()->set('tackle.github.repo', 'acme/app');

    $result = makePrTool()->handle(new Request([
        'body' => 'Some body.',
        'branch' => 'tackle/fix',
    ]));

    expect($result)->toContain('required');
});

it('returns error when no changes to commit', function () {
    config()->set('tackle.github.token', 'ghp_token');
    config()->set('tackle.github.repo', 'acme/app');

    Process::fake([
        'git status --porcelain' => Process::result(''), // empty = no changes
    ]);

    $result = makePrTool()->handle(new Request([
        'title' => 'Fix login',
        'body' => 'Fixed.',
        'branch' => 'tackle/fix',
    ]));

    expect($result)->toContain('No changes to commit');
});

// ---------------------------------------------------------------------------
// Happy path
// ---------------------------------------------------------------------------

it('creates branch, commits, pushes, and opens a PR', function () {
    config()->set('tackle.github.token', 'ghp_token');
    config()->set('tackle.github.repo', 'acme/app');

    fakeGitSuccess();

    Http::fake([
        '*api.github.com*' => Http::response([
            'html_url' => 'https://github.com/acme/app/pull/99',
            'number' => 99,
        ], 201),
    ]);

    $result = makePrTool()->handle(new Request([
        'title' => 'Fix issue 3',
        'body' => 'Implemented the fix.',
        'branch' => 'tackle/issue-3-fix',
        'base' => 'main',
        'issue_number' => 3,
    ]));

    expect($result)->toContain('https://github.com/acme/app/pull/99');
});

it('appends Closes #N to PR body when issue_number is given', function () {
    config()->set('tackle.github.token', 'ghp_token');
    config()->set('tackle.github.repo', 'acme/app');

    fakeGitSuccess();

    Http::fake([
        '*api.github.com*' => Http::response(['html_url' => 'https://github.com/acme/app/pull/10'], 201),
    ]);

    makePrTool()->handle(new Request([
        'title' => 'My fix',
        'body' => 'Details here.',
        'branch' => 'tackle/issue-5',
        'issue_number' => 5,
    ]));

    Http::assertSent(fn ($request) => str_contains($request->body(), 'Closes #5'));
});

it('returns error when GitHub API rejects the PR', function () {
    config()->set('tackle.github.token', 'ghp_token');
    config()->set('tackle.github.repo', 'acme/app');

    fakeGitSuccess();

    Http::fake([
        '*api.github.com*' => Http::response(['message' => 'Validation Failed'], 422),
    ]);

    $result = makePrTool()->handle(new Request([
        'title' => 'Fix',
        'body' => 'Details.',
        'branch' => 'tackle/fix',
    ]));

    expect($result)->toContain('Validation Failed');
});

it('returns error when git checkout fails', function () {
    config()->set('tackle.github.token', 'ghp_token');
    config()->set('tackle.github.repo', 'acme/app');

    Process::fake([
        'git status --porcelain' => Process::result(' M app/Foo.php'),
        'git checkout*' => Process::result('', 'fatal: branch already exists', 1),
    ]);

    $result = makePrTool()->handle(new Request([
        'title' => 'Fix',
        'body' => 'Details.',
        'branch' => 'tackle/fix',
    ]));

    expect($result)->toContain('Failed to create branch');
});

// ---------------------------------------------------------------------------
// Closing references
// ---------------------------------------------------------------------------

it('does not repeat a closing reference the body already carries', function () {
    // Tackle Cloud's issue-to-task prompt tells the agent to write "Closes #N"
    // into the description, and this tool appended another — every PR the
    // factory opened closed the same issue twice.
    $tool = makePrTool();
    $method = new ReflectionMethod($tool, 'alreadyCloses');

    expect($method->invoke($tool, 'Fixes the thing.

Closes #30', 30))->toBeTrue();
});

it('recognises every closing keyword GitHub accepts', function () {
    $tool = makePrTool();
    $method = new ReflectionMethod($tool, 'alreadyCloses');

    foreach (['Closes #7', 'closed #7', 'Fixes #7', 'fixed #7', 'Resolves #7', 'resolve: #7', 'FIX #7'] as $body) {
        expect($method->invoke($tool, $body, 7))->toBeTrue("'{$body}' should count");
    }
});

it('still appends when the body only mentions the issue in passing', function () {
    $tool = makePrTool();
    $method = new ReflectionMethod($tool, 'alreadyCloses');

    // A bare reference does not close anything, so the tool must still add one.
    expect($method->invoke($tool, 'Related to #30, but see also #31.', 30))->toBeFalse();
});

it('does not confuse a different issue number', function () {
    $tool = makePrTool();
    $method = new ReflectionMethod($tool, 'alreadyCloses');

    expect($method->invoke($tool, 'Closes #300', 30))->toBeFalse()
        ->and($method->invoke($tool, 'Closes #3', 30))->toBeFalse();
});

// ---------------------------------------------------------------------------
// Verification block
// ---------------------------------------------------------------------------

it('reports the red-green proof in the PR body', function () {
    config()->set('tackle.github.token', 'ghp_token');
    config()->set('tackle.github.repo', 'acme/app');

    // A closure fake: pattern-keyed fakes proved unreliable for array
    // commands, and this test is about the PR body, not fake plumbing.
    $testRuns = 0;
    Process::fake(function ($process) use (&$testRuns) {
        $command = is_array($process->command) ? implode(' ', $process->command) : $process->command;

        if (str_contains($command, '--porcelain')) {
            return Process::result(" M app/Foo.php\n?? tests/FooTest.php\n");
        }

        if (str_contains($command, 'artisan test') || str_contains($command, 'vendor/bin/pest')) {
            // Green with the fix, red without it.
            return ++$testRuns === 1 ? Process::result('ok') : Process::result('fail', exitCode: 1);
        }

        return Process::result('');
    });

    Http::fake(['*api.github.com*' => Http::response(['html_url' => 'https://github.com/acme/app/pull/9'], 201)]);

    makePrTool()->handle(new Request([
        'title' => 'Fix',
        'body' => 'Details.',
        'branch' => 'tackle/fix',
    ]));

    Http::assertSent(fn ($request) => str_contains($request->body(), 'Verification')
        && str_contains($request->body(), 'fails without the change')
        // No slash: the body is raw JSON, where the path is tests\/FooTest.php.
        && str_contains($request->body(), 'FooTest.php'));
});

it('says nothing about verification when no test was added', function () {
    config()->set('tackle.github.token', 'ghp_token');
    config()->set('tackle.github.repo', 'acme/app');

    fakeGitSuccess(); // ' M app/Foo.php' — a change with no test

    Http::fake(['*api.github.com*' => Http::response(['html_url' => 'https://github.com/acme/app/pull/9'], 201)]);

    makePrTool()->handle(new Request(['title' => 'Fix', 'body' => 'Details.', 'branch' => 'tackle/fix']));

    Http::assertSent(fn ($request) => ! str_contains($request->body(), 'Verification'));
});
