<?php

namespace Tackle\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Composer;
use TackleRemote\TackleRemoteServiceProvider;

class InstallCommand extends Command
{
    protected $signature = 'tackle:install
        {component? : Optional add-on: "remote" (phone/browser UI) or "review" (PR review workflow)}
        {--stubs : Also publish customisable stubs to stubs/tackle/}
        {--migrate : Run migrations automatically after publishing}
        {--no-dev : For "remote": add to require instead of require-dev}';

    protected $description = 'Install Laravel Tackle — publish config, migrations, and optionally stubs — or an ecosystem add-on.';

    public function handle(): int
    {
        $component = (string) $this->argument('component');

        if ($component !== '') {
            return match ($component) {
                'remote' => $this->installRemote(),
                'review' => $this->installReview(),
                default => $this->unknownComponent($component),
            };
        }

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

    /**
     * composer-require the tackle-remote companion package. Mirrors what
     * Laravel's own install:api / install:broadcasting commands do: shell
     * out through Illuminate\Support\Composer, streaming real output so a
     * failed require is never reported as success.
     */
    private function installRemote(): int
    {
        if (class_exists(TackleRemoteServiceProvider::class)) {
            $this->components->info('Tackle Remote is already installed.');
            $this->line('  Run <fg=cyan>php artisan tackle:remote --host=0.0.0.0</> and scan the QR with your phone.');

            return self::SUCCESS;
        }

        $dev = ! $this->option('no-dev');

        $this->line('');
        $this->line('<fg=green;options=bold>Installing Tackle Remote'.($dev ? ' (require-dev)' : '').'...</>');
        $this->line('');

        $composer = $this->laravel->make(Composer::class)->setWorkingPath(base_path());

        if (! $composer->requirePackages(['jordandalton/laravel-tackle-remote'], $dev, $this->output)) {
            $this->components->error('composer require failed — see the output above. Nothing was installed.');

            return self::FAILURE;
        }

        $this->line('');
        $this->line('<fg=green;options=bold>Done!</> Drive the agent from your phone:');
        $this->line('');
        $this->line('  <fg=cyan>php artisan tackle:remote --host=0.0.0.0</>');
        $this->line('');
        $this->line('  Scan the QR code it prints. Pairing links are single-use; sessions die with the process.');
        $this->line('');

        return self::SUCCESS;
    }

    /**
     * Scaffold the tackle-review GitHub Actions workflow so every pull
     * request gets reviewed. Never overwrites an existing workflow.
     */
    private function installReview(): int
    {
        $path = base_path('.github/workflows/tackle-review.yml');

        if (file_exists($path)) {
            $this->components->warn('.github/workflows/tackle-review.yml already exists — leaving it untouched.');

            return self::SUCCESS;
        }

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        file_put_contents($path, <<<'YAML'
        name: Tackle Review
        on:
          pull_request:

        permissions:
          contents: read
          pull-requests: write

        jobs:
          review:
            runs-on: ubuntu-latest
            steps:
              - uses: JordanDalton/tackle-review@v1
                with:
                  anthropic-api-key: ${{ secrets.ANTHROPIC_API_KEY }}
                  # fail-on: critical   # uncomment to fail the check on critical findings

        YAML);

        $this->line('');
        $this->line('  <fg=green>✓</> Workflow written → <fg=cyan>.github/workflows/tackle-review.yml</>');
        $this->line('');
        $this->line('Next steps:');
        $this->line('');
        $this->line('  1. Add the <fg=cyan>ANTHROPIC_API_KEY</> secret to the GitHub repository.');
        $this->line('  2. Commit the workflow and open a pull request — it gets reviewed automatically.');
        $this->line('  3. Reviewers can reply <fg=cyan>/tackle fix this</> under any finding once ai:respond is wired up.');
        $this->line('');

        return self::SUCCESS;
    }

    private function unknownComponent(string $component): int
    {
        $this->components->error("Unknown component '{$component}'. Available:");
        $this->line('  <fg=cyan>php artisan tackle:install remote</>  — browser/phone UI for the agent');
        $this->line('  <fg=cyan>php artisan tackle:install review</>  — GitHub Actions workflow reviewing every PR');

        return self::FAILURE;
    }

    private function publishConfig(): void
    {
        $this->callSilently('vendor:publish', ['--tag' => 'tackle-config']);
        $this->line('  <fg=green>✓</> Config published → <fg=cyan>config/tackle.php</>');
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
            'GITHUB_TOKEN' => '',
            'GITHUB_REPO' => '',
            'SENTRY_AUTH_TOKEN' => '',
            'SENTRY_ORG' => '',
        ];

        foreach ($defaults as $key => $value) {
            if (! str_contains($contents, $key)) {
                $appended[] = "{$key}={$value}";
            }
        }

        if ($appended) {
            file_put_contents($envPath, $contents."\n".implode("\n", $appended)."\n");
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
