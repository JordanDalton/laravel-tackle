<?php

namespace Tackle\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

class MakeEvalCommand extends Command
{
    protected $signature = 'tackle:eval
        {name : The eval case name (e.g. "refund rounding")}
        {--force : Overwrite an existing case file}';

    protected $description = 'Scaffold a new ai:eval case in your project\'s evals directory.';

    public function handle(): int
    {
        $id = Str::slug($this->argument('name'));

        if ($id === '') {
            $this->error('Provide a case name, e.g. tackle:eval "refund rounding".');

            return self::FAILURE;
        }

        $dir = config('tackle.evals.path') ?: base_path('evals');
        $path = rtrim($dir, '/').'/'.$id.'.php';

        if (file_exists($path) && ! $this->option('force')) {
            $this->error("Eval case already exists: {$path} (use --force to overwrite).");

            return self::FAILURE;
        }

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        file_put_contents($path, $this->resolveStub($id));

        $relative = str_starts_with($path, base_path())
            ? ltrim(substr($path, strlen(base_path())), '/')
            : $path;

        $this->line('');
        $this->line("<fg=green;options=bold>Eval case created:</> {$relative}");
        $this->line('');
        $this->line('Next steps:');
        $this->line('  1. Replace the seeded bug, the prompt, and the grader assertions.');
        $this->line("  2. Run it: <fg=cyan>php artisan ai:eval --case={$id}</>");
        $this->line('');

        return self::SUCCESS;
    }

    private function resolveStub(string $id): string
    {
        $published = base_path('stubs/tackle/eval.stub');
        $default = __DIR__.'/../../resources/stubs/eval.stub';

        $stub = file_exists($published) ? $published : $default;

        $class = 'Eval'.Str::studly($id);
        $title = Str::of($id)->replace('-', ' ')->title()->value();

        return str_replace(
            ['{{ id }}', '{{ class }}', '{{ title }}'],
            [$id, $class, $title],
            file_get_contents($stub),
        );
    }
}
