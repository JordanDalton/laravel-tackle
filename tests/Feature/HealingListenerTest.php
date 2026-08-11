<?php

use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Queue;
use Tackle\Agents\HealingAgent;
use Tackle\Healing\JobFailureListener;
use Tackle\Jobs\HealJobFailure;

beforeEach(function () {
    config()->set('tackle.healing.enabled', true);
    config()->set('tackle.healing.threshold', 1);
    config()->set('tackle.healing.queue', 'healer');
    config()->set('database.default', 'sqlite');
    config()->set('database.connections.sqlite', [
        'driver' => 'sqlite',
        'database' => ':memory:',
    ]);
});

it('dispatches HealJobFailure when healing is enabled', function () {
    Queue::fake();

    $payload = json_encode([
        'displayName' => 'App\\Jobs\\SendWelcomeEmail',
        'uuid' => 'test-uuid-1234',
        'data' => ['command' => ''],
    ]);

    $job = Mockery::mock(Job::class);
    $job->allows('getRawBody')->andReturn($payload);

    $event = new JobFailed('database', $job, new RuntimeException('Connection timed out'));

    $listener = new JobFailureListener;
    $listener->handle($event);

    Queue::assertPushed(HealJobFailure::class, function ($job) {
        return $job->jobClass === 'App\\Jobs\\SendWelcomeEmail'
            && $job->jobUuid === 'test-uuid-1234'
            && $job->exceptionClass === RuntimeException::class
            && str_contains($job->exceptionMessage, 'Connection timed out');
    });
});

it('does not dispatch when the failing job is itself HealJobFailure', function () {
    Queue::fake();

    $payload = json_encode([
        'displayName' => HealJobFailure::class,
        'uuid' => 'healer-uuid',
        'data' => ['command' => ''],
    ]);

    $job = Mockery::mock(Job::class);
    $job->allows('getRawBody')->andReturn($payload);

    $event = new JobFailed('database', $job, new RuntimeException('nested failure'));

    $listener = new JobFailureListener;
    $listener->handle($event);

    Queue::assertNothingPushed();
});

it('builds HealJobFailure with correct queue name', function () {
    Queue::fake();

    config()->set('tackle.healing.queue', 'custom-healer');

    $payload = json_encode([
        'displayName' => 'App\\Jobs\\ProcessOrder',
        'uuid' => 'order-uuid-5678',
        'data' => ['command' => ''],
    ]);

    $job = Mockery::mock(Job::class);
    $job->allows('getRawBody')->andReturn($payload);

    $event = new JobFailed('database', $job, new LogicException('Invalid state'));

    $listener = new JobFailureListener;
    $listener->handle($event);

    Queue::assertPushed(HealJobFailure::class, fn ($j) => $j->queue === 'custom-healer');
});

it('constructs every healing agent tool without errors', function () {
    $agent = new HealingAgent(sys_get_temp_dir());

    // Regression: RunTests gained a CommandGuard argument and HealingAgent
    // was not updated, breaking every heal attempt at tool construction.
    expect(iterator_count(collect($agent->tools())->getIterator()))->toBeGreaterThan(0);
});
