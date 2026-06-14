<?php

namespace Tackle\Commands;

use Illuminate\Console\Command;
use Tackle\Models\HealingLog;

class HealingLogCommand extends Command
{
    protected $signature = 'tackle:healing-log
        {--limit=25        : Maximum number of entries to display}
        {--type=           : Filter by subject type: job or scheduled_task}
        {--outcome=        : Filter by outcome: pr_opened, patched, or failed}';

    protected $description = 'Display the self-healing audit log.';

    public function handle(): int
    {
        $query = HealingLog::latest();

        if ($type = $this->option('type')) {
            $query->where('subject_type', $type);
        }

        if ($outcome = $this->option('outcome')) {
            $query->where('outcome', $outcome);
        }

        $entries = $query->limit((int) $this->option('limit'))->get();

        if ($entries->isEmpty()) {
            $this->info('No healing attempts recorded yet.');
            $this->line('<fg=gray>Run the healer queue worker to start: php artisan queue:work --queue=healer</>');
            return self::SUCCESS;
        }

        $this->line('');
        $this->line('<fg=green;options=bold>Laravel Tackle — Healing Audit Log</>');
        $this->line('');

        $this->table(
            ['When', 'Type', 'Subject', 'Exception', 'Tests', 'Outcome', 'PR / Branch'],
            $entries->map(fn (HealingLog $e) => [
                $e->created_at->diffForHumans(),
                $e->subject_type,
                class_basename($e->subject_class),
                class_basename($e->exception_class),
                $e->tests_passed ? '<fg=green>✓</>' : '<fg=red>✗</>',
                $this->outcomeLabel($e->outcome),
                $e->pr_url ?? $e->branch ?? '—',
            ])
        );

        $this->line('');

        return self::SUCCESS;
    }

    private function outcomeLabel(string $outcome): string
    {
        return match ($outcome) {
            'pr_opened' => '<fg=cyan>PR opened</>',
            'patched'   => '<fg=green>patched</>',
            'failed'    => '<fg=red>failed</>',
            default     => $outcome,
        };
    }
}
