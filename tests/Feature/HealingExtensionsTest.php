<?php

use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Scheduling\Event as SchedulingEvent;
use Illuminate\Support\Facades\Queue;
use Tackle\Healing\JobFailureListener;
use Tackle\Healing\ScheduledTaskFailureListener;
use Tackle\Jobs\HealJobFailure;
use Tackle\Jobs\HealScheduledTask;

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

// ---------------------------------------------------------------------------
// ScheduledTaskFailureListener
// ---------------------------------------------------------------------------

it('dispatches HealScheduledTask when a scheduled task fails', function () {
    Queue::fake();

    $task = Mockery::mock(SchedulingEvent::class);
    $task->command     = "'artisan' emails:send";
    $task->description = 'Send daily emails';

    $event = new ScheduledTaskFailed($task, new RuntimeException('SMTP connection refused'));

    $listener = new ScheduledTaskFailureListener();
    $listener->handle($event);

    Queue::assertPushed(HealScheduledTask::class, function ($job) {
        return $job->taskCommand     === "'artisan' emails:send"
            && $job->taskDescription === 'Send daily emails'
            && $job->exceptionClass  === RuntimeException::class
            && str_contains($job->exceptionMessage, 'SMTP');
    });
});

it('dispatches HealScheduledTask to the healer queue', function () {
    Queue::fake();

    $task = Mockery::mock(SchedulingEvent::class);
    $task->command     = "'artisan' reports:generate";
    $task->description = 'Generate weekly report';

    $event = new ScheduledTaskFailed($task, new LogicException('Division by zero'));

    (new ScheduledTaskFailureListener())->handle($event);

    Queue::assertPushed(HealScheduledTask::class, fn ($j) => $j->queue === 'healer');
});

it('uses command as description when task has no description', function () {
    Queue::fake();

    $task = Mockery::mock(SchedulingEvent::class);
    $task->command     = "'artisan' cache:prune-stale-tags";
    $task->description = null;

    $event = new ScheduledTaskFailed($task, new RuntimeException('Table not found'));

    (new ScheduledTaskFailureListener())->handle($event);

    Queue::assertPushed(HealScheduledTask::class, function ($job) {
        return $job->taskDescription === "'artisan' cache:prune-stale-tags";
    });
});

// ---------------------------------------------------------------------------
// Per-class opt-out ($healable = false)
// ---------------------------------------------------------------------------

it('skips healing when job class declares $healable = false', function () {
    Queue::fake();

    // Synthesise a job class with $healable = false
    $jobClass = new class {
        public bool $healable = false;
    };
    $concreteClass = get_class($jobClass);

    $payload = json_encode([
        'displayName' => $concreteClass,
        'uuid'        => 'opt-out-uuid',
        'data'        => ['command' => ''],
    ]);

    $job = Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
    $job->allows('getRawBody')->andReturn($payload);

    $event    = new \Illuminate\Queue\Events\JobFailed('database', $job, new RuntimeException('oops'));
    $listener = new JobFailureListener();
    $listener->handle($event);

    Queue::assertNothingPushed();
});

it('heals a job class that has no $healable property', function () {
    Queue::fake();

    $jobClass = new class {};
    $concreteClass = get_class($jobClass);

    $payload = json_encode([
        'displayName' => $concreteClass,
        'uuid'        => 'no-property-uuid',
        'data'        => ['command' => ''],
    ]);

    $job = Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
    $job->allows('getRawBody')->andReturn($payload);

    $event = new \Illuminate\Queue\Events\JobFailed('database', $job, new RuntimeException('boom'));

    (new JobFailureListener())->handle($event);

    Queue::assertPushed(HealJobFailure::class);
});

it('heals a job class that has $healable = true', function () {
    Queue::fake();

    $jobClass = new class {
        public bool $healable = true;
    };
    $concreteClass = get_class($jobClass);

    $payload = json_encode([
        'displayName' => $concreteClass,
        'uuid'        => 'healable-true-uuid',
        'data'        => ['command' => ''],
    ]);

    $job = Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
    $job->allows('getRawBody')->andReturn($payload);

    $event = new \Illuminate\Queue\Events\JobFailed('database', $job, new RuntimeException('err'));

    (new JobFailureListener())->handle($event);

    Queue::assertPushed(HealJobFailure::class);
});

// ---------------------------------------------------------------------------
// HealScheduledTask — branch suffix is stable for same command
// ---------------------------------------------------------------------------

it('HealScheduledTask generates a consistent branch suffix for the same command', function () {
    $job1 = new HealScheduledTask("'artisan' emails:send", 'desc', 'Ex', 'msg', 'trace');
    $job2 = new HealScheduledTask("'artisan' emails:send", 'desc', 'Ex', 'msg', 'trace');

    $suffix = fn ($j) => (new ReflectionMethod($j, 'branchSuffix'))->invoke($j);

    expect($suffix($job1))->toBe($suffix($job2));
});
