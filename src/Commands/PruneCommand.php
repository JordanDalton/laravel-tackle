<?php

namespace Tackle\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

class PruneCommand extends Command
{
    protected $signature = 'tackle:prune {--dry-run : List dangling worktrees without removing them}';

    protected $description = 'Remove any dangling Tackle healing worktrees left behind by interrupted jobs.';

    public function handle(): int
    {
        $base   = base_path();
        $result = Process::path($base)->run(['git', 'worktree', 'list', '--porcelain']);

        if (! $result->successful()) {
            $this->error('Could not list git worktrees — is this a git repository?');
            return self::FAILURE;
        }

        $dangling = collect(explode("\n", trim($result->output())))
            ->filter(fn ($line) => str_starts_with($line, 'worktree '))
            ->map(fn ($line) => trim(substr($line, strlen('worktree '))))
            ->filter(fn ($path) => str_contains($path, 'tackle-worktree-'))
            ->values();

        if ($dangling->isEmpty()) {
            $this->info('No dangling Tackle worktrees found.');
            return self::SUCCESS;
        }

        foreach ($dangling as $path) {
            if ($this->option('dry-run')) {
                $this->line("  <fg=yellow>would remove:</> {$path}");
                continue;
            }

            $this->line("  <fg=cyan>removing:</> {$path}");

            // Drop vendor symlink first so git doesn't choke on the broken link.
            $vendorLink = $path . '/vendor';
            if (is_link($vendorLink)) {
                unlink($vendorLink);
            }

            $remove = Process::path($base)
                ->timeout(30)
                ->run(['git', 'worktree', 'remove', '--force', $path]);

            if ($remove->successful()) {
                $this->line("  <fg=green>✓</> removed {$path}");
            } else {
                $this->line("  <fg=red>✗</> failed to remove {$path}: " . trim($remove->errorOutput()));
            }
        }

        if (! $this->option('dry-run')) {
            // Prune stale administrative files for any worktrees already gone from disk.
            Process::path($base)->run(['git', 'worktree', 'prune']);
        }

        return self::SUCCESS;
    }
}
