<?php

namespace Tackle\Commands;

use Illuminate\Console\Command;
use Tackle\Support\ProjectMemory;

class InitCommand extends Command
{
    protected $signature = 'tackle:init {--force : Overwrite an existing TACKLE.md}';

    protected $description = 'Generate a TACKLE.md project instructions file that Tackle agents load every session.';

    public function handle(): int
    {
        $workspace = rtrim(config('tackle.workspace') ?? base_path(), DIRECTORY_SEPARATOR);
        $target = $workspace.DIRECTORY_SEPARATOR.'TACKLE.md';

        $existing = (new ProjectMemory($workspace))->path();

        if (is_file($target) && ! $this->option('force')) {
            $this->components->error('TACKLE.md already exists. Use --force to overwrite it.');

            return self::FAILURE;
        }

        if ($existing !== null && basename($existing) !== 'TACKLE.md') {
            $this->components->warn(
                basename($existing).' found — agents are already loading it. '.
                'TACKLE.md takes precedence once created.'
            );
        }

        file_put_contents($target, $this->scaffold($workspace));

        $this->components->info('TACKLE.md created.');
        $this->line('  Every Tackle agent (ai:code, ai:fix, ai:review, ai:test, ai:explain, and the self-healer)');
        $this->line('  loads this file at the start of each session. Edit it to teach the agents your');
        $this->line('  project\'s conventions, boundaries, and gotchas.');

        return self::SUCCESS;
    }

    private function scaffold(string $workspace): string
    {
        $composer = $this->composerJson($workspace);

        $name = $composer['name'] ?? basename($workspace);
        $description = $composer['description'] ?? '';
        $dev = ($composer['require-dev'] ?? []) + ($composer['require'] ?? []);

        $php = $composer['require']['php'] ?? null;
        $laravel = $composer['require']['laravel/framework'] ?? null;

        $stack = [];
        $stack[] = trim('PHP '.($php ?? '').($laravel ? ', Laravel '.$laravel : ''), ' ,') ?: 'PHP / Laravel';
        $stack[] = 'Tests: '.(isset($dev['pestphp/pest']) ? 'Pest' : 'PHPUnit').' — run with `php artisan test`';

        if (isset($dev['laravel/pint'])) {
            $stack[] = 'Formatting: Laravel Pint — run on changed files before finishing';
        }

        if (isset($dev['larastan/larastan']) || isset($dev['nunomaduro/larastan']) || isset($dev['phpstan/phpstan'])) {
            $stack[] = 'Static analysis: Larastan/PHPStan — run on changed files before finishing';
        }

        if (isset($dev['laravel/telescope'])) {
            $stack[] = 'Observability: Laravel Telescope is installed';
        }

        $stackLines = implode("\n", array_map(fn ($line) => "- {$line}", $stack));

        $structureLines = implode("\n", array_map(
            fn ($dir) => '- `app/'.$dir.'/`',
            $this->appDirectories($workspace),
        )) ?: '- `app/`';

        $title = $description !== '' ? "# {$name}\n\n{$description}" : "# {$name}";

        return <<<MARKDOWN
        {$title}

        Instructions for AI agents working in this codebase. Tackle loads this file into
        every agent session — keep it short, specific, and current.

        ## Stack

        {$stackLines}

        ## Structure

        {$structureLines}

        ## Conventions

        <!-- Rules the agent should follow. Be specific. Examples:
        - All money values are integer cents — never floats.
        - New endpoints go through Form Requests for validation, never inline `validate()`.
        - Use the existing `ApiResponse` helper for JSON responses.
        -->

        ## Boundaries

        <!-- Things the agent must not touch or do. Examples:
        - Never modify files under `app/Legacy/` — scheduled for deletion.
        - Do not add new composer dependencies without asking first.
        -->

        ## Gotchas

        <!-- Non-obvious behaviour that trips people up. Examples:
        - `User::active()` excludes soft-deleted AND suspended users.
        - The `orders` table is partitioned — migrations on it need review.
        -->
        MARKDOWN;
    }

    private function composerJson(string $workspace): array
    {
        $path = $workspace.DIRECTORY_SEPARATOR.'composer.json';

        if (! is_file($path)) {
            return [];
        }

        return json_decode((string) file_get_contents($path), true) ?: [];
    }

    private function appDirectories(string $workspace): array
    {
        $app = $workspace.DIRECTORY_SEPARATOR.'app';

        if (! is_dir($app)) {
            return [];
        }

        $dirs = array_filter(
            scandir($app) ?: [],
            fn ($entry) => $entry !== '.' && $entry !== '..' && is_dir($app.DIRECTORY_SEPARATOR.$entry),
        );

        sort($dirs);

        return array_values($dirs);
    }
}
