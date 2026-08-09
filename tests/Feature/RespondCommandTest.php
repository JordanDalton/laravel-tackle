<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

function fakeRespondPr(array $overrides = []): void
{
    Http::fake(function ($request) use ($overrides) {
        if (str_contains($request->url(), '/pulls/comments/555')) {
            return Http::response([
                'id' => 555,
                'user' => ['login' => 'jordan'],
                'body' => '@tackle fix this',
                'path' => 'app/A.php',
                'line' => 5,
            ], 200);
        }

        if (str_contains($request->url(), '/pulls/42/comments')) {
            return Http::response([], 200);
        }

        if ($request->hasHeader('Accept', 'application/vnd.github.v3.diff')) {
            return Http::response("diff --git a/a.php b/a.php\n", 200);
        }

        if ($request->method() === 'POST') {
            return Http::response(['id' => 1], 201);
        }

        return Http::response(array_merge([
            'title' => 'T',
            'body' => '',
            'head' => ['ref' => 'feat', 'sha' => 'abc9999', 'repo' => ['full_name' => 'acme/app']],
            'base' => ['ref' => 'main'],
            'html_url' => 'https://github.com/acme/app/pull/42',
        ], $overrides), 200);
    });
}

beforeEach(function () {
    config()->set('tackle.github.token', 'ghp_token');
    config()->set('tackle.github.repo', 'acme/app');
});

it('ai:respond command is registered', function () {
    expect(Artisan::all())->toHaveKey('ai:respond');
});

it('requires a numeric --pr', function () {
    $this->artisan('ai:respond', ['--comment-id' => '5'])
        ->expectsOutputToContain('--pr option is required')
        ->assertExitCode(1);
});

it('requires a numeric --comment-id', function () {
    $this->artisan('ai:respond', ['--pr' => '42'])
        ->expectsOutputToContain('--comment-id option is required')
        ->assertExitCode(1);
});

it('rejects an unknown --comment-type', function () {
    $this->artisan('ai:respond', ['--pr' => '42', '--comment-id' => '5', '--comment-type' => 'discussion'])
        ->expectsOutputToContain('must be review or issue')
        ->assertExitCode(1);
});

it('refuses fork PRs and replies instead of pushing', function () {
    fakeRespondPr(['head' => ['ref' => 'feat', 'sha' => 'abc9999', 'repo' => ['full_name' => 'stranger/fork']]]);

    $this->artisan('ai:respond', ['--pr' => '42', '--comment-id' => '555'])
        ->expectsOutputToContain('from a fork')
        ->assertExitCode(1);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'pulls/42/comments/555/replies')
        && str_contains($request->data()['body'], 'fork'));
});

it('refuses to run when the checkout does not match the PR head', function () {
    fakeRespondPr();

    Process::fake(['*' => Process::result("differentsha000\n")]);

    $this->artisan('ai:respond', ['--pr' => '42', '--comment-id' => '555'])
        ->expectsOutputToContain('does not match the PR head')
        ->assertExitCode(1);
});
