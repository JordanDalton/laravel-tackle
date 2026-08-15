<?php

namespace Tackle\Commands\Concerns;

use Tackle\Support\ModelCatalog;

/**
 * Shell, worktree, and model option handling shared by ai:code and ai:run.
 * Both accept the same flags and must resolve them identically.
 */
trait ResolvesSessionOptions
{
    /**
     * Apply any --model / --provider override to config. Must run before the
     * agent and BudgetTracker are resolved from the container — both read
     * tackle.model / tackle.pricing at construction time.
     */
    protected function applyModelOverride(): void
    {
        $provider = $this->option('provider');
        $model = $this->option('model');

        if ($provider !== null && $provider !== '') {
            config(['tackle.provider' => $provider]);
        }

        if ($model !== null && $model !== '') {
            config(['tackle.model' => $model]);

            if (ModelCatalog::pricing($model) === null
                && config('tackle.pricing.input_per_mtok') === null) {
                $this->warn("No known pricing for '{$model}' — budget tracking will assume \$3/\$15 per MTok. Set AI_CODE_PRICE_INPUT / AI_CODE_PRICE_OUTPUT or add it to tackle.pricing.models for accurate enforcement.");
            }
        }
    }

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
