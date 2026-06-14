<?php

namespace Tackle\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

class MakeAgentCommand extends Command
{
    protected $signature = 'tackle:agent
        {name : The agent class name}
        {--full : Scaffold a full CodingAgent implementation instead of extending DefaultCodingAgent}';

    protected $description = 'Create a new Tackle agent class.';

    public function handle(): int
    {
        $name = Str::studly($this->argument('name'));
        $path = app_path("Ai/{$name}.php");

        if (file_exists($path)) {
            $this->error("Agent already exists: {$path}");
            return self::FAILURE;
        }

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        file_put_contents($path, $this->resolveStub($name));

        $this->line('');
        $this->line("<fg=green;options=bold>Agent created:</> app/Ai/{$name}.php");
        $this->line('');
        $this->line('To activate your agent, bind it in <fg=cyan>AppServiceProvider::register()</>:');
        $this->line('');
        $this->line("    \$this->app->bind(\\Tackle\\Contracts\\CodingAgent::class, \\App\\Ai\\{$name}::class);");
        $this->line('');

        return self::SUCCESS;
    }

    private function resolveStub(string $class): string
    {
        $stubKey  = $this->option('full') ? 'agent.full' : 'agent.extend';
        $published = base_path("stubs/tackle/{$stubKey}.stub");
        $default   = __DIR__ . "/../../resources/stubs/{$stubKey}.stub";

        $stub = file_exists($published) ? $published : $default;

        return str_replace('{{ class }}', $class, file_get_contents($stub));
    }
}
