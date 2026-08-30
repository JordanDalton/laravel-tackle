<?php

namespace Tackle\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Process;
use Laravel\Ai\Tools\Request;
use Tackle\Support\PathGuard;
use Tackle\Support\Utf8;

class RunPint extends AbstractTool
{
    public function __construct(private PathGuard $guard) {}

    public function description(): string
    {
        return 'Run Laravel Pint to format PHP files. Call this before finishing a task to ensure code style is consistent.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'path' => $schema->string()
                ->description('Optional file or directory to format. Defaults to the whole workspace.'),
        ];
    }

    public function handle(Request $request): string
    {
        $workspace = $this->guard->workspace();

        if (! file_exists($workspace.'/vendor/bin/pint')) {
            return "Pint is not installed. Run 'composer require laravel/pint --dev' first.";
        }

        $target = $this->arg($request, 'path', '');
        $arg = $target !== '' ? ' '.escapeshellarg($target) : '';

        $result = Process::path($workspace)
            ->timeout(60)
            ->run("./vendor/bin/pint{$arg}");

        return Utf8::clean($result->output().$result->errorOutput()) ?: '(Pint ran with no output.)';
    }
}
