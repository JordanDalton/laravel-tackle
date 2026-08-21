<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tackle\Healing\NightwatchIssue;
use Tackle\Jobs\HealNightwatchIssue;
use Tackle\TackleServiceProvider;

beforeEach(function () {
    config()->set('tackle.nightwatch.enabled', true);
    config()->set('tackle.nightwatch.secret', 'shhh');
    config()->set('tackle.nightwatch.path', 'tackle/nightwatch/webhook');
    config()->set('tackle.nightwatch.events', ['issue.opened', 'issue.reopened']);
    config()->set('tackle.nightwatch.issue_types', ['exception', 'performance']);
    config()->set('tackle.nightwatch.environments', []);
    config()->set('tackle.nightwatch.min_priority', 'none');
    config()->set('tackle.nightwatch.handled_exceptions', false);
    config()->set('tackle.nightwatch.cooldown', 3600);

    Cache::flush();
});

/**
 * The route is registered in packageBooted, which has already run by the time a
 * test body sets config — so re-register the provider to pick the flag up.
 */
function bootNightwatchRoute(): void
{
    app()->register(TackleServiceProvider::class, force: true);
}

function nightwatchExceptionPayload(array $overrides = []): array
{
    return array_replace_recursive([
        'event' => 'issue.opened',
        'timestamp' => '2026-08-21T10:00:00+00:00',
        'payload' => [
            'webhook_id' => 'wh_1',
            'application_id' => 'app_1',
            'organization_id' => 'org_1',
            'issue' => [
                'id' => '9f1c-issue-uuid',
                'ref' => 412,
                'type' => 'exception',
                'title' => 'QueryException in CheckoutController',
                'status' => 'open',
                'priority' => 'high',
                'url' => 'https://nightwatch.laravel.com/issues/412',
                'details' => [
                    'type' => 'exception',
                    'handled' => false,
                    'class' => 'Illuminate\\Database\\QueryException',
                    'message' => 'SQLSTATE[42S22]: Column not found: orders.total_cents',
                    'file' => 'app/Http/Controllers/CheckoutController.php',
                    'line' => 84,
                    'laravel_version' => '12.20.0',
                    'php_version' => '8.4.1',
                ],
            ],
            'environment' => ['id' => 'env_1', 'name' => 'production'],
        ],
    ], $overrides);
}

function nightwatchPerformancePayload(array $overrides = []): array
{
    return array_replace_recursive([
        'event' => 'issue.opened',
        'timestamp' => '2026-08-21T10:00:00+00:00',
        'payload' => [
            'application_id' => 'app_1',
            'issue' => [
                'id' => 'perf-issue-uuid',
                'ref' => 77,
                'type' => 'performance',
                'title' => 'Slow route: /checkout',
                'status' => 'open',
                'priority' => 'medium',
                'url' => 'https://nightwatch.laravel.com/issues/77',
                'details' => [
                    'type' => 'slow-route',
                    'methods' => ['GET', 'POST'],
                    'path' => 'checkout/{order}',
                    'action' => 'App\\Http\\Controllers\\CheckoutController@store',
                    'duration' => 2400,
                    'threshold' => 500,
                ],
            ],
            'environment' => ['id' => 'env_1', 'name' => 'production'],
        ],
    ], $overrides);
}

function postNightwatchWebhook(array $payload, ?string $signature = null)
{
    $body = json_encode($payload);

    $signature ??= hash_hmac('sha256', $body, config('tackle.nightwatch.secret'));

    return test()->call(
        'POST',
        '/'.config('tackle.nightwatch.path'),
        server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_NIGHTWATCH_SIGNATURE' => $signature,
        ],
        content: $body,
    );
}

// ---------------------------------------------------------------------------
// Signature verification
// ---------------------------------------------------------------------------

it('accepts a correctly signed webhook and queues a heal', function () {
    bootNightwatchRoute();
    Queue::fake();

    postNightwatchWebhook(nightwatchExceptionPayload())
        ->assertOk()
        ->assertJson(['status' => 'queued', 'issue' => '#412']);

    Queue::assertPushed(HealNightwatchIssue::class, function (HealNightwatchIssue $job) {
        return $job->issue->id === '9f1c-issue-uuid'
            && $job->issue->exceptionClass() === 'Illuminate\\Database\\QueryException'
            && $job->issue->isException();
    });
});

it('rejects a webhook with a bad signature', function () {
    bootNightwatchRoute();
    Queue::fake();

    postNightwatchWebhook(nightwatchExceptionPayload(), signature: str_repeat('a', 64))->assertForbidden();

    Queue::assertNothingPushed();
});

it('rejects a webhook with no signature header', function () {
    bootNightwatchRoute();
    Queue::fake();

    $body = json_encode(nightwatchExceptionPayload());

    $this->call('POST', '/'.config('tackle.nightwatch.path'), server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
    ], content: $body)->assertForbidden();

    Queue::assertNothingPushed();
});

it('tolerates an algorithm-prefixed signature header', function () {
    bootNightwatchRoute();
    Queue::fake();

    $body = json_encode(nightwatchExceptionPayload());
    $signature = 'sha256='.hash_hmac('sha256', $body, 'shhh');

    postNightwatchWebhook(nightwatchExceptionPayload(), signature: $signature)->assertOk();

    Queue::assertPushed(HealNightwatchIssue::class);
});

it('refuses every delivery when no signing secret is configured', function () {
    config()->set('tackle.nightwatch.secret', null);
    bootNightwatchRoute();
    Queue::fake();

    postNightwatchWebhook(nightwatchExceptionPayload(), signature: 'anything')->assertStatus(500);

    Queue::assertNothingPushed();
});

it('does not register the route when the integration is disabled', function () {
    config()->set('tackle.nightwatch.enabled', false);
    bootNightwatchRoute();

    postNightwatchWebhook(nightwatchExceptionPayload())->assertNotFound();
});

// ---------------------------------------------------------------------------
// Gates
// ---------------------------------------------------------------------------

it('queues a heal for a performance issue with its duration and threshold', function () {
    bootNightwatchRoute();
    Queue::fake();

    postNightwatchWebhook(nightwatchPerformancePayload())->assertOk();

    Queue::assertPushed(HealNightwatchIssue::class, function (HealNightwatchIssue $job) {
        return $job->issue->isPerformance()
            && $job->issue->subtype() === 'slow-route'
            && str_contains($job->issue->describe(), '2400ms')
            && str_contains($job->issue->describe(), 'threshold: 500ms');
    });
});

it('ignores issue.resolved and issue.ignored events', function () {
    bootNightwatchRoute();
    Queue::fake();

    postNightwatchWebhook(nightwatchExceptionPayload(['event' => 'issue.resolved']))
        ->assertOk()
        ->assertJson(['status' => 'skipped']);

    Queue::assertNothingPushed();
});

it('skips handled exceptions unless they are opted in', function () {
    bootNightwatchRoute();
    Queue::fake();

    $handled = nightwatchExceptionPayload(['payload' => ['issue' => ['details' => ['handled' => true]]]]);

    postNightwatchWebhook($handled)->assertOk()->assertJson(['status' => 'skipped']);
    Queue::assertNothingPushed();

    config()->set('tackle.nightwatch.handled_exceptions', true);

    postNightwatchWebhook($handled)->assertOk()->assertJson(['status' => 'queued']);
    Queue::assertPushed(HealNightwatchIssue::class);
});

it('skips issues below the configured priority floor', function () {
    config()->set('tackle.nightwatch.min_priority', 'high');
    bootNightwatchRoute();
    Queue::fake();

    postNightwatchWebhook(nightwatchPerformancePayload())->assertOk()->assertJson(['status' => 'skipped']);
    Queue::assertNothingPushed();

    postNightwatchWebhook(nightwatchExceptionPayload())->assertOk()->assertJson(['status' => 'queued']);
    Queue::assertPushed(HealNightwatchIssue::class);
});

it('skips issues from environments that are not configured for healing', function () {
    config()->set('tackle.nightwatch.environments', ['production']);
    bootNightwatchRoute();
    Queue::fake();

    $staging = nightwatchExceptionPayload(['payload' => ['environment' => ['name' => 'staging']]]);

    postNightwatchWebhook($staging)->assertOk()->assertJson(['status' => 'skipped']);
    Queue::assertNothingPushed();

    postNightwatchWebhook(nightwatchExceptionPayload())->assertOk()->assertJson(['status' => 'queued']);
});

it('skips issue types that are not configured for healing', function () {
    config()->set('tackle.nightwatch.issue_types', ['exception']);
    bootNightwatchRoute();
    Queue::fake();

    postNightwatchWebhook(nightwatchPerformancePayload())->assertOk()->assertJson(['status' => 'skipped']);
    Queue::assertNothingPushed();
});

it('heals a given issue once within the cooldown window', function () {
    bootNightwatchRoute();
    Queue::fake();

    postNightwatchWebhook(nightwatchExceptionPayload())->assertOk()->assertJson(['status' => 'queued']);

    // A flapping issue reopens; the second delivery must not cost another agent.
    postNightwatchWebhook(nightwatchExceptionPayload(['event' => 'issue.reopened']))
        ->assertOk()
        ->assertJson(['status' => 'skipped']);

    Queue::assertPushed(HealNightwatchIssue::class, 1);
});

it('does not dedupe when the cooldown is disabled', function () {
    config()->set('tackle.nightwatch.cooldown', 0);
    bootNightwatchRoute();
    Queue::fake();

    postNightwatchWebhook(nightwatchExceptionPayload())->assertOk();
    postNightwatchWebhook(nightwatchExceptionPayload(['event' => 'issue.reopened']))->assertOk();

    Queue::assertPushed(HealNightwatchIssue::class, 2);
});

it('skips a payload with no issue object', function () {
    bootNightwatchRoute();
    Queue::fake();

    postNightwatchWebhook(['event' => 'issue.opened', 'payload' => []])
        ->assertOk()
        ->assertJson(['status' => 'skipped']);

    Queue::assertNothingPushed();
});

// ---------------------------------------------------------------------------
// Issue parsing and prompt construction
// ---------------------------------------------------------------------------

it('parses an exception issue into the fields the healer logs', function () {
    $issue = NightwatchIssue::fromWebhook(nightwatchExceptionPayload());

    expect($issue)->not->toBeNull()
        ->and($issue->label())->toBe('#412')
        ->and($issue->subject())->toBe('Illuminate\\Database\\QueryException')
        ->and($issue->exceptionMessage())->toContain('Column not found')
        ->and($issue->environment)->toBe('production')
        ->and($issue->branchSuffix())->toStartWith('nw-412-')
        ->and($issue->describe())->toContain('app/Http/Controllers/CheckoutController.php:84');
});

it('parses a performance issue into a readable subject', function () {
    $issue = NightwatchIssue::fromWebhook(nightwatchPerformancePayload());

    expect($issue->subject())->toBe('slow-route GET|POST checkout/{order}')
        ->and($issue->exceptionClass())->toBe('performance:slow-route')
        ->and($issue->target())->toBe('GET|POST checkout/{order}');
});

it('survives a payload missing every optional field', function () {
    $issue = NightwatchIssue::fromWebhook([
        'event' => 'issue.opened',
        'payload' => ['issue' => ['id' => 'bare-uuid']],
    ]);

    expect($issue)->not->toBeNull()
        ->and($issue->label())->toBe('bare-uuid')
        ->and($issue->exceptionClass())->toBe('unknown')
        ->and($issue->exceptionMessage())->toBe('Untitled issue')
        ->and($issue->describe())->toContain('Untitled issue');
});

it('writes different prompts for exception and performance issues', function () {
    $exception = new HealNightwatchIssue(NightwatchIssue::fromWebhook(nightwatchExceptionPayload()));
    $performance = new HealNightwatchIssue(NightwatchIssue::fromWebhook(nightwatchPerformancePayload()));

    $prompt = fn (HealNightwatchIssue $job) => (function () {
        return $this->agentPrompt();
    })->call($job);

    expect($prompt($exception))->toContain('does not send a stack')
        ->and($prompt($performance))->toContain('N+1')
        ->and($prompt($performance))->toContain('Do not change observable behaviour');
});

it('keeps the subject type inside the audit log column width', function () {
    $job = new HealNightwatchIssue(NightwatchIssue::fromWebhook(nightwatchExceptionPayload()));

    $subjectType = (function () {
        return $this->subjectType();
    })->call($job);

    // tackle_healing_log.subject_type is a string(20).
    expect(strlen($subjectType))->toBeLessThanOrEqual(20);
});

it('survives the queue serialization round trip', function () {
    // Queue::fake() never serializes, so a readonly value object riding on a
    // job can look fine in tests and fail on a real worker.
    $job = new HealNightwatchIssue(NightwatchIssue::fromWebhook(nightwatchPerformancePayload()));

    $restored = unserialize(serialize($job));

    expect($restored->issue->id)->toBe('perf-issue-uuid')
        ->and($restored->issue->subtype())->toBe('slow-route')
        ->and($restored->issue->details['duration'])->toBe(2400)
        ->and($restored->queue)->toBe($job->queue);
});
