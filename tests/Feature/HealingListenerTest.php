<?php

use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Queue;
use Tackle\Healing\JobFailureListener;
use Tackle\Jobs\HealJobFailure;

beforeEach(function () {
    config()->set('ai-code.healing.enabled', true);
    config()->set('ai-code.healing.threshold', 1);
    config()->set('ai-code.healing.queue', 'healer');
    config()->set('database.default', 'sqlite');
    config()->set('database.connections.sqlite', [
        'driver'   => 'sqlite',
        'database' => ':memory:',
    ]);
});

it('dispatches HealJobFailure when healing is enabled', function () {
    Queue::fake();

    $payload = json_encode([
        'displayName' => 'App\\Jobs\\SendWelcomeEmail',
        'uuid'        => 'test-uuid-1234',
        'data'        => ['command' => ''],
    ]);

    $job = Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
    $job->allows('getRawBody')->andReturn($payload);

    $event = new JobFailed('database', $job, new RuntimeException('Connection timed out'));

    $listener = new JobFailureListener();
    $listener->handle($event);

    Queue::assertPushed(HealJobFailure::class, function ($job) {
        return $job->jobClass   === 'App\\Jobs\\SendWelcomeEmail'
            && $job->jobUuid    === 'test-uuid-1234'
            && $job->exceptionClass === RuntimeException::class
            && str_contains($job->exceptionMessage, 'Connection timed out');
    });
});

it('does not dispatch when the failing job is itself HealJobFailure', function () {
    Queue::fake();

    $payload = json_encode([
        'displayName' => HealJobFailure::class,
        'uuid'        => 'healer-uuid',
        'data'        => ['command' => ''],
    ]);

    $job = Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
    $job->allows('getRawBody')->andReturn($payload);

    $event = new JobFailed('database', $job, new RuntimeException('nested failure'));

    $listener = new JobFailureListener();
    $listener->handle($event);

    Queue::assertNothingPushed();
});

it('builds HealJobFailure with correct queue name', function () {
    Queue::fake();

    config()->set('ai-code.healing.queue', 'custom-healer');

    $payload = json_encode([
        'displayName' => 'App\\Jobs\\ProcessOrder',
        'uuid'        => 'order-uuid-5678',
        'data'        => ['command' => ''],
    ]);

    $job = Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
    $job->allows('getRawBody')->andReturn($payload);

    $event = new JobFailed('database', $job, new LogicException('Invalid state'));

    $listener = new JobFailureListener();
    $listener->handle($event);

    Queue::assertPushed(HealJobFailure::class, fn ($j) => $j->queue === 'custom-healer');
});
