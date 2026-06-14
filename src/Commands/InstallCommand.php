<?php

namespace Tackle\Commands;

use Illuminate\Console\Command;

class InstallCommand extends Command
{
    protected $signature = 'tackle:install
        {--stubs : Also publish customisable stubs to stubs/tackle/}
        {--migrate : Run migrations automatically after publishing}';

    protected $description = 'Install Laravel Tackle — publish config, migrations, and optionally stubs.';

    public function handle(): int
    {
        $this->line('');
        $this->line('<fg=green;options=bold>Installing Laravel Tackle...</>');
        $this->line('');

        $this->publishConfig();
        $this->publishMigrations();

        if ($this->option('stubs')) {
            $this->publishStubs();
        }

        if ($this->option('migrate')) {
            $this->runMigrations();
        }

        $this->appendEnvVars();
        $this->printNextSteps();

        return self::SUCCESS;
    }

    private function publishConfig(): void
    {
        $this->callSilently('vendor:publish', ['--tag' => 'tackle-config']);
        $this->line('  <fg=green>✓</> Config published → <fg=cyan>config/ai-code.php</>');
    }

    private function publishMigrations(): void
    {
        $this->callSilently('vendor:publish', ['--tag' => 'tackle-migrations']);
        $this->line('  <fg=green>✓</> Migrations published → <fg=cyan>database/migrations/</>');
    }

    private function publishStubs(): void
    {
        $this->callSilently('vendor:publish', ['--tag' => 'tackle-stubs']);
        $this->line('  <fg=green>✓</> Stubs published → <fg=cyan>stubs/tackle/</>');
    }

    private function runMigrations(): void
    {
        $this->callSilently('migrate');
        $this->line('  <fg=green>✓</> Migrations run');
    }

    private function appendEnvVars(): void
    {
        $envPath = base_path('.env');

        if (! file_exists($envPath)) {
            return;
        }

        $contents = file_get_contents($envPath);
        $appended = [];

        $defaults = [
            'AI_CODE_HEALING_ENABLED' => 'false',
            'GITHUB_TOKEN'            => '',
            'GITHUB_REPO'             => '',
            'SENTRY_AUTH_TOKEN'       => '',
            'SENTRY_ORG'              => '',
        ];

        foreach ($defaults as $key => $value) {
            if (! str_contains($contents, $key)) {
                $appended[] = "{$key}={$value}";
            }
        }

        if ($appended) {
            file_put_contents($envPath, $contents . "\n" . implode("\n", $appended) . "\n");
            $this->line('  <fg=green>✓</> Environment variables added to <fg=cyan>.env</>');
        }
    }

    private function printNextSteps(): void
    {
        $this->line('');
        $this->line('<fg=green;options=bold>Done!</>');
        $this->line('');
        $this->line('Next steps:');
        $this->line('');
        $this->line('  1. Publish the <fg=cyan>laravel/ai</> config if you haven\'t already:');
        $this->line('     <fg=cyan>php artisan vendor:publish --provider="Laravel\Ai\AiServiceProvider"</>');
        $this->line('');
        $this->line('  2. Add your API key to <fg=cyan>.env</>:');
        $this->line('     <fg=cyan>ANTHROPIC_API_KEY=sk-ant-...</>');
        $this->line('');
        $this->line('  3. Run your first session:');
        $this->line('     <fg=cyan>php artisan ai:code</>');
        $this->line('');
        $this->line('  4. To enable self-healing, set <fg=cyan>AI_CODE_HEALING_ENABLED=true</> and run:');
        $this->line('     <fg=cyan>php artisan migrate</>');
        $this->line('     <fg=cyan>php artisan queue:work --queue=healer</>');
        $this->line('');
    }
}
