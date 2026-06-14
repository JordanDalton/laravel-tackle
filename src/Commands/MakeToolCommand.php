<?php

namespace Tackle\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

class MakeToolCommand extends Command
{
    protected $signature = 'tackle:tool {name : The tool class name}';

    protected $description = 'Create a new Tackle tool class.';

    public function handle(): int
    {
        $name = Str::studly($this->argument('name'));
        $path = app_path("Ai/Tools/{$name}.php");

        if (file_exists($path)) {
            $this->error("Tool already exists: {$path}");
            return self::FAILURE;
        }

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        file_put_contents($path, $this->resolveStub($name));

        $this->line('');
        $this->line("<fg=green;options=bold>Tool created:</> app/Ai/Tools/{$name}.php");
        $this->line('');
        $this->line('Next steps:');
        $this->line('  1. Implement <fg=cyan>description()</>, <fg=cyan>schema()</>, and <fg=cyan>handle()</> in your new class.');
        $this->line('  2. Inject it into your agent\'s <fg=cyan>tools()</> method.');
        $this->line('     (Run <fg=cyan>php artisan tackle:agent MyAgent</> to scaffold an agent that wires it in.)');
        $this->line('');

        return self::SUCCESS;
    }

    private function resolveStub(string $class): string
    {
        $published = base_path('stubs/tackle/tool.stub');
        $default   = __DIR__ . '/../../resources/stubs/tool.stub';

        $stub = file_exists($published) ? $published : $default;

        return str_replace('{{ class }}', $class, file_get_contents($stub));
    }
}
