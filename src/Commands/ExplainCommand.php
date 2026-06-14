<?php

namespace Tackle\Commands;

use Illuminate\Console\Command;
use Laravel\Ai\Streaming\Events\TextDelta;
use Tackle\Agents\ExplainAgent;

class ExplainCommand extends Command
{
    protected $signature = 'ai:explain
        {path           : File or class path to explain (relative to project root)}
        {--method=      : Focus on a specific method name}';

    protected $description = 'Explain what a file, class, or method does in plain English.';

    public function handle(ExplainAgent $agent): int
    {
        $path   = $this->argument('path');
        $method = $this->option('method');

        $fullPath = base_path($path);

        if (! file_exists($fullPath)) {
            $this->error("File not found: {$path}");
            return self::FAILURE;
        }

        $this->line('');
        $this->line('<fg=green;options=bold>Laravel Tackle — AI Explain</>');
        $this->line("<fg=gray>Target: {$path}" . ($method ? " → {$method}()" : '') . '</>' );
        $this->line('');

        $prompt = $method
            ? "Explain the `{$method}()` method in `{$path}`. Read the full class for context before explaining."
            : "Explain what `{$path}` does. Read the file and any closely related classes before explaining.";

        $response = $agent->stream($prompt);

        $response->each(function ($event) {
            if ($event instanceof TextDelta) {
                $this->output->write($event->delta);
            }
        });

        $this->newLine(2);

        return self::SUCCESS;
    }
}
