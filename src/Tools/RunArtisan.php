<?php

namespace Tackle\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Process;
use Laravel\Ai\Tools\Request;
use Tackle\Contracts\InteractionPolicy;
use Tackle\Support\CommandGuard;
use Tackle\Support\PathGuard;
use Tackle\Support\Utf8;

class RunArtisan extends AbstractTool
{
    public function __construct(
        private PathGuard $pathGuard,
        private CommandGuard $commandGuard,
        private ?InteractionPolicy $interaction = null,
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
            if (! $this->interaction()->confirm("⚠ Destructive: php artisan {$command} — proceed?", default: false)) {
                return 'Cancelled by user.';
            }
        } elseif ($refusal = $this->commandGuard->check($command, $allowlist)) {
            return $refusal;
        }

        $result = Process::path($this->pathGuard->workspace())
            ->run("php artisan {$command}");

        if ($result->failed()) {
            // Artisan writes most of its errors to stdout, not stderr — an
            // unknown option, a missing argument, a rendered exception. A
            // failure reported from stderr alone was very often blank.
            $detail = trim($result->errorOutput()."\n".$result->output());

            return "Artisan command failed (exit {$result->exitCode()}):\n".Utf8::clean($detail);
        }

        $output = Utf8::clean($result->output()) ?: '(Command ran successfully with no output.)';

        return $output.$this->createdFiles($output);
    }

    /**
     * The contents of whatever a make:* command just created.
     *
     * Without this the next step is always a guess at the stub's contents
     * followed by an EditFile that misses, then a ReadFile, then the edit
     * again — three steps to learn what artisan had just printed the path
     * of. Two runs in a row did exactly that.
     */
    private function createdFiles(string $output): string
    {
        if (! preg_match_all('/\[([^\]]+\.php)\] created successfully/', $output, $matches)) {
            return '';
        }

        $appended = '';

        foreach (array_unique($matches[1]) as $relative) {
            $path = $this->pathGuard->workspace().'/'.ltrim($relative, '/');

            if (! is_file($path)) {
                continue;
            }

            $appended .= "\n\n--- {$relative} ---\n".Utf8::clean((string) file_get_contents($path));
        }

        return $appended;
    }

    private function interaction(): InteractionPolicy
    {
        return $this->interaction ??= app(InteractionPolicy::class);
    }
}
