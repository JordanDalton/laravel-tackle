<?php

namespace Tackle\Healing;

use Illuminate\Console\Events\ScheduledTaskFailed;
use Tackle\Jobs\HealScheduledTask;
use Throwable;

class ScheduledTaskFailureListener
{
    public function handle(ScheduledTaskFailed $event): void
    {
        try {
            $this->process($event);
        } catch (Throwable $e) {
            logger()->error('Tackle Healer scheduled-task listener error: ' . $e->getMessage());
        }
    }

    private function process(ScheduledTaskFailed $event): void
    {
        $task      = $event->task;
        $exception = $event->exception;

        $command     = $task->command     ?? 'unknown';
        $description = $task->description ?? $command;

        HealScheduledTask::dispatch(
            taskCommand:      $command,
            taskDescription:  $description,
            exceptionClass:   get_class($exception),
            exceptionMessage: $exception->getMessage(),
            exceptionTrace:   $exception->getTraceAsString(),
        );
    }
}
