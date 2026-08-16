<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Tackle\Support\GitHubClient;
use Tackle\Upgrade\AuditIssueReporter;

function makeAuditIssueReporter(): AuditIssueReporter
{
    config()->set('tackle.github.token', 'test-token');
    config()->set('tackle.github.repo', 'acme/app');

    return new AuditIssueReporter(new GitHubClient);
}

function pestMajor(): array
{
    return [
        'name' => 'pestphp/pest',
        'version' => 'v4.7.8',
        'latest' => 'v5.1.1',
        'blockers' => 'pest-plugin-laravel v4.1.0 requires pestphp/pest (^4.4.1)',
    ];
}

it('creates an issue when majors exist and none is open', function () {
    Http::fake([
        'api.github.com/repos/acme/app/issues?*' => Http::response([]),
        'api.github.com/repos/acme/app/issues' => Http::response(['number' => 7, 'html_url' => 'https://github.com/acme/app/issues/7'], 201),
    ]);

    $result = makeAuditIssueReporter()->sync([pestMajor()]);

    expect($result)->toBe('Created issue #7: https://github.com/acme/app/issues/7');

    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && str_ends_with($request->url(), '/issues')
        && $request['title'] === AuditIssueReporter::TITLE
        && $request['labels'] === [AuditIssueReporter::LABEL]
        && str_contains($request['body'], 'pestphp/pest')
        && str_contains($request['body'], 'pest-plugin-laravel v4.1.0'));
});

it('leaves the issue alone when the audit has not changed', function () {
    $reporter = makeAuditIssueReporter();

    Http::fake([
        'api.github.com/repos/acme/app/issues?*' => Http::response([
            ['number' => 7, 'body' => $reporter->buildBody([pestMajor()])],
        ]),
    ]);

    expect($reporter->sync([pestMajor()]))->toBe('Issue #7 is already up to date.');

    Http::assertSentCount(1);
});

it('updates the issue in place when the audit changed', function () {
    Http::fake([
        'api.github.com/repos/acme/app/issues?*' => Http::response([
            ['number' => 7, 'body' => 'stale audit from yesterday'],
        ]),
        'api.github.com/repos/acme/app/issues/7' => Http::response(['number' => 7]),
    ]);

    expect(makeAuditIssueReporter()->sync([pestMajor()]))
        ->toBe('Updated issue #7 with the latest audit.');

    Http::assertSent(fn ($request) => $request->method() === 'PATCH'
        && str_ends_with($request->url(), '/issues/7')
        && str_contains($request['body'], 'pestphp/pest'));
});

it('closes the issue when no majors remain', function () {
    Http::fake([
        'api.github.com/repos/acme/app/issues?*' => Http::response([
            ['number' => 7, 'body' => 'stale audit'],
        ]),
        'api.github.com/repos/acme/app/issues/7/comments' => Http::response(['id' => 1], 201),
        'api.github.com/repos/acme/app/issues/7' => Http::response(['number' => 7]),
    ]);

    expect(makeAuditIssueReporter()->sync([]))
        ->toBe('Closed issue #7 — no major upgrades remain.');

    Http::assertSent(fn ($request) => $request->method() === 'PATCH' && $request['state'] === 'closed');
});

it('does nothing when no majors and no open issue', function () {
    Http::fake([
        'api.github.com/repos/acme/app/issues?*' => Http::response([]),
    ]);

    expect(makeAuditIssueReporter()->sync([]))
        ->toBe('No major upgrades available and no open audit issue — nothing to do.');

    Http::assertSentCount(1);
});

it('ignores pull requests returned by the issues endpoint', function () {
    Http::fake([
        'api.github.com/repos/acme/app/issues?*' => Http::response([
            ['number' => 5, 'body' => 'a PR', 'pull_request' => ['url' => 'x']],
        ]),
        'api.github.com/repos/acme/app/issues' => Http::response(['number' => 8, 'html_url' => 'https://github.com/acme/app/issues/8'], 201),
    ]);

    expect(makeAuditIssueReporter()->sync([pestMajor()]))->toContain('Created issue #8');
});

it('throws when GitHub is not configured', function () {
    config()->set('tackle.github.token', null);
    config()->set('tackle.github.repo', null);
    Process::fake(fn () => Process::result(output: '', errorOutput: 'not logged in', exitCode: 1));

    (new AuditIssueReporter(new GitHubClient))->sync([pestMajor()]);
})->throws(RuntimeException::class, 'GitHub is not configured');
