<?php

namespace Tackle\Commands;

use Illuminate\Console\Command;
use Tackle\Jobs\HealJobFailure;
use Tackle\Jobs\HealScheduledTask;
use Tackle\Models\HealingLog;

class ReplayCommand extends Command
{
    protected $signature = 'tackle:replay
        {--id=        : Replay a specific healing log entry by ID}
        {--class=     : Replay the last healing attempt for a given job class}';

    protected $description = 'Re-dispatch a previous healing attempt.';

    public function handle(): int
    {
        $entry = $this->resolveEntry();

        if (! $entry) {
            return self::FAILURE;
        }

        $this->line('');
        $this->line('<fg=green;options=bold>Laravel Tackle — Replay Healing Attempt</>');
        $this->line('');
        $this->line("  Type:      {$entry->subject_type}");
        $this->line("  Subject:   {$entry->subject_class}");
        $this->line("  Exception: {$entry->exception_class}: {$entry->exception_message}");
        $this->line("  Original:  {$entry->outcome} (branch: {$entry->branch})");
        $this->line('');

        if (! $this->confirm('Re-dispatch this healing job?', true)) {
            $this->line('Cancelled.');
            return self::SUCCESS;
        }

        $this->dispatch($entry);

        $this->line('');
        $this->line('<fg=green>✓</> Healing job dispatched to the <fg=cyan>' . config('ai-code.healing.queue', 'healer') . '</> queue.');
        $this->line('');

        return self::SUCCESS;
    }

    private function resolveEntry(): ?HealingLog
    {
        if ($id = $this->option('id')) {
            $entry = HealingLog::find((int) $id);
            if (! $entry) {
                $this->error("No healing log entry found with ID {$id}.");
            }
            return $entry;
        }

        if ($class = $this->option('class')) {
            $entry = HealingLog::where('subject_class', $class)->latest()->first();
            if (! $entry) {
                $this->error("No healing log entries found for class [{$class}].");
            }
            return $entry;
        }

        $entry = HealingLog::latest()->first();
        if (! $entry) {
            $this->error('No healing log entries found. Has the healer run yet?');
        }
        return $entry;
    }

    private function dispatch(HealingLog $entry): void
    {
        if ($entry->subject_type === 'scheduled_task') {
            HealScheduledTask::dispatch(
                taskCommand:      $entry->subject_class,
                taskDescription:  $entry->subject_class,
                exceptionClass:   $entry->exception_class,
                exceptionMessage: $entry->exception_message,
                exceptionTrace:   '',
            );
            return;
        }

        HealJobFailure::dispatch(
            jobUuid:          uniqid('replay-', more_entropy: true),
            jobClass:         $entry->subject_class,
            jobPayload:       '{}',
            exceptionClass:   $entry->exception_class,
            exceptionMessage: $entry->exception_message,
            exceptionTrace:   '',
        );
    }
}
