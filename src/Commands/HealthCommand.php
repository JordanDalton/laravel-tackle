<?php

namespace Tackle\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class HealthCommand extends Command
{
    protected $signature = 'tackle:health';

    protected $description = 'Check that Laravel Tackle is correctly configured.';

    private array $passed  = [];
    private array $failed  = [];
    private array $warnings = [];

    public function handle(): int
    {
        $this->line('');
        $this->line('<fg=green;options=bold>Laravel Tackle — Health Check</>');
        $this->line('');

        $this->checkConfig();
        $this->checkAiConfig();
        $this->checkApiKey();
        $this->checkGit();
        $this->checkEnvTesting();

        if (config('ai-code.healing.enabled', false)) {
            $this->checkHealing();
        }

        $this->checkGitHub();
        $this->checkSentry();
        $this->checkWorktrees();

        $this->printSummary();

        return empty($this->failed) ? self::SUCCESS : self::FAILURE;
    }

    private function checkConfig(): void
    {
        if (file_exists(config_path('ai-code.php'))) {
            $this->pass('config/ai-code.php published');
        } else {
            $this->check('config/ai-code.php not found', 'Run: php artisan vendor:publish --tag="tackle-config"');
        }
    }

    private function checkAiConfig(): void
    {
        if (file_exists(config_path('ai.php'))) {
            $this->pass('config/ai.php published');
        } else {
            $this->check('config/ai.php not found', 'Run: php artisan vendor:publish --provider="Laravel\Ai\AiServiceProvider"');
        }
    }

    private function checkApiKey(): void
    {
        $provider = config('ai-code.provider', 'anthropic');
        $key      = config("ai.providers.{$provider}.api_key");

        if (! empty($key)) {
            $this->pass("API key configured for provider [{$provider}]");
        } else {
            $this->check(
                "No API key found for provider [{$provider}]",
                'Set the key in .env — e.g. ANTHROPIC_API_KEY=sk-ant-...'
            );
        }
    }

    private function checkGit(): void
    {
        $base = base_path();

        if (! is_dir($base . '/.git')) {
            $this->check('Not a git repository', 'Run: git init && git add -A && git commit -m "initial"');
            return;
        }

        $this->pass('Git repository detected');

        $result = \Illuminate\Support\Facades\Process::path($base)->run(['git', 'rev-parse', 'HEAD']);

        if (! $result->successful()) {
            $this->notice('No commits yet', 'The self-healer requires at least one commit. Run: git add -A && git commit -m "initial"');
        } else {
            $this->pass('Git repository has commits');
        }
    }

    private function checkEnvTesting(): void
    {
        if (file_exists(base_path('.env.testing'))) {
            $this->pass('.env.testing exists — RunTests will use isolated test environment');
        } else {
            $this->notice(
                '.env.testing not found',
                'Without .env.testing, RunTests in production would use the live database. Create .env.testing pointing at a test DB.'
            );
        }
    }

    private function checkHealing(): void
    {
        $this->line('  <fg=gray>Healing checks (AI_CODE_HEALING_ENABLED=true):</>');

        try {
            DB::table('tackle_healing_log')->count();
            $this->pass('tackle_healing_log migration has been run');
        } catch (\Throwable) {
            $this->check(
                'tackle_healing_log table not found',
                'Run: php artisan vendor:publish --tag="tackle-migrations" && php artisan migrate'
            );
        }

        $mode = config('ai-code.healing.mode', 'pr');

        if ($mode === 'pr') {
            $token = config('ai-code.healing.github_token')
                ?? $this->resolveGhToken();

            if ($token) {
                $this->pass('GitHub token found (PR mode)');
            } else {
                $this->check(
                    'No GitHub token found (required for pr mode)',
                    'Set GITHUB_TOKEN in .env, or authenticate with: gh auth login'
                );
            }
        }
    }

    private function checkGitHub(): void
    {
        $token = config('ai-code.github.token');
        $repo  = config('ai-code.github.repo');

        if ($token && $repo) {
            $this->pass("GitHub configured ({$repo}) — ReadGitHubIssue tool is active");
        } elseif ($token && ! $repo) {
            $this->notice(
                'GITHUB_TOKEN set but GITHUB_REPO is missing — ReadGitHubIssue tool will no-op',
                'Set GITHUB_REPO=owner/repo in .env'
            );
        } else {
            $this->notice(
                'GitHub not configured — ReadGitHubIssue tool will no-op',
                'Set GITHUB_TOKEN and GITHUB_REPO in .env to enable it'
            );
        }
    }

    private function checkSentry(): void
    {
        $token = config('ai-code.sentry.auth_token');
        $org   = config('ai-code.sentry.org');

        if ($token && $org) {
            $this->pass('Sentry configured — ReadSentryIssue tool is active');
        } else {
            $this->notice(
                'Sentry not configured — ReadSentryIssue tool will no-op',
                'Set SENTRY_AUTH_TOKEN and SENTRY_ORG in .env to enable it'
            );
        }
    }

    private function checkWorktrees(): void
    {
        $result = \Illuminate\Support\Facades\Process::path(base_path())
            ->run(['git', 'worktree', 'list', '--porcelain']);

        if (! $result->successful()) {
            return;
        }

        $dangling = collect(explode("\n", trim($result->output())))
            ->filter(fn ($line) => str_starts_with($line, 'worktree '))
            ->map(fn ($line) => trim(substr($line, strlen('worktree '))))
            ->filter(fn ($path) => str_contains($path, 'tackle-'))
            ->values();

        if ($dangling->isEmpty()) {
            $this->pass('No dangling Tackle worktrees');
        } else {
            foreach ($dangling as $path) {
                $this->notice(
                    "Dangling worktree: {$path}",
                    'Run: php artisan tackle:prune'
                );
            }
        }
    }

    private function resolveGhToken(): ?string
    {
        $result = \Illuminate\Support\Facades\Process::run(['gh', 'auth', 'token']);
        $token  = trim($result->output());
        return ($result->successful() && $token !== '') ? $token : null;
    }

    private function pass(string $message): void
    {
        $this->passed[] = $message;
        $this->line("  <fg=green>✓</> {$message}");
    }

    private function check(string $message, string $hint = ''): void
    {
        $this->failed[] = $message;
        $this->line("  <fg=red>✗</> {$message}");
        if ($hint) {
            $this->line("    <fg=gray>→ {$hint}</>");
        }
    }

    private function notice(string $message, string $hint = ''): void
    {
        $this->warnings[] = $message;
        $this->line("  <fg=yellow>!</> {$message}");
        if ($hint) {
            $this->line("    <fg=gray>→ {$hint}</>");
        }
    }

    private function printSummary(): void
    {
        $this->line('');

        if (empty($this->failed)) {
            $warnings = count($this->warnings);
            $msg = $warnings > 0
                ? "<fg=green>All checks passed</> with <fg=yellow>{$warnings} warning(s)</>."
                : '<fg=green;options=bold>All checks passed.</>';
            $this->line($msg);
        } else {
            $count = count($this->failed);
            $this->line("<fg=red>{$count} check(s) failed.</> Fix the issues above and re-run <fg=cyan>php artisan tackle:health</>.");
        }

        $this->line('');
    }
}
