<?php

namespace Tackle\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Process;
use Laravel\Ai\Tools\Request;
use Tackle\Support\CommandGuard;
use Tackle\Support\PathGuard;

use function Laravel\Prompts\confirm;

class RunArtisan extends AbstractTool
{
    public function __construct(
        private PathGuard $pathGuard,
        private CommandGuard $commandGuard,
    ) {}

    public function description(): string
    {
        return 'Run an Artisan command as a subprocess. Commands in the artisan_allowlist run freely; commands in artisan_destructive require terminal confirmation; everything else is refused. Both lists are environment-aware. Returns stdout on success, or exit code + stderr on failure.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'command' => $schema->string()
                ->description('The Artisan command to run (without "php artisan" prefix), e.g. "make:model Post".')
                ->required(),
        ];
    }

    public function handle(Request $request): string
    {
        $command = trim($request->string('command', ''));

        if ($command === '') {
            return 'A non-empty command is required.';
        }

        $allowlist = $this->commandGuard->resolveList(config('tackle.artisan_allowlist', []));
        $destructive = $this->commandGuard->resolveList(config('tackle.artisan_destructive', []));

        if ($this->commandGuard->matches($command, $destructive)) {
            echo PHP_EOL;
            if (! confirm("⚠ Destructive: php artisan {$command} — proceed?", default: false)) {
                return 'Cancelled by user.';
            }
        } elseif ($refusal = $this->commandGuard->check($command, $allowlist)) {
            return $refusal;
        }

        $result = Process::path($this->pathGuard->workspace())
            ->run("php artisan {$command}");

        if ($result->failed()) {
            return "Artisan command failed (exit {$result->exitCode()}):\n{$result->errorOutput()}";
        }

        return $result->output() ?: '(Command ran successfully with no output.)';
    }
}
