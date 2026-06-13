<?php

namespace Tackle\Healing;

use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\DB;
use Tackle\Jobs\HealJobFailure;
use Throwable;

class JobFailureListener
{
    public function handle(JobFailed $event): void
    {
        try {
            $this->process($event);
        } catch (Throwable $e) {
            // Never let the listener crash the worker.
            logger()->error('Tackle Healer listener error: ' . $e->getMessage());
        }
    }

    private function process(JobFailed $event): void
    {
        $payload = json_decode($event->job->getRawBody(), true) ?? [];

        $jobClass = $payload['displayName'] ?? ($payload['job'] ?? 'unknown');
        $jobUuid  = $payload['uuid']        ?? uniqid('heal-', more_entropy: true);

        // Skip jobs that are themselves part of the healer to avoid loops.
        if (is_a($jobClass, HealJobFailure::class, allow_string: true)) {
            return;
        }

        // Threshold check: how many times has this job class failed before?
        $threshold = (int) config('ai-code.healing.threshold', 1);
        if ($threshold > 1) {
            $count = DB::table('failed_jobs')
                ->where('payload', 'like', '%' . addslashes($jobClass) . '%')
                ->count();

            if ($count < $threshold) {
                return;
            }
        }

        $exception = $event->exception;

        HealJobFailure::dispatch(
            jobUuid:          $jobUuid,
            jobClass:         $jobClass,
            jobPayload:       $event->job->getRawBody(),
            exceptionClass:   get_class($exception),
            exceptionMessage: $exception->getMessage(),
            exceptionTrace:   $exception->getTraceAsString(),
        );
    }
}
