<?php

namespace Tackle\Commands\Concerns;

/**
 * Shell and worktree option handling shared by ai:code and ai:run. Both accept
 * the same flags and must resolve them identically.
 */
trait ResolvesSessionOptions
{
    /**
     * Apply any --shell / --off / --allowlist / --approve / --yolo override to
     * config. Returns false if the value was invalid (an error is printed).
     */
    protected function applyShellOverride(): bool
    {
        $shell = match (true) {
            (bool) $this->option('off') => 'off',
            (bool) $this->option('allowlist') => 'allowlist',
            (bool) $this->option('approve') => 'approve',
            (bool) $this->option('yolo') => 'yolo',
            default => $this->option('shell'),
        };

        if ($shell === null) {
            return true;
        }

        if (! in_array($shell, ['off', 'allowlist', 'approve', 'yolo'], strict: true)) {
            $this->error("Invalid --shell value '{$shell}'. Must be one of: off, allowlist, approve, yolo.");

            return false;
        }

        config(['tackle.shell' => $shell]);

        return true;
    }

    protected function resolveWorktreeMode(): bool
    {
        if ($this->option('worktree')) {
            return true;
        }

        if ($this->option('no-worktree')) {
            return false;
        }

        $config = config('tackle.worktree', false);

        if (is_array($config)) {
            $env = app()->environment();

            return (bool) ($config[$env] ?? $config['*'] ?? false);
        }

        return (bool) $config;
    }

    protected function resolveShellMode(): string
    {
        $config = config('tackle.shell', 'approve');

        if (is_array($config)) {
            $env = app()->environment();

            return $config[$env] ?? $config['*'] ?? 'approve';
        }

        return $config;
    }
}
