<?php

namespace Tackle\Commands;

use Illuminate\Console\Command;
use Laravel\Ai\Streaming\Events\TextDelta;
use Tackle\Agents\TestWriterAgent;

class TestCommand extends Command
{
    protected $signature = 'ai:test
        {path           : File or class to write tests for (relative to project root)}
        {--method=      : Focus on a specific method}
        {--feature      : Write a feature test (default: inferred from path)}
        {--unit         : Write a unit test (default: inferred from path)}';

    protected $description = 'Generate tests for a class or method using AI.';

    public function handle(TestWriterAgent $agent): int
    {
        $path   = $this->argument('path');
        $method = $this->option('method');

        if (! file_exists(base_path($path))) {
            $this->error("File not found: {$path}");
            return self::FAILURE;
        }

        $type = $this->resolveTestType($path);

        $this->line('');
        $this->line('<fg=green;options=bold>Laravel Tackle — AI Test Writer</>');
        $this->line("<fg=gray>Target: {$path}" . ($method ? " → {$method}()" : '') . " | Type: {$type}</>");
        $this->line('');

        $prompt = $this->buildPrompt($path, $method, $type);

        $response = $agent->stream($prompt);

        $response->each(function ($event) {
            if ($event instanceof TextDelta) {
                $this->output->write($event->delta);
            }
        });

        $this->newLine(2);

        return self::SUCCESS;
    }

    private function buildPrompt(string $path, ?string $method, string $type): string
    {
        $target = $method
            ? "the `{$method}()` method in `{$path}`"
            : "`{$path}`";

        return "Write {$type} tests for {$target}. "
            . "Read the class and any related classes before writing. "
            . "Check the tests/ directory for existing conventions. "
            . "Run the tests after writing to confirm they pass.";
    }

    private function resolveTestType(string $path): string
    {
        if ($this->option('feature')) {
            return 'Feature';
        }

        if ($this->option('unit')) {
            return 'Unit';
        }

        // Infer from path: controllers, jobs, commands → Feature; everything else → Unit
        $featureIndicators = ['Controller', 'Command', 'Job', 'Listener', 'Middleware'];

        foreach ($featureIndicators as $indicator) {
            if (str_contains($path, $indicator)) {
                return 'Feature';
            }
        }

        return 'Unit';
    }
}
