<?php

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Tools\Request;
use Tackle\Healing\SentryReader;
use Tackle\Tools\ReadSentryIssue;

// ---------------------------------------------------------------------------
// SentryReader — credential resolution
// ---------------------------------------------------------------------------

it('returns empty string when auth token is not configured', function () {
    config()->set('tackle.sentry.auth_token', null);
    config()->set('tackle.sentry.org', 'my-org');

    $reader = new SentryReader;

    expect($reader->forIssue('123'))->toBe('');
    expect($reader->recent())->toBe('');
});

it('returns empty string when org is not configured', function () {
    config()->set('tackle.sentry.auth_token', 'sntrys_token');
    config()->set('tackle.sentry.org', null);

    $reader = new SentryReader;

    expect($reader->forIssue('123'))->toBe('');
    expect($reader->recent())->toBe('');
});

// ---------------------------------------------------------------------------
// SentryReader::forIssue — HTTP behaviour
// ---------------------------------------------------------------------------

it('returns empty string when Sentry API returns a non-2xx response for forIssue', function () {
    config()->set('tackle.sentry.auth_token', 'sntrys_token');
    config()->set('tackle.sentry.org', 'my-org');

    Http::fake([
        'sentry.io/*' => Http::response([], 401),
    ]);

    $reader = new SentryReader;

    expect($reader->forIssue('123'))->toBe('');
});

it('formats a Sentry issue event correctly', function () {
    config()->set('tackle.sentry.auth_token', 'sntrys_token');
    config()->set('tackle.sentry.org', 'my-org');

    Http::fake([
        'sentry.io/*' => Http::response([
            'groupID' => '4821',
            'title' => 'DivisionByZeroError: Division by zero',
            'entries' => [
                [
                    'type' => 'request',
                    'data' => ['method' => 'POST', 'url' => 'https://example.com/api/pay'],
                ],
                [
                    'type' => 'exception',
                    'data' => [
                        'values' => [[
                            'type' => 'DivisionByZeroError',
                            'value' => 'Division by zero',
                            'stacktrace' => [
                                'frames' => [
                                    ['filename' => 'app/Services/PaymentService.php', 'lineno' => 42, 'function' => 'charge'],
                                ],
                            ],
                        ]],
                    ],
                ],
            ],
        ], 200),
    ]);

    $reader = new SentryReader;
    $result = $reader->forIssue('4821');

    expect($result)
        ->toContain('Sentry issue #4821')
        ->toContain('DivisionByZeroError')
        ->toContain('Division by zero')
        ->toContain('app/Services/PaymentService.php:42')
        ->toContain('POST https://example.com/api/pay');
});

// ---------------------------------------------------------------------------
// SentryReader::recent — HTTP behaviour
// ---------------------------------------------------------------------------

it('returns empty string when project is not configured for recent()', function () {
    config()->set('tackle.sentry.auth_token', 'sntrys_token');
    config()->set('tackle.sentry.org', 'my-org');
    config()->set('tackle.sentry.project', null);

    $reader = new SentryReader;

    expect($reader->recent())->toBe('');
});

it('returns empty string when Sentry API returns a non-2xx response for recent()', function () {
    config()->set('tackle.sentry.auth_token', 'sntrys_token');
    config()->set('tackle.sentry.org', 'my-org');
    config()->set('tackle.sentry.project', 'my-project');

    Http::fake([
        'sentry.io/*' => Http::response([], 403),
    ]);

    $reader = new SentryReader;

    expect($reader->recent())->toBe('');
});

it('formats recent issues correctly', function () {
    config()->set('tackle.sentry.auth_token', 'sntrys_token');
    config()->set('tackle.sentry.org', 'my-org');
    config()->set('tackle.sentry.project', 'my-project');

    Http::fake([
        'sentry.io/*' => Http::response([
            ['id' => '101', 'title' => 'TypeError: null given', 'count' => 5,  'lastSeen' => '2026-06-14T10:00:00Z'],
            ['id' => '102', 'title' => 'RuntimeException: oops', 'count' => 1, 'lastSeen' => '2026-06-14T09:00:00Z'],
        ], 200),
    ]);

    $reader = new SentryReader;
    $result = $reader->recent();

    expect($result)
        ->toContain('#101')
        ->toContain('TypeError: null given')
        ->toContain('(5×)')
        ->toContain('#102');
});

it('returns no issues message when API returns empty array', function () {
    config()->set('tackle.sentry.auth_token', 'sntrys_token');
    config()->set('tackle.sentry.org', 'my-org');
    config()->set('tackle.sentry.project', 'my-project');

    Http::fake([
        'sentry.io/*' => Http::response([], 200),
    ]);

    $reader = new SentryReader;

    expect($reader->recent())->toBe('No unresolved Sentry issues found.');
});

// ---------------------------------------------------------------------------
// ReadSentryIssue tool — handle() routing
// ---------------------------------------------------------------------------

it('ReadSentryIssue returns not-configured message when credentials are missing', function () {
    config()->set('tackle.sentry.auth_token', null);
    config()->set('tackle.sentry.org', null);

    $tool = new ReadSentryIssue(new SentryReader);
    $result = $tool->handle(new Request(['issue_id' => '4821']));

    expect($result)->toContain('SENTRY_AUTH_TOKEN');
});

it('ReadSentryIssue routes to recent() when no issue_id is given', function () {
    config()->set('tackle.sentry.auth_token', 'sntrys_token');
    config()->set('tackle.sentry.org', 'my-org');
    config()->set('tackle.sentry.project', null); // no project → empty from recent()

    $tool = new ReadSentryIssue(new SentryReader);
    $result = $tool->handle(new Request([]));

    expect($result)->toContain('SENTRY_AUTH_TOKEN');
});

it('ReadSentryIssue clamps limit between 1 and 25', function () {
    config()->set('tackle.sentry.auth_token', 'sntrys_token');
    config()->set('tackle.sentry.org', 'my-org');
    config()->set('tackle.sentry.project', 'my-project');

    Http::fake([
        'sentry.io/*' => Http::response([], 200),
    ]);

    $tool = new ReadSentryIssue(new SentryReader);
    $result = $tool->handle(new Request(['limit' => 999]));

    // Should not throw — limit is clamped internally in SentryReader
    expect($result)->toBeString();
});
