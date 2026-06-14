<?php

namespace Tackle\Support;

use Illuminate\Support\Facades\Process;
use RuntimeException;

class WorktreeManager
{
    private ?string $path = null;

    public function create(): string
    {
        $suffix     = substr(md5(uniqid('tackle', true)), 0, 8);
        $this->path = sys_get_temp_dir() . "/tackle-worktree-{$suffix}";

        $result = Process::path(base_path())->run('git worktree add ' . escapeshellarg($this->path) . ' HEAD');

        if ($result->failed()) {
            $this->path = null;
            throw new RuntimeException('Failed to create worktree: ' . trim($result->errorOutput()));
        }

        return $this->path;
    }

    public function cleanup(): void
    {
        if ($this->path === null) {
            return;
        }

        Process::path(base_path())->run('git worktree remove --force ' . escapeshellarg($this->path));
        $this->path = null;
    }

    public function path(): string
    {
        return $this->path ?? base_path();
    }

    public function active(): bool
    {
        return $this->path !== null;
    }
}
